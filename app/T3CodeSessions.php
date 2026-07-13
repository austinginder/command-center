<?php

/**
 * T3 Code session provider - reads from ~/.t3/userdata/state.sqlite.
 *
 * T3 Code (pingdotgg/t3code) is a local web GUI for coding agents (Claude, Codex).
 * Instead of per-session JSONL files, it event-sources everything into a single
 * SQLite database and materializes projections we can SELECT from directly.
 *
 * Relevant tables:
 *   projection_projects         - workspaces T3 has seen
 *   projection_threads          - conversations (our "session" equivalent)
 *   projection_thread_messages  - user/assistant text per thread
 *   projection_thread_activities - tool calls, task progress, errors, etc.
 *   provider_session_runtime    - provider metadata (claudeAgent, codex, ...)
 *
 * We open the DB read-only so concurrent T3 writes aren't disturbed.
 */
class T3CodeSessions {

	private static ?\SQLite3 $db = null;

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 't3code';
	}

	public static function sourceLabel(): string {
		return 'T3 Code';
	}

	/**
	 * Does this provider know about the given thread id?
	 */
	public static function hasSession( string $sessionId ): bool {
		$db = self::db();
		if ( ! $db ) {
			return false;
		}
		$stmt = $db->prepare( 'SELECT 1 FROM projection_threads WHERE thread_id = :id AND deleted_at IS NULL LIMIT 1' );
		if ( ! $stmt ) {
			return false;
		}
		$stmt->bindValue( ':id', $sessionId, SQLITE3_TEXT );
		$row = $stmt->execute()->fetchArray( SQLITE3_ASSOC );
		return ! empty( $row );
	}

	/**
	 * Return the SQLite path as the "session file" - gives SearchIndex/stream
	 * a stable handle to fingerprint against.
	 */
	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		$path = self::dbPath();
		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Fingerprint = ( thread.updated_at epoch, message+activity row count ).
	 *
	 * Matches the mtime+size semantics SearchIndex expects. Using the thread's
	 * own updated_at avoids re-indexing every T3 session whenever any thread
	 * updates the shared DB file.
	 */
	public static function fingerprint( array $session ): ?array {
		$db = self::db();
		if ( ! $db ) {
			return null;
		}
		$stmt = $db->prepare( '
			SELECT
				(SELECT updated_at FROM projection_threads WHERE thread_id = :id) AS updated_at,
				(SELECT COUNT(*) FROM projection_thread_messages   WHERE thread_id = :id) AS msg_count,
				(SELECT COUNT(*) FROM projection_thread_activities WHERE thread_id = :id) AS act_count
		' );
		if ( ! $stmt ) {
			return null;
		}
		$stmt->bindValue( ':id', $session['id'] ?? '', SQLITE3_TEXT );
		$row = $stmt->execute()->fetchArray( SQLITE3_ASSOC );
		if ( ! $row || empty( $row['updated_at'] ) ) {
			return null;
		}
		return [
			'mtime' => self::isoToEpoch( $row['updated_at'] ),
			'size'  => (int) ( $row['msg_count'] + $row['act_count'] ),
		];
	}

	/**
	 * Extract searchable text for FTS. Concatenates thread title,
	 * all message bodies, and every activity summary.
	 */
	/**
	 * Estimated token usage - T3 Code's SQLite stores only role + text per
	 * message, no usage. Same estimation model as Command Code: ~4 chars per
	 * token; output from assistant text, input from all distinct text,
	 * cache_read from cumulative context replay per assistant turn.
	 */
	public static function extractUsage( array $session ): ?array {
		$db = self::db();
		if ( ! $db ) {
			return null;
		}

		$stmt = $db->prepare( 'SELECT role, text FROM projection_thread_messages WHERE thread_id = :id ORDER BY created_at' );
		$stmt->bindValue( ':id', $session['id'] ?? '', SQLITE3_TEXT );
		$res = $stmt->execute();

		$total    = 0;
		$outChars = 0;
		$ctxChars = 0;

		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$chars = strlen( $row['text'] ?? '' );
			if ( ( $row['role'] ?? '' ) === 'assistant' ) {
				$outChars += $chars;
				$ctxChars += $total;
			}
			$total += $chars;
		}

		if ( $total === 0 ) {
			return null;
		}

		return [
			'input'          => intdiv( $total, 4 ),
			'output'         => intdiv( $outChars, 4 ),
			'cache_read'     => intdiv( $ctxChars, 4 ),
			'cache_creation' => 0,
		];
	}

	public static function extractSessionText( array $session ): string {
		$db = self::db();
		if ( ! $db ) {
			return '';
		}

		$parts    = [];
		$maxChars = 10000;
		$threadId = $session['id'] ?? '';

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		$stmt = $db->prepare( 'SELECT text FROM projection_thread_messages WHERE thread_id = :id ORDER BY created_at' );
		$stmt->bindValue( ':id', $threadId, SQLITE3_TEXT );
		$res = $stmt->execute();
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$text = $row['text'] ?? '';
			if ( $text !== '' ) {
				$parts[] = mb_substr( $text, 0, $maxChars );
			}
		}

		$stmt = $db->prepare( 'SELECT summary FROM projection_thread_activities WHERE thread_id = :id ORDER BY sequence, created_at' );
		$stmt->bindValue( ':id', $threadId, SQLITE3_TEXT );
		$res = $stmt->execute();
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$summary = $row['summary'] ?? '';
			if ( $summary !== '' ) {
				$parts[] = mb_substr( $summary, 0, 500 );
			}
		}

		return implode( "\n", $parts );
	}

	// ─── Listing ────────────────────────────────────────────────

	/**
	 * List non-deleted threads, joined against their project for display paths.
	 */
	public static function listSessions( ?string $project = null ): array {
		$db = self::db();
		if ( ! $db ) {
			return [];
		}

		$sql = '
			SELECT
				t.thread_id,
				t.project_id,
				t.title,
				t.created_at,
				t.updated_at,
				t.archived_at,
				p.title           AS project_title,
				p.workspace_root  AS workspace_root,
				r.provider_name   AS provider_name,
				r.status          AS runtime_status
			FROM projection_threads t
			LEFT JOIN projection_projects        p ON p.project_id = t.project_id
			LEFT JOIN provider_session_runtime   r ON r.thread_id  = t.thread_id
			WHERE t.deleted_at IS NULL
		';
		if ( $project ) {
			$sql .= ' AND p.workspace_root = :project';
		}
		$sql .= ' ORDER BY t.updated_at DESC';

		$stmt = $db->prepare( $sql );
		if ( ! $stmt ) {
			return [];
		}
		if ( $project ) {
			$stmt->bindValue( ':project', $project, SQLITE3_TEXT );
		}

		$res = $stmt->execute();
		if ( ! $res ) {
			return [];
		}

		// Build id→(msg_count, total_chars) map in one query for efficient size estimation.
		$sizes = self::threadSizes();

		$out = [];
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$id            = $row['thread_id'];
			$workspaceRoot = $row['workspace_root'] ?? '';
			$projectTitle  = $row['project_title']  ?? '';
			$updated       = self::isoToEpoch( $row['updated_at'] );
			$size          = $sizes[ $id ]['chars'] ?? 0;

			$out[] = [
				'id'             => $id,
				'display'        => $row['title'] ?? '',
				'timestamp'      => $updated * 1000, // ms, matches ClaudeSessions convention
				'timestamp_s'    => $updated,
				'project'        => $workspaceRoot,
				'projectName'    => $projectTitle !== '' ? $projectTitle : ( $workspaceRoot ? Helpers::projectDisplayName( $workspaceRoot ) : '' ),
				'size'           => $size,
				'archived'       => ! empty( $row['archived_at'] ),
				'provider'       => $row['provider_name']  ?? '',
				'runtimeStatus'  => $row['runtime_status'] ?? '',
			];
		}

		return $out;
	}

	/**
	 * List workspaces that have threads.
	 */
	public static function listProjects(): array {
		$db = self::db();
		if ( ! $db ) {
			return [];
		}

		$sql = '
			SELECT
				p.workspace_root,
				p.title,
				COUNT(t.thread_id)                           AS sessions,
				MAX(COALESCE(t.updated_at, t.created_at))    AS latest_iso
			FROM projection_projects p
			LEFT JOIN projection_threads t
				ON t.project_id = p.project_id
				AND t.deleted_at IS NULL
			WHERE p.deleted_at IS NULL
			GROUP BY p.project_id
			HAVING sessions > 0
			ORDER BY latest_iso DESC
		';

		$res = $db->query( $sql );
		if ( ! $res ) {
			return [];
		}

		$out = [];
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$latest = $row['latest_iso'] ? self::isoToEpoch( $row['latest_iso'] ) * 1000 : 0;
			$out[]  = [
				'path'     => $row['workspace_root'] ?? '',
				'name'     => $row['title']          ?? Helpers::projectDisplayName( $row['workspace_root'] ?? '' ),
				'sessions' => (int) $row['sessions'],
				'latest'   => $latest,
			];
		}
		return $out;
	}

	// ─── Conversation ───────────────────────────────────────────

	/**
	 * Return CC-shaped event list for a thread.
	 *
	 * Strategy:
	 *   - Messages (role=user|assistant) → user_message / text events
	 *   - Activity kind tool.completed   → emit tool_call + tool_result (carries full input AND output)
	 *   - Other activities: skipped to keep the stream quiet, except runtime.error
	 *
	 * Events are emitted in chronological order by created_at.
	 */
	public static function getConversation( string $threadId ): array {
		$db = self::db();
		if ( ! $db ) {
			return [];
		}

		$events = [];

		// Open with an init event so the UI header has a clue about the provider.
		$providerStmt = $db->prepare( 'SELECT provider_name, adapter_key FROM provider_session_runtime WHERE thread_id = :id' );
		$providerStmt->bindValue( ':id', $threadId, SQLITE3_TEXT );
		$providerRow = $providerStmt->execute()->fetchArray( SQLITE3_ASSOC );
		if ( $providerRow ) {
			$events[] = [
				'type'       => 'init',
				'model'      => $providerRow['provider_name'] ?? 't3code',
				'session_id' => $threadId,
				'skills'     => [],
				'_ts'        => 0, // Always first
			];
		}

		// Thread title → summary for quick scanning.
		$titleStmt = $db->prepare( 'SELECT title, created_at FROM projection_threads WHERE thread_id = :id' );
		$titleStmt->bindValue( ':id', $threadId, SQLITE3_TEXT );
		$titleRow = $titleStmt->execute()->fetchArray( SQLITE3_ASSOC );
		if ( $titleRow && ! empty( $titleRow['title'] ) ) {
			$events[] = [
				'type' => 'summary',
				'text' => $titleRow['title'],
				'_ts'  => 0,
			];
		}

		// Messages.
		$msgStmt = $db->prepare( '
			SELECT message_id, role, text, created_at
			FROM projection_thread_messages
			WHERE thread_id = :id
			ORDER BY created_at
		' );
		$msgStmt->bindValue( ':id', $threadId, SQLITE3_TEXT );
		$msgRes = $msgStmt->execute();

		while ( $row = $msgRes->fetchArray( SQLITE3_ASSOC ) ) {
			$text = trim( $row['text'] ?? '' );
			if ( $text === '' ) {
				continue;
			}
			$ts = self::isoToMs( $row['created_at'] );
			if ( $row['role'] === 'user' ) {
				$events[] = [ 'type' => 'user_message', 'text' => $text, '_ts' => $ts ];
			} elseif ( $row['role'] === 'assistant' ) {
				$events[] = [ 'type' => 'text', 'text' => $text, '_ts' => $ts ];
			}
		}

		// Activities - we care about tool_call / tool_result pairs and errors.
		$actStmt = $db->prepare( '
			SELECT activity_id, kind, tone, summary, payload_json, created_at, sequence
			FROM projection_thread_activities
			WHERE thread_id = :id
			ORDER BY sequence, created_at
		' );
		$actStmt->bindValue( ':id', $threadId, SQLITE3_TEXT );
		$actRes = $actStmt->execute();

		while ( $row = $actRes->fetchArray( SQLITE3_ASSOC ) ) {
			$kind    = $row['kind'];
			$payload = json_decode( $row['payload_json'] ?? 'null', true );
			$ts      = self::isoToMs( $row['created_at'] );

			// Only tool.completed carries the full invocation *and* result.
			// Use it to emit the pair atomically (in the UI the call appears just before the result).
			if ( $kind === 'tool.completed' ) {
				$data = $payload['data'] ?? [];
				$tool = $data['toolName'] ?? ( $payload['detail'] ?? 'tool' );
				$input = is_array( $data['input'] ?? null ) ? $data['input'] : [];

				$events[] = [
					'type'     => 'tool_call',
					'tool'     => $tool,
					'category' => ClaudeSessions::toolCategory( $tool ),
					'label'    => ClaudeSessions::describeToolCall( $tool, $input ),
					'_ts'      => $ts,
				] + ( $tool === 'TodoWrite' && ! empty( $input['todos'] ) ? [
					'todos' => array_map(
						fn( $t ) => [
							'text'   => mb_substr( $t['content'] ?? $t['subject'] ?? '', 0, 80 ),
							'status' => $t['status'] ?? 'pending',
						],
						$input['todos']
					),
				] : [] );

				$resultText = self::extractResultText( $data['result'] ?? null );
				if ( $resultText !== '' ) {
					$events[] = [
						'type'    => 'tool_result',
						'preview' => ClaudeSessions::cleanResultText( mb_substr( $resultText, 0, 500 ) ),
						'length'  => mb_strlen( $resultText ),
						'_ts'     => $ts + 1, // Nudge just after the call.
					];
				}
				continue;
			}

			if ( $kind === 'runtime.error' ) {
				$msg = $row['summary'] ?? 'Runtime error';
				if ( is_array( $payload ) && ! empty( $payload['detail'] ) ) {
					$msg .= ': ' . $payload['detail'];
				}
				$events[] = [
					'type' => 'text',
					'text' => '⚠️ ' . $msg,
					'_ts'  => $ts,
				];
				continue;
			}

			// Everything else (tool.started/updated, context-window.updated, task.*) is noise for replay.
		}

		// Stable chronological sort; ties fall back to insertion order.
		usort( $events, function ( $a, $b ) {
			$da = ( $a['_ts'] ?? 0 ) <=> ( $b['_ts'] ?? 0 );
			return $da !== 0 ? $da : 0;
		} );

		// Strip the internal _ts key before returning.
		return array_map( function ( $ev ) {
			unset( $ev['_ts'] );
			return $ev;
		}, $events );
	}

	// ─── SSE stream ─────────────────────────────────────────────

	/**
	 * T3 Code threads don't have a tailable file like Claude's JSONL.
	 * For v1 we replay the full conversation and emit `done`. T3 Code has its
	 * own WebSocket UI for live streaming - Command Center just shows history.
	 */
	public static function handleStream( string $sessionId, int $runnerPid = 0 ): void {
		if ( ! self::hasSession( $sessionId ) ) {
			http_response_code( 404 );
			echo json_encode( [ 'error' => 'Thread not found' ] );
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
	 * Resolve the T3 Code home directory (env override → ~/.t3).
	 */
	public static function dbPath(): string {
		$home = getenv( 'T3CODE_HOME' );
		if ( ! $home ) {
			$baseHome = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
			$home     = $baseHome . '/.t3';
		}
		return rtrim( $home, '/' ) . '/userdata/state.sqlite';
	}

	/**
	 * Open the DB read-only. Returns null if it doesn't exist yet.
	 */
	private static function db(): ?\SQLite3 {
		if ( self::$db !== null ) {
			return self::$db;
		}
		$path = self::dbPath();
		if ( ! file_exists( $path ) ) {
			return null;
		}
		try {
			$db = new \SQLite3( $path, SQLITE3_OPEN_READONLY );
			$db->busyTimeout( 3000 );
			self::$db = $db;
			return $db;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Return [thread_id => ['msgs'=>int, 'chars'=>int]] in one pass.
	 * Used to estimate session "size" cheaply at list time.
	 */
	private static function threadSizes(): array {
		$db = self::db();
		if ( ! $db ) {
			return [];
		}
		$res = $db->query( '
			SELECT thread_id,
			       COUNT(*)                     AS msgs,
			       COALESCE(SUM(LENGTH(text)),0) AS chars
			FROM projection_thread_messages
			GROUP BY thread_id
		' );
		$out = [];
		if ( $res ) {
			while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
				$out[ $row['thread_id'] ] = [
					'msgs'  => (int) $row['msgs'],
					'chars' => (int) $row['chars'],
				];
			}
		}
		return $out;
	}

	/**
	 * Parse T3's ISO-8601 timestamps into epoch seconds.
	 */
	private static function isoToEpoch( ?string $iso ): int {
		if ( ! $iso ) {
			return 0;
		}
		$ts = strtotime( $iso );
		return $ts === false ? 0 : $ts;
	}

	private static function isoToMs( ?string $iso ): int {
		return self::isoToEpoch( $iso ) * 1000;
	}

	/**
	 * Pluck the first text block out of a tool result payload.
	 */
	private static function extractResultText( $result ): string {
		if ( ! is_array( $result ) ) {
			return '';
		}
		$content = $result['content'] ?? null;
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( is_array( $content ) ) {
			foreach ( $content as $block ) {
				if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
					return $block['text'];
				}
			}
		}
		return '';
	}
}
