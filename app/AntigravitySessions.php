<?php

class AntigravitySessions {

	private static ?string $cmdDir = null;

	/**
	 * Resolve the Antigravity brain directory (env override → ~/.gemini/antigravity-cli/brain).
	 */
	private static function cmdDir(): string {
		if ( self::$cmdDir === null ) {
			$override = getenv( 'ANTIGRAVITY_HOME' );
			$home     = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
			self::$cmdDir = $override ?: $home . '/.gemini/antigravity-cli/brain';
		}
		return self::$cmdDir;
	}

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'antigravity';
	}

	public static function sourceLabel(): string {
		return 'Antigravity';
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

			$type = $obj['type'] ?? '';
            $content = $obj['content'] ?? '';

            if ( in_array($type, ['USER_INPUT', 'PLANNER_RESPONSE']) ) {
                if ( is_string($content) && ! empty( trim($content) ) ) {
                    $parts[] = mb_substr( $content, 0, $maxChars );
                }
            }
		}

		fclose( $fp );

		return implode( "\n", $parts );
	}

	// ─── Sessions ───────────────────────────────────────────────

	public static function listSessions( ?string $project = null ): array {
		if ( ! is_dir( self::cmdDir() ) ) {
			return [];
		}

		$sessions = [];

		foreach ( scandir( self::cmdDir() ) as $dir ) {
			if ( $dir[0] === '.' ) {
				continue;
			}

			$sessionDir = self::cmdDir() . '/' . $dir;
			if ( ! is_dir( $sessionDir ) ) {
				continue;
			}

			$jsonlPath = $sessionDir . '/.system_generated/logs/transcript.jsonl';
			if ( ! file_exists( $jsonlPath ) ) {
				continue;
			}

			$sessionId = $dir;
			
			$meta = self::parseFirstInput( $jsonlPath );
            $sessionProject = $meta['project'];
            $display = $meta['display'];
            $timestamp = $meta['timestamp'];

			if ( $project && self::encodeProjectPath( $project ) !== self::encodeProjectPath($sessionProject) ) {
				continue;
			}

			$size = filesize( $jsonlPath );

			$sessions[] = [
				'id'        => $sessionId,
				'display'   => $display,
				'timestamp' => $timestamp,
				'project'   => $sessionProject,
				'size'      => $size,
			];
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
		if ( ! is_dir( self::cmdDir() ) ) {
			return [];
		}

		$projects = [];

		foreach ( scandir( self::cmdDir() ) as $dir ) {
			if ( $dir[0] === '.' ) {
				continue;
			}

			$sessionDir = self::cmdDir() . '/' . $dir;
			$jsonlPath = $sessionDir . '/.system_generated/logs/transcript.jsonl';
			if ( ! file_exists( $jsonlPath ) ) {
				continue;
			}

            $meta = self::parseFirstInput( $jsonlPath );
            $sessionProject = $meta['project'];
            $ts = $meta['timestamp'];
            
            if (empty($sessionProject)) {
                $sessionProject = 'unknown';
            }

            if (!isset($projects[$sessionProject])) {
                $projects[$sessionProject] = [
					'path'     => $sessionProject,
					'name'     => self::decodeProjectName( $sessionProject ),
					'sessions' => 0,
					'latest'   => 0,
				];
            }
            
            $projects[$sessionProject]['sessions']++;
            if ($ts > $projects[$sessionProject]['latest']) {
                $projects[$sessionProject]['latest'] = $ts;
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

			$type = $obj['type'] ?? '';
            $source = $obj['source'] ?? '';
            
			if ( $type === 'USER_INPUT' ) {
                $content = $obj['content'] ?? '';
                // Try to extract only the <USER_REQUEST> if present
                if (preg_match('/<USER_REQUEST>\s*(.*?)\s*<\/USER_REQUEST>/s', $content, $m)) {
                    $text = $m[1];
                } else {
                    $text = $content;
                }
				if ( trim( $text ) !== '' ) {
					$messages[] = [
						'type' => 'user_message',
						'text' => $text,
					];
				}
			} elseif ( $type === 'PLANNER_RESPONSE' ) {
                $content = $obj['content'] ?? '';
                if ( trim( $content ) !== '' ) {
					$messages[] = [
						'type' => 'text',
						'text' => $content,
					];
				}
                $tool_calls = $obj['tool_calls'] ?? [];
				foreach ( $tool_calls as $block ) {
                    $toolName = $block['name'] ?? 'unknown';
                    $input    = $block['args'] ?? [];
                    $messages[] = [
                        'type'     => 'tool_call',
                        'tool'     => self::normalizeToolName( $toolName ),
                        'category' => self::toolCategory( $toolName ),
                        'label'    => self::describeToolCall( $toolName, $input ),
                    ];
				}
			} elseif ( $source === 'MODEL' && $type !== 'PLANNER_RESPONSE' && $type !== 'THOUGHT_LOG' && $type !== 'MODEL_RESPONSE' ) {
                // This is a tool result (like LIST_DIRECTORY)
                $content = $obj['content'] ?? '';
                if ( trim( $content ) !== '' ) {
                    $messages[] = [
                        'type'    => 'tool_result',
                        'preview' => mb_substr( $content, 0, 500 ),
                        'length'  => mb_strlen( $content ),
                    ];
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
		$file = self::cmdDir() . '/' . $sessionId . '/.system_generated/logs/transcript.jsonl';
        if (file_exists($file)) {
            return $file;
        }
		return null;
	}

	public static function findSessionFile( string $sessionId, ?string $project = null ): ?string {
		return self::findSessionFileById( $sessionId );
	}

	// ─── Helpers ────────────────────────────────────────────────

	private static function parseFirstInput( string $jsonlPath ): array {
        static $cache = [];
        if (isset($cache[$jsonlPath])) {
            return $cache[$jsonlPath];
        }

		$fp = fopen( $jsonlPath, 'r' );
		if ( ! $fp ) {
			return ['project' => '', 'display' => '', 'timestamp' => 0];
		}

        $project = '';
        $display = '';
        $timestamp = 0;

		$lineCount = 0;
		while ( ( $line = fgets( $fp ) ) !== false ) {
			if (++$lineCount > 100) break;
			$obj = json_decode( trim( $line ), true );
			if ( ! $obj ) {
                continue;
            }

            if ( ( $obj['type'] ?? '' ) === 'USER_INPUT' ) {
                if (!$timestamp) {
                    $timestamp = self::isoToMs($obj['created_at'] ?? '0');
                }

                $content = $obj['content'] ?? '';
                
                // Extract display (first <USER_REQUEST> block)
                if (!$display) {
                    if (preg_match('/<USER_REQUEST>\s*(.*?)\s*<\/USER_REQUEST>/s', $content, $m)) {
                        $display = mb_substr($m[1], 0, 120);
                    } else {
                        $display = mb_substr($content, 0, 120);
                    }
                }

                // Extract project from <user_information> block workspace mapping (if ever supported)
                if (!$project && preg_match('/\[URI\] -> \[CorpusName\]:\s*([^\s]+)/s', $content, $m)) {
                    $project = trim($m[1]);
                }
            }

            // Infer project from early tool calls if not set
            if ( !$project && isset($obj['tool_calls']) && is_array($obj['tool_calls']) ) {
                foreach ($obj['tool_calls'] as $call) {
                    $args = $call['args'] ?? [];
                    $path = $args['Cwd'] ?? $args['DirectoryPath'] ?? $args['SearchPath'] ?? '';
                    if (empty($path) && !empty($args['AbsolutePath'])) {
                        $path = dirname(trim($args['AbsolutePath'], '"\''));
                    }
                    if (empty($path) && !empty($args['TargetFile'])) {
                        $path = dirname(trim($args['TargetFile'], '"\''));
                    }
                    if ($path && is_string($path)) {
                        $path = trim($path, '"\'');
                        if (str_starts_with($path, '/Users/')) {
                            $project = $path;
                            break;
                        }
                    }
                }
            }

            // If we found both, or we've scanned enough lines, break early.
            // (We scan up to 100 lines to find a tool call to infer the project from)
            if ( $display && $project ) {
                break;
            }
		}

		fclose( $fp );
        
        $res = ['project' => $project, 'display' => $display, 'timestamp' => $timestamp];
        $cache[$jsonlPath] = $res;
		return $res;
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

	private static function encodeProjectPath( string $path ): string {
		return preg_replace( '/[^a-z0-9]/', '-', strtolower( $path ) );
	}

	private static function decodeProjectName( string $path ): string {
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

	// ─── Tool Helpers ────────────────────────────────────────────

	private static function normalizeToolName( string $name ): string {
		// e.g. default_api:list_dir => list_dir
        $parts = explode(':', $name);
        $baseName = end($parts);
		return match ( $baseName ) {
			'bash', 'shell_command', 'run_command'   => 'Bash',
			'read', 'read_file', 'view_file'         => 'Read',
			'write', 'write_file', 'write_to_file'   => 'Write',
			'edit', 'edit_file', 'replace_file_content', 'multi_replace_file_content' => 'Edit',
			'glob'                    => 'Glob',
			'grep', 'grep_search'     => 'Grep',
			'web_search', 'web_fetch' => 'WebSearch',
			'task', 'agent', 'invoke_subagent' => 'Agent',
			'ask_user_question'       => 'AskUserQuestion',
			'enter_plan_mode'         => 'EnterPlanMode',
			'exit_plan_mode'          => 'ExitPlanMode',
			'plan'                    => 'EnterPlanMode',
			'think'                   => 'Think',
			'explore'                 => 'Explore',
            'list_dir'                => 'ListDir',
			default                   => $baseName,
		};
	}

	public static function toolCategory( string $tool ): string {
        $norm = self::normalizeToolName($tool);
		return match ( $norm ) {
			'Bash', 'shell_command'           => 'shell',
			'Read', 'ListDir', 'read_multiple_files' => 'file',
			'Write', 'Edit'                   => 'file',
			'Glob', 'Grep'                    => 'search',
			'Agent', 'task', 'Explore'        => 'agent',
			'WebSearch', 'web_fetch'          => 'web',
			'AskUserQuestion', 'Think',
			'EnterPlanMode', 'ExitPlanMode'   => 'misc',
			default                           => 'other',
		};
	}

	public static function describeToolCall( string $tool, array $input ): string {
		$desc = match ( self::normalizeToolName($tool) ) {
			'Bash'          => mb_substr( $input['CommandLine'] ?? $input['command'] ?? $input['description'] ?? '', 0, 150 ),
			'Read'          => basename( $input['AbsolutePath'] ?? $input['filePath'] ?? '' ),
			'Write'         => basename( $input['TargetFile'] ?? $input['filePath'] ?? '' ),
			'Edit'          => basename( $input['TargetFile'] ?? $input['filePath'] ?? '' ),
			'Glob'          => $input['pattern'] ?? '',
			'Grep'          => $input['Query'] ?? $input['pattern'] ?? '',
			'Agent'         => mb_substr( $input['description'] ?? $input['prompt'] ?? '', 0, 120 ),
			'Explore'       => mb_substr( $input['messages'][0]['content'] ?? '', 0, 120 ),
			'AskUserQuestion' => mb_substr( ( $input['questions'][0]['question'] ?? '' ), 0, 120 ),
			'Think'         => mb_substr( $input['thought'] ?? '', 0, 80 ),
			'EnterPlanMode', 'ExitPlanMode' => '',
            'ListDir'       => basename( $input['DirectoryPath'] ?? '' ),
			default         => self::describeGenericTool( $input ),
		};
        if (empty($desc)) {
            $desc = self::describeGenericTool($input);
        }
        return $desc;
	}

	private static function describeGenericTool( array $input ): string {
		foreach ( [ 'description', 'query', 'prompt', 'command', 'name', 'message', 'url', 'path', 'file_path', 'text', 'question', 'TargetFile', 'AbsolutePath', 'DirectoryPath', 'Query', 'CommandLine' ] as $key ) {
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
