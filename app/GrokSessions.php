<?php

/**
 * Grok Code (xAI) session provider - reads from ~/.grok/sessions.
 *
 * Layout (see ~/.grok/docs/user-guide/17-sessions.md):
 *
 *   ~/.grok/sessions/<url-encoded-cwd>/<session-id>/
 *     summary.json          - title, timestamps, model, message counts
 *     updates.jsonl         - ACP session/update stream (authoritative conversation)
 *     chat_history.jsonl    - raw model messages (not used for UI replay)
 *     plan.json / signals.json / rewind_points.jsonl / subagents/ …
 *
 * When the encoded cwd exceeds 255 bytes, Grok uses a slug+hash directory
 * and records the real path in a `.cwd` file inside the group.
 *
 * Session IDs are UUIDv7. There is no TTL: sessions stay on disk until
 * deleted. GROK_HOME overrides the base directory (default ~/.grok).
 */
class GrokSessions {

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'grok';
	}

	public static function sourceLabel(): string {
		return 'Grok Code';
	}

	public static function hasSession( string $sessionId ): bool {
		return self::findSessionDir( $sessionId ) !== null;
	}

	/**
	 * Canonical tailable handle is updates.jsonl (append-only conversation log).
	 */
	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		$dir = self::findSessionDir( $id, $project );
		if ( ! $dir ) {
			return null;
		}
		$updates = $dir . '/updates.jsonl';
		if ( file_exists( $updates ) ) {
			return $updates;
		}
		// Incomplete sessions may only have summary.json so far.
		$summary = $dir . '/summary.json';
		return file_exists( $summary ) ? $summary : null;
	}

	/**
	 * Fingerprint = (updates.jsonl mtime, updates.jsonl size). New turns append
	 * to updates.jsonl; summary.json may lag slightly but size catches growth.
	 */
	public static function fingerprint( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '' );
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
	 * Token usage from the ACP update stream. Grok records no per-request
	 * usage, but every update carries _meta.totalTokens - a live context-size
	 * counter that climbs within a compaction segment and resets after
	 * compaction. Summing each segment's peak gives the real count of tokens
	 * that entered the context (reported as input). There is no output
	 * counter, so output is estimated from streamed message/thought text at
	 * ~4 chars per token - which is why this provider reports as estimated
	 * (see SessionRegistry::usageType()).
	 */
	public static function extractUsage( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? null );
		if ( ! $file || substr( $file, -13 ) !== 'updates.jsonl' ) {
			return null;
		}

		$fp = fopen( $file, 'r' );
		if ( ! $fp ) {
			return null;
		}

		$peak     = 0;
		$context  = 0;
		$outChars = 0;

		while ( ( $line = fgets( $fp ) ) !== false ) {
			if ( strpos( $line, 'totalTokens' ) === false && strpos( $line, '_chunk' ) === false ) {
				continue;
			}
			$obj    = json_decode( $line, true );
			$params = $obj['params'] ?? [];

			$t = $params['_meta']['totalTokens'] ?? null;
			if ( is_numeric( $t ) ) {
				$t = (int) $t;
				if ( $t < $peak ) {
					$context += $peak; // compaction reset - bank the segment peak
				}
				$peak = $t;
			}

			$update = $params['update'] ?? [];
			$kind   = $update['sessionUpdate'] ?? '';
			if ( $kind === 'agent_message_chunk' || $kind === 'agent_thought_chunk' ) {
				$outChars += strlen( $update['content']['text'] ?? '' );
			}
		}

		fclose( $fp );
		$context += $peak;

		if ( $context === 0 && $outChars === 0 ) {
			return null;
		}

		return [
			'input'          => $context,
			'output'         => intdiv( $outChars, 4 ),
			'cache_read'     => 0,
			'cache_creation' => 0,
		];
	}

	/**
	 * Concat title + user prompts + agent text for FTS.
	 * Thoughts and tool I/O are skipped (noise / bloat).
	 */
	public static function extractSessionText( array $session ): string {
		$parts    = [];
		$maxChars = 10000;

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		$file = self::findSessionFile( $session['id'] ?? '' );
		if ( ! $file || ! str_ends_with( $file, 'updates.jsonl' ) ) {
			return implode( "\n", $parts );
		}

		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return implode( "\n", $parts );
		}

		$userBuf  = '';
		$agentBuf = '';

		$flushUser = function () use ( &$parts, &$userBuf, $maxChars ) {
			$t = trim( $userBuf );
			if ( $t !== '' ) {
				$parts[] = mb_substr( $t, 0, $maxChars );
			}
			$userBuf = '';
		};
		$flushAgent = function () use ( &$parts, &$agentBuf, $maxChars ) {
			$t = trim( $agentBuf );
			if ( $t !== '' ) {
				$parts[] = mb_substr( $t, 0, $maxChars );
			}
			$agentBuf = '';
		};

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$update = $obj['params']['update'] ?? null;
			if ( ! is_array( $update ) ) {
				continue;
			}
			$type = $update['sessionUpdate'] ?? '';

			if ( $type === 'user_message_chunk' ) {
				$flushAgent();
				$userBuf .= self::contentText( $update['content'] ?? null );
				continue;
			}
			if ( $type === 'agent_message_chunk' ) {
				$flushUser();
				$agentBuf .= self::contentText( $update['content'] ?? null );
				continue;
			}
			// Boundary: tool calls / thoughts end a text run.
			if ( $type === 'tool_call' || $type === 'tool_call_update' || $type === 'agent_thought_chunk' ) {
				$flushUser();
				$flushAgent();
			}
		}
		$flushUser();
		$flushAgent();
		fclose( $fh );

		return implode( "\n", $parts );
	}

	// ─── Listing ────────────────────────────────────────────────

	public static function listSessions( ?string $project = null ): array {
		$root = self::sessionsDir();
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$out = [];

		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] as $cwdDir ) {
			$projectPath = self::resolveProjectPath( $cwdDir );
			$projectName = $projectPath !== '' ? basename( $projectPath ) : '';

			if ( $project !== null && $project !== '' && $project !== $projectPath ) {
				continue;
			}

			foreach ( glob( $cwdDir . '/*', GLOB_ONLYDIR ) ?: [] as $sessionDir ) {
				$id = basename( $sessionDir );
				if ( ! self::isValidSessionId( $id ) ) {
					continue;
				}

				$summaryFile = $sessionDir . '/summary.json';
				$updatesFile = $sessionDir . '/updates.jsonl';
				if ( ! file_exists( $summaryFile ) && ! file_exists( $updatesFile ) ) {
					continue;
				}

				$summary = self::readJson( $summaryFile ) ?: [];
				// Prefer cwd from summary when present (handles slug+hash dirs).
				$path = $summary['info']['cwd'] ?? $projectPath;
				$name = $path !== '' ? self::projectDisplayName( $path ) : $projectName;

				if ( $project !== null && $project !== '' && $path !== $project ) {
					continue;
				}

				$updatedMs = self::parseIsoMs( $summary['updated_at'] ?? $summary['last_active_at'] ?? null );
				$createdMs = self::parseIsoMs( $summary['created_at'] ?? null );

				if ( ! $updatedMs && file_exists( $updatesFile ) ) {
					$updatedMs = ( (int) @filemtime( $updatesFile ) ) * 1000;
				}
				if ( ! $createdMs ) {
					$createdMs = $updatedMs;
				}

				$display = trim( (string) ( $summary['generated_title'] ?? $summary['session_summary'] ?? '' ) );
				$size    = file_exists( $updatesFile )
					? (int) @filesize( $updatesFile )
					: (int) @filesize( $summaryFile );

				$out[] = [
					'id'          => $id,
					'display'     => $display,
					'timestamp'   => $updatedMs,
					'timestamp_s' => (int) floor( $updatedMs / 1000 ),
					'project'     => $path,
					'projectName' => $name,
					'size'        => $size,
					'created'     => $createdMs,
					'model'       => $summary['current_model_id'] ?? '',
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

		$byPath = [];

		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] as $cwdDir ) {
			foreach ( glob( $cwdDir . '/*', GLOB_ONLYDIR ) ?: [] as $sessionDir ) {
				$id = basename( $sessionDir );
				if ( ! self::isValidSessionId( $id ) ) {
					continue;
				}
				$summaryFile = $sessionDir . '/summary.json';
				$updatesFile = $sessionDir . '/updates.jsonl';
				if ( ! file_exists( $summaryFile ) && ! file_exists( $updatesFile ) ) {
					continue;
				}

				$summary = self::readJson( $summaryFile ) ?: [];
				$path    = $summary['info']['cwd'] ?? self::resolveProjectPath( $cwdDir );
				if ( $path === '' ) {
					$path = '(unknown)';
				}

				$mtime = 0;
				if ( file_exists( $updatesFile ) ) {
					$mtime = (int) @filemtime( $updatesFile );
				} elseif ( file_exists( $summaryFile ) ) {
					$mtime = (int) @filemtime( $summaryFile );
				}
				$updatedMs = self::parseIsoMs( $summary['updated_at'] ?? null ) ?: ( $mtime * 1000 );

				if ( ! isset( $byPath[ $path ] ) ) {
					$byPath[ $path ] = [
						'path'     => $path,
						'name'     => $path === '(unknown)' ? '(unknown)' : self::projectDisplayName( $path ),
						'sessions' => 0,
						'latest'   => 0,
					];
				}
				$byPath[ $path ]['sessions']++;
				$byPath[ $path ]['latest'] = max( $byPath[ $path ]['latest'], $updatedMs );
			}
		}

		$out = array_values( $byPath );
		usort( $out, fn( $a, $b ) => $b['latest'] <=> $a['latest'] );
		return $out;
	}

	// ─── Conversation ───────────────────────────────────────────

	/**
	 * Replay updates.jsonl as Command Center events.
	 *
	 *   user_message_chunk  → user_message (coalesced)
	 *   agent_message_chunk → text (coalesced)
	 *   agent_thought_chunk → skipped
	 *   tool_call           → tool_call
	 *   tool_call_update (status=completed) → tool_result
	 */
	public static function getConversation( string $sessionId ): array {
		$dir = self::findSessionDir( $sessionId );
		if ( ! $dir ) {
			return [];
		}

		$summary = self::readJson( $dir . '/summary.json' ) ?: [];
		$events  = [
			[
				'type'       => 'init',
				'model'      => $summary['current_model_id'] ?? 'grok',
				'session_id' => $sessionId,
				'skills'     => [],
				'_ts'        => 0,
			],
		];

		$file = $dir . '/updates.jsonl';
		if ( ! file_exists( $file ) ) {
			return array_map( function ( $ev ) {
				unset( $ev['_ts'] );
				return $ev;
			}, $events );
		}

		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return array_map( function ( $ev ) {
				unset( $ev['_ts'] );
				return $ev;
			}, $events );
		}

		// First pass: completed tool results keyed by toolCallId.
		$results = [];
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$update = $obj['params']['update'] ?? null;
			if ( ! is_array( $update ) || ( $update['sessionUpdate'] ?? '' ) !== 'tool_call_update' ) {
				continue;
			}
			if ( ( $update['status'] ?? '' ) !== 'completed' ) {
				continue;
			}
			$id = $update['toolCallId'] ?? '';
			if ( $id === '' ) {
				continue;
			}
			$results[ $id ] = [
				'ts'     => self::eventTs( $obj ),
				'output' => self::extractToolOutput( $update ),
			];
		}

		// Second pass: emit events in order, coalescing text chunks.
		rewind( $fh );
		$lineIndex = 0;
		$userBuf   = '';
		$userTs    = 0;
		$agentBuf  = '';
		$agentTs   = 0;

		$flushUser = function () use ( &$events, &$userBuf, &$userTs ) {
			$t = trim( $userBuf );
			if ( $t !== '' ) {
				$events[] = [
					'type' => 'user_message',
					'text' => $t,
					'_ts'  => $userTs,
				];
			}
			$userBuf = '';
			$userTs  = 0;
		};
		$flushAgent = function () use ( &$events, &$agentBuf, &$agentTs ) {
			$t = trim( $agentBuf );
			if ( $t !== '' ) {
				$events[] = [
					'type' => 'text',
					'text' => $t,
					'_ts'  => $agentTs,
				];
			}
			$agentBuf = '';
			$agentTs  = 0;
		};

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$lineIndex++;
			$obj = json_decode( $line, true );
			if ( ! is_array( $obj ) ) {
				continue;
			}
			$update = $obj['params']['update'] ?? null;
			if ( ! is_array( $update ) ) {
				continue;
			}
			$type = $update['sessionUpdate'] ?? '';
			$ts   = self::eventTs( $obj, $lineIndex );

			if ( $type === 'user_message_chunk' ) {
				$flushAgent();
				$chunk = self::contentText( $update['content'] ?? null );
				if ( $chunk === '' ) {
					continue;
				}
				if ( $userBuf === '' ) {
					$userTs = $ts;
				}
				$userBuf .= $chunk;
				continue;
			}

			if ( $type === 'agent_message_chunk' ) {
				$flushUser();
				$chunk = self::contentText( $update['content'] ?? null );
				if ( $chunk === '' ) {
					continue;
				}
				if ( $agentBuf === '' ) {
					$agentTs = $ts;
				}
				$agentBuf .= $chunk;
				continue;
			}

			if ( $type === 'tool_call' ) {
				$flushUser();
				$flushAgent();

				$callId  = $update['toolCallId'] ?? '';
				$rawName = ( $update['_meta']['x.ai/tool']['name'] ?? null )
					?: ( $update['title'] ?? 'tool' );
				// Title is sometimes a human string ("Web search: …"); prefer meta name.
				if ( str_contains( $rawName, ' ' ) && isset( $update['_meta']['x.ai/tool']['name'] ) ) {
					$rawName = $update['_meta']['x.ai/tool']['name'];
				}

				$rawInput = $update['rawInput'] ?? [];
				if ( ! is_array( $rawInput ) ) {
					$rawInput = [];
				}
				// Prefer structured meta input when present.
				$metaInput = $update['_meta']['x.ai/tool']['input'] ?? null;
				if ( is_array( $metaInput ) && $metaInput ) {
					$rawInput = array_merge( $rawInput, $metaInput );
				}

				$tool  = self::normalizeToolName( (string) $rawName );
				$input = self::normalizeToolInput( $tool, $rawInput );

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
						is_array( $input['todos'] ) ? $input['todos'] : []
					);
				}
				$events[] = $call;

				$result = $results[ $callId ] ?? null;
				if ( $result && $result['output'] !== '' ) {
					$preview = ClaudeSessions::cleanResultText( mb_substr( $result['output'], 0, 500 ) );
					$events[] = [
						'type'    => 'tool_result',
						'preview' => $preview,
						'length'  => mb_strlen( $result['output'] ),
						'_ts'     => $result['ts'] ?: ( $ts + 1 ),
					];
				}
				continue;
			}

			// tool_call_update / agent_thought_chunk / other → flush text runs, skip body.
			if ( $type === 'tool_call_update' || $type === 'agent_thought_chunk' ) {
				$flushUser();
				$flushAgent();
			}
		}
		$flushUser();
		$flushAgent();
		fclose( $fh );

		usort( $events, fn( $a, $b ) => ( $a['_ts'] ?? 0 ) <=> ( $b['_ts'] ?? 0 ) );

		return array_map( function ( $ev ) {
			unset( $ev['_ts'] );
			return $ev;
		}, $events );
	}

	// ─── SSE stream ─────────────────────────────────────────────

	/**
	 * History-only replay - Grok has its own TUI for live streaming.
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

	public static function dataDir(): string {
		$override = getenv( 'GROK_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		return rtrim( $home, '/' ) . '/.grok';
	}

	private static function sessionsDir(): string {
		return self::dataDir() . '/sessions';
	}

	/**
	 * Locate the session directory by id. Optional $project is currently unused
	 * for the filesystem probe (ids are globally unique UUIDs) but kept for
	 * contract parity with other providers.
	 */
	private static function findSessionDir( string $id, ?string $project = null ): ?string {
		if ( ! self::isValidSessionId( $id ) ) {
			return null;
		}
		$matches = glob( self::sessionsDir() . '/*/' . $id, GLOB_ONLYDIR );
		return $matches ? $matches[0] : null;
	}

	/**
	 * Decode the cwd group directory name, or read `.cwd` when present.
	 */
	private static function resolveProjectPath( string $cwdDir ): string {
		$cwdFile = $cwdDir . '/.cwd';
		if ( is_file( $cwdFile ) ) {
			$path = trim( (string) @file_get_contents( $cwdFile ) );
			if ( $path !== '' ) {
				return $path;
			}
		}
		$name = basename( $cwdDir );
		// Skip non-session group files (e.g. if a stray dir appears).
		$decoded = rawurldecode( $name );
		// Encoded paths start with / or ~ after decode.
		if ( $decoded !== '' && ( $decoded[0] === '/' || $decoded[0] === '~' ) ) {
			return $decoded;
		}
		return $decoded;
	}

	private static function isValidSessionId( string $id ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

	/**
	 * Human project label. Cove sites are almost always …/Sites/<name>.localhost/public,
	 * so "public" as a basename is useless - prefer the parent folder in that case.
	 */
	private static function projectDisplayName( string $path ): string {
		$base = basename( $path );
		if ( $base === 'public' || $base === 'htdocs' || $base === 'www' ) {
			$parent = basename( dirname( $path ) );
			if ( $parent !== '' && $parent !== '/' && $parent !== '.' ) {
				return $parent;
			}
		}
		return $base !== '' ? $base : $path;
	}

	private static function readJson( string $path ): ?array {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Parse ISO-8601 timestamps (with fractional seconds + Z) to epoch ms.
	 */
	private static function parseIsoMs( ?string $iso ): int {
		if ( $iso === null || $iso === '' ) {
			return 0;
		}
		try {
			$dt = new DateTimeImmutable( $iso );
			return (int) ( $dt->format( 'U' ) * 1000 + (int) $dt->format( 'v' ) );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Event timestamp → ms. Prefer _meta.agentTimestampMs, then top-level
	 * unix seconds, then line index for stable order.
	 */
	private static function eventTs( array $obj, int $lineIndex = 0 ): int {
		$meta = $obj['params']['_meta'] ?? $obj['params']['update']['_meta'] ?? null;
		if ( is_array( $meta ) && isset( $meta['agentTimestampMs'] ) ) {
			return (int) $meta['agentTimestampMs'];
		}
		if ( isset( $obj['timestamp'] ) ) {
			$t = (float) $obj['timestamp'];
			// Heuristic: values > 1e12 are already ms.
			return $t > 1e12 ? (int) $t : (int) round( $t * 1000 );
		}
		return $lineIndex;
	}

	/**
	 * Pull plain text out of an ACP content block ({type:text,text:…}) or string.
	 */
	private static function contentText( $content ): string {
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( ! is_array( $content ) ) {
			return '';
		}
		if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
			return $content['text'];
		}
		// Rare: list of blocks.
		if ( array_is_list( $content ) ) {
			$out = '';
			foreach ( $content as $block ) {
				$out .= self::contentText( $block );
			}
			return $out;
		}
		return '';
	}

	/**
	 * Flatten a completed tool_call_update into a preview string.
	 */
	private static function extractToolOutput( array $update ): string {
		if ( isset( $update['rawOutput'] ) ) {
			$ro = $update['rawOutput'];
			if ( is_string( $ro ) ) {
				return $ro;
			}
			if ( is_array( $ro ) ) {
				// Common Grok shapes: {type, Content: {content: "…"}} or nested content.
				if ( isset( $ro['Content']['content'] ) && is_string( $ro['Content']['content'] ) ) {
					return $ro['Content']['content'];
				}
				if ( isset( $ro['content'] ) && is_string( $ro['content'] ) ) {
					return $ro['content'];
				}
				if ( isset( $ro['output'] ) && is_string( $ro['output'] ) ) {
					return $ro['output'];
				}
				if ( isset( $ro['stdout'] ) && is_string( $ro['stdout'] ) ) {
					return $ro['stdout'];
				}
				return json_encode( $ro, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}

		// Streaming content[] blocks on intermediate updates.
		if ( ! empty( $update['content'] ) && is_array( $update['content'] ) ) {
			$parts = [];
			foreach ( $update['content'] as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				if ( ( $block['type'] ?? '' ) === 'content' && isset( $block['content'] ) ) {
					$parts[] = self::contentText( $block['content'] );
				} elseif ( isset( $block['text'] ) ) {
					$parts[] = (string) $block['text'];
				}
			}
			$joined = trim( implode( "\n", array_filter( $parts ) ) );
			if ( $joined !== '' ) {
				return $joined;
			}
		}

		if ( ! empty( $update['title'] ) && is_string( $update['title'] ) ) {
			return $update['title'];
		}

		return '';
	}

	/**
	 * Map Grok / ACP tool names onto Claude's PascalCase set so category
	 * and describe helpers light up. Unknown names pass through.
	 */
	private static function normalizeToolName( string $name ): string {
		static $map = [
			// Grok Build tool ids
			'read_file'                      => 'Read',
			'list_dir'                       => 'Glob',
			'grep'                           => 'Grep',
			'search_replace'                 => 'Edit',
			'write'                          => 'Write',
			'run_terminal_command'           => 'Bash',
			'web_search'                     => 'WebSearch',
			'web_fetch'                      => 'WebFetch',
			'open_page'                      => 'WebFetch',
			'open_page_with_find'            => 'WebFetch',
			'todo_write'                     => 'TodoWrite',
			'spawn_subagent'                 => 'Task',
			'get_command_or_subagent_output' => 'Task',
			'kill_command_or_subagent'       => 'Task',
			// Already-PascalCase / Anthropic-style names seen in older logs
			'read'                           => 'Read',
			'shell'                          => 'Bash',
			'strreplace'                     => 'Edit',
			'glob'                           => 'Glob',
			'webfetch'                       => 'WebFetch',
			'websearch'                      => 'WebSearch',
			'todowrite'                      => 'TodoWrite',
			'task'                           => 'Task',
			'delete'                         => 'Bash',
		];
		$key = strtolower( $name );
		return $map[ $key ] ?? $name;
	}

	/**
	 * Translate Grok tool input keys to the snake_case shape ClaudeSessions
	 * describers expect (file_path, command, pattern, …).
	 */
	private static function normalizeToolInput( string $tool, array $input ): array {
		// Drop Grok-only bookkeeping keys.
		unset( $input['variant'], $input['is_background'] );

		if ( isset( $input['target_file'] ) && ! isset( $input['file_path'] ) ) {
			$input['file_path'] = $input['target_file'];
		}
		if ( isset( $input['path'] ) && ! isset( $input['file_path'] ) ) {
			$input['file_path'] = $input['path'];
		}
		if ( isset( $input['filePath'] ) && ! isset( $input['file_path'] ) ) {
			$input['file_path'] = $input['filePath'];
		}
		if ( isset( $input['target_directory'] ) && ! isset( $input['path'] ) ) {
			// list_dir → treat as glob-ish path for display
			$input['path'] = $input['target_directory'];
			if ( ! isset( $input['pattern'] ) ) {
				$input['pattern'] = $input['target_directory'];
			}
		}
		if ( $tool === 'Bash' && isset( $input['description'] ) && ! isset( $input['description_shown'] ) ) {
			// keep description; describeToolCall may use command
		}
		if ( isset( $input['old_string'] ) && ! isset( $input['old_str'] ) ) {
			// Edit helpers look at old_string/new_string or similar - leave as-is
		}
		return $input;
	}

	private static function normalizeTodoStatus( string $status ): string {
		static $map = [
			'done'        => 'completed',
			'completed'   => 'completed',
			'in_progress' => 'in_progress',
			'pending'     => 'pending',
			'cancelled'   => 'cancelled',
			'canceled'    => 'cancelled',
		];
		return $map[ strtolower( $status ) ] ?? $status;
	}
}
