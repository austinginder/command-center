<?php

/**
 * OpenCode session provider — reads from $XDG_DATA_HOME/opencode/storage.
 *
 * OpenCode (sst/opencode) is a terminal coding agent that stores every
 * entity as its own JSON file. A "conversation" spans three directories:
 *
 *   storage/project/{projectID}.json          — workspace records
 *   storage/session/{projectID}/ses_*.json    — session metadata
 *   storage/message/{sessionID}/msg_*.json    — one file per turn
 *   storage/part/{messageID}/prt_*.json       — message content chunks
 *                                               (type=text|reasoning|tool|step-start|step-finish)
 *
 * ProjectID is sha1(worktree-path) for real projects, or the literal
 * "global" for ad-hoc sessions without a worktree. We surface "global"
 * as a pseudo-project named "(global)" so those sessions remain searchable.
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
		return self::findSessionFile( $sessionId ) !== null;
	}

	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		if ( ! preg_match( '/^ses_[A-Za-z0-9]+$/', $id ) ) {
			return null;
		}
		$matches = glob( self::storageDir() . '/session/*/' . $id . '.json' );
		return $matches ? $matches[0] : null;
	}

	/**
	 * Fingerprint = ( max(session-file mtime, message-dir mtime), message-file count ).
	 *
	 * Session metadata rarely changes after creation; new turns write new
	 * message/part files. Watching the message dir catches live growth cheaply
	 * — we never have to stat individual files.
	 */
	public static function fingerprint( array $session ): ?array {
		$id   = $session['id'] ?? '';
		$file = self::findSessionFile( $id );
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
	 * Reasoning parts and tool I/O are intentionally skipped for FTS — they
	 * inflate the index and hurt result relevance on user-intent queries.
	 */
	public static function extractSessionText( array $session ): string {
		$id       = $session['id'] ?? '';
		$msgDir   = self::storageDir() . '/message/' . $id;
		$partRoot = self::storageDir() . '/part';

		if ( ! is_dir( $msgDir ) ) {
			return $session['display'] ?? '';
		}

		$parts = [];

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		$messages = glob( $msgDir . '/msg_*.json' ) ?: [];
		$ordered  = [];
		foreach ( $messages as $path ) {
			$msg = self::readJson( $path );
			if ( ! $msg ) {
				continue;
			}
			$ordered[] = [
				'id'      => $msg['id'] ?? basename( $path, '.json' ),
				'created' => $msg['time']['created'] ?? 0,
			];
		}
		usort( $ordered, fn( $a, $b ) => $a['created'] <=> $b['created'] );

		foreach ( $ordered as $m ) {
			$partDir = $partRoot . '/' . $m['id'];
			$files   = glob( $partDir . '/prt_*.json' ) ?: [];
			sort( $files ); // ULID prefix gives time order
			foreach ( $files as $pf ) {
				$part = self::readJson( $pf );
				if ( ! $part || ( $part['type'] ?? '' ) !== 'text' ) {
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
		$id     = $session['id'] ?? '';
		$msgDir = self::storageDir() . '/message/' . $id;
		if ( ! is_dir( $msgDir ) ) {
			return null;
		}

		$totals = [ 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0 ];
		$found  = false;

		foreach ( glob( $msgDir . '/msg_*.json' ) ?: [] as $path ) {
			$msg = self::readJson( $path );
			if ( ! $msg || ( $msg['role'] ?? '' ) !== 'assistant' ) {
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
		$storage = self::storageDir();
		if ( ! is_dir( $storage . '/session' ) ) {
			return [];
		}

		$projects = self::projects();
		$files    = glob( $storage . '/session/*/ses_*.json' ) ?: [];
		$out      = [];

		foreach ( $files as $file ) {
			$ses = self::readJson( $file );
			if ( ! $ses || empty( $ses['id'] ) ) {
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

	public static function listProjects(): array {
		$storage = self::storageDir();
		if ( ! is_dir( $storage . '/session' ) ) {
			return [];
		}

		$projects    = self::projects();
		$sessionDirs = glob( $storage . '/session/*', GLOB_ONLYDIR ) ?: [];
		$out         = [];

		foreach ( $sessionDirs as $dir ) {
			$projectID = basename( $dir );
			$files     = glob( $dir . '/ses_*.json' ) ?: [];
			$count     = count( $files );
			if ( $count === 0 ) {
				continue;
			}

			$info     = $projects[ $projectID ] ?? null;
			$worktree = self::projectPath( $projectID, $info );
			$name     = self::projectName( $projectID, $info );

			$latest = 0;
			foreach ( $files as $f ) {
				$ses = self::readJson( $f );
				if ( ! $ses ) {
					continue;
				}
				$ts = $ses['time']['updated'] ?? $ses['time']['created'] ?? 0;
				if ( $ts > $latest ) {
					$latest = $ts;
				}
			}

			$out[] = [
				'path'     => $worktree,
				'name'     => $name,
				'sessions' => $count,
				'latest'   => (int) $latest,
			];
		}

		usort( $out, fn( $a, $b ) => $b['latest'] <=> $a['latest'] );
		return $out;
	}

	// ─── Conversation ───────────────────────────────────────────

	public static function getConversation( string $sessionId ): array {
		$file = self::findSessionFile( $sessionId );
		if ( ! $file ) {
			return [];
		}

		$session = self::readJson( $file );
		$msgDir  = self::storageDir() . '/message/' . $sessionId;
		$events  = [];

		// Gather messages with providerID/modelID off the first assistant message so we can emit init.
		$messages = [];
		foreach ( glob( $msgDir . '/msg_*.json' ) ?: [] as $path ) {
			$msg = self::readJson( $path );
			if ( ! $msg ) {
				continue;
			}
			$messages[] = $msg;
		}
		usort(
			$messages,
			fn( $a, $b ) => ( $a['time']['created'] ?? 0 ) <=> ( $b['time']['created'] ?? 0 )
		);

		$model = '';
		foreach ( $messages as $m ) {
			if ( ( $m['role'] ?? '' ) === 'assistant' ) {
				$prov  = $m['model']['providerID'] ?? '';
				$mid   = $m['model']['modelID']    ?? '';
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

		if ( ! empty( $session['title'] ) ) {
			$events[] = [
				'type' => 'summary',
				'text' => $session['title'],
				'_ts'  => 0,
			];
		}

		foreach ( $messages as $msg ) {
			$role    = $msg['role']            ?? '';
			$msgId   = $msg['id']              ?? '';
			$msgTs   = $msg['time']['created'] ?? 0;
			$partDir = self::storageDir() . '/part/' . $msgId;
			$parts   = [];
			foreach ( glob( $partDir . '/prt_*.json' ) ?: [] as $pf ) {
				$p = self::readJson( $pf );
				if ( $p ) {
					$parts[] = $p;
				}
			}
			sort( $parts ); // no-op for objects; use id-based sort below
			usort( $parts, fn( $a, $b ) => strcmp( $a['id'] ?? '', $b['id'] ?? '' ) );

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
				// reasoning, step-start, step-finish — skipped for replay.
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
	 * Load and cache every project file once per request.
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
