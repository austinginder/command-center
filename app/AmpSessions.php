<?php

/**
 * Amp session provider - reads local thread snapshots from ~/.local/share/amp/threads.
 *
 * Amp stores each thread as one JSON document containing its title, workspace trees,
 * messages, tool calls/results, and per-inference token usage. AMP_HOME overrides the
 * data directory (default $XDG_DATA_HOME/amp, then ~/.local/share/amp).
 */
class AmpSessions {

	public static function sourceId(): string {
		return 'amp';
	}

	public static function sourceLabel(): string {
		return 'Amp';
	}

	public static function hasSession( string $sessionId ): bool {
		if ( ! self::isValidSessionId( $sessionId ) ) {
			return false;
		}
		if ( self::findSessionFile( $sessionId ) !== null ) {
			return true;
		}
		foreach ( self::remoteThreads() as $thread ) {
			if ( ( $thread['id'] ?? '' ) === $sessionId ) {
				return true;
			}
		}
		return false;
	}

	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		if ( ! self::isValidSessionId( $id ) ) {
			return null;
		}
		$local = self::threadsDir() . '/' . $id . '.json';
		$cached = self::cacheDir() . '/' . $id . '.json';
		$files  = array_values( array_filter( [ $cached, $local ], 'is_file' ) );
		usort( $files, fn( $a, $b ) => (int) @filemtime( $b ) <=> (int) @filemtime( $a ) );
		return $files[0] ?? null;
	}

	public static function fingerprint( array $session ): ?array {
		if ( isset( $session['remote_updated'] ) ) {
			$file = self::findSessionFile( $session['id'] ?? '' );
			return [
				'mtime' => max( (int) $session['remote_updated'], (int) ( $file ? @filemtime( $file ) * 1000 : 0 ) ),
				'size'  => max( (int) ( $session['message_count'] ?? 0 ), (int) ( $file ? @filesize( $file ) : 0 ) ),
			];
		}
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
	 * Index user and assistant prose, excluding thinking and tool output.
	 */
	public static function extractSessionText( array $session ): string {
		$thread = self::loadThread( $session['id'] ?? '', (int) ( $session['remote_updated'] ?? 0 ), true );
		if ( ! $thread ) {
			return '';
		}

		$parts = [];
		if ( ! empty( $thread['title'] ) ) {
			$parts[] = $thread['title'];
		}

		foreach ( $thread['messages'] ?? [] as $message ) {
			foreach ( $message['content'] ?? [] as $block ) {
				if ( ( $block['type'] ?? '' ) !== 'text' ) {
					continue;
				}
				$text = trim( (string) ( $block['text'] ?? '' ) );
				if ( $text !== '' ) {
					$parts[] = mb_substr( $text, 0, 10000 );
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Amp records usage on each assistant inference message.
	 */
	public static function extractUsage( array $session ): ?array {
		$thread = self::loadThread( $session['id'] ?? '', (int) ( $session['remote_updated'] ?? 0 ), true );
		if ( ! $thread ) {
			return null;
		}

		$totals = [ 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0 ];
		$found  = false;

		foreach ( $thread['messages'] ?? [] as $message ) {
			$usage = $message['usage'] ?? null;
			if ( ( $message['role'] ?? '' ) !== 'assistant' || ! is_array( $usage ) ) {
				continue;
			}
			$totals['input']          += (int) ( $usage['inputTokens'] ?? 0 );
			$totals['output']         += (int) ( $usage['outputTokens'] ?? 0 );
			$totals['cache_read']     += (int) ( $usage['cacheReadInputTokens'] ?? 0 );
			$totals['cache_creation'] += (int) ( $usage['cacheCreationInputTokens'] ?? 0 );
			$found = true;
		}

		return $found ? $totals : null;
	}

	public static function listSessions( ?string $project = null ): array {
		$root = self::threadsDir();

		$byId = [];
		foreach ( is_dir( $root ) ? ( glob( $root . '/T-*.json' ) ?: [] ) : [] as $file ) {
			$thread = self::readJson( $file );
			$id     = $thread['id'] ?? basename( $file, '.json' );
			if ( ! $thread || ! self::isValidSessionId( $id ) ) {
				continue;
			}

			$projectPath = self::projectPath( $thread );
			if ( $project !== null && $project !== '' && $project !== $projectPath ) {
				continue;
			}

			$updatedMs = self::updatedTimestamp( $thread, $file );
			$createdMs = (int) ( $thread['created'] ?? $updatedMs );
			$display   = trim( (string) ( $thread['title'] ?? '' ) );
			if ( $display === '' ) {
				$display = self::firstUserText( $thread );
			}

			$byId[ $id ] = [
				'id'          => $id,
				'display'     => $display,
				'timestamp'   => $updatedMs,
				'timestamp_s' => (int) floor( $updatedMs / 1000 ),
				'project'     => $projectPath,
				'projectName' => self::projectName( $projectPath, $thread ),
				'size'        => (int) ( @filesize( $file ) ?: 0 ),
				'created'     => $createdMs,
				'model'       => self::lastModel( $thread ),
			];
		}

		// Modern Amp versions keep the authoritative thread list on Amp Server.
		// Merge that metadata so recent threads appear even before they are exported.
		foreach ( self::remoteThreads() as $remote ) {
			$id = $remote['id'] ?? '';
			if ( ! self::isValidSessionId( $id ) ) {
				continue;
			}
			$projectPath = self::uriToPath( $remote['tree'] ?? '' );
			if ( $project !== null && $project !== '' && $project !== $projectPath ) {
				unset( $byId[ $id ] );
				continue;
			}
			$updatedMs = self::parseIsoMs( $remote['updated'] ?? null );
			$existing  = $byId[ $id ] ?? [];
			$byId[ $id ] = array_merge( $existing, [
				'id'             => $id,
				'display'        => trim( (string) ( $remote['title'] ?? ( $existing['display'] ?? '' ) ) ),
				'timestamp'      => $updatedMs ?: ( $existing['timestamp'] ?? 0 ),
				'timestamp_s'    => (int) floor( ( $updatedMs ?: ( $existing['timestamp'] ?? 0 ) ) / 1000 ),
				'project'        => $projectPath ?: ( $existing['project'] ?? '' ),
				'projectName'    => self::projectName( $projectPath ?: ( $existing['project'] ?? '' ), [] ),
				'size'           => $existing['size'] ?? 0,
				'created'        => $existing['created'] ?? $updatedMs,
				'model'          => $existing['model'] ?? '',
				'remote_updated' => $updatedMs,
				'message_count'  => (int) ( $remote['messageCount'] ?? 0 ),
			] );
		}

		$out = array_values( $byId );
		usort( $out, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		return $out;
	}

	public static function listProjects(): array {
		$byPath = [];
		foreach ( self::listSessions() as $session ) {
			$path = $session['project'] ?? '';
			if ( $path === '' ) {
				continue;
			}
			if ( ! isset( $byPath[ $path ] ) ) {
				$byPath[ $path ] = [
					'path'     => $path,
					'name'     => $session['projectName'] ?? basename( $path ),
					'sessions' => 0,
					'latest'   => 0,
				];
			}
			$byPath[ $path ]['sessions']++;
			$byPath[ $path ]['latest'] = max( $byPath[ $path ]['latest'], $session['timestamp'] ?? 0 );
		}

		$out = array_values( $byPath );
		usort( $out, fn( $a, $b ) => $b['latest'] <=> $a['latest'] );
		return $out;
	}

	public static function getConversation( string $sessionId ): array {
		$thread = self::loadThread( $sessionId, self::remoteUpdated( $sessionId ) );
		if ( ! $thread ) {
			return [];
		}

		$events = [
			[
				'type'       => 'init',
				'model'      => self::lastModel( $thread ) ?: 'amp',
				'session_id' => $sessionId,
				'skills'     => [],
			],
		];

		foreach ( $thread['messages'] ?? [] as $message ) {
			$role          = $message['role'] ?? '';
			$hasToolResult = false;

			foreach ( $message['content'] ?? [] as $block ) {
				$type = $block['type'] ?? '';

				if ( $role === 'user' && $type === 'tool_result' ) {
					$hasToolResult = true;
					$text = self::toolResultText( $block['run']['result'] ?? null );
					if ( $text !== '' ) {
						$events[] = [
							'type'    => 'tool_result',
							'preview' => ClaudeSessions::cleanResultText( mb_substr( $text, 0, 500 ) ),
							'length'  => mb_strlen( $text ),
						];
					}
					continue;
				}

				if ( $role === 'assistant' && $type === 'text' ) {
					$text = trim( (string) ( $block['text'] ?? '' ) );
					if ( $text !== '' ) {
						$events[] = [ 'type' => 'text', 'text' => $text ];
					}
					continue;
				}

				if ( $role === 'assistant' && $type === 'tool_use' ) {
					$tool  = (string) ( $block['name'] ?? 'tool' );
					$input = is_array( $block['input'] ?? null ) ? $block['input'] : [];
					$events[] = [
						'type'     => 'tool_call',
						'tool'     => $tool,
						'category' => self::toolCategory( $tool ),
						'label'    => self::describeToolCall( $input ),
					];
				}
			}

			if ( $role === 'user' && ! $hasToolResult ) {
				foreach ( $message['content'] ?? [] as $block ) {
					if ( ( $block['type'] ?? '' ) !== 'text' ) {
						continue;
					}
					$text = trim( (string) ( $block['text'] ?? '' ) );
					if ( $text !== '' ) {
						$events[] = [ 'type' => 'user_message', 'text' => $text ];
					}
				}
			}

			if ( $role === 'assistant' && ( $message['state']['stopReason'] ?? '' ) === 'end_turn' ) {
				$usage = $message['usage'] ?? null;
				$events[] = [
					'type'     => 'complete',
					'text'     => '',
					'usage'    => is_array( $usage ) ? [
						'input'          => (int) ( $usage['inputTokens'] ?? 0 ),
						'output'         => (int) ( $usage['outputTokens'] ?? 0 ),
						'cache_read'     => (int) ( $usage['cacheReadInputTokens'] ?? 0 ),
						'cache_creation' => (int) ( $usage['cacheCreationInputTokens'] ?? 0 ),
					] : null,
					'duration' => $message['turnElapsedMs'] ?? null,
					'turns'    => null,
				];
			}
		}

		return $events;
	}

	/**
	 * Automatic search refreshes must not hydrate remote-only threads while the
	 * SQLite index holds its write transaction. Explicit reindex may fetch them.
	 */
	public static function canIndexWithoutFetch( array $session ): bool {
		$file = self::findSessionFile( $session['id'] ?? '' );
		if ( ! $file ) {
			return false;
		}
		$remoteUpdated = (int) ( $session['remote_updated'] ?? 0 );
		if ( $remoteUpdated > 0 && (int) @filemtime( $file ) < (int) floor( $remoteUpdated / 1000 ) ) {
			return false;
		}
		$thread = self::readJson( $file );
		return is_array( $thread ) && ! self::isProvisional( $thread );
	}

	/**
	 * Amp rewrites a complete JSON snapshot rather than appending JSONL, so replay
	 * the latest valid snapshot and close instead of tailing a file mid-write.
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

		$eventId     = 0;
		$lastEventId = intval( $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0 );
		foreach ( self::getConversation( $sessionId ) as $event ) {
			$eventId++;
			if ( $eventId <= $lastEventId ) {
				continue;
			}
			echo "id: $eventId\n";
			echo "event: {$event['type']}\n";
			echo 'data: ' . json_encode( $event ) . "\n\n";
		}

		echo 'id: ' . ( ++$eventId ) . "\n";
		echo "event: done\n";
		echo 'data: ' . json_encode( [ 'reason' => 'history-only' ] ) . "\n\n";
		flush();
	}

	public static function dataDir(): string {
		$override = getenv( 'AMP_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		$xdg  = getenv( 'XDG_DATA_HOME' );
		return rtrim( $xdg ?: $home . '/.local/share', '/' ) . '/amp';
	}

	private static function threadsDir(): string {
		return self::dataDir() . '/threads';
	}

	private static function cacheDir(): string {
		$base = defined( 'DATA_DIR' ) ? DATA_DIR : sys_get_temp_dir() . '/command-center';
		return rtrim( $base, '/' ) . '/amp-threads';
	}

	private static function listCacheFile(): string {
		$base = defined( 'DATA_DIR' ) ? DATA_DIR : sys_get_temp_dir() . '/command-center';
		return rtrim( $base, '/' ) . '/amp-thread-list.json';
	}

	private static function isValidSessionId( string $id ): bool {
		return (bool) preg_match( '/^T-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

	private static function readJson( ?string $file ): ?array {
		if ( ! $file || ! is_file( $file ) ) {
			return null;
		}
		$raw = @file_get_contents( $file );
		if ( $raw === false ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	private static function loadThread( string $id, int $remoteUpdated = 0, bool $requireFresh = false ): ?array {
		if ( ! self::isValidSessionId( $id ) ) {
			return null;
		}

		$local  = self::threadsDir() . '/' . $id . '.json';
		$cached = self::cacheDir() . '/' . $id . '.json';
		$files  = array_values( array_filter( [ $cached, $local ], 'is_file' ) );
		usort( $files, fn( $a, $b ) => (int) @filemtime( $b ) <=> (int) @filemtime( $a ) );
		$file = $files[0] ?? null;

		if ( $file && ( $remoteUpdated === 0 || (int) @filemtime( $file ) >= (int) floor( $remoteUpdated / 1000 ) ) ) {
			$thread = self::readJson( $file );
			if ( $thread && ! self::isProvisional( $thread ) ) {
				return $thread;
			}
			if ( $thread && ! $requireFresh && time() - (int) @filemtime( $file ) < 30 ) {
				return $thread;
			}
		}

		$exported = self::runAmp( [ 'threads', 'export', $id ] );
		$thread   = $exported !== null ? json_decode( $exported, true ) : null;
		if ( ! is_array( $thread ) ) {
			return $requireFresh ? null : ( $file ? self::readJson( $file ) : null );
		}

		$dir = self::cacheDir();
		if ( ( is_dir( $dir ) || @mkdir( $dir, 0700, true ) ) && is_writable( $dir ) ) {
			@file_put_contents( $cached, $exported, LOCK_EX );
			if ( $remoteUpdated > 0 ) {
				@touch( $cached, (int) floor( $remoteUpdated / 1000 ) );
			}
		}
		return $requireFresh && self::isProvisional( $thread ) ? null : $thread;
	}

	private static function isProvisional( array $thread ): bool {
		$state = strtolower( (string) ( $thread['meta']['lastKnownAgentState']['state'] ?? '' ) );
		return in_array( $state, [ 'streaming', 'running', 'working' ], true );
	}

	private static function remoteThreads(): array {
		static $threads = null;
		if ( is_array( $threads ) ) {
			return $threads;
		}

		$cache = self::listCacheFile();
		if ( is_file( $cache ) && ( time() - (int) @filemtime( $cache ) ) < 60 ) {
			$threads = self::readJson( $cache );
			if ( is_array( $threads ) ) {
				return $threads;
			}
		}

		$threads = [];
		$offset  = 0;
		do {
			$raw  = self::runAmp( [ 'threads', 'list', '--json', '--include-archived', '--limit', '200', '--offset', (string) $offset ] );
			$page = $raw !== null ? json_decode( $raw, true ) : null;
			if ( ! is_array( $page ) ) {
				$cached = self::readJson( $cache );
				return is_array( $cached ) ? $cached : [];
			}
			$threads = array_merge( $threads, $page );
			$offset += count( $page );
		} while ( count( $page ) === 200 );

		$dir = dirname( $cache );
		if ( ( is_dir( $dir ) || @mkdir( $dir, 0700, true ) ) && is_writable( $dir ) ) {
			@file_put_contents( $cache, json_encode( $threads, JSON_UNESCAPED_SLASHES ), LOCK_EX );
		}
		return $threads;
	}

	private static function remoteUpdated( string $id ): int {
		foreach ( self::remoteThreads() as $thread ) {
			if ( ( $thread['id'] ?? '' ) === $id ) {
				return self::parseIsoMs( $thread['updated'] ?? null );
			}
		}
		return 0;
	}

	private static function runAmp( array $args ): ?string {
		$override = getenv( 'AMP_BIN' );
		$home     = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		$binary   = $override ?: ( is_executable( $home . '/.local/bin/amp' ) ? $home . '/.local/bin/amp' : 'amp' );
		$pipes    = [];
		$process  = @proc_open(
			array_merge( [ $binary ], $args ),
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			null,
			null,
			[ 'bypass_shell' => true ]
		);
		if ( ! is_resource( $process ) ) {
			return null;
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$output   = '';
		$deadline = microtime( true ) + 20;
		do {
			$output .= stream_get_contents( $pipes[1] );
			stream_get_contents( $pipes[2] );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				break;
			}
			usleep( 50000 );
		} while ( microtime( true ) < $deadline );

		if ( $status['running'] ?? false ) {
			proc_terminate( $process );
		}
		$output .= stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		return trim( $output ) !== '' ? $output : null;
	}

	private static function projectPath( array $thread ): string {
		$uri = $thread['env']['initial']['trees'][0]['uri'] ?? '';
		return self::uriToPath( is_string( $uri ) ? $uri : '' );
	}

	private static function uriToPath( string $uri ): string {
		if ( $uri === '' ) {
			return '';
		}
		if ( str_starts_with( $uri, 'file://' ) ) {
			$path = parse_url( $uri, PHP_URL_PATH );
			return is_string( $path ) ? rawurldecode( $path ) : '';
		}
		return $uri;
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

	private static function projectName( string $path, array $thread ): string {
		$name = basename( $path );
		if ( in_array( $name, [ 'public', 'htdocs', 'www' ], true ) ) {
			$parent = basename( dirname( $path ) );
			if ( $parent !== '' && $parent !== '.' && $parent !== '/' ) {
				return $parent;
			}
		}
		return $name ?: (string) ( $thread['env']['initial']['trees'][0]['displayName'] ?? '' );
	}

	private static function updatedTimestamp( array $thread, string $file ): int {
		$latest = (int) ( $thread['created'] ?? 0 );
		foreach ( $thread['messages'] ?? [] as $message ) {
			$latest = max( $latest, (int) ( $message['meta']['sentAt'] ?? 0 ) );
			$usageTs = $message['usage']['timestamp'] ?? null;
			if ( is_string( $usageTs ) && $usageTs !== '' ) {
				$parsed = strtotime( $usageTs );
				if ( $parsed !== false ) {
					$latest = max( $latest, $parsed * 1000 );
				}
			}
		}
		return max( $latest, (int) ( @filemtime( $file ) ?: 0 ) * 1000 );
	}

	private static function firstUserText( array $thread ): string {
		foreach ( $thread['messages'] ?? [] as $message ) {
			if ( ( $message['role'] ?? '' ) !== 'user' ) {
				continue;
			}
			foreach ( $message['content'] ?? [] as $block ) {
				if ( ( $block['type'] ?? '' ) === 'text' && ! empty( trim( $block['text'] ?? '' ) ) ) {
					return mb_substr( trim( $block['text'] ), 0, 160 );
				}
			}
		}
		return '';
	}

	private static function lastModel( array $thread ): string {
		$model = '';
		foreach ( $thread['messages'] ?? [] as $message ) {
			if ( ! empty( $message['usage']['model'] ) ) {
				$model = (string) $message['usage']['model'];
			}
		}
		return $model;
	}

	private static function toolCategory( string $tool ): string {
		$name = strtolower( $tool );
		if ( str_contains( $name, 'shell' ) || in_array( $name, [ 'bash', 'manual_bash_invocation' ], true ) ) {
			return 'shell';
		}
		if ( in_array( $name, [ 'read', 'write', 'edit', 'apply_patch', 'create_file', 'view_media' ], true ) ) {
			return 'file';
		}
		if ( in_array( $name, [ 'grep', 'glob', 'finder' ], true ) ) {
			return 'search';
		}
		if ( in_array( $name, [ 'task', 'oracle' ], true ) ) {
			return 'agent';
		}
		if ( in_array( $name, [ 'web_search', 'websearch', 'read_web_page', 'webfetch' ], true ) ) {
			return 'web';
		}
		return $name === 'skill' ? 'skill' : 'other';
	}

	private static function describeToolCall( array $input ): string {
		foreach ( [ 'description', 'query', 'command', 'cmd', 'prompt', 'path', 'file_path', 'url', 'name' ] as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && $input[ $key ] !== '' ) {
				return mb_substr( $input[ $key ], 0, 150 );
			}
		}
		return implode( ', ', array_slice( array_keys( $input ), 0, 5 ) );
	}

	private static function toolResultText( $result ): string {
		if ( is_string( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return '';
		}
		foreach ( [ 'output', 'content', 'diff', 'error', 'message' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				return $result[ $key ];
			}
		}
		$encoded = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}
}
