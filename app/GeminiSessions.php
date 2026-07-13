<?php

/**
 * Gemini CLI session provider - reads from ~/.gemini/tmp.
 *
 * Layout (see Gemini CLI session-management docs):
 *
 *   ~/.gemini/tmp/<project-dir>/chats/session-*.json|jsonl
 *   ~/.gemini/tmp/<project-dir>/.project_root   - absolute project path (when present)
 *   ~/.gemini/projects.json                     - { projects: { "/path": "folder-name" } }
 *
 * <project-dir> is either a human slug (from projects.json) or sha256(projectRoot).
 * Each session file stores sessionId (UUID), projectHash, startTime, lastUpdated,
 * and messages (user / gemini / info / error). Newer files may be JSONL with a
 * header line plus append-only message / $set patches.
 *
 * GEMINI_HOME overrides the base directory (default ~/.gemini).
 */
class GeminiSessions {

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'gemini';
	}

	public static function sourceLabel(): string {
		return 'Gemini CLI';
	}

	public static function hasSession( string $sessionId ): bool {
		return self::findSessionFile( $sessionId ) !== null;
	}

	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		if ( ! self::isValidSessionId( $id ) ) {
			return null;
		}
		foreach ( self::iterSessionFiles() as $file ) {
			$meta = self::readSessionMeta( $file );
			if ( $meta && ( $meta['sessionId'] ?? '' ) === $id ) {
				return $file;
			}
		}
		return null;
	}

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
	 * Sum tokens from gemini messages: {input, output, cached, thoughts, tool, total}.
	 * Map cached → cache_read; thoughts folded into output (generated tokens).
	 */
	public static function extractUsage( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? null );
		if ( ! $file ) {
			return null;
		}
		$data = self::loadSession( $file );
		if ( ! $data ) {
			return null;
		}

		$input = $output = $cache = 0;
		$found = false;
		foreach ( $data['messages'] as $msg ) {
			$t = $msg['tokens'] ?? null;
			if ( ! is_array( $t ) ) {
				continue;
			}
			$found  = true;
			$input += (int) ( $t['input'] ?? 0 );
			$output += (int) ( $t['output'] ?? 0 ) + (int) ( $t['thoughts'] ?? 0 ) + (int) ( $t['tool'] ?? 0 );
			$cache += (int) ( $t['cached'] ?? 0 );
		}

		if ( ! $found ) {
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
	 * Concat user + gemini text for FTS. Tool I/O and info/error noise skipped.
	 */
	public static function extractSessionText( array $session ): string {
		$parts    = [];
		$maxChars = 10000;

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		$file = self::findSessionFile( $session['id'] ?? '' );
		if ( ! $file ) {
			return implode( "\n", $parts );
		}
		$data = self::loadSession( $file );
		if ( ! $data ) {
			return implode( "\n", $parts );
		}

		foreach ( $data['messages'] as $msg ) {
			$type = $msg['type'] ?? '';
			if ( $type !== 'user' && $type !== 'gemini' ) {
				continue;
			}
			$text = trim( self::contentText( $msg['content'] ?? null ) );
			if ( $text !== '' ) {
				$parts[] = mb_substr( $text, 0, $maxChars );
			}
		}

		return implode( "\n", $parts );
	}

	// ─── Listing ────────────────────────────────────────────────

	public static function listSessions( ?string $project = null ): array {
		$out      = [];
		$pathMap  = self::projectPathMap();

		foreach ( self::iterSessionFiles() as $file ) {
			$meta = self::readSessionMeta( $file );
			if ( ! $meta || empty( $meta['sessionId'] ) ) {
				continue;
			}

			$projectDir = basename( dirname( dirname( $file ) ) ); // .../tmp/<dir>/chats/file
			$path       = self::resolveProjectPath( $projectDir, $meta['projectHash'] ?? '', $pathMap );
			if ( $project !== null && $project !== '' && $path !== $project ) {
				continue;
			}

			$updatedMs = self::parseIsoMs( $meta['lastUpdated'] ?? $meta['startTime'] ?? null );
			$createdMs = self::parseIsoMs( $meta['startTime'] ?? null );
			if ( ! $updatedMs ) {
				$updatedMs = ( (int) @filemtime( $file ) ) * 1000;
			}
			if ( ! $createdMs ) {
				$createdMs = $updatedMs;
			}

			$display = trim( (string) ( $meta['display'] ?? '' ) );
			if ( $display === '' ) {
				$display = self::firstUserPrompt( $file, $meta );
			}

			$out[] = [
				'id'          => $meta['sessionId'],
				'display'     => $display,
				'timestamp'   => $updatedMs,
				'timestamp_s' => (int) floor( $updatedMs / 1000 ),
				'project'     => $path,
				'projectName' => Helpers::projectDisplayName( $path ),
				'size'        => (int) @filesize( $file ),
				'created'     => $createdMs,
				'model'       => $meta['model'] ?? '',
			];
		}

		usort( $out, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		return $out;
	}

	public static function listProjects(): array {
		$byPath  = [];
		$pathMap = self::projectPathMap();

		foreach ( self::iterSessionFiles() as $file ) {
			$meta = self::readSessionMeta( $file );
			if ( ! $meta || empty( $meta['sessionId'] ) ) {
				continue;
			}
			$projectDir = basename( dirname( dirname( $file ) ) );
			$path       = self::resolveProjectPath( $projectDir, $meta['projectHash'] ?? '', $pathMap );
			if ( $path === '' ) {
				$path = '(unknown)';
			}

			$updatedMs = self::parseIsoMs( $meta['lastUpdated'] ?? null )
				?: ( (int) @filemtime( $file ) ) * 1000;

			if ( ! isset( $byPath[ $path ] ) ) {
				$byPath[ $path ] = [
					'path'     => $path,
					'name'     => $path === '(unknown)' ? '(unknown)' : Helpers::projectDisplayName( $path ),
					'sessions' => 0,
					'latest'   => 0,
				];
			}
			$byPath[ $path ]['sessions']++;
			$byPath[ $path ]['latest'] = max( $byPath[ $path ]['latest'], $updatedMs );
		}

		$out = array_values( $byPath );
		usort( $out, fn( $a, $b ) => $b['latest'] <=> $a['latest'] );
		return $out;
	}

	// ─── Conversation ───────────────────────────────────────────

	public static function getConversation( string $sessionId ): array {
		$file = self::findSessionFile( $sessionId );
		if ( ! $file ) {
			return [];
		}
		$data = self::loadSession( $file );
		if ( ! $data ) {
			return [];
		}

		$events = [
			[
				'type'       => 'init',
				'model'      => $data['model'] ?? 'gemini',
				'session_id' => $sessionId,
				'skills'     => [],
			],
		];

		foreach ( $data['messages'] as $msg ) {
			$type = $msg['type'] ?? '';
			if ( $type === 'user' ) {
				$text = trim( self::contentText( $msg['content'] ?? null ) );
				if ( $text !== '' ) {
					$events[] = [ 'type' => 'user_message', 'text' => $text ];
				}
				continue;
			}

			if ( $type === 'gemini' ) {
				$text = trim( self::contentText( $msg['content'] ?? null ) );
				if ( $text !== '' ) {
					$events[] = [ 'type' => 'text', 'text' => $text ];
				}
				foreach ( $msg['toolCalls'] ?? [] as $tc ) {
					if ( ! is_array( $tc ) ) {
						continue;
					}
					$rawName = (string) ( $tc['name'] ?? 'tool' );
					$tool    = self::normalizeToolName( $rawName );
					$input   = is_array( $tc['args'] ?? null ) ? $tc['args'] : [];
					$input   = self::normalizeToolInput( $tool, $input );

					$events[] = [
						'type'     => 'tool_call',
						'tool'     => $tool,
						'category' => ClaudeSessions::toolCategory( $tool ),
						'label'    => ClaudeSessions::describeToolCall( $tool, $input ),
					];

					$resultText = self::toolResultText( $tc );
					if ( $resultText !== '' ) {
						$preview = ClaudeSessions::cleanResultText( mb_substr( $resultText, 0, 500 ) );
						$events[] = [
							'type'    => 'tool_result',
							'preview' => $preview,
							'length'  => mb_strlen( $resultText ),
						];
					}
				}
				continue;
			}

			// info / error - surface short text when useful
			if ( $type === 'error' ) {
				$text = trim( self::contentText( $msg['content'] ?? null ) );
				if ( $text !== '' ) {
					$events[] = [ 'type' => 'text', 'text' => $text ];
				}
			}
		}

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

	// ─── Paths ──────────────────────────────────────────────────

	public static function dataDir(): string {
		$override = getenv( 'GEMINI_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		return rtrim( $home, '/' ) . '/.gemini';
	}

	private static function tmpDir(): string {
		return self::dataDir() . '/tmp';
	}

	/**
	 * Yield absolute paths to session-*.json / session-*.jsonl under each
	 * project chats directory (tmp/<project>/chats/).
	 *
	 * @return \Generator<int,string>
	 */
	private static function iterSessionFiles(): \Generator {
		$root = self::tmpDir();
		if ( ! is_dir( $root ) ) {
			return;
		}
		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] as $projectDir ) {
			$name = basename( $projectDir );
			if ( $name === 'bin' || $name === '' || $name[0] === '.' ) {
				continue;
			}
			$chats = $projectDir . '/chats';
			if ( ! is_dir( $chats ) ) {
				continue;
			}
			foreach ( array_merge(
				glob( $chats . '/session-*.json' ) ?: [],
				glob( $chats . '/session-*.jsonl' ) ?: []
			) as $file ) {
				if ( is_file( $file ) ) {
					yield $file;
				}
			}
		}
	}

	/**
	 * folder-name → absolute path from projects.json + .project_root files.
	 *
	 * @return array<string,string>
	 */
	private static function projectPathMap(): array {
		$map  = [];
		$file = self::dataDir() . '/projects.json';
		if ( is_file( $file ) ) {
			$raw = @file_get_contents( $file );
			$data = $raw !== false ? json_decode( $raw, true ) : null;
			if ( is_array( $data ) ) {
				$projects = $data['projects'] ?? $data;
				if ( is_array( $projects ) ) {
					foreach ( $projects as $path => $folder ) {
						if ( is_string( $path ) && is_string( $folder ) && $folder !== '' ) {
							$map[ $folder ] = $path;
						}
					}
				}
			}
		}

		$root = self::tmpDir();
		if ( is_dir( $root ) ) {
			foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] as $dir ) {
				$folder = basename( $dir );
				$pr     = $dir . '/.project_root';
				if ( is_file( $pr ) ) {
					$path = trim( (string) @file_get_contents( $pr ) );
					if ( $path !== '' ) {
						$map[ $folder ] = $path;
					}
				}
			}
		}

		return $map;
	}

	private static function resolveProjectPath( string $projectDir, string $projectHash, array $pathMap ): string {
		if ( isset( $pathMap[ $projectDir ] ) ) {
			return $pathMap[ $projectDir ];
		}
		// Hash-named dirs: reverse map via sha256 of known paths.
		if ( $projectHash !== '' && preg_match( '/^[0-9a-f]{64}$/i', $projectHash ) ) {
			foreach ( $pathMap as $path ) {
				if ( hash( 'sha256', $path ) === $projectHash ) {
					return $path;
				}
			}
		}
		if ( preg_match( '/^[0-9a-f]{64}$/i', $projectDir ) ) {
			foreach ( $pathMap as $path ) {
				if ( hash( 'sha256', $path ) === $projectDir ) {
					return $path;
				}
			}
			return '(unknown)';
		}
		// Human slug without mapping - use as display-ish path placeholder.
		return $projectDir !== '' ? $projectDir : '(unknown)';
	}

	// ─── Session file parsing ───────────────────────────────────

	/**
	 * Lightweight header for listing (avoids loading huge message arrays twice).
	 *
	 * @return array{sessionId?:string,projectHash?:string,startTime?:string,lastUpdated?:string,display?:string,model?:string}|null
	 */
	private static function readSessionMeta( string $file ): ?array {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( $ext === 'jsonl' ) {
			$fh = @fopen( $file, 'r' );
			if ( ! $fh ) {
				return null;
			}
			$meta = null;
			while ( ( $line = fgets( $fh ) ) !== false ) {
				$line = trim( $line );
				if ( $line === '' ) {
					continue;
				}
				$obj = json_decode( $line, true );
				if ( ! is_array( $obj ) ) {
					continue;
				}
				if ( isset( $obj['sessionId'] ) ) {
					$meta = $obj;
					continue;
				}
				if ( isset( $obj['$set'] ) && is_array( $obj['$set'] ) && $meta ) {
					$meta = array_merge( $meta, $obj['$set'] );
				}
			}
			fclose( $fh );
			return $meta;
		}

		$raw = @file_get_contents( $file );
		if ( $raw === false ) {
			return null;
		}
		// Huge files: still need full json for lastUpdated in object form - accept cost.
		$obj = json_decode( $raw, true );
		return is_array( $obj ) ? $obj : null;
	}

	/**
	 * Full session: meta + messages[].
	 *
	 * @return array{sessionId?:string,projectHash?:string,startTime?:string,lastUpdated?:string,model?:string,messages:array}|null
	 */
	private static function loadSession( string $file ): ?array {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( $ext === 'jsonl' ) {
			$fh = @fopen( $file, 'r' );
			if ( ! $fh ) {
				return null;
			}
			$meta     = [ 'messages' => [] ];
			$messages = [];
			while ( ( $line = fgets( $fh ) ) !== false ) {
				$line = trim( $line );
				if ( $line === '' ) {
					continue;
				}
				$obj = json_decode( $line, true );
				if ( ! is_array( $obj ) ) {
					continue;
				}
				if ( isset( $obj['sessionId'] ) && ! isset( $obj['type'] ) ) {
					$meta = array_merge( $meta, $obj );
					if ( ! isset( $meta['messages'] ) || ! is_array( $meta['messages'] ) ) {
						$meta['messages'] = [];
					}
					continue;
				}
				if ( isset( $obj['$set'] ) && is_array( $obj['$set'] ) ) {
					$meta = array_merge( $meta, $obj['$set'] );
					continue;
				}
				if ( isset( $obj['type'] ) ) {
					$messages[] = $obj;
				}
			}
			fclose( $fh );
			$meta['messages'] = $messages;
			return $meta;
		}

		$raw = @file_get_contents( $file );
		if ( $raw === false ) {
			return null;
		}
		$obj = json_decode( $raw, true );
		if ( ! is_array( $obj ) ) {
			return null;
		}
		if ( ! isset( $obj['messages'] ) || ! is_array( $obj['messages'] ) ) {
			$obj['messages'] = [];
		}
		return $obj;
	}

	private static function firstUserPrompt( string $file, array $meta ): string {
		// Prefer messages already in meta if present (full json).
		if ( ! empty( $meta['messages'] ) && is_array( $meta['messages'] ) ) {
			foreach ( $meta['messages'] as $msg ) {
				if ( ( $msg['type'] ?? '' ) === 'user' ) {
					$text = trim( self::contentText( $msg['content'] ?? null ) );
					if ( $text !== '' ) {
						return mb_substr( $text, 0, 120 );
					}
				}
			}
		}
		$data = self::loadSession( $file );
		if ( ! $data ) {
			return '';
		}
		foreach ( $data['messages'] as $msg ) {
			if ( ( $msg['type'] ?? '' ) === 'user' ) {
				$text = trim( self::contentText( $msg['content'] ?? null ) );
				if ( $text !== '' ) {
					return mb_substr( $text, 0, 120 );
				}
			}
		}
		return '';
	}

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
		if ( array_is_list( $content ) ) {
			$out = '';
			foreach ( $content as $block ) {
				$out .= self::contentText( $block );
			}
			return $out;
		}
		return '';
	}

	private static function toolResultText( array $tc ): string {
		$result = $tc['result'] ?? null;
		if ( is_string( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return '';
		}
		// Common Gemini shapes: [{functionResponse:{response:{output:"…"}}}]
		$parts = [];
		foreach ( $result as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$fr = $block['functionResponse']['response'] ?? null;
			if ( is_array( $fr ) ) {
				if ( isset( $fr['output'] ) && is_string( $fr['output'] ) ) {
					$parts[] = $fr['output'];
				} elseif ( isset( $fr['error'] ) && is_string( $fr['error'] ) ) {
					$parts[] = $fr['error'];
				} else {
					$parts[] = json_encode( $fr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
			} elseif ( isset( $block['text'] ) ) {
				$parts[] = (string) $block['text'];
			}
		}
		return trim( implode( "\n", array_filter( $parts ) ) );
	}

	private static function normalizeToolName( string $name ): string {
		static $map = [
			'read_file'       => 'Read',
			'write_file'      => 'Write',
			'replace'         => 'Edit',
			'search_file_content' => 'Grep',
			'glob'            => 'Glob',
			'list_directory'  => 'Glob',
			'run_shell_command' => 'Bash',
			'shell'           => 'Bash',
			'web_fetch'       => 'WebFetch',
			'web_search'      => 'WebSearch',
			'read_many_files' => 'Read',
			'save_memory'     => 'TodoWrite',
		];
		$key = strtolower( $name );
		return $map[ $key ] ?? $name;
	}

	private static function normalizeToolInput( string $tool, array $input ): array {
		if ( isset( $input['absolute_path'] ) && ! isset( $input['file_path'] ) ) {
			$input['file_path'] = $input['absolute_path'];
		}
		if ( isset( $input['path'] ) && ! isset( $input['file_path'] ) && $tool !== 'Bash' ) {
			$input['file_path'] = $input['path'];
		}
		if ( isset( $input['command'] ) ) {
			// already fine for Bash
		}
		return $input;
	}

	private static function isValidSessionId( string $id ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

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
}
