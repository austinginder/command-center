<?php

/**
 * OpenAI Codex (ChatGPT / VS Code Codex) session provider.
 *
 * Layout under CODEX_HOME (default ~/.codex):
 *
 *   sqlite/state_5.sqlite  - threads catalog (id, title, cwd, model, tokens, rollout_path)
 *   sessions/YYYY/MM/DD/rollout-…-{uuid}.jsonl  - full conversation rollouts
 *   session_index.jsonl    - lightweight id + thread_name + updated_at (optional)
 *
 * List prefers the SQLite catalog when present; falls back to scanning rollout
 * files. Conversation / FTS / fingerprint always use the rollout JSONL.
 *
 * Token usage: measured from the last event_msg token_count in the rollout
 * (input / cached / output / reasoning). Falls back to threads.tokens_used as
 * a single "input" total when the rollout has no token events.
 */
class CodexSessions {

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'codex';
	}

	public static function sourceLabel(): string {
		return 'Codex';
	}

	public static function hasSession( string $sessionId ): bool {
		return self::findSessionFile( $sessionId ) !== null;
	}

	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		if ( ! self::isValidSessionId( $id ) ) {
			return null;
		}

		// Prefer catalog path.
		$row = self::threadRow( $id );
		if ( $row && ! empty( $row['rollout_path'] ) && is_file( $row['rollout_path'] ) ) {
			return $row['rollout_path'];
		}

		// Scan rollouts by filename suffix.
		$root = self::sessionsDir();
		if ( ! is_dir( $root ) ) {
			return null;
		}
		$needle = '-' . $id . '.jsonl';
		$it     = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$name = $file->getFilename();
			if ( str_ends_with( $name, $needle ) || str_contains( $name, $id ) ) {
				return $file->getPathname();
			}
		}
		return null;
	}

	public static function fingerprint( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? null );
		if ( ! $file ) {
			return null;
		}
		clearstatcache( true, $file );
		return [
			'mtime' => (int) ( @filemtime( $file ) ?: 0 ),
			'size'  => (int) ( @filesize( $file ) ?: 0 ),
		];
	}

	/**
	 * Measured usage from last token_count event, else threads.tokens_used.
	 */
	public static function extractUsage( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? null );
		if ( $file ) {
			$fromRollout = self::usageFromRollout( $file );
			if ( $fromRollout ) {
				return $fromRollout;
			}
		}

		$row = self::threadRow( $session['id'] ?? '' );
		if ( $row && (int) ( $row['tokens_used'] ?? 0 ) > 0 ) {
			// Single aggregate counter - treat as input-ish total.
			return [
				'input'          => (int) $row['tokens_used'],
				'output'         => 0,
				'cache_read'     => 0,
				'cache_creation' => 0,
			];
		}
		return null;
	}

	public static function extractSessionText( array $session ): string {
		$parts    = [];
		$maxChars = 10000;

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? null );
		if ( ! $file ) {
			return implode( "\n", $parts );
		}

		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return implode( "\n", $parts );
		}
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$obj = json_decode( trim( $line ), true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$text = self::eventSearchText( $obj );
			if ( $text !== '' ) {
				$parts[] = mb_substr( $text, 0, $maxChars );
			}
		}
		fclose( $fh );

		return implode( "\n", $parts );
	}

	// ─── Listing ────────────────────────────────────────────────

	/**
	 * Union SQLite catalog + on-disk rollouts.
	 *
	 * After the ChatGPT desktop merge, newer threads often appear in
	 * sessions/YYYY/MM/DD/rollout-*.jsonl and session_index.jsonl before
	 * (or without) a matching row in state_5.sqlite. Catalog-only listing
	 * missed those.
	 */
	public static function listSessions( ?string $project = null ): array {
		$byId = [];

		foreach ( self::listFromCatalog( self::allThreadRows(), null ) as $s ) {
			$id = $s['id'] ?? '';
			if ( $id !== '' ) {
				$byId[ $id ] = $s;
			}
		}

		// Rollouts fill gaps and refresh size/mtime when catalog is stale.
		foreach ( self::listFromRollouts( null ) as $s ) {
			$id = $s['id'] ?? '';
			if ( $id === '' ) {
				continue;
			}
			if ( ! isset( $byId[ $id ] ) ) {
				$byId[ $id ] = $s;
				continue;
			}
			// Prefer newer timestamp; keep catalog title/model when present.
			$existing = $byId[ $id ];
			if ( (int) ( $s['timestamp'] ?? 0 ) > (int) ( $existing['timestamp'] ?? 0 ) ) {
				$existing['timestamp']   = $s['timestamp'];
				$existing['timestamp_s'] = $s['timestamp_s'];
			}
			if ( empty( $existing['display'] ) || $existing['display'] === $id ) {
				$existing['display'] = $s['display'];
			}
			if ( empty( $existing['project'] ) && ! empty( $s['project'] ) ) {
				$existing['project']     = $s['project'];
				$existing['projectName'] = $s['projectName'] ?? '';
			}
			if ( ( self::isBareProviderLabel( $existing['model'] ?? '' ) || empty( $existing['model'] ) )
				&& ! empty( $s['model'] ) && ! self::isBareProviderLabel( $s['model'] ) ) {
				$existing['model'] = $s['model'];
			}
			if ( empty( $existing['reasoning_effort'] ) && ! empty( $s['reasoning_effort'] ) ) {
				$existing['reasoning_effort'] = $s['reasoning_effort'];
			}
			if ( empty( $existing['cli_version'] ) && ! empty( $s['cli_version'] ) ) {
				$existing['cli_version'] = $s['cli_version'];
			}
			if ( empty( $existing['originator'] ) && ! empty( $s['originator'] ) ) {
				$existing['originator'] = $s['originator'];
			}
			if ( (int) ( $s['size'] ?? 0 ) > (int) ( $existing['size'] ?? 0 ) ) {
				$existing['size'] = $s['size'];
			}
			$byId[ $id ] = $existing;
		}

		// session_index titles for rows that still only have UUID display.
		$titles = self::sessionIndexMap();
		foreach ( $byId as $id => &$s ) {
			if ( ( empty( $s['display'] ) || $s['display'] === $id ) && ! empty( $titles[ $id ]['name'] ) ) {
				$s['display'] = $titles[ $id ]['name'];
			}
			if ( ! empty( $titles[ $id ]['updated_ms'] ) && (int) $titles[ $id ]['updated_ms'] > (int) ( $s['timestamp'] ?? 0 ) ) {
				$s['timestamp']   = (int) $titles[ $id ]['updated_ms'];
				$s['timestamp_s'] = (int) floor( $s['timestamp'] / 1000 );
			}
		}
		unset( $s );

		$out = array_values( $byId );
		if ( $project !== null && $project !== '' ) {
			$out = array_values( array_filter( $out, fn( $s ) => ( $s['project'] ?? '' ) === $project ) );
		}
		usort( $out, fn( $a, $b ) => ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 ) );
		return $out;
	}

	public static function listProjects(): array {
		$byPath = [];
		foreach ( self::listSessions() as $s ) {
			$path = $s['project'] ?? '';
			if ( $path === '' ) {
				$path = '(unknown)';
			}
			if ( ! isset( $byPath[ $path ] ) ) {
				$byPath[ $path ] = [
					'path'     => $path === '(unknown)' ? '' : $path,
					'name'     => $path === '(unknown)' ? '(unknown)' : Helpers::projectDisplayName( $path ),
					'sessions' => 0,
					'latest'   => 0,
				];
			}
			$byPath[ $path ]['sessions']++;
			$byPath[ $path ]['latest'] = max( $byPath[ $path ]['latest'], (int) ( $s['timestamp'] ?? 0 ) );
		}
		$out = array_values( $byPath );
		usort( $out, fn( $a, $b ) => $b['latest'] <=> $a['latest'] );
		return $out;
	}

	public static function getSession( string $sessionId ): ?array {
		foreach ( self::listSessions() as $s ) {
			if ( ( $s['id'] ?? '' ) === $sessionId ) {
				return $s;
			}
		}
		return null;
	}

	// ─── Conversation ───────────────────────────────────────────

	public static function getConversation( string $sessionId ): array {
		$file = self::findSessionFile( $sessionId );
		if ( ! $file ) {
			return [];
		}

		$row   = self::threadRow( $sessionId );
		$model = trim( (string) ( $row['model'] ?? '' ) );
		if ( self::isBareProviderLabel( $model ) ) {
			$model = '';
		}
		if ( $model === '' ) {
			$peek  = self::peekRolloutMeta( $file );
			$model = $peek['model'] ?? '';
		}
		$events = [
			[
				'type'       => 'init',
				'model'      => $model !== '' ? $model : 'codex',
				'session_id' => $sessionId,
				'skills'     => [],
			],
		];

		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return $events;
		}

		$pendingTools = []; // call_id => tool name

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$obj = json_decode( trim( $line ), true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$type = $obj['type'] ?? '';
			$pl   = is_array( $obj['payload'] ?? null ) ? $obj['payload'] : [];

			if ( $type === 'session_meta' && $model === '' ) {
				// Prefer concrete model later from catalog; payload only has provider.
			}

			if ( $type === 'response_item' ) {
				$itemType = $pl['type'] ?? '';

				if ( $itemType === 'message' ) {
					$role = $pl['role'] ?? '';
					$text = self::messageContentText( $pl['content'] ?? null );
					$text = self::stripEnvContext( $text );
					if ( $text === '' ) {
						continue;
					}
					if ( $role === 'user' ) {
						$events[] = [ 'type' => 'user_message', 'text' => $text ];
					} elseif ( $role === 'assistant' ) {
						$events[] = [ 'type' => 'text', 'text' => $text ];
					}
					// developer / system noise skipped
					continue;
				}

				if ( $itemType === 'function_call' || $itemType === 'custom_tool_call' ) {
					$name    = (string) ( $pl['name'] ?? 'tool' );
					$callId  = (string) ( $pl['call_id'] ?? $pl['id'] ?? '' );
					$argsRaw = $pl['arguments'] ?? '';
					$input   = [];
					if ( is_string( $argsRaw ) && $argsRaw !== '' ) {
						$decoded = json_decode( $argsRaw, true );
						$input   = is_array( $decoded ) ? $decoded : [ 'arguments' => $argsRaw ];
					} elseif ( is_array( $argsRaw ) ) {
						$input = $argsRaw;
					}
					$tool = self::normalizeToolName( $name );
					if ( $callId !== '' ) {
						$pendingTools[ $callId ] = $tool;
					}
					$events[] = [
						'type'     => 'tool_call',
						'tool'     => $tool,
						'category' => ClaudeSessions::toolCategory( $tool ),
						'label'    => ClaudeSessions::describeToolCall( $tool, self::normalizeToolInput( $tool, $input ) ),
					];
					continue;
				}

				if ( $itemType === 'function_call_output' || $itemType === 'custom_tool_call_output' ) {
					$callId = (string) ( $pl['call_id'] ?? '' );
					$out    = $pl['output'] ?? '';
					if ( is_array( $out ) ) {
						$out = json_encode( $out );
					}
					$out = is_string( $out ) ? $out : '';
					if ( $out !== '' ) {
						$preview  = ClaudeSessions::cleanResultText( mb_substr( $out, 0, 500 ) );
						$events[] = [
							'type'    => 'tool_result',
							'preview' => $preview,
							'length'  => mb_strlen( $out ),
						];
					}
					if ( $callId !== '' ) {
						unset( $pendingTools[ $callId ] );
					}
					continue;
				}

				// reasoning items skipped for replay noise
				continue;
			}

			if ( $type === 'event_msg' ) {
				$et = $pl['type'] ?? '';
				if ( $et === 'user_message' ) {
					$text = trim( (string) ( $pl['message'] ?? '' ) );
					$text = self::stripEnvContext( $text );
					if ( $text !== '' ) {
						$events[] = [ 'type' => 'user_message', 'text' => $text ];
					}
				} elseif ( $et === 'agent_message' ) {
					$text = trim( (string) ( $pl['message'] ?? '' ) );
					if ( $text !== '' ) {
						$events[] = [ 'type' => 'text', 'text' => $text ];
					}
				}
			}
		}
		fclose( $fh );

		return $events;
	}

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

	// ─── Paths / catalog ────────────────────────────────────────

	public static function dataDir(): string {
		$override = getenv( 'CODEX_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		return rtrim( $home, '/' ) . '/.codex';
	}

	private static function sessionsDir(): string {
		return self::dataDir() . '/sessions';
	}

	private static function stateDbPath(): string {
		$primary = self::dataDir() . '/sqlite/state_5.sqlite';
		if ( is_file( $primary ) ) {
			return $primary;
		}
		$alt = self::dataDir() . '/state_5.sqlite';
		return is_file( $alt ) ? $alt : $primary;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function allThreadRows(): array {
		$path = self::stateDbPath();
		if ( ! is_file( $path ) ) {
			return [];
		}
		try {
			$db = new SQLite3( $path, SQLITE3_OPEN_READONLY );
			$db->busyTimeout( 2000 );
		} catch ( \Throwable $e ) {
			return [];
		}

		$res = $db->query( 'SELECT * FROM threads ORDER BY COALESCE(updated_at_ms, updated_at * 1000) DESC' );
		if ( ! $res ) {
			$db->close();
			return [];
		}
		$out = [];
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$out[] = $row;
		}
		$db->close();
		return $out;
	}

	/** @return array<string,mixed>|null */
	private static function threadRow( string $id ): ?array {
		static $cache = null;
		if ( $cache === null ) {
			$cache = [];
			foreach ( self::allThreadRows() as $row ) {
				$rid = (string) ( $row['id'] ?? '' );
				if ( $rid !== '' ) {
					$cache[ $rid ] = $row;
				}
			}
		}
		return $cache[ $id ] ?? null;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function listFromCatalog( array $rows, ?string $project ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( $id === '' || ! self::isValidSessionId( $id ) ) {
				continue;
			}
			// Keep archived visible but mark them; still list (user may want search).
			$path = (string) ( $row['cwd'] ?? '' );
			if ( $project !== null && $project !== '' && $path !== $project ) {
				continue;
			}

			$updatedMs = (int) ( $row['updated_at_ms'] ?? 0 );
			if ( $updatedMs <= 0 ) {
				$updatedMs = ( (int) ( $row['updated_at'] ?? 0 ) ) * 1000;
			}
			$createdMs = (int) ( $row['created_at_ms'] ?? 0 );
			if ( $createdMs <= 0 ) {
				$createdMs = ( (int) ( $row['created_at'] ?? 0 ) ) * 1000;
			}

			$display = trim( (string) ( $row['title'] ?? '' ) );
			if ( $display === '' ) {
				$display = trim( (string) ( $row['preview'] ?? '' ) );
			}
			if ( $display === '' ) {
				$display = trim( (string) ( $row['first_user_message'] ?? '' ) );
			}
			if ( $display === '' ) {
				$display = self::titleFromIndex( $id ) ?: $id;
			}
			// Collapse whitespace for list rows.
			$display = preg_replace( '/\s+/', ' ', $display ) ?? $display;
			$display = mb_substr( $display, 0, 200 );

			$rollout = (string) ( $row['rollout_path'] ?? '' );
			$size    = ( $rollout !== '' && is_file( $rollout ) ) ? (int) @filesize( $rollout ) : 0;

			$model = trim( (string) ( $row['model'] ?? '' ) );
			if ( self::isBareProviderLabel( $model ) ) {
				$model = '';
			}
			// Catalog may lag; prefer turn_context model from rollout when needed.
			$effort = trim( (string) ( $row['reasoning_effort'] ?? '' ) );
			$cli    = trim( (string) ( $row['cli_version'] ?? '' ) );
			if ( ( $model === '' || $effort === '' ) && $rollout !== '' && is_file( $rollout ) ) {
				$peek = self::peekRolloutMeta( $rollout );
				if ( $model === '' && ! empty( $peek['model'] ) ) {
					$model = $peek['model'];
				}
				if ( $effort === '' && ! empty( $peek['effort'] ) ) {
					$effort = $peek['effort'];
				}
				if ( $cli === '' && ! empty( $peek['cli_version'] ) ) {
					$cli = $peek['cli_version'];
				}
			}

			$rec = [
				'id'                => $id,
				'display'           => $display,
				'timestamp'         => $updatedMs,
				'timestamp_s'       => (int) floor( $updatedMs / 1000 ),
				'project'           => $path,
				'projectName'       => $path !== '' ? Helpers::projectDisplayName( $path ) : '',
				'size'              => $size,
				'created'           => $createdMs,
				'model'             => $model,
				'reasoning_effort'  => $effort,
				'cli_version'       => $cli,
				'codex_source'      => trim( (string) ( $row['source'] ?? '' ) ),
			];
			if ( ! empty( $row['git_branch'] ) ) {
				$rec['git_branch'] = (string) $row['git_branch'];
			}
			if ( ! empty( $row['tokens_used'] ) ) {
				$rec['tokens_used'] = (int) $row['tokens_used'];
			}
			if ( ! empty( $row['archived'] ) ) {
				$rec['archived'] = true;
			}
			$out[] = $rec;
		}
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function listFromRollouts( ?string $project ): array {
		$root = self::sessionsDir();
		if ( ! is_dir( $root ) ) {
			return [];
		}
		$out = [];
		$it  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || ! str_ends_with( $file->getFilename(), '.jsonl' ) ) {
				continue;
			}
			$path = $file->getPathname();
			$meta = self::readSessionMeta( $path );
			if ( ! $meta || empty( $meta['id'] ) ) {
				continue;
			}
			$id  = (string) $meta['id'];
			$cwd = (string) ( $meta['cwd'] ?? '' );
			if ( $project !== null && $project !== '' && $cwd !== $project ) {
				continue;
			}
			$updatedMs = self::parseIsoMs( $meta['timestamp'] ?? null )
				?: ( (int) $file->getMTime() ) * 1000;
			$display = self::titleFromIndex( $id );
			if ( $display === '' ) {
				$display = self::firstUserFromRollout( $path );
			}
			if ( $display === '' ) {
				$display = $id;
			}
			// session_meta only has model_provider ("openai"); real model is on turn_context.
			$peek = self::peekRolloutMeta( $path );
			$model  = $peek['model'] ?? '';
			$effort = $peek['effort'] ?? '';
			// Prefer file mtime (activity) over session_meta start time when later.
			$mtimeMs = ( (int) $file->getMTime() ) * 1000;
			if ( $mtimeMs > $updatedMs ) {
				$updatedMs = $mtimeMs;
			}
			$out[] = [
				'id'               => $id,
				'display'          => mb_substr( preg_replace( '/\s+/', ' ', $display ) ?? $display, 0, 200 ),
				'timestamp'        => $updatedMs,
				'timestamp_s'      => (int) floor( $updatedMs / 1000 ),
				'project'          => $cwd,
				'projectName'      => $cwd !== '' ? Helpers::projectDisplayName( $cwd ) : '',
				'size'             => (int) $file->getSize(),
				'created'          => $updatedMs,
				'model'            => $model,
				'reasoning_effort' => $effort,
				'cli_version'      => $peek['cli_version'] ?? trim( (string) ( $meta['cli_version'] ?? '' ) ),
				'originator'       => $peek['originator'] ?? trim( (string) ( $meta['originator'] ?? '' ) ),
				'codex_source'     => trim( (string) ( $meta['source'] ?? '' ) ),
			];
		}
		usort( $out, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		return $out;
	}

	// ─── Rollout parsing helpers ────────────────────────────────

	/** @return array<string,mixed>|null */
	private static function readSessionMeta( string $file ): ?array {
		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return null;
		}
		$max = 20;
		$n   = 0;
		while ( ( $line = fgets( $fh ) ) !== false && $n < $max ) {
			$n++;
			$obj = json_decode( trim( $line ), true );
			if ( ! is_array( $obj ) || ( $obj['type'] ?? '' ) !== 'session_meta' ) {
				continue;
			}
			$pl = $obj['payload'] ?? null;
			fclose( $fh );
			return is_array( $pl ) ? $pl : null;
		}
		fclose( $fh );
		return null;
	}

	/**
	 * Cheap head scan for fields that live on turn_context (not session_meta).
	 * session_meta only carries model_provider ("openai"); the concrete model
	 * (e.g. gpt-5.5) and reasoning effort are on the first turn_context.
	 *
	 * @return array{model?:string,effort?:string,cli_version?:string,originator?:string}
	 */
	private static function peekRolloutMeta( string $file ): array {
		static $cache = [];
		if ( isset( $cache[ $file ] ) ) {
			return $cache[ $file ];
		}
		$out = [];
		$fh  = @fopen( $file, 'r' );
		if ( ! $fh ) {
			$cache[ $file ] = $out;
			return $out;
		}
		$max = 60;
		$n   = 0;
		while ( ( $line = fgets( $fh ) ) !== false && $n < $max ) {
			$n++;
			$obj = json_decode( trim( $line ), true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$type = $obj['type'] ?? '';
			$pl   = is_array( $obj['payload'] ?? null ) ? $obj['payload'] : [];

			if ( $type === 'session_meta' ) {
				if ( ! empty( $pl['cli_version'] ) ) {
					$out['cli_version'] = trim( (string) $pl['cli_version'] );
				}
				if ( ! empty( $pl['originator'] ) ) {
					$out['originator'] = trim( (string) $pl['originator'] );
				}
				// Do NOT use model_provider ("openai") as model.
				continue;
			}

			if ( $type === 'turn_context' ) {
				$m = trim( (string) ( $pl['model'] ?? '' ) );
				if ( $m === '' && is_array( $pl['collaboration_mode']['settings'] ?? null ) ) {
					$m = trim( (string) ( $pl['collaboration_mode']['settings']['model'] ?? '' ) );
				}
				if ( $m !== '' && ! self::isBareProviderLabel( $m ) ) {
					$out['model'] = $m;
				}
				$effort = trim( (string) ( $pl['effort'] ?? '' ) );
				if ( $effort === '' && is_array( $pl['collaboration_mode']['settings'] ?? null ) ) {
					$effort = trim( (string) ( $pl['collaboration_mode']['settings']['reasoning_effort'] ?? '' ) );
				}
				if ( $effort !== '' ) {
					$out['effort'] = $effort;
				}
				// First turn_context is enough for list chips.
				if ( ! empty( $out['model'] ) ) {
					break;
				}
			}
		}
		fclose( $fh );
		$cache[ $file ] = $out;
		return $out;
	}

	/** True for labels that are providers, not models (must not show as model chip). */
	private static function isBareProviderLabel( string $label ): bool {
		$l = strtolower( trim( $label ) );
		return $l === '' || in_array( $l, [ 'openai', 'anthropic', 'google', 'xai', 'azure' ], true );
	}

	private static function firstUserFromRollout( string $file ): string {
		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return '';
		}
		$max = 80;
		$n   = 0;
		while ( ( $line = fgets( $fh ) ) !== false && $n < $max ) {
			$n++;
			$obj = json_decode( trim( $line ), true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$text = self::eventSearchText( $obj, true );
			$text = self::stripEnvContext( $text );
			if ( $text !== '' && mb_strlen( $text ) >= 4 ) {
				fclose( $fh );
				return mb_substr( $text, 0, 160 );
			}
		}
		fclose( $fh );
		return '';
	}

	/**
	 * @return array<string,array{name:string,updated_ms:int}>
	 */
	private static function sessionIndexMap(): array {
		static $map = null;
		if ( $map !== null ) {
			return $map;
		}
		$map  = [];
		$path = self::dataDir() . '/session_index.jsonl';
		if ( ! is_file( $path ) ) {
			return $map;
		}
		$fh = @fopen( $path, 'r' );
		if ( ! $fh ) {
			return $map;
		}
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$o = json_decode( trim( $line ), true );
			if ( ! is_array( $o ) || empty( $o['id'] ) ) {
				continue;
			}
			$id = (string) $o['id'];
			$map[ $id ] = [
				'name'        => trim( (string) ( $o['thread_name'] ?? '' ) ),
				'updated_ms'  => self::parseIsoMs( $o['updated_at'] ?? null ),
			];
		}
		fclose( $fh );
		return $map;
	}

	private static function titleFromIndex( string $id ): string {
		return self::sessionIndexMap()[ $id ]['name'] ?? '';
	}

	/**
	 * @return array{input:int,output:int,cache_read:int,cache_creation:int}|null
	 */
	private static function usageFromRollout( string $file ): ?array {
		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return null;
		}
		$last = null;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			if ( ! str_contains( $line, 'token_count' ) ) {
				continue;
			}
			$obj = json_decode( trim( $line ), true );
			if ( ! is_array( $obj ) || ( $obj['type'] ?? '' ) !== 'event_msg' ) {
				continue;
			}
			$pl = $obj['payload'] ?? null;
			if ( ! is_array( $pl ) || ( $pl['type'] ?? '' ) !== 'token_count' ) {
				continue;
			}
			$info = $pl['info']['total_token_usage'] ?? null;
			if ( is_array( $info ) ) {
				$last = $info;
			}
		}
		fclose( $fh );
		if ( ! $last ) {
			return null;
		}
		$input  = (int) ( $last['input_tokens'] ?? 0 );
		$cache  = (int) ( $last['cached_input_tokens'] ?? 0 );
		$output = (int) ( $last['output_tokens'] ?? 0 ) + (int) ( $last['reasoning_output_tokens'] ?? 0 );
		if ( $input === 0 && $output === 0 && $cache === 0 ) {
			return null;
		}
		return [
			'input'          => $input,
			'output'         => $output,
			'cache_read'     => $cache,
			'cache_creation' => 0,
		];
	}

	/**
	 * Extract searchable / display text from a rollout line.
	 * When $userOnly, only user-facing prompts.
	 */
	private static function eventSearchText( array $obj, bool $userOnly = false ): string {
		$type = $obj['type'] ?? '';
		$pl   = is_array( $obj['payload'] ?? null ) ? $obj['payload'] : [];

		if ( $type === 'response_item' && ( $pl['type'] ?? '' ) === 'message' ) {
			$role = $pl['role'] ?? '';
			if ( $userOnly && $role !== 'user' ) {
				return '';
			}
			if ( ! $userOnly && $role !== 'user' && $role !== 'assistant' ) {
				return '';
			}
			return self::messageContentText( $pl['content'] ?? null );
		}

		if ( $type === 'event_msg' ) {
			$et = $pl['type'] ?? '';
			if ( $et === 'user_message' ) {
				return trim( (string) ( $pl['message'] ?? '' ) );
			}
			if ( ! $userOnly && $et === 'agent_message' ) {
				return trim( (string) ( $pl['message'] ?? '' ) );
			}
		}
		return '';
	}

	private static function messageContentText( mixed $content ): string {
		if ( is_string( $content ) ) {
			return trim( $content );
		}
		if ( ! is_array( $content ) ) {
			return '';
		}
		$parts = [];
		foreach ( $content as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$bt = $block['type'] ?? '';
			if ( in_array( $bt, [ 'input_text', 'output_text', 'text' ], true ) ) {
				$t = $block['text'] ?? $block['content'] ?? '';
				if ( is_string( $t ) && $t !== '' ) {
					$parts[] = $t;
				}
			}
		}
		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Drop Codex <environment_context>… wrappers for display titles / cleaner FTS.
	 */
	private static function stripEnvContext( string $text ): string {
		$text = trim( $text );
		if ( $text === '' ) {
			return '';
		}
		// Full env block only → empty
		if ( preg_match( '/^<environment_context>[\s\S]*<\/environment_context>\s*$/i', $text ) ) {
			return '';
		}
		// Leading env block + real prompt
		$text = preg_replace( '/^<environment_context>[\s\S]*?<\/environment_context>\s*/i', '', $text ) ?? $text;
		// Permissions / sandbox instruction blobs
		if ( str_starts_with( $text, '<permissions instructions>' ) ) {
			return '';
		}
		return trim( $text );
	}

	private static function normalizeToolName( string $name ): string {
		$map = [
			'exec_command'       => 'Bash',
			'shell'              => 'Bash',
			'local_shell'        => 'Bash',
			'apply_patch'        => 'Edit',
			'write_file'         => 'Write',
			'read_file'          => 'Read',
			'list_dir'           => 'Bash',
			'grep_files'         => 'Grep',
			'codebase_search'    => 'Grep',
			'update_plan'        => 'TodoWrite',
			'web_search'         => 'WebSearch',
		];
		$key = strtolower( $name );
		return $map[ $key ] ?? $name;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	private static function normalizeToolInput( string $tool, array $input ): array {
		// Map common Codex argument keys to Claude-style keys for describeToolCall.
		if ( isset( $input['cmd'] ) && ! isset( $input['command'] ) ) {
			$input['command'] = $input['cmd'];
		}
		if ( isset( $input['path'] ) && ! isset( $input['file_path'] ) ) {
			$input['file_path'] = $input['path'];
		}
		if ( isset( $input['file'] ) && ! isset( $input['file_path'] ) ) {
			$input['file_path'] = $input['file'];
		}
		return $input;
	}

	private static function isValidSessionId( string $id ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$id
		);
	}

	private static function parseIsoMs( ?string $iso ): int {
		if ( ! $iso ) {
			return 0;
		}
		try {
			$dt = new DateTimeImmutable( $iso );
			return (int) ( $dt->format( 'U' ) * 1000 + (int) $dt->format( 'v' ) );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
