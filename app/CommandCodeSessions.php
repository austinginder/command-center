<?php

class CommandCodeSessions {

	private static ?string $cmdDir = null;

	/**
	 * Resolve the Command Code home directory (env override → ~/.commandcode).
	 */
	private static function cmdDir(): string {
		if ( self::$cmdDir === null ) {
			$override = getenv( 'COMMANDCODE_HOME' );
			$home     = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
			self::$cmdDir = $override ?: $home . '/.commandcode';
		}
		return self::$cmdDir;
	}

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'commandcode';
	}

	public static function sourceLabel(): string {
		return 'Command Code';
	}

	public static function hasSession( string $sessionId ): bool {
		return self::findSessionFileById( $sessionId ) !== null;
	}

	public static function fingerprint( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? '' );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		clearstatcache( true, $file );
		return [
			'mtime' => (int) filemtime( $file ),
			'size'  => (int) filesize( $file ),
		];
	}

	public static function extractSessionText( array $session ): string {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? '' );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}

		$fp = fopen( $file, 'r' );
		if ( ! $fp ) {
			return '';
		}

		$parts    = [];
		$maxChars = 10000;

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$obj = json_decode( $line, true );
			if ( ! $obj ) {
				continue;
			}

			$role = $obj['role'] ?? '';

			if ( $role === 'user' ) {
				foreach ( self::normalizeContent( $obj['content'] ) as $block ) {
					if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
						$parts[] = mb_substr( $block['text'], 0, $maxChars );
					}
				}
			} elseif ( $role === 'assistant' ) {
				foreach ( self::normalizeContent( $obj['content'] ) as $block ) {
					if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
						$parts[] = mb_substr( $block['text'], 0, $maxChars );
					}
				}
			}
		}

		fclose( $fp );

		return implode( "\n", $parts );
	}

	// ─── Sessions ───────────────────────────────────────────────

	public static function listSessions( ?string $project = null ): array {
		$projectsDir = self::cmdDir() . '/projects';
		if ( ! is_dir( $projectsDir ) ) {
			return [];
		}

		$sessions = [];

		foreach ( scandir( $projectsDir ) as $dir ) {
			if ( $dir[0] === '.' ) {
				continue;
			}

			$sessionDir = $projectsDir . '/' . $dir;
			if ( ! is_dir( $sessionDir ) ) {
				continue;
			}

			if ( $project && self::encodeProjectPath( $project ) !== $dir ) {
				continue;
			}

			foreach ( scandir( $sessionDir ) as $file ) {
				if ( ! str_ends_with( $file, '.jsonl' ) || str_contains( $file, '.checkpoints.' ) ) {
					continue;
				}

				$sessionId = basename( $file, '.jsonl' );
				$jsonlPath = $sessionDir . '/' . $file;

				$display   = self::readDisplay( $sessionDir, $sessionId, $jsonlPath );
				$timestamp = self::readFirstTimestamp( $jsonlPath );
				$size      = filesize( $jsonlPath );

				$sessions[] = [
					'id'        => $sessionId,
					'display'   => $display,
					'timestamp' => $timestamp,
					'project'   => self::resolveProjectPath( $dir ),
					'size'      => $size,
				];
			}
		}

		usort( $sessions, fn( $a, $b ) => $b['timestamp'] - $a['timestamp'] );

		foreach ( $sessions as &$s ) {
			$s['projectName'] = self::decodeProjectName( $s['project'] );
			$s['timestamp_s'] = intval( $s['timestamp'] / 1000 );
			$s['size']        = $s['size'] ?? 0;
		}

		return $sessions;
	}

	public static function listProjects(): array {
		$projectsDir = self::cmdDir() . '/projects';
		if ( ! is_dir( $projectsDir ) ) {
			return [];
		}

		$projects = [];

		foreach ( scandir( $projectsDir ) as $dir ) {
			if ( $dir[0] === '.' ) {
				continue;
			}

			$sessionDir = $projectsDir . '/' . $dir;
			if ( ! is_dir( $sessionDir ) ) {
				continue;
			}

			$count  = 0;
			$latest = 0;

			foreach ( scandir( $sessionDir ) as $file ) {
				if ( ! str_ends_with( $file, '.jsonl' ) || str_contains( $file, '.checkpoints.' ) ) {
					continue;
				}

				$count++;
				$ts = self::readFirstTimestamp( $sessionDir . '/' . $file );
				if ( $ts > $latest ) {
					$latest = $ts;
				}
			}

			if ( $count > 0 ) {
				$resolved = self::resolveProjectPath( $dir );
				$projects[ $dir ] = [
					'path'     => $resolved,
					'name'     => self::decodeProjectName( $resolved ),
					'sessions' => $count,
					'latest'   => $latest,
				];
			}
		}

		$result = array_values( $projects );
		usort( $result, fn( $a, $b ) => $b['latest'] - $a['latest'] );
		return $result;
	}

	public static function getConversation( string $sessionId ): array {
		$file = self::findSessionFileById( $sessionId );
		if ( ! $file || ! file_exists( $file ) ) {
			return [];
		}

		$messages = [];
		$fp       = fopen( $file, 'r' );

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$obj = json_decode( $line, true );
			if ( ! $obj ) {
				continue;
			}

			$role = $obj['role'] ?? '';

			if ( $role === 'user' ) {
				$contentBlocks = is_array( $obj['content'] ) ? $obj['content'] : ( is_string( $obj['content'] ) ? [ [ 'type' => 'text', 'text' => $obj['content'] ] ] : [] );
				foreach ( $contentBlocks as $block ) {
					if ( ( $block['type'] ?? '' ) === 'text' && isset( $block['text'] ) && trim( $block['text'] ) !== '' ) {
						$messages[] = [
							'type' => 'user_message',
							'text' => $block['text'],
						];
					}
				}
			} elseif ( $role === 'assistant' ) {
				$contentBlocks = is_array( $obj['content'] ) ? $obj['content'] : [];
				foreach ( $contentBlocks as $block ) {
					$bt = $block['type'] ?? '';
					if ( $bt === 'text' && ! empty( trim( $block['text'] ?? '' ) ) ) {
						$messages[] = [
							'type' => 'text',
							'text' => $block['text'],
						];
					} elseif ( $bt === 'tool-call' ) {
						$toolName = $block['toolName'] ?? 'unknown';
						$input    = $block['input'] ?? [];
						$messages[] = [
							'type'     => 'tool_call',
							'tool'     => self::normalizeToolName( $toolName ),
							'category' => self::toolCategory( $toolName ),
							'label'    => self::describeToolCall( $toolName, $input ),
						];
					}
				}
			} elseif ( $role === 'tool' ) {
				foreach ( self::normalizeContent( $obj['content'] ) as $block ) {
					if ( ( $block['type'] ?? '' ) === 'tool-result' ) {
						$resultText = self::extractToolResultText( $block );
						if ( $resultText !== '' ) {
							$messages[] = [
								'type'    => 'tool_result',
								'preview' => mb_substr( $resultText, 0, 500 ),
								'length'  => mb_strlen( $resultText ),
							];
						}
					}
				}
			}
		}

		fclose( $fp );

		return $messages;
	}

	public static function handleStream( string $sessionId, int $runnerPid = 0 ): void {
		$file = self::findSessionFileById( $sessionId );
		if ( ! $file || ! file_exists( $file ) ) {
			http_response_code( 404 );
			echo json_encode( [ 'error' => 'Session not found' ] );
			exit;
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		@ini_set( 'max_execution_time', 0 );
		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'zlib.output_compression', false );
		while ( ob_get_level() ) {
			ob_end_flush();
		}

		$messages = self::getConversation( $sessionId );
		$eventId  = 0;

		foreach ( $messages as $msg ) {
			if ( connection_aborted() ) {
				break;
			}
			$eventId++;
			echo "id: $eventId\n";
			echo "event: {$msg['type']}\n";
			echo 'data: ' . json_encode( $msg ) . "\n\n";
			flush();
		}

		if ( ! connection_aborted() ) {
			$eventId++;
			echo "id: $eventId\n";
			echo "event: done\n";
			echo 'data: ' . json_encode( [ 'reason' => 'complete' ] ) . "\n\n";
			flush();
		}

		exit;
	}

	// ─── Deep Search ────────────────────────────────────────────

	public static function deepSearch( string $query, ?string $project = null ): array {
		if ( mb_strlen( $query ) < 3 ) {
			return [];
		}

		try {
			require_once BASE_DIR . '/app/SearchIndex.php';

			$sessions = self::listSessions( $project );

			$stale = SearchIndex::getStaleSessions( $sessions );
			if ( ! empty( $stale ) ) {
				SearchIndex::indexSessions( $stale );
			}

			return SearchIndex::search( $query, $project, self::sourceId() );
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	// ─── File Lookup ─────────────────────────────────────────────

	public static function findSessionFileById( string $sessionId ): ?string {
		$projectsDir = self::cmdDir() . '/projects';
		if ( ! is_dir( $projectsDir ) ) {
			return null;
		}

		foreach ( scandir( $projectsDir ) as $dir ) {
			if ( $dir[0] === '.' ) {
				continue;
			}
			$file = $projectsDir . '/' . $dir . '/' . $sessionId . '.jsonl';
			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		return null;
	}

	public static function findSessionFile( string $sessionId, string $project ): ?string {
		if ( $project ) {
			$file = self::cmdDir() . '/projects/' . $project . '/' . $sessionId . '.jsonl';
			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		return self::findSessionFileById( $sessionId );
	}

	// ─── Helpers ────────────────────────────────────────────────

	private static function readDisplay( string $sessionDir, string $sessionId, string $jsonlPath ): string {
		$metaFile = $sessionDir . '/' . $sessionId . '.meta.json';
		if ( file_exists( $metaFile ) ) {
			$meta = json_decode( file_get_contents( $metaFile ), true );
			if ( ! empty( $meta['title'] ) ) {
				return $meta['title'];
			}
		}

		$fp = fopen( $jsonlPath, 'r' );
		if ( ! $fp ) {
			return '';
		}

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$obj = json_decode( trim( $line ), true );
			if ( ! $obj || ( $obj['role'] ?? '' ) !== 'user' ) {
				continue;
			}
			foreach ( self::normalizeContent( $obj['content'] ) as $block ) {
				if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
					fclose( $fp );
					return mb_substr( $block['text'], 0, 120 );
				}
			}
		}

		fclose( $fp );
		return '';
	}

	private static function readFirstTimestamp( string $jsonlPath ): int {
		$fp = fopen( $jsonlPath, 'r' );
		if ( ! $fp ) {
			return 0;
		}

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$obj = json_decode( trim( $line ), true );
			if ( ! $obj || ( $obj['role'] ?? '' ) !== 'user' ) {
				continue;
			}
			fclose( $fp );
			return self::isoToMs( $obj['timestamp'] ?? '0' );
		}

		fclose( $fp );
		return 0;
	}

	private static function isoToMs( string $ts ): int {
		if ( is_numeric( $ts ) ) {
			return (int) $ts;
		}
		$ms = 0;
		if ( preg_match( '/\.(\d{1,3})/', $ts, $m ) ) {
			$ms = (int) str_pad( $m[1], 3, '0', STR_PAD_RIGHT );
		}
		$s = strtotime( $ts );
		return $s > 0 ? ( $s * 1000 + $ms ) : 0;
	}

	private static function resolveProjectPath( string $encoded ): string {
		static $cache = [];
		if ( isset( $cache[ $encoded ] ) ) {
			return $cache[ $encoded ];
		}

		$tlds = [ 'localhost', 'com', 'org', 'net', 'io', 'dev', 'app', 'ai', 'test' ];
		$segments = explode( '-', $encoded );
		$n = count( $segments );

		foreach ( $tlds as $tld ) {
			$tldParts = explode( '-', $tld );
			$tldLen = count( $tldParts );

			for ( $i = $n - $tldLen; $i >= 1; $i-- ) {
				if ( array_slice( $segments, $i, $tldLen ) !== $tldParts ) {
					continue;
				}

				$rootParts = array_slice( $segments, 0, $i );
				$tailParts = array_slice( $segments, $i + $tldLen );
				$rootCount = count( $rootParts );

				// Brute-force: between each pair of root segments, try: slash, dot, or hyphen.
				self::tryResolveDomain( $rootParts, $tld, $tailParts, $encoded, $cache );
				if ( isset( $cache[ $encoded ] ) ) {
					return $cache[ $encoded ];
				}
			}
		}

		$cache[ $encoded ] = $encoded;
		return $encoded;
	}

	private static function tryResolveDomain( array $rootParts, string $tld, array $tailParts, string $encoded, array &$cache ): void {
		$rootCount = count( $rootParts );
		if ( $rootCount === 0 ) {
			return;
		}

		$gaps = $rootCount - 1;
		// For each gap: 0=slash, 1=dot, 2=hyphen. Total combos: 3^gaps.
		$total = pow( 3, $gaps );
		for ( $combo = 0; $combo < $total; $combo++ ) {
			$pathParts = [];
			$buf       = $rootParts[0];
			$remaining = $combo;

			for ( $g = 0; $g < $gaps; $g++ ) {
				$choice = $remaining % 3;
				$remaining = intval( $remaining / 3 );

				if ( $choice === 0 ) {
					// Slash — commit current buffer, start new.
					$pathParts[] = $buf;
					$buf = $rootParts[ $g + 1 ];
				} elseif ( $choice === 1 ) {
					// Dot — keep building the domain.
					$buf .= '.' . $rootParts[ $g + 1 ];
				} else {
					// Hyphen — keep building with a literal hyphen.
					$buf .= '-' . $rootParts[ $g + 1 ];
				}
			}
			$pathParts[] = $buf;

			$last        = array_pop( $pathParts );
			$pathParts[] = $last . '.' . $tld;

			foreach ( $tailParts as $tp ) {
				$pathParts[] = $tp;
			}

			$path = '/' . implode( '/', $pathParts );

			if ( is_dir( $path ) ) {
				$cache[ $encoded ] = $path;
			}
		}
	}

	private static function encodeProjectPath( string $path ): string {
		return preg_replace( '/[^a-z0-9]/', '-', strtolower( $path ) );
	}

	private static function normalizeContent( array|string $content ): array {
		if ( is_array( $content ) ) {
			return $content;
		}
		if ( is_string( $content ) && trim( $content ) !== '' ) {
			return [ [ 'type' => 'text', 'text' => $content ] ];
		}
		return [];
	}

	private static function decodeProjectName( string $path ): string {
		// $path is now the real filesystem path (or the encoded fallback).
		// If it looks like a real path (starts with /), extract the last 1-2
		// meaningful segments.
		if ( $path !== '' && $path[0] === '/' ) {
			$parts    = explode( '/', trim( $path, '/' ) );
			$relevant = [];
			foreach ( $parts as $p ) {
				if ( $p === 'Users' || $p === 'users' || $p === 'home' || $p === 'Home' ) {
					continue;
				}
				if ( preg_match( '/^[a-z]\d*$/i', $p ) ) {
					continue;
				}
				if ( in_array( strtolower( $p ), [ 'cove', 'sites', 'www', 'htdocs', 'public_html', 'public', 'src' ] ) ) {
					continue;
				}
				$relevant[] = $p;
			}
			if ( ! empty( $relevant ) ) {
				return implode( '/', array_slice( $relevant, -2 ) );
			}
		}

		// Fallback for encoded name that couldn't be resolved.
		$parts  = explode( '-', $path );
		$relevant = [];
		foreach ( $parts as $p ) {
			if ( $p === 'users' || $p === 'home' ) {
				continue;
			}
			if ( preg_match( '/^[a-z]\d*$/', $p ) ) {
				continue;
			}
			if ( in_array( $p, [ 'cove', 'sites', 'www', 'htdocs', 'public_html', 'public', 'src', 'localhost' ] ) ) {
				continue;
			}
			$relevant[] = $p;
		}
		if ( empty( $relevant ) ) {
			return $path;
		}
		return implode( '/', array_slice( $relevant, -2 ) );
	}

	private static function extractToolResultText( array $block ): string {
		$output = $block['output'] ?? [];
		$type   = $output['type'] ?? '';

		if ( $type === 'text' ) {
			$text = $output['text'] ?? $output['value'] ?? '';
			if ( is_string( $text ) && $text !== '' ) {
				return $text;
			}
		}

		if ( $type === 'error-text' ) {
			$text = $output['value'] ?? $output['text'] ?? '';
			if ( is_string( $text ) && $text !== '' ) {
				return $text;
			}
		}

		return '';
	}

	// ─── Tool Helpers ────────────────────────────────────────────

	private static function normalizeToolName( string $name ): string {
		return match ( $name ) {
			'bash', 'shell_command'   => 'Bash',
			'read', 'read_file'       => 'Read',
			'write', 'write_file'     => 'Write',
			'edit', 'edit_file'       => 'Edit',
			'glob'                    => 'Glob',
			'grep'                    => 'Grep',
			'web_search', 'web_fetch' => 'WebSearch',
			'task', 'agent'           => 'Agent',
			'todo_write'              => 'TodoWrite',
			'ask_user_question'       => 'AskUserQuestion',
			'enter_plan_mode'         => 'EnterPlanMode',
			'exit_plan_mode'          => 'ExitPlanMode',
			'plan'                    => 'EnterPlanMode',
			'think'                   => 'Think',
			'explore'                 => 'Explore',
			default                   => $name,
		};
	}

	public static function toolCategory( string $tool ): string {
		return match ( $tool ) {
			'bash', 'shell_command'           => 'shell',
			'read', 'read_file', 'read_multiple_files' => 'file',
			'write', 'write_file', 'edit', 'edit_file' => 'file',
			'glob', 'grep'                    => 'search',
			'agent', 'task', 'explore'        => 'agent',
			'web_search', 'web_fetch'         => 'web',
			'todo_write'                      => 'misc',
			'ask_user_question', 'think',
			'enter_plan_mode', 'exit_plan_mode', 'plan' => 'misc',
			default                           => 'other',
		};
	}

	public static function describeToolCall( string $tool, array $input ): string {
		return match ( $tool ) {
			'bash', 'shell_command' => mb_substr( $input['command'] ?? $input['description'] ?? '', 0, 150 ),
			'read', 'read_file'     => is_array( $input['filePath'] ?? null ) ? ( basename( $input['filePath'][0] ?? '' ) . '...' ) : basename( $input['filePath'] ?? $input['absolutePath'] ?? '' ),
			'write', 'write_file'   => basename( $input['filePath'] ?? '' ),
			'edit', 'edit_file'     => basename( $input['filePath'] ?? '' ),
			'glob'                  => $input['pattern'] ?? '',
			'grep'                  => $input['pattern'] ?? '',
			'agent'                 => mb_substr( $input['description'] ?? $input['prompt'] ?? '', 0, 120 ),
			'task'                  => mb_substr( $input['description'] ?? $input['prompt'] ?? '', 0, 120 ),
			'explore'               => mb_substr( $input['messages'][0]['content'] ?? '', 0, 120 ),
			'todo_write'            => self::summarizeTodoWriteInput( $input ),
			'ask_user_question'     => mb_substr( ( $input['questions'][0]['question'] ?? '' ), 0, 120 ),
			'think'                 => mb_substr( $input['thought'] ?? '', 0, 80 ),
			'enter_plan_mode'       => '',
			'exit_plan_mode'        => '',
			'plan'                  => mb_substr( $input['prompt'] ?? $input['messages'][0]['content'] ?? '', 0, 120 ),
			default                 => self::describeGenericTool( $input ),
		};
	}

	private static function summarizeTodoWriteInput( array $input ): string {
		$todos = $input['todos'] ?? [];
		if ( empty( $todos ) ) {
			return '';
		}
		$parts = [];
		foreach ( array_slice( $todos, 0, 4 ) as $t ) {
			$status = $t['status'] ?? 'pending';
			$icon   = match ( $status ) {
				'completed'   => "\u{2713}",
				'in_progress' => "\u{25B6}",
				default       => "\u{25CB}",
			};
			$parts[] = $icon . ' ' . mb_substr( $t['content'] ?? $t['subject'] ?? '', 0, 50 );
		}
		$extra = count( $todos ) > 4 ? ' +' . ( count( $todos ) - 4 ) . ' more' : '';
		return implode( ' | ', $parts ) . $extra;
	}

	private static function describeGenericTool( array $input ): string {
		foreach ( [ 'description', 'query', 'prompt', 'command', 'name', 'message', 'url', 'path', 'file_path', 'text', 'question' ] as $key ) {
			if ( ! empty( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				return mb_substr( $input[ $key ], 0, 120 );
			}
		}
		$keys = array_keys( $input );
		if ( empty( $keys ) ) {
			return '';
		}
		return implode( ', ', array_slice( $keys, 0, 5 ) );
	}
}
