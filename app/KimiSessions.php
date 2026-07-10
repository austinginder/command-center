<?php

/**
 * Kimi (Moonshot) session provider - reads from ~/.kimi/sessions.
 *
 * Kimi CLI lays sessions out as:
 *
 *   ~/.kimi/kimi.json                                - top-level config; lists work_dirs[].path
 *   ~/.kimi/sessions/{md5(work_dir)}/{uuid}/state.json    - metadata (custom_title, archived, …)
 *   ~/.kimi/sessions/{md5(work_dir)}/{uuid}/wire.jsonl    - wire-protocol event log w/ unix-float timestamps
 *   ~/.kimi/sessions/{md5(work_dir)}/{uuid}/context.jsonl - full LLM context (system prompt + role/content)
 *
 * The directory hash is md5() of the worktree path, so we recover project
 * paths by md5'ing every kimi.json work_dirs entry and building a reverse
 * map. Hashes that don't appear in kimi.json fall through to a "(unknown)"
 * project - those sessions remain visible/searchable, just unattached.
 *
 * wire.jsonl is treated as the canonical session file: it has timestamps,
 * tool calls, and assistant text in stream order.
 */
class KimiSessions {

	private static ?array $hashMapCache = null;

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'kimi';
	}

	public static function sourceLabel(): string {
		return 'Kimi';
	}

	public static function hasSession( string $sessionId ): bool {
		return self::findSessionFile( $sessionId ) !== null;
	}

	/**
	 * wire.jsonl is the canonical, tailable handle for a session.
	 */
	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		if ( ! self::isValidSessionId( $id ) ) {
			return null;
		}
		$matches = glob( self::sessionsDir() . '/*/' . $id . '/wire.jsonl' );
		return $matches ? $matches[0] : null;
	}

	/**
	 * Fingerprint = (wire.jsonl mtime, wire.jsonl size). New turns append
	 * to wire.jsonl, so this catches every change without touching state.json.
	 */
	public static function fingerprint( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '' );
		if ( ! $file ) {
			return null;
		}
		$mtime = @filemtime( $file ) ?: 0;
		$size  = @filesize( $file ) ?: 0;
		return [ 'mtime' => $mtime, 'size' => $size ];
	}

	/**
	 * Concat user input + assistant text parts for FTS. Reasoning ("think")
	 * blocks and tool I/O are intentionally skipped - they bloat the index
	 * and hurt relevance on user-intent queries.
	 */
	public static function extractSessionText( array $session ): string {
		$file = self::findSessionFile( $session['id'] ?? '' );
		if ( ! $file ) {
			return $session['display'] ?? '';
		}

		$parts    = [];
		$maxChars = 10000;

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return implode( "\n", $parts );
		}
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$msg     = $obj['message'] ?? $obj;
			$type    = $msg['type']    ?? '';
			$payload = $msg['payload'] ?? [];

			if ( $type === 'TurnBegin' ) {
				foreach ( self::userInputTexts( $payload['user_input'] ?? null ) as $text ) {
					$parts[] = mb_substr( $text, 0, $maxChars );
				}
			} elseif ( $type === 'ContentPart' && ( $payload['type'] ?? '' ) === 'text' ) {
				$text = trim( $payload['text'] ?? '' );
				if ( $text !== '' ) {
					$parts[] = mb_substr( $text, 0, $maxChars );
				}
			}
		}
		fclose( $fh );

		return implode( "\n", $parts );
	}

	/**
	 * Sum token usage from wire.jsonl. Payloads carry a `token_usage` object
	 * ({input_other, output, input_cache_read, input_cache_creation}) that can
	 * repeat per message as streaming snapshots, so dedupe by message_id with
	 * last-write-wins before summing.
	 */
	public static function extractUsage( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '' );
		if ( ! $file ) {
			return null;
		}

		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return null;
		}

		$perMsg = [];
		$anon   = [ 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0 ];
		$found  = false;

		while ( ( $line = fgets( $fh ) ) !== false ) {
			if ( strpos( $line, 'token_usage' ) === false ) {
				continue;
			}
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$payload = ( $obj['message'] ?? $obj )['payload'] ?? [];
			$tu      = $payload['token_usage'] ?? null;
			if ( ! is_array( $tu ) ) {
				continue;
			}

			$usage = [
				'input'          => (int) ( $tu['input_other'] ?? 0 ),
				'output'         => (int) ( $tu['output'] ?? 0 ),
				'cache_read'     => (int) ( $tu['input_cache_read'] ?? 0 ),
				'cache_creation' => (int) ( $tu['input_cache_creation'] ?? 0 ),
			];

			$mid = $payload['message_id'] ?? '';
			if ( $mid !== '' ) {
				$perMsg[ $mid ] = $usage;
			} else {
				foreach ( $usage as $k => $v ) {
					$anon[ $k ] += $v;
				}
			}
			$found = true;
		}
		fclose( $fh );

		if ( ! $found ) {
			return null;
		}

		$totals = $anon;
		foreach ( $perMsg as $usage ) {
			foreach ( $usage as $k => $v ) {
				$totals[ $k ] += $v;
			}
		}

		return $totals;
	}

	// ─── Listing ────────────────────────────────────────────────

	public static function listSessions( ?string $project = null ): array {
		$root = self::sessionsDir();
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$hashMap = self::projectHashMap();
		$out     = [];

		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] as $hashDir ) {
			$hash        = basename( $hashDir );
			$projectPath = $hashMap[ $hash ] ?? '';
			$projectName = $projectPath ? basename( $projectPath ) : '';

			if ( $project !== null && $project !== '' && $project !== $projectPath ) {
				continue;
			}

			foreach ( glob( $hashDir . '/*', GLOB_ONLYDIR ) ?: [] as $sessionDir ) {
				$id = basename( $sessionDir );
				if ( ! self::isValidSessionId( $id ) ) {
					continue;
				}

				$wireFile  = $sessionDir . '/wire.jsonl';
				$stateFile = $sessionDir . '/state.json';
				if ( ! file_exists( $wireFile ) ) {
					continue;
				}

				$state    = self::readJson( $stateFile ) ?: [];
				$mtime    = @filemtime( $wireFile ) ?: 0;
				$size     = @filesize( $wireFile )  ?: 0;
				$created  = self::firstWireTimestamp( $wireFile ) ?: $mtime;

				$out[] = [
					'id'          => $id,
					'display'     => $state['custom_title'] ?? '',
					'timestamp'   => $mtime * 1000,
					'timestamp_s' => $mtime,
					'project'     => $projectPath,
					'projectName' => $projectName,
					'size'        => $size,
					'archived'    => ! empty( $state['archived'] ),
					'created'     => $created * 1000,
				];
			}
		}

		usort( $out, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		return $out;
	}

	public static function listProjects(): array {
		$root = self::sessionsDir();
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$hashMap = self::projectHashMap();
		$out     = [];

		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] as $hashDir ) {
			$hash    = basename( $hashDir );
			$dirs    = glob( $hashDir . '/*', GLOB_ONLYDIR ) ?: [];
			$count   = 0;
			$latest  = 0;
			foreach ( $dirs as $sessionDir ) {
				if ( ! file_exists( $sessionDir . '/wire.jsonl' ) ) {
					continue;
				}
				$count++;
				$mtime = @filemtime( $sessionDir . '/wire.jsonl' ) ?: 0;
				if ( $mtime > $latest ) {
					$latest = $mtime;
				}
			}
			if ( $count === 0 ) {
				continue;
			}

			$path = $hashMap[ $hash ] ?? '';
			$out[] = [
				'path'     => $path,
				'name'     => $path ? basename( $path ) : '(unknown ' . substr( $hash, 0, 8 ) . ')',
				'sessions' => $count,
				'latest'   => $latest * 1000,
			];
		}

		usort( $out, fn( $a, $b ) => $b['latest'] <=> $a['latest'] );
		return $out;
	}

	// ─── Conversation ───────────────────────────────────────────

	/**
	 * Replay wire.jsonl as CC events.
	 *
	 *   metadata        → init
	 *   TurnBegin       → user_message (per text input)
	 *   ContentPart text→ text
	 *   ContentPart think→ skipped (reasoning is noise for replay)
	 *   ToolCall        → tool_call (+ pending result lookup)
	 *   ToolResult      → tool_result (paired by tool_call_id)
	 *   ToolCallPart    → skipped (streaming arg chunks; final ToolCall has the full arguments)
	 *   StatusUpdate / StepBegin / TurnEnd → skipped
	 */
	public static function getConversation( string $sessionId ): array {
		$file = self::findSessionFile( $sessionId );
		if ( ! $file ) {
			return [];
		}

		$events     = [];
		$emittedInit = false;

		// First, index ToolResults by tool_call_id so we can attach them at call time.
		$results = [];
		$fh      = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return [];
		}
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$msg = $obj['message'] ?? $obj;
			if ( ( $msg['type'] ?? '' ) !== 'ToolResult' ) {
				continue;
			}
			$payload = $msg['payload'] ?? [];
			$id      = $payload['tool_call_id'] ?? '';
			if ( $id === '' ) {
				continue;
			}
			$results[ $id ] = [
				'ts'       => self::eventTs( $obj ),
				'output'   => self::extractToolOutput( $payload['return_value'] ?? null ),
				'is_error' => ! empty( $payload['return_value']['is_error'] ),
			];
		}

		// Second pass: emit events in order.
		rewind( $fh );
		$lineIndex = 0;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$lineIndex++;
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$msg     = $obj['message'] ?? $obj;
			$type    = $msg['type']    ?? '';
			$payload = $msg['payload'] ?? [];
			$ts      = self::eventTs( $obj, $lineIndex );

			if ( $type === 'metadata' && ! $emittedInit ) {
				$events[] = [
					'type'       => 'init',
					'model'      => 'kimi',
					'session_id' => $sessionId,
					'skills'     => [],
					'_ts'        => 0,
				];
				$emittedInit = true;
				continue;
			}

			if ( $type === 'TurnBegin' ) {
				foreach ( self::userInputTexts( $payload['user_input'] ?? null ) as $text ) {
					$events[] = [
						'type' => 'user_message',
						'text' => $text,
						'_ts'  => $ts,
					];
				}
				continue;
			}

			if ( $type === 'ContentPart' && ( $payload['type'] ?? '' ) === 'text' ) {
				$text = trim( $payload['text'] ?? '' );
				if ( $text !== '' ) {
					$events[] = [ 'type' => 'text', 'text' => $text, '_ts' => $ts ];
				}
				continue;
			}

			if ( $type === 'ToolCall' ) {
				$callId   = $payload['id']                   ?? '';
				$rawName  = $payload['function']['name']     ?? 'tool';
				$rawArgs  = $payload['function']['arguments'] ?? '{}';
				$argsArr  = is_string( $rawArgs ) ? ( json_decode( $rawArgs, true ) ?: [] ) : ( is_array( $rawArgs ) ? $rawArgs : [] );

				$tool  = self::normalizeToolName( $rawName );
				$input = self::normalizeToolInput( $rawName, $argsArr );

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
							'text'   => mb_substr( $t['content'] ?? $t['title'] ?? $t['subject'] ?? '', 0, 80 ),
							'status' => self::normalizeTodoStatus( $t['status'] ?? 'pending' ),
						],
						$input['todos']
					);
				}
				$events[] = $call;

				$result = $results[ $callId ] ?? null;
				if ( $result && $result['output'] !== '' ) {
					$preview = ClaudeSessions::cleanResultText( mb_substr( $result['output'], 0, 500 ) );
					if ( $result['is_error'] ) {
						$preview = '⚠️ ' . $preview;
					}
					$events[] = [
						'type'    => 'tool_result',
						'preview' => $preview,
						'length'  => mb_strlen( $result['output'] ),
						'_ts'     => $result['ts'] ?: ( $ts + 1 ),
					];
				}
				continue;
			}
			// ContentPart think, ToolResult (already paired), ToolCallPart, StepBegin, StatusUpdate, TurnEnd → skipped.
		}
		fclose( $fh );

		// Stable chronological sort.
		usort( $events, fn( $a, $b ) => ( $a['_ts'] ?? 0 ) <=> ( $b['_ts'] ?? 0 ) );

		return array_map( function ( $ev ) {
			unset( $ev['_ts'] );
			return $ev;
		}, $events );
	}

	// ─── SSE stream ─────────────────────────────────────────────

	/**
	 * History-only replay - Kimi has its own TTY UI for live streaming.
	 * Mirrors T3CodeSessions / OpenCodeSessions behaviour.
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
	 * Resolve Kimi's home dir. Env override → ~/.kimi.
	 */
	public static function dataDir(): string {
		$override = getenv( 'KIMI_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		return rtrim( $home, '/' ) . '/.kimi';
	}

	private static function sessionsDir(): string {
		return self::dataDir() . '/sessions';
	}

	/**
	 * Build md5(work_dir) → work_dir map from kimi.json. Cached per request.
	 */
	private static function projectHashMap(): array {
		if ( self::$hashMapCache !== null ) {
			return self::$hashMapCache;
		}
		$config = self::readJson( self::dataDir() . '/kimi.json' ) ?: [];
		$map    = [];
		foreach ( ( $config['work_dirs'] ?? [] ) as $entry ) {
			$path = $entry['path'] ?? '';
			if ( $path !== '' ) {
				$map[ md5( $path ) ] = $path;
			}
		}
		self::$hashMapCache = $map;
		return $map;
	}

	private static function isValidSessionId( string $id ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id );
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
	 * Read the first wire.jsonl event with a timestamp to approximate session
	 * creation time (the leading "metadata" line has none).
	 */
	private static function firstWireTimestamp( string $file ): int {
		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return 0;
		}
		$ts = 0;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$obj = json_decode( $line, true );
			if ( is_array( $obj ) && isset( $obj['timestamp'] ) ) {
				$ts = (int) $obj['timestamp'];
				break;
			}
		}
		fclose( $fh );
		return $ts;
	}

	/**
	 * Convert a wire-event float-seconds timestamp to integer ms. Lines
	 * without a timestamp (e.g. the metadata header) fall back to lineIndex
	 * so they retain insertion order.
	 */
	private static function eventTs( array $obj, int $lineIndex = 0 ): int {
		$t = $obj['timestamp'] ?? null;
		if ( $t === null ) {
			return $lineIndex;
		}
		return (int) round( ( (float) $t ) * 1000 );
	}

	/**
	 * Pull a string out of a Kimi tool result. `return_value.output` is
	 * usually a string but can be structured - handle both.
	 */
	private static function extractToolOutput( $rv ): string {
		if ( is_string( $rv ) ) {
			return $rv;
		}
		if ( ! is_array( $rv ) ) {
			return '';
		}
		$out = $rv['output'] ?? '';
		if ( is_string( $out ) ) {
			return $out;
		}
		if ( is_array( $out ) ) {
			return json_encode( $out );
		}
		return '';
	}

	/**
	 * Map Kimi tool names to Claude's PascalCase set so ClaudeSessions's
	 * describer/category helpers light up. Unknown names pass through.
	 */
	private static function normalizeToolName( string $name ): string {
		static $map = [
			'readfile'      => 'Read',
			'readmediafile' => 'Read',
			'writefile'     => 'Write',
			'editfile'      => 'Edit',
			'shell'         => 'Bash',
			'grep'          => 'Grep',
			'glob'          => 'Glob',
			'fetchurl'      => 'WebFetch',
			'searchweb'     => 'WebSearch',
			'settodolist'   => 'TodoWrite',
		];
		return $map[ strtolower( $name ) ] ?? $name;
	}

	/**
	 * Translate Kimi tool input keys to the snake_case shape ClaudeSessions
	 * describers expect.
	 */
	private static function normalizeToolInput( string $rawTool, array $input ): array {
		if ( array_key_exists( 'path', $input ) && ! array_key_exists( 'file_path', $input ) ) {
			$input['file_path'] = $input['path'];
		}
		return $input;
	}

	/**
	 * TurnBegin's user_input is normally a list of {type,text} blocks, but
	 * older Kimi builds occasionally write it as a bare string. Accept both.
	 */
	private static function userInputTexts( $userInput ): array {
		if ( is_string( $userInput ) ) {
			$t = trim( $userInput );
			return $t === '' ? [] : [ $t ];
		}
		if ( ! is_array( $userInput ) ) {
			return [];
		}
		$out = [];
		foreach ( $userInput as $block ) {
			if ( is_string( $block ) ) {
				$t = trim( $block );
				if ( $t !== '' ) {
					$out[] = $t;
				}
				continue;
			}
			if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' ) {
				$t = trim( $block['text'] ?? '' );
				if ( $t !== '' ) {
					$out[] = $t;
				}
			}
		}
		return $out;
	}

	private static function normalizeTodoStatus( string $status ): string {
		static $map = [
			'done'        => 'completed',
			'completed'   => 'completed',
			'in_progress' => 'in_progress',
			'pending'     => 'pending',
		];
		return $map[ strtolower( $status ) ] ?? $status;
	}
}
