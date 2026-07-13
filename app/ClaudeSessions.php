<?php

class ClaudeSessions {

	private static ?string $claudeDir = null;

	/**
	 * Resolve the Claude Code home directory (env override → ~/.claude).
	 */
	private static function claudeDir(): string {
		if ( self::$claudeDir === null ) {
			$override = getenv( 'CLAUDE_HOME' );
			$home     = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
			self::$claudeDir = $override ?: $home . '/.claude';
		}
		return self::$claudeDir;
	}

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'claude';
	}

	public static function sourceLabel(): string {
		return 'Claude Code';
	}

	/**
	 * Does this provider own the given session id?
	 * Accepts primary UUIDs and nested subagent ids: {parentUuid}_agent_{agentId}.
	 */
	public static function hasSession( string $sessionId ): bool {
		return self::findSessionFileById( $sessionId ) !== null;
	}

	/**
	 * Synthetic id for a Claude subagent transcript file under a parent session.
	 * Format: {parentUuid}_agent_{agentId} (URL-safe for /api/sessions/{id}).
	 */
	public static function subagentSessionId( string $parentId, string $agentId ): string {
		$agentId = preg_replace( '/^agent-/', '', $agentId );
		return $parentId . '_agent_' . $agentId;
	}

	/**
	 * Parse a synthetic subagent id. Returns [parentId, agentId] or null.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function parseSubagentSessionId( string $sessionId ): ?array {
		if ( ! preg_match( '/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})_agent_(.+)$/i', $sessionId, $m ) ) {
			return null;
		}
		return [ $m[1], $m[2] ];
	}

	/**
	 * Fingerprint a session (mtime + size across the main .jsonl file and any
	 * subagent transcripts). Folding subagent files in means the incremental
	 * indexer notices subagent-only activity and re-extracts usage.
	 */
	public static function fingerprint( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? '' );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		clearstatcache( true, $file );
		$mtime = (int) filemtime( $file );
		$size  = (int) filesize( $file );

		foreach ( self::subagentFiles( $file ) as $sub ) {
			clearstatcache( true, $sub );
			$mtime = max( $mtime, (int) filemtime( $sub ) );
			$size += (int) filesize( $sub );
		}

		return [
			'mtime' => $mtime,
			'size'  => $size,
		];
	}

	/**
	 * List subagent transcript files for a session. Claude Code writes them
	 * to a sibling directory named after the session id, e.g.
	 * <project>/<session-id>/subagents/agent-*.jsonl (workflow agents nest
	 * deeper under subagents/workflows/wf_xxx/), so scan it recursively.
	 */
	private static function subagentFiles( string $mainFile ): array {
		$dir = dirname( $mainFile ) . '/' . basename( $mainFile, '.jsonl' );
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$files = [];
		$it    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $f ) {
			if ( $f->isFile() && substr( $f->getFilename(), -6 ) === '.jsonl' ) {
				$files[] = $f->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Extract searchable text from a session's .jsonl file.
	 * Concatenates user messages, assistant text, and summaries.
	 */
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

			if ( $type === 'summary' ) {
				$text = $obj['summary'] ?? '';
				if ( $text ) {
					$parts[] = mb_substr( $text, 0, $maxChars );
				}
				continue;
			}

			if ( $type === 'user' ) {
				$content = $obj['message']['content'] ?? '';
				if ( is_string( $content ) && $content !== '' ) {
					$parts[] = mb_substr( $content, 0, $maxChars );
				} elseif ( is_array( $content ) ) {
					foreach ( $content as $block ) {
						if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
							$parts[] = mb_substr( $block['text'], 0, $maxChars );
						}
					}
				}
				continue;
			}

			if ( $type === 'assistant' ) {
				$content = $obj['message']['content'] ?? $obj['content'] ?? [];
				if ( is_array( $content ) ) {
					foreach ( $content as $block ) {
						if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
							$parts[] = mb_substr( $block['text'], 0, $maxChars );
						}
					}
				}
				continue;
			}
		}

		fclose( $fp );

		return implode( "\n", $parts );
	}

	/**
	 * Sum API token usage across the session's .jsonl file plus all of its
	 * subagent transcripts (Agent tool and workflow agents log usage to
	 * separate files under <session-id>/, not the main transcript).
	 *
	 * Assistant events can repeat the same API response across multiple lines
	 * (one per content block, streaming snapshots), so usage is deduped by
	 * message id with last-write-wins before summing. The dedup map is shared
	 * across files so a response echoed in two transcripts counts once.
	 */
	public static function extractUsage( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '', $session['project'] ?? '' );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}

		$perMsg = [];
		$anon   = [ 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0 ];
		$found  = false;

		foreach ( array_merge( [ $file ], self::subagentFiles( $file ) ) as $path ) {
			if ( self::sumUsageFile( $path, $perMsg, $anon ) ) {
				$found = true;
			}
		}

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

	/**
	 * Accumulate one transcript file's usage into the shared dedup map.
	 * Returns true when the file contributed any usage data.
	 */
	private static function sumUsageFile( string $file, array &$perMsg, array &$anon ): bool {
		$fp = fopen( $file, 'r' );
		if ( ! $fp ) {
			return false;
		}

		$found = false;

		while ( ( $line = fgets( $fp ) ) !== false ) {
			if ( strpos( $line, '"usage"' ) === false ) {
				continue;
			}
			$obj = json_decode( $line, true );
			$msg = $obj['message'] ?? null;
			$u   = $msg['usage'] ?? null;
			if ( ! is_array( $u ) ) {
				continue;
			}

			$usage = [
				'input'          => (int) ( $u['input_tokens'] ?? 0 ),
				'output'         => (int) ( $u['output_tokens'] ?? 0 ),
				'cache_read'     => (int) ( $u['cache_read_input_tokens'] ?? 0 ),
				'cache_creation' => (int) ( $u['cache_creation_input_tokens'] ?? 0 ),
			];

			$mid = $msg['id'] ?? '';
			if ( $mid !== '' ) {
				$perMsg[ $mid ] = $usage;
			} else {
				foreach ( $usage as $k => $v ) {
					$anon[ $k ] += $v;
				}
			}
			$found = true;
		}

		fclose( $fp );

		return $found;
	}

	// ─── Sessions ───────────────────────────────────────────────

	/**
	 * List all sessions from ~/.claude/history.jsonl, deduplicated and sorted.
	 */
	public static function listSessions( ?string $project = null ): array {
		$historyFile = self::claudeDir() . '/history.jsonl';
		if ( ! file_exists( $historyFile ) ) {
			return [];
		}

		$sessions = [];
		$fp       = fopen( $historyFile, 'r' );

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$obj = json_decode( $line, true );
			if ( ! $obj || empty( $obj['sessionId'] ) ) {
				continue;
			}

			$sessionProject = $obj['project'] ?? '';

			if ( $project && $sessionProject !== $project ) {
				continue;
			}

			// Last entry per sessionId wins (most recent prompt).
			$sessions[ $obj['sessionId'] ] = [
				'id'        => $obj['sessionId'],
				'display'   => $obj['display'] ?? '',
				'timestamp' => $obj['timestamp'] ?? 0,
				'project'   => $sessionProject,
			];
		}

		fclose( $fp );

		// Sort by timestamp descending.
		usort( $sessions, fn( $a, $b ) => $b['timestamp'] - $a['timestamp'] );

		// Add project short name, size, and subagent *count* only.
		// Full children[] is lazy-loaded via getSession() when the UI expands
		// a parent - scanning ~10k agent transcripts on every list made the
		// dashboard multi-second.
		foreach ( $sessions as &$s ) {
			$s['projectName'] = $s['project'] ? basename( $s['project'] ) : '';
			$s['timestamp_s'] = intval( $s['timestamp'] / 1000 ); // JS ms → PHP seconds

			$file = self::findSessionFile( $s['id'], $s['project'] );
			$s['size'] = $file && file_exists( $file ) ? filesize( $file ) : 0;

			$count = self::countSubagents( $s['id'], $s['project'] ?? '', $file );
			if ( $count > 0 ) {
				$s['subagent_count'] = $count;
				// Empty children signals the UI to fetch on expand.
				$s['children'] = [];
			}
		}
		unset( $s );

		return array_values( $sessions );
	}

	/**
	 * Single session (primary or nested subagent) for the session viewer.
	 */
	public static function getSession( string $sessionId ): ?array {
		$parsed = self::parseSubagentSessionId( $sessionId );
		if ( $parsed ) {
			[ $parentId, $agentId ] = $parsed;
			$file = self::findSubagentFile( $parentId, $agentId );
			if ( ! $file ) {
				return null;
			}
			$parentFile = self::findSessionFileById( $parentId );
			$project    = '';
			if ( $parentFile ) {
				// Derive project path from encoded project dir name when possible.
				$project = self::projectPathFromSessionFile( $parentFile );
			}
			$child = self::subagentRecord( $parentId, $file, $project );
			$child['parent_id']   = $parentId;
			$child['is_subagent'] = true;
			$child['project']     = $project;
			$child['projectName'] = $project ? basename( $project ) : '';
			return $child;
		}

		// Primary: find in listSessions, then hydrate full children for expand/detail.
		foreach ( self::listSessions() as $s ) {
			if ( ( $s['id'] ?? '' ) === $sessionId ) {
				$kids = self::listSubagents( $sessionId, $s['project'] ?? '' );
				if ( $kids ) {
					$s['children']       = $kids;
					$s['subagent_count'] = count( $kids );
				}
				return $s;
			}
		}
		// Not in history.jsonl but file exists.
		$file = self::findSessionFileById( $sessionId );
		if ( ! $file ) {
			return null;
		}
		$project = self::projectPathFromSessionFile( $file );
		$kids    = self::listSubagents( $sessionId, $project );
		$rec     = [
			'id'          => $sessionId,
			'display'     => $sessionId,
			'timestamp'   => ( (int) @filemtime( $file ) ) * 1000,
			'timestamp_s' => (int) @filemtime( $file ),
			'project'     => $project,
			'projectName' => $project ? basename( $project ) : '',
			'size'        => (int) @filesize( $file ),
		];
		if ( $kids ) {
			$rec['children']       = $kids;
			$rec['subagent_count'] = count( $kids );
		}
		return $rec;
	}

	/**
	 * List unique projects from history.
	 */
	public static function listProjects(): array {
		$historyFile = self::claudeDir() . '/history.jsonl';
		if ( ! file_exists( $historyFile ) ) {
			return [];
		}

		$projects = [];
		$fp       = fopen( $historyFile, 'r' );

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$obj = json_decode( trim( $line ), true );
			if ( ! $obj || empty( $obj['project'] ) ) {
				continue;
			}
			$p = $obj['project'];
			if ( ! isset( $projects[ $p ] ) ) {
				$projects[ $p ] = [
					'path'     => $p,
					'name'     => basename( $p ),
					'sessions' => 0,
					'latest'   => 0,
				];
			}
			$projects[ $p ]['sessions']++;
			$ts = $obj['timestamp'] ?? 0;
			if ( $ts > $projects[ $p ]['latest'] ) {
				$projects[ $p ]['latest'] = $ts;
			}
		}

		fclose( $fp );

		$result = array_values( $projects );
		usort( $result, fn( $a, $b ) => $b['latest'] - $a['latest'] );
		return $result;
	}

	/**
	 * Get conversation messages for a session.
	 */
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
			if ( in_array( $type, [ 'queue-operation', 'file-history-snapshot' ], true ) ) {
				continue;
			}

			$events = self::parseMessage( $obj );
			foreach ( $events as $event ) {
				$messages[] = $event;
			}
		}

		fclose( $fp );

		return $messages;
	}

	/**
	 * Handle SSE stream for a session's JSONL file.
	 *
	 * @param string $sessionId  Claude session UUID.
	 * @param int    $runnerPid  Optional runner PID for staleness detection.
	 */
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

		$fp          = fopen( $file, 'r' );
		$eventId     = 0;
		$lastEventId = intval( $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0 );
		$staleChecks = 0;

		while ( ! connection_aborted() ) {
			$line = fgets( $fp );

			if ( $line !== false ) {
				$line = trim( $line );
				if ( $line === '' ) {
					continue;
				}

				$obj = json_decode( $line, true );
				if ( ! $obj ) {
					continue;
				}

				$type = $obj['type'] ?? '';
				if ( in_array( $type, [ 'queue-operation', 'file-history-snapshot' ], true ) ) {
					continue;
				}

				$events = self::parseMessage( $obj );
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

				$staleChecks = 0;
			} else {
				clearstatcache( true, $file );
				$currentSize = filesize( $file );
				$pos         = ftell( $fp );

				if ( $currentSize > $pos ) {
					fseek( $fp, $pos );
					continue;
				}

				// Staleness detection.
				$runnerAlive = $runnerPid > 0 && posix_kill( $runnerPid, 0 );

				if ( ! $runnerAlive ) {
					$mtime = filemtime( $file );
					if ( time() - $mtime > 30 ) {
						$staleChecks++;
						if ( $staleChecks >= 3 ) {
							echo 'id: ' . ( ++$eventId ) . "\n";
							echo "event: done\n";
							echo 'data: ' . json_encode( [ 'reason' => 'stale' ] ) . "\n\n";
							flush();
							break;
						}
					}
				}

				echo ": keepalive\n\n";
				flush();
				sleep( 2 );
			}
		}

		fclose( $fp );
		exit;
	}

	// ─── Deep Search ────────────────────────────────────────────

	/**
	 * Search conversation content using the FTS5 index, with grep fallback.
	 */
	public static function deepSearch( string $query, ?string $project = null ): array {
		if ( mb_strlen( $query ) < 3 ) {
			return [];
		}

		try {
			require_once BASE_DIR . '/app/SearchIndex.php';

			$sessions = self::listSessions( $project );

			// Incremental index update - only re-index changed files.
			$stale = SearchIndex::getStaleSessions( $sessions );
			if ( ! empty( $stale ) ) {
				SearchIndex::indexSessions( $stale );
			}

			return SearchIndex::search( $query, $project );
		} catch ( \Exception $e ) {
			// Fall back to grep if index is unavailable or broken.
			return self::deepSearchGrep( $query, $project );
		}
	}

	/**
	 * Grep-based deep search fallback.
	 */
	private static function deepSearchGrep( string $query, ?string $project = null ): array {
		$sessions   = self::listSessions( $project );
		$sessionMap = [];
		foreach ( $sessions as $s ) {
			$file = self::findSessionFile( $s['id'], $s['project'] );
			if ( $file ) {
				$sessionMap[ realpath( $file ) ] = $s;
			}
		}

		if ( empty( $sessionMap ) ) {
			return [];
		}

		// Determine search directory - derive from actual file paths when project-scoped.
		$searchDir = self::claudeDir() . '/projects';
		if ( $project && ! empty( $sessionMap ) ) {
			$firstFile = array_key_first( $sessionMap );
			$projectDir = dirname( $firstFile );
			if ( is_dir( $projectDir ) ) {
				$searchDir = $projectDir;
			}
		}

		if ( ! is_dir( $searchDir ) ) {
			return [];
		}

		$escaped = escapeshellarg( $query );
		$dir     = escapeshellarg( $searchDir );
		$cmd     = "grep -rli {$escaped} {$dir} --include='*.jsonl' 2>/dev/null";
		$output  = [];
		exec( $cmd, $output );

		$results   = [];
		$quotedQuery = preg_quote( $query, '/' );

		foreach ( $output as $filepath ) {
			$real = realpath( $filepath );
			if ( ! $real || ! isset( $sessionMap[ $real ] ) ) {
				continue;
			}

			$session = $sessionMap[ $real ];
			$snippet = self::findSnippetInFile( $real, $query, $quotedQuery );

			$results[] = [
				'id'          => $session['id'],
				'display'     => $session['display'],
				'timestamp'   => $session['timestamp'],
				'timestamp_s' => $session['timestamp_s'],
				'project'     => $session['project'],
				'projectName' => $session['projectName'],
				'size'        => $session['size'],
				'snippet'     => $snippet['text'] ?? '',
				'matchType'   => $snippet['type'] ?? 'unknown',
			];

			if ( count( $results ) >= 50 ) {
				break;
			}
		}

		// Sort by timestamp descending.
		usort( $results, fn( $a, $b ) => $b['timestamp'] - $a['timestamp'] );

		return $results;
	}

	/**
	 * Scan a JSONL file for the first line containing the query in a searchable field.
	 */
	private static function findSnippetInFile( string $filepath, string $query, string $quotedQuery ): array {
		$fp = fopen( $filepath, 'r' );
		if ( ! $fp ) {
			return [ 'text' => '', 'type' => 'unknown' ];
		}

		$lowerQuery = mb_strtolower( $query );

		while ( ( $line = fgets( $fp ) ) !== false ) {
			if ( stripos( $line, $query ) === false ) {
				continue;
			}

			$obj = json_decode( trim( $line ), true );
			if ( ! $obj ) {
				continue;
			}

			$searchable = self::extractSearchableText( $obj );
			if ( ! $searchable ) {
				continue;
			}

			if ( mb_stripos( $searchable['text'], $query ) !== false ) {
				fclose( $fp );
				return [
					'text' => self::extractSnippet( $searchable['text'], $query ),
					'type' => $searchable['type'],
				];
			}
		}

		fclose( $fp );
		return [ 'text' => '', 'type' => 'unknown' ];
	}

	/**
	 * Extract searchable text from a parsed JSONL line.
	 */
	private static function extractSearchableText( array $obj ): ?array {
		$type = $obj['type'] ?? '';

		if ( $type === 'summary' ) {
			$text = $obj['summary'] ?? '';
			return $text ? [ 'text' => $text, 'type' => 'summary' ] : null;
		}

		if ( $type === 'user' ) {
			$content = $obj['message']['content'] ?? '';
			if ( is_string( $content ) ) {
				return $content ? [ 'text' => $content, 'type' => 'user' ] : null;
			}
			if ( is_array( $content ) ) {
				foreach ( $content as $block ) {
					if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
						return [ 'text' => $block['text'], 'type' => 'user' ];
					}
				}
			}
			return null;
		}

		if ( $type === 'assistant' ) {
			$content = $obj['message']['content'] ?? $obj['content'] ?? [];
			foreach ( $content as $block ) {
				if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
					return [ 'text' => $block['text'], 'type' => 'assistant' ];
				}
			}
			return null;
		}

		return null;
	}

	/**
	 * Extract a ~120-char snippet with the match highlighted in <mark> tags.
	 */
	private static function extractSnippet( string $text, string $query ): string {
		$pos = mb_stripos( $text, $query );
		if ( $pos === false ) {
			return '';
		}

		$contextChars = 60;
		$start        = max( 0, $pos - $contextChars );
		$length       = mb_strlen( $query ) + $contextChars * 2;
		$snippet      = mb_substr( $text, $start, $length );

		// Clean up whitespace.
		$snippet = preg_replace( '/\s+/', ' ', $snippet );
		$snippet = trim( $snippet );

		// Add ellipsis.
		$prefix = $start > 0 ? '...' : '';
		$suffix = ( $start + $length ) < mb_strlen( $text ) ? '...' : '';

		// Escape HTML first, then insert mark tags.
		$escaped      = htmlspecialchars( $snippet, ENT_QUOTES, 'UTF-8' );
		$escapedQuery = htmlspecialchars( $query, ENT_QUOTES, 'UTF-8' );
		$marked       = preg_replace(
			'/' . preg_quote( $escapedQuery, '/' ) . '/i',
			'<mark>$0</mark>',
			$escaped,
			1
		);

		return $prefix . $marked . $suffix;
	}

	// ─── File Lookup ─────────────────────────────────────────────

	/**
	 * Find session file by scanning project dirs.
	 * Also resolves nested subagent ids ({parent}_agent_{agentId}).
	 */
	public static function findSessionFileById( string $sessionId ): ?string {
		$parsed = self::parseSubagentSessionId( $sessionId );
		if ( $parsed ) {
			return self::findSubagentFile( $parsed[0], $parsed[1] );
		}

		$projectsDir = self::claudeDir() . '/projects';
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

	/**
	 * Find session file using known project path.
	 */
	public static function findSessionFile( string $sessionId, string $project ): ?string {
		$parsed = self::parseSubagentSessionId( $sessionId );
		if ( $parsed ) {
			return self::findSubagentFile( $parsed[0], $parsed[1] );
		}

		if ( $project ) {
			$encoded = self::encodeProjectDir( $project );
			$file    = self::claudeDir() . '/projects/' . $encoded . '/' . $sessionId . '.jsonl';
			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		return self::findSessionFileById( $sessionId );
	}

	/**
	 * Claude encodes project paths by replacing / and . with hyphens.
	 * Absolute paths keep a leading hyphen: /Users/foo → -Users-foo.
	 */
	public static function encodeProjectDir( string $project ): string {
		return str_replace( [ '/', '.' ], '-', $project );
	}

	/**
	 * Locate agent-{id}.jsonl under parent session's subagents/ tree.
	 */
	private static function findSubagentFile( string $parentId, string $agentId ): ?string {
		$agentId = preg_replace( '/^agent-/', '', $agentId );
		$parent  = self::findSessionFileById( $parentId );
		if ( ! $parent ) {
			return null;
		}
		// Prefer nested subagents dir; also allow project-level agent-*.jsonl sidechains.
		$candidates = [
			dirname( $parent ) . '/' . $parentId . '/subagents/agent-' . $agentId . '.jsonl',
			dirname( $parent ) . '/agent-' . $agentId . '.jsonl',
		];
		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				return $file;
			}
		}
		// Recursive under parent subagents/ (workflows nest deeper).
		$subRoot = dirname( $parent ) . '/' . $parentId . '/subagents';
		if ( is_dir( $subRoot ) ) {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $subRoot, FilesystemIterator::SKIP_DOTS )
			);
			$want = 'agent-' . $agentId . '.jsonl';
			foreach ( $it as $f ) {
				if ( $f->isFile() && $f->getFilename() === $want ) {
					return $f->getPathname();
				}
			}
		}
		return null;
	}

	/**
	 * Fast count of nested agent transcripts (no file content reads).
	 * Used on the list endpoint so the dashboard stays snappy.
	 */
	private static function countSubagents( string $parentId, string $project, ?string $mainFile = null ): int {
		$file = $mainFile ?: self::findSessionFile( $parentId, $project );
		if ( ! $file ) {
			return 0;
		}
		$sub = dirname( $file ) . '/' . $parentId . '/subagents';
		if ( ! is_dir( $sub ) ) {
			return 0;
		}
		$n = 0;
		// Shallow agents.
		foreach ( glob( $sub . '/agent-*.jsonl' ) ?: [] as $f ) {
			if ( ! str_contains( basename( $f ), 'acompact-' ) ) {
				$n++;
			}
		}
		// Workflow-nested agents (rare).
		foreach ( glob( $sub . '/workflows/*/agent-*.jsonl' ) ?: [] as $f ) {
			if ( ! str_contains( basename( $f ), 'acompact-' ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * List nested Claude subagent transcripts for a parent session.
	 * Heavier than countSubagents - used for expand / session detail only.
	 *
	 * @return array<int,array>
	 */
	private static function listSubagents( string $parentId, string $project ): array {
		$file = self::findSessionFile( $parentId, $project );
		if ( ! $file ) {
			return [];
		}
		$agentFiles = self::subagentFiles( $file );
		// Also pick up project-level agent-*.jsonl that reference this sessionId.
		$projectDir = dirname( $file );
		foreach ( glob( $projectDir . '/agent-*.jsonl' ) ?: [] as $side ) {
			// Only include if the file's sessionId matches parent (sidechain of this session).
			if ( self::agentFileBelongsToSession( $side, $parentId ) ) {
				$agentFiles[] = $side;
			}
		}
		$agentFiles = array_values( array_unique( $agentFiles ) );
		if ( ! $agentFiles ) {
			return [];
		}

		$out = [];
		foreach ( $agentFiles as $agentFile ) {
			$base = basename( $agentFile, '.jsonl' ); // agent-xxx or agent-acompact-xxx
			if ( ! str_starts_with( $base, 'agent-' ) ) {
				continue;
			}
			$agentId = substr( $base, 6 );
			// Skip pure compaction sidechains in the UI list - they're noise.
			if ( str_starts_with( $agentId, 'acompact-' ) ) {
				continue;
			}
			$out[] = self::subagentRecord( $parentId, $agentFile, $project );
		}

		usort( $out, fn( $a, $b ) => ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 ) );
		return $out;
	}

	/**
	 * @return array{id:string,display:string,status:string,subagent_type:string,timestamp:int,timestamp_s:int,size:int,resumable:bool,parent_id:string,is_subagent:bool}
	 */
	private static function subagentRecord( string $parentId, string $agentFile, string $project ): array {
		$base    = basename( $agentFile, '.jsonl' );
		$agentId = str_starts_with( $base, 'agent-' ) ? substr( $base, 6 ) : $base;
		$mtime   = (int) ( @filemtime( $agentFile ) ?: 0 );
		$size    = (int) ( @filesize( $agentFile ) ?: 0 );
		$display = self::firstUserPromptFromJsonl( $agentFile );
		if ( $display === '' ) {
			$display = 'Subagent ' . $agentId;
		}

		return [
			'id'            => self::subagentSessionId( $parentId, $agentId ),
			'display'       => $display,
			'status'        => 'completed',
			'subagent_type' => str_starts_with( $agentId, 'acompact-' ) ? 'compact' : 'agent',
			'timestamp'     => $mtime * 1000,
			'timestamp_s'   => $mtime,
			'size'          => $size,
			'resumable'     => false,
			'parent_id'     => $parentId,
			'is_subagent'   => true,
			'project'       => $project,
			'projectName'   => $project ? basename( $project ) : '',
		];
	}

	private static function agentFileBelongsToSession( string $file, string $sessionId ): bool {
		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return false;
		}
		$line = fgets( $fh );
		fclose( $fh );
		if ( $line === false ) {
			return false;
		}
		$obj = json_decode( trim( $line ), true );
		return is_array( $obj ) && ( $obj['sessionId'] ?? '' ) === $sessionId;
	}

	private static function firstUserPromptFromJsonl( string $file ): string {
		$fh = @fopen( $file, 'r' );
		if ( ! $fh ) {
			return '';
		}
		$maxLines = 40;
		$n        = 0;
		while ( ( $line = fgets( $fh ) ) !== false && $n < $maxLines ) {
			$n++;
			$obj = json_decode( trim( $line ), true );
			if ( ! is_array( $obj ) || ( $obj['type'] ?? '' ) !== 'user' ) {
				continue;
			}
			$content = $obj['message']['content'] ?? '';
			if ( is_string( $content ) && trim( $content ) !== '' ) {
				fclose( $fh );
				return mb_substr( trim( $content ), 0, 120 );
			}
			if ( is_array( $content ) ) {
				foreach ( $content as $block ) {
					if ( ( $block['type'] ?? '' ) === 'text' && ! empty( $block['text'] ) ) {
						fclose( $fh );
						return mb_substr( trim( $block['text'] ), 0, 120 );
					}
				}
			}
		}
		fclose( $fh );
		return '';
	}

	/**
	 * Best-effort reverse of Claude's project dir encoding.
	 * Absolute paths are stored as -Users-foo-bar (leading hyphen, / → -).
	 * Not perfect for path segments that themselves contain hyphens.
	 */
	private static function projectPathFromSessionFile( string $sessionFile ): string {
		// .../projects/<encoded>/<session>.jsonl  OR  .../projects/<encoded>/<session>/subagents/...
		$projectsDir = self::claudeDir() . '/projects/';
		if ( ! str_starts_with( $sessionFile, $projectsDir ) ) {
			return '';
		}
		$rest = substr( $sessionFile, strlen( $projectsDir ) );
		$enc  = explode( '/', $rest, 2 )[0] ?? '';
		if ( $enc === '' ) {
			return '';
		}
		if ( $enc[0] === '-' ) {
			return '/' . str_replace( '-', '/', substr( $enc, 1 ) );
		}
		return str_replace( '-', '/', $enc );
	}

	// ─── Message Parsing ─────────────────────────────────────────

	/**
	 * Parse a JSONL message into display events.
	 * This is the single source of truth for event parsing.
	 */
	private static function parseMessage( array $obj ): array {
		$events = [];
		$type   = $obj['type'] ?? '';

		if ( $type === 'system' && ( $obj['subtype'] ?? '' ) === 'init' ) {
			$events[] = [
				'type'       => 'init',
				'model'      => $obj['model'] ?? '',
				'session_id' => $obj['session_id'] ?? '',
				'skills'     => $obj['skills'] ?? [],
			];
		} elseif ( $type === 'summary' ) {
			$events[] = [
				'type' => 'summary',
				'text' => $obj['summary'] ?? '',
			];
		} elseif ( $type === 'user' ) {
			$content = $obj['message']['content'] ?? '';

			if ( is_string( $content ) && ! empty( trim( $content ) ) ) {
				$events[] = [
					'type' => 'user_message',
					'text' => $content,
				];
			} elseif ( is_array( $content ) ) {
				$hasToolResult = false;
				foreach ( $content as $block ) {
					if ( ( $block['type'] ?? '' ) === 'tool_result' ) {
						$hasToolResult = true;
						$resultText    = '';
						$c             = $block['content'] ?? '';
						if ( is_array( $c ) ) {
							foreach ( $c as $sub ) {
								if ( ( $sub['type'] ?? '' ) === 'text' ) {
									$resultText = $sub['text'];
									break;
								}
							}
						} elseif ( is_string( $c ) ) {
							$resultText = $c;
						}

						if ( $resultText ) {
							$events[] = [
								'type'    => 'tool_result',
								'preview' => self::cleanResultText( mb_substr( $resultText, 0, 500 ) ),
								'length'  => mb_strlen( $resultText ),
							];
						}
					}
				}

				if ( ! $hasToolResult ) {
					foreach ( $content as $block ) {
						if ( ( $block['type'] ?? '' ) === 'text' && ! empty( trim( $block['text'] ?? '' ) ) ) {
							$events[] = [
								'type' => 'user_message',
								'text' => $block['text'],
							];
						}
					}
				}
			}
		} elseif ( $type === 'assistant' ) {
			$content = $obj['message']['content'] ?? $obj['content'] ?? [];
			foreach ( $content as $block ) {
				$bt = $block['type'] ?? '';
				if ( $bt === 'text' && ! empty( trim( $block['text'] ?? '' ) ) ) {
					$events[] = [
						'type' => 'text',
						'text' => $block['text'],
					];
				} elseif ( $bt === 'tool_use' ) {
					$tool  = $block['name'] ?? 'unknown';
					$input = $block['input'] ?? [];
					$event = [
						'type'     => 'tool_call',
						'tool'     => $tool,
						'category' => self::toolCategory( $tool ),
						'label'    => self::describeToolCall( $tool, $input ),
					];
					if ( $tool === 'TodoWrite' && ! empty( $input['todos'] ) ) {
						$event['todos'] = array_map( fn( $t ) => [
							'text'   => mb_substr( $t['content'] ?? $t['subject'] ?? '', 0, 80 ),
							'status' => $t['status'] ?? 'pending',
						], $input['todos'] );
					}
					$events[] = $event;
				}
			}
		} elseif ( $type === 'result' ) {
			$text = '';
			foreach ( ( $obj['content'] ?? [] ) as $block ) {
				if ( ( $block['type'] ?? '' ) === 'text' ) {
					$text = $block['text'];
					break;
				}
			}
			$u     = $obj['usage'] ?? [];
			$usage = null;
			if ( ! empty( $u ) ) {
				$usage = [
					'input'          => ( $u['input_tokens'] ?? 0 ) + ( $u['cache_creation_input_tokens'] ?? 0 ) + ( $u['cache_read_input_tokens'] ?? 0 ),
					'output'         => $u['output_tokens'] ?? 0,
					'cache_read'     => $u['cache_read_input_tokens'] ?? 0,
					'cache_creation' => $u['cache_creation_input_tokens'] ?? 0,
				];
			}
			$events[] = [
				'type'     => 'complete',
				'text'     => mb_substr( $text, 0, 500 ),
				'usage'    => $usage,
				'duration' => $obj['duration_ms'] ?? $obj['duration_api_ms'] ?? null,
				'turns'    => $obj['num_turns'] ?? null,
			];
		}

		return $events;
	}

	public static function toolCategory( string $tool ): string {
		return match ( $tool ) {
			'Bash'                                     => 'shell',
			'Read', 'Write', 'Edit'                    => 'file',
			'Glob', 'Grep', 'ToolSearch'               => 'search',
			'Agent', 'Task', 'TaskCreate', 'TaskOutput' => 'agent',
			'WebFetch', 'WebSearch'                    => 'web',
			'Skill'                                    => 'skill',
			'TodoWrite', 'NotebookEdit', 'LSP'         => 'misc',
			default                                    => 'other',
		};
	}

	public static function describeToolCall( string $tool, array $input ): string {
		return match ( $tool ) {
			'Bash'           => $input['description'] ?? mb_substr( $input['command'] ?? '', 0, 150 ),
			'Read'           => basename( $input['file_path'] ?? '' ),
			'Write'          => basename( $input['file_path'] ?? '' ),
			'Edit'           => basename( $input['file_path'] ?? '' ),
			'Glob'           => $input['pattern'] ?? '',
			'Grep'           => $input['pattern'] ?? '',
			'Agent'          => $input['description'] ?? mb_substr( $input['prompt'] ?? '', 0, 120 ),
			'ToolSearch'     => $input['query'] ?? '',
			'Task'           => $input['description'] ?? mb_substr( $input['prompt'] ?? '', 0, 120 ),
			'TaskCreate'     => $input['description'] ?? mb_substr( $input['prompt'] ?? '', 0, 120 ),
			'TaskOutput'     => 'task_' . substr( $input['task_id'] ?? '', 0, 8 ) . '...',
			'TaskGet'        => 'task_' . substr( $input['task_id'] ?? '', 0, 8 ) . '...',
			'TaskStop'       => 'task_' . substr( $input['task_id'] ?? '', 0, 8 ) . '...',
			'TaskUpdate'     => mb_substr( $input['description'] ?? ( $input['status'] ?? '' ), 0, 80 ),
			'TaskList'       => '',
			'TodoWrite'      => self::summarizeTodos( $input ),
			'WebFetch'       => parse_url( $input['url'] ?? '', PHP_URL_HOST ) ?: ( $input['url'] ?? '' ),
			'WebSearch'      => $input['query'] ?? '',
			'Skill'          => $input['skill'] ?? '',
			'LSP'            => $input['command'] ?? '',
			'NotebookEdit'   => basename( $input['notebook_path'] ?? $input['file_path'] ?? '' ),
			'EnterPlanMode'  => '',
			'ExitPlanMode'   => '',
			'EnterWorktree'  => '',
			'CronCreate'     => mb_substr( $input['schedule'] ?? '', 0, 40 ) . ' ' . mb_substr( $input['command'] ?? '', 0, 60 ),
			'CronDelete'     => $input['id'] ?? '',
			'CronList'       => '',
			default          => self::describeGenericTool( $input ),
		};
	}

	private static function summarizeTodos( array $input ): string {
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
		// Try common parameter names for a human-readable label.
		foreach ( [ 'description', 'query', 'prompt', 'command', 'name', 'message', 'url', 'path', 'file_path', 'text' ] as $key ) {
			if ( ! empty( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
				return mb_substr( $input[ $key ], 0, 120 );
			}
		}
		// Fallback: list parameter keys instead of dumping values.
		$keys = array_keys( $input );
		if ( empty( $keys ) ) {
			return '';
		}
		return implode( ', ', array_slice( $keys, 0, 5 ) );
	}

	public static function cleanResultText( string $text ): string {
		$text = preg_replace( '#<(retrieval_status|task_id|task_type|status|result|error|output)\b[^>]*>.*?</\1>#s', '', $text );
		$text = str_replace( '\\n', "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}
}
