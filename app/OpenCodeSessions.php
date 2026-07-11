<?php

/**
 * OpenCode session provider - reads from $XDG_DATA_HOME/opencode.
 *
 * OpenCode (sst/opencode) has used two storage backends over its life:
 *
 *   1. SQLite (current): opencode.db at the data-dir root, managed by drizzle.
 *      Tables: project, session (typed columns), message + part (JSON `data`
 *      column, same shapes as the old per-entity files). OpenCode's own
 *      migration imports the legacy tree into the db on first run, so when
 *      the db exists it is a superset of the file tree and wins.
 *
 *   2. Legacy per-entity JSON files under storage/:
 *      storage/project/{projectID}.json          - workspace records
 *      storage/session/{projectID}/ses_*.json    - session metadata
 *      storage/message/{sessionID}/msg_*.json    - one file per turn
 *      storage/part/{messageID}/prt_*.json       - message content chunks
 *                                                  (type=text|reasoning|tool|step-start|step-finish)
 *
 * We read the db when present and union in any legacy sessions the migration
 * missed; installs that never migrated fall back to the file tree entirely.
 *
 * ProjectID is sha1(worktree-path) legacy / git-root-commit in the db for real
 * projects, or the literal "global" for ad-hoc sessions without a worktree.
 * We surface "global" as a pseudo-project named "(global)" so those sessions
 * remain searchable.
 */
class OpenCodeSessions {

	private static ?array $projectsCache = null;

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'opencode';
	}

	public static function sourceLabel(): string {
		return 'OpenCode';
	}

	public static function hasSession( string $sessionId ): bool {
		if ( ! preg_match( '/^ses_[A-Za-z0-9]+$/', $sessionId ) ) {
			return false;
		}
		if ( self::dbRows( 'SELECT 1 FROM session WHERE id = ?', [ $sessionId ] ) ) {
			return true;
		}
		return self::findLegacySessionFile( $sessionId ) !== null;
	}

	/**
	 * Canonical path for a session: its legacy JSON file when one exists,
	 * otherwise the SQLite database that holds it.
	 */
	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		if ( ! preg_match( '/^ses_[A-Za-z0-9]+$/', $id ) ) {
			return null;
		}
		$legacy = self::findLegacySessionFile( $id );
		if ( $legacy ) {
			return $legacy;
		}
		if ( self::dbRows( 'SELECT 1 FROM session WHERE id = ?', [ $id ] ) ) {
			return self::dbPath();
		}
		return null;
	}

	private static function findLegacySessionFile( string $id ): ?string {
		$matches = glob( self::storageDir() . '/session/*/' . $id . '.json' );
		return $matches ? $matches[0] : null;
	}

	/**
	 * Fingerprint for incremental indexing.
	 *
	 * Db sessions: ( max(session/message time_updated), message count ).
	 * Legacy tree: ( max(session-file mtime, message-dir mtime), message-file
	 * count ) - new turns write new message/part files, so watching the dir
	 * catches live growth cheaply without stat'ing individual files.
	 */
	public static function fingerprint( array $session ): ?array {
		$id = $session['id'] ?? '';

		$rows = self::dbRows(
			'SELECT s.time_updated,
			        (SELECT COUNT(*)           FROM message m WHERE m.session_id = s.id) AS messages,
			        (SELECT MAX(m.time_updated) FROM message m WHERE m.session_id = s.id) AS latest
			   FROM session s WHERE s.id = ?',
			[ $id ]
		);
		if ( $rows ) {
			$r = $rows[0];
			return [
				'mtime' => intval( max( (int) $r['time_updated'], (int) ( $r['latest'] ?? 0 ) ) / 1000 ),
				'size'  => 1 + (int) $r['messages'],
			];
		}

		$file = self::findLegacySessionFile( $id );
		if ( ! $file ) {
			return null;
		}

		$mtime = @filemtime( $file ) ?: 0;
		$size  = 1; // session file itself

		$msgDir = self::storageDir() . '/message/' . $id;
		if ( is_dir( $msgDir ) ) {
			$dirMtime = @filemtime( $msgDir );
			if ( $dirMtime && $dirMtime > $mtime ) {
				$mtime = $dirMtime;
			}
			$entries = @scandir( $msgDir );
			if ( is_array( $entries ) ) {
				$size += count( $entries ) - 2; // strip . and ..
			}
		}

		return [ 'mtime' => $mtime, 'size' => $size ];
	}

	/**
	 * Concat every text-type part, grouped by message in chronological order.
	 * Reasoning parts and tool I/O are intentionally skipped for FTS - they
	 * inflate the index and hurt result relevance on user-intent queries.
	 */
	public static function extractSessionText( array $session ): string {
		$id    = $session['id'] ?? '';
		$parts = [];

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		foreach ( self::sessionMessages( $id ) as $msg ) {
			foreach ( self::messageParts( $msg['id'] ?? '' ) as $part ) {
				if ( ( $part['type'] ?? '' ) !== 'text' ) {
					continue;
				}
				$text = trim( $part['text'] ?? '' );
				if ( $text !== '' ) {
					$parts[] = $text;
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Sum token usage across assistant messages. OpenCode stores a `tokens`
	 * object per assistant message: {input, output, reasoning, cache:{read,write}}.
	 * Reasoning tokens are billed as output, so they fold into `output`.
	 */
	public static function extractUsage( array $session ): ?array {
		$totals = [ 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0 ];
		$found  = false;

		foreach ( self::sessionMessages( $session['id'] ?? '' ) as $msg ) {
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}
			$t = $msg['tokens'] ?? null;
			if ( ! is_array( $t ) ) {
				continue;
			}
			$totals['input']          += (int) ( $t['input'] ?? 0 );
			$totals['output']         += (int) ( $t['output'] ?? 0 ) + (int) ( $t['reasoning'] ?? 0 );
			$totals['cache_read']     += (int) ( $t['cache']['read'] ?? 0 );
			$totals['cache_creation'] += (int) ( $t['cache']['write'] ?? 0 );
			$found = true;
		}

		return $found ? $totals : null;
	}

	// ─── Listing ────────────────────────────────────────────────

	public static function listSessions( ?string $project = null ): array {
		$out  = [];
		$seen = [];

		// Primary: the SQLite database. Superset of the legacy tree once
		// OpenCode's own migration has run.
		$rows = self::dbRows(
			'SELECT s.id, s.title, s.slug, s.project_id, s.time_created, s.time_updated,
			        (SELECT COUNT(*) FROM message m WHERE m.session_id = s.id) AS messages,
			        p.worktree, p.name AS project_label
			   FROM session s LEFT JOIN project p ON p.id = s.project_id'
		);
		foreach ( $rows as $row ) {
			$worktree = self::dbProjectPath( $row['project_id'] ?? '', $row['worktree'] ?? null );
			$name     = self::dbProjectName( $row['project_id'] ?? '', $row['worktree'] ?? null, $row['project_label'] ?? null );

			$seen[ $row['id'] ] = true;

			if ( $project !== null && $project !== '' && $project !== $worktree ) {
				continue;
			}

			$updatedMs = (int) ( $row['time_updated'] ?: $row['time_created'] );

			$out[] = [
				'id'          => $row['id'],
				'display'     => $row['title'] ?: ( $row['slug'] ?? '' ),
				'timestamp'   => $updatedMs,
				'timestamp_s' => intval( $updatedMs / 1000 ),
				'project'     => $worktree,
				'projectName' => $name,
				'size'        => (int) $row['messages'],
				'created'     => (int) ( $row['time_created'] ?: $updatedMs ),
				'slug'        => $row['slug'] ?? '',
			];
		}

		// Union: legacy per-file sessions the db migration missed (or the
		// whole tree on installs that never migrated).
		$storage  = self::storageDir();
		$projects = self::projects();
		foreach ( glob( $storage . '/session/*/ses_*.json' ) ?: [] as $file ) {
			$ses = self::readJson( $file );
			if ( ! $ses || empty( $ses['id'] ) || isset( $seen[ $ses['id'] ] ) ) {
				continue;
			}

			$projectID   = $ses['projectID'] ?? 'global';
			$projectInfo = $projects[ $projectID ] ?? null;
			$worktree    = self::projectPath( $projectID, $projectInfo );
			$projectName = self::projectName( $projectID, $projectInfo );

			if ( $project !== null && $project !== '' && $project !== $worktree ) {
				continue;
			}

			$updatedMs = $ses['time']['updated'] ?? $ses['time']['created'] ?? 0;
			$createdMs = $ses['time']['created'] ?? $updatedMs;

			$out[] = [
				'id'          => $ses['id'],
				'display'     => $ses['title'] ?? ( $ses['slug'] ?? '' ),
				'timestamp'   => (int) $updatedMs,
				'timestamp_s' => intval( $updatedMs / 1000 ),
				'project'     => $worktree,
				'projectName' => $projectName,
				'size'        => self::estimateSessionSize( $ses['id'] ),
				'created'     => (int) $createdMs,
				'slug'        => $ses['slug'] ?? '',
			];
		}

		usort( $out, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		return $out;
	}

	/**
	 * Aggregate projects from the session list so both backends (and the
	 * union of them) stay consistent with what listSessions() reports.
	 */
	public static function listProjects(): array {
		$agg = [];

		foreach ( self::listSessions() as $s ) {
			$key = $s['project'];
			if ( ! isset( $agg[ $key ] ) ) {
				$agg[ $key ] = [
					'path'     => $s['project'],
					'name'     => $s['projectName'],
					'sessions' => 0,
					'latest'   => 0,
				];
			}
			$agg[ $key ]['sessions']++;
			if ( $s['timestamp'] > $agg[ $key ]['latest'] ) {
				$agg[ $key ]['latest'] = $s['timestamp'];
			}
		}

		$out = array_values( $agg );
		usort( $out, fn( $a, $b ) => $b['latest'] <=> $a['latest'] );
		return $out;
	}

	// ─── Conversation ───────────────────────────────────────────

	public static function getConversation( string $sessionId ): array {
		if ( ! self::hasSession( $sessionId ) ) {
			return [];
		}

		$messages = self::sessionMessages( $sessionId );
		$events   = [];

		$model = '';
		foreach ( $messages as $m ) {
			if ( ( $m['role'] ?? '' ) === 'assistant' ) {
				$prov  = $m['providerID'] ?? $m['model']['providerID'] ?? '';
				$mid   = $m['modelID']    ?? $m['model']['modelID']    ?? '';
				$model = trim( "$prov $mid" );
				break;
			}
		}

		$events[] = [
			'type'       => 'init',
			'model'      => $model ?: 'opencode',
			'session_id' => $sessionId,
			'skills'     => [],
			'_ts'        => 0,
		];

		$title = self::sessionTitle( $sessionId );
		if ( $title !== '' ) {
			$events[] = [
				'type' => 'summary',
				'text' => $title,
				'_ts'  => 0,
			];
		}

		foreach ( $messages as $msg ) {
			$role  = $msg['role']            ?? '';
			$msgTs = $msg['time']['created'] ?? 0;
			$parts = self::messageParts( $msg['id'] ?? '' );

			foreach ( $parts as $i => $part ) {
				$type = $part['type'] ?? '';
				$ts   = $msgTs + $i; // preserve intra-message order

				if ( $type === 'text' ) {
					$text = trim( $part['text'] ?? '' );
					if ( $text === '' ) {
						continue;
					}
					$events[] = [
						'type' => $role === 'user' ? 'user_message' : 'text',
						'text' => $text,
						'_ts'  => $ts,
					];
					continue;
				}

				if ( $type === 'tool' ) {
					$tool  = self::normalizeToolName( $part['tool'] ?? 'unknown' );
					$input = self::normalizeToolInput( $part['tool'] ?? '', $part['state']['input'] ?? [] );

					$call = [
						'type'     => 'tool_call',
						'tool'     => $tool,
						'category' => ClaudeSessions::toolCategory( $tool ),
						'label'    => ClaudeSessions::describeToolCall( $tool, $input ),
						'_ts'      => $ts,
					];
					if ( $tool === 'TodoWrite' && ! empty( $input['todos'] ) ) {
						$call['todos'] = array_map(
							fn( $t ) => [
								'text'   => mb_substr( $t['content'] ?? $t['subject'] ?? '', 0, 80 ),
								'status' => $t['status'] ?? 'pending',
							],
							$input['todos']
						);
					}
					$events[] = $call;

					$output = $part['state']['output'] ?? '';
					if ( is_array( $output ) ) {
						$output = json_encode( $output );
					}
					$output = (string) $output;
					if ( $output !== '' && ( $part['state']['status'] ?? '' ) === 'completed' ) {
						$events[] = [
							'type'    => 'tool_result',
							'preview' => ClaudeSessions::cleanResultText( mb_substr( $output, 0, 500 ) ),
							'length'  => mb_strlen( $output ),
							'_ts'     => $ts + 1,
						];
					}
				}
				// reasoning, step-start, step-finish - skipped for replay.
			}
		}

		usort( $events, fn( $a, $b ) => ( $a['_ts'] ?? 0 ) <=> ( $b['_ts'] ?? 0 ) );
		return array_map( function ( $ev ) {
			unset( $ev['_ts'] );
			return $ev;
		}, $events );
	}

	// ─── SSE stream ─────────────────────────────────────────────

	/**
	 * History-only replay, matching T3CodeSessions::handleStream.
	 * OpenCode doesn't expose a tailable handle Command Center can read.
	 */
	public static function handleStream( string $sessionId, int $runnerPid = 0 ): void {
		if ( ! self::hasSession( $sessionId ) ) {
			http_response_code( 404 );
			echo json_encode( [ 'error' => 'Session not found' ] );
			return;
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'zlib.output_compression', false );
		while ( ob_get_level() ) {
			ob_end_flush();
		}

		$events      = self::getConversation( $sessionId );
		$eventId     = 0;
		$lastEventId = intval( $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0 );

		foreach ( $events as $event ) {
			$eventId++;
			if ( $eventId <= $lastEventId ) {
				continue;
			}
			echo "id: $eventId\n";
			echo "event: {$event['type']}\n";
			echo 'data: ' . json_encode( $event ) . "\n\n";
			flush();
		}

		echo 'id: ' . ( ++$eventId ) . "\n";
		echo "event: done\n";
		echo 'data: ' . json_encode( [ 'reason' => 'history-only' ] ) . "\n\n";
		flush();
	}

	// ─── SQLite backend ─────────────────────────────────────────

	private static ?SQLite3 $dbHandle  = null;
	private static bool     $dbChecked = false;

	private static function dbPath(): string {
		return self::dataDir() . '/opencode.db';
	}

	/**
	 * Read-only handle on opencode.db, or null when the db doesn't exist
	 * (pre-SQLite installs) or isn't usable yet.
	 */
	private static function db(): ?SQLite3 {
		if ( self::$dbChecked ) {
			return self::$dbHandle;
		}
		self::$dbChecked = true;

		$path = self::dbPath();
		if ( ! is_readable( $path ) ) {
			return null;
		}

		try {
			$db = new SQLite3( $path, SQLITE3_OPEN_READONLY );
			$db->busyTimeout( 2000 );
			$ok = $db->querySingle( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'session'" );
			self::$dbHandle = $ok ? $db : null;
		} catch ( \Throwable $e ) {
			self::$dbHandle = null;
		}

		return self::$dbHandle;
	}

	private static function dbRows( string $sql, array $params = [] ): array {
		$db = self::db();
		if ( ! $db ) {
			return [];
		}
		$stmt = @$db->prepare( $sql );
		if ( ! $stmt ) {
			return [];
		}
		$i = 1;
		foreach ( $params as $p ) {
			$stmt->bindValue( $i++, $p );
		}
		$res = @$stmt->execute();
		if ( ! $res ) {
			return [];
		}
		$rows = [];
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Messages for a session, oldest first, from the db when the session
	 * lives there, else the legacy tree. Each entry is the decoded message
	 * JSON with `id` guaranteed.
	 */
	private static function sessionMessages( string $sessionId ): array {
		if ( $sessionId === '' ) {
			return [];
		}

		$rows = self::dbRows(
			'SELECT id, data FROM message WHERE session_id = ? ORDER BY time_created',
			[ $sessionId ]
		);
		if ( $rows ) {
			$out = [];
			foreach ( $rows as $r ) {
				$msg = json_decode( $r['data'], true );
				if ( is_array( $msg ) ) {
					$msg['id'] = $msg['id'] ?? $r['id'];
					$out[]     = $msg;
				}
			}
			return $out;
		}

		$msgDir = self::storageDir() . '/message/' . $sessionId;
		$out    = [];
		foreach ( glob( $msgDir . '/msg_*.json' ) ?: [] as $path ) {
			$msg = self::readJson( $path );
			if ( $msg ) {
				$msg['id'] = $msg['id'] ?? basename( $path, '.json' );
				$out[]     = $msg;
			}
		}
		usort( $out, fn( $a, $b ) => ( $a['time']['created'] ?? 0 ) <=> ( $b['time']['created'] ?? 0 ) );
		return $out;
	}

	/**
	 * Parts for a message in intra-message order (prt_ ULIDs sort by time).
	 */
	private static function messageParts( string $messageId ): array {
		if ( $messageId === '' ) {
			return [];
		}

		$rows = self::dbRows(
			'SELECT id, data FROM part WHERE message_id = ? ORDER BY id',
			[ $messageId ]
		);
		if ( $rows ) {
			$out = [];
			foreach ( $rows as $r ) {
				$p = json_decode( $r['data'], true );
				if ( is_array( $p ) ) {
					$out[] = $p;
				}
			}
			return $out;
		}

		$files = glob( self::storageDir() . '/part/' . $messageId . '/prt_*.json' ) ?: [];
		sort( $files );
		$out = [];
		foreach ( $files as $pf ) {
			$p = self::readJson( $pf );
			if ( $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private static function sessionTitle( string $sessionId ): string {
		$rows = self::dbRows( 'SELECT title FROM session WHERE id = ?', [ $sessionId ] );
		if ( $rows ) {
			return (string) ( $rows[0]['title'] ?? '' );
		}
		$file = self::findLegacySessionFile( $sessionId );
		if ( $file ) {
			$ses = self::readJson( $file );
			return (string) ( $ses['title'] ?? '' );
		}
		return '';
	}

	private static function dbProjectPath( string $projectID, ?string $worktree ): string {
		if ( $projectID === 'global' || $worktree === '/' ) {
			return '/';
		}
		return $worktree ?? '';
	}

	private static function dbProjectName( string $projectID, ?string $worktree, ?string $label ): string {
		if ( $projectID === 'global' || $worktree === '/' ) {
			return '(global)';
		}
		if ( $label ) {
			return $label;
		}
		return $worktree ? basename( $worktree ) : substr( $projectID, 0, 8 );
	}

	// ─── Internals ──────────────────────────────────────────────

	/**
	 * Resolve OpenCode's data dir. Env override → $XDG_DATA_HOME/opencode
	 * → ~/.local/share/opencode.
	 */
	public static function dataDir(): string {
		$override = getenv( 'OPENCODE_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$xdg = getenv( 'XDG_DATA_HOME' );
		if ( ! $xdg ) {
			$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
			$xdg  = $home . '/.local/share';
		}
		return rtrim( $xdg, '/' ) . '/opencode';
	}

	private static function storageDir(): string {
		return self::dataDir() . '/storage';
	}

	/**
	 * Load and cache every legacy project file once per request.
	 */
	private static function projects(): array {
		if ( self::$projectsCache !== null ) {
			return self::$projectsCache;
		}
		$dir = self::storageDir() . '/project';
		$out = [];
		foreach ( glob( $dir . '/*.json' ) ?: [] as $file ) {
			$p = self::readJson( $file );
			if ( $p && ! empty( $p['id'] ) ) {
				$out[ $p['id'] ] = $p;
			}
		}
		self::$projectsCache = $out;
		return $out;
	}

	private static function projectPath( string $projectID, ?array $info ): string {
		if ( $projectID === 'global' ) {
			return '/';
		}
		return $info['worktree'] ?? '';
	}

	private static function projectName( string $projectID, ?array $info ): string {
		if ( $projectID === 'global' ) {
			return '(global)';
		}
		$worktree = $info['worktree'] ?? '';
		return $worktree ? basename( $worktree ) : substr( $projectID, 0, 8 );
	}

	private static function estimateSessionSize( string $sessionId ): int {
		$msgDir = self::storageDir() . '/message/' . $sessionId;
		if ( ! is_dir( $msgDir ) ) {
			return 0;
		}
		$entries = @scandir( $msgDir );
		if ( ! is_array( $entries ) ) {
			return 0;
		}
		return max( 0, count( $entries ) - 2 );
	}

	private static function readJson( string $path ): ?array {
		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Map OpenCode lowercase tool names to Claude's PascalCase so
	 * ClaudeSessions::toolCategory / describeToolCall light up correctly.
	 */
	private static function normalizeToolName( string $name ): string {
		static $map = [
			'read'       => 'Read',
			'write'      => 'Write',
			'edit'       => 'Edit',
			'bash'       => 'Bash',
			'glob'       => 'Glob',
			'grep'       => 'Grep',
			'webfetch'   => 'WebFetch',
			'websearch'  => 'WebSearch',
			'task'       => 'Task',
			'todowrite'  => 'TodoWrite',
			'patch'      => 'Edit',
		];
		return $map[ strtolower( $name ) ] ?? $name;
	}

	/**
	 * OpenCode uses camelCase input keys (filePath, ...); the Claude
	 * describers expect snake_case (file_path, ...). Translate the keys
	 * we actually read.
	 */
	private static function normalizeToolInput( string $rawTool, array $input ): array {
		$aliases = [
			'filePath'     => 'file_path',
			'filepath'     => 'file_path',
			'notebookPath' => 'notebook_path',
			'taskId'       => 'task_id',
		];
		foreach ( $aliases as $from => $to ) {
			if ( array_key_exists( $from, $input ) && ! array_key_exists( $to, $input ) ) {
				$input[ $to ] = $input[ $from ];
			}
		}
		return $input;
	}
}
