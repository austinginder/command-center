<?php
/**
 * FlowExtractor - reconstruct the "direction flow" of a project from Claude Code
 * session transcripts. The code the agent wrote is disposable; the sequence of
 * human instructions that steered it is the durable, learnable artifact.
 *
 * Produces three views over the same recovered record:
 *   - cliffnotes : the learn-from-it distillation (arc, steering, course-corrections, cost)
 *   - forensic   : every user turn, verbatim, interleaved with a one-line agent trace
 *   - json       : structured payload - the ingest format for a "browse the build" site
 */
class FlowExtractor {

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

	// ─── Project / session resolution ───────────────────────────

	/** Resolve a loose project query ("dismissed", a name, or a full cwd path) to a projects/ dir. */
	public static function resolveProjectDir( string $query ): ?string {
		$projectsDir = self::claudeDir() . '/projects';
		if ( ! is_dir( $projectsDir ) ) {
			return null;
		}

		// Full cwd path → encode ( / and . both become - ).
		if ( $query !== '' && $query[0] === '/' ) {
			$encoded = '-' . str_replace( [ '/', '.' ], '-', ltrim( $query, '/' ) );
			$dir     = $projectsDir . '/' . $encoded;
			if ( is_dir( $dir ) ) {
				return $dir;
			}
		}

		$needle   = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $query ) );
		$matches  = [];
		foreach ( scandir( $projectsDir ) as $d ) {
			if ( $d[0] === '.' ) {
				continue;
			}
			$hay = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $d ) );
			if ( $needle === '' || str_contains( $hay, $needle ) ) {
				$count     = count( glob( $projectsDir . '/' . $d . '/*.jsonl' ) );
				$matches[] = [ 'dir' => $projectsDir . '/' . $d, 'count' => $count, 'len' => strlen( $d ) ];
			}
		}
		if ( empty( $matches ) ) {
			return null;
		}
		// Prefer the busiest project; break ties by shortest (most specific) dir name.
		usort( $matches, fn( $a, $b ) => $b['count'] <=> $a['count'] ?: $a['len'] <=> $b['len'] );
		return $matches[0]['dir'];
	}

	/** Resolve a full or partial (>=6 char) session id to its .jsonl path. */
	public static function resolveSessionFile( string $partial ): ?string {
		if ( strlen( $partial ) < 6 ) {
			return null;
		}
		$projectsDir = self::claudeDir() . '/projects';
		if ( ! is_dir( $projectsDir ) ) {
			return null;
		}
		foreach ( scandir( $projectsDir ) as $d ) {
			if ( $d[0] === '.' ) {
				continue;
			}
			// Exact hit first.
			$exact = $projectsDir . '/' . $d . '/' . $partial . '.jsonl';
			if ( is_file( $exact ) ) {
				return $exact;
			}
			foreach ( glob( $projectsDir . '/' . $d . '/' . $partial . '*.jsonl' ) as $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/** Every session file in a project dir, sorted by first-message time ascending. */
	public static function listProjectSessions( string $dir ): array {
		$out = [];
		foreach ( glob( $dir . '/*.jsonl' ) as $file ) {
			$firstTs = self::firstTimestamp( $file );
			$out[]   = [ 'id' => basename( $file, '.jsonl' ), 'file' => $file, 'ts' => $firstTs ];
		}
		usort( $out, fn( $a, $b ) => strcmp( $a['ts'] ?? '', $b['ts'] ?? '' ) );
		return $out;
	}

	private static function firstTimestamp( string $file ): ?string {
		$fp = fopen( $file, 'r' );
		if ( ! $fp ) {
			return null;
		}
		$ts = null;
		$n  = 0;
		while ( ( $line = fgets( $fp ) ) !== false && $n++ < 40 ) {
			$obj = json_decode( $line, true );
			if ( $obj && ! empty( $obj['timestamp'] ) ) {
				$ts = $obj['timestamp'];
				break;
			}
		}
		fclose( $fp );
		return $ts;
	}

	// ─── Per-session parse ──────────────────────────────────────

	/**
	 * Parse one session into interleaved turns + stats.
	 * Returns [ 'turns' => [...], 'stats' => [...] ].
	 * A turn is either:
	 *   [ 'role'=>'user', 'text'=>..., 'kind'=>'prompt|slash|interrupt', 'ts'=>hh:mm ]
	 *   [ 'role'=>'agent', 'tools'=>[ [name,label], ... ] ]
	 */
	public static function parseSession( string $file ): array {
		$turns   = [];
		$pending = [];
		$stats   = [
			'user_turns' => 0, 'tool_calls' => 0, 'assistant_messages' => 0, 'images' => 0,
			'models' => [], 'tokens' => [ 'output' => 0, 'fresh_input' => 0, 'cache_read' => 0 ],
			'top_tools' => [],
		];

		$flush = function () use ( &$turns, &$pending ) {
			if ( ! empty( $pending ) ) {
				$turns[]  = [ 'role' => 'agent', 'tools' => $pending ];
				$pending  = [];
			}
		};

		$fp = fopen( $file, 'r' );
		if ( ! $fp ) {
			return [ 'turns' => [], 'stats' => $stats ];
		}

		while ( ( $line = fgets( $fp ) ) !== false ) {
			$obj = json_decode( $line, true );
			if ( ! $obj ) {
				continue;
			}
			$type = $obj['type'] ?? '';

			if ( $type === 'user' ) {
				$content   = $obj['message']['content'] ?? '';
				$hasImg    = false;
				$imgTypes  = [];
				$text      = '';
				if ( is_string( $content ) ) {
					$text = $content;
				} elseif ( is_array( $content ) ) {
					// Pure tool_result turns are agent plumbing, not user speech.
					$onlyToolResult = ! empty( $content );
					foreach ( $content as $b ) {
						$bt = $b['type'] ?? '';
						if ( $bt !== 'tool_result' ) {
							$onlyToolResult = false;
						}
						if ( $bt === 'image' ) {
							$hasImg     = true;
							$imgTypes[] = $b['source']['media_type'] ?? 'image';
						}
						if ( $bt === 'text' ) {
							$text .= ( $text ? "\n" : '' ) . ( $b['text'] ?? '' );
						}
					}
					if ( $onlyToolResult ) {
						continue;
					}
				}

				[ $clean, $kind ] = self::cleanUserText( trim( $text ) );
				if ( $clean === null && ! $hasImg ) {
					continue;
				}
				if ( $clean === null && $hasImg ) {
					$clean = '[image]';
					$kind  = 'prompt';
				} elseif ( $hasImg && $kind === 'prompt' && ! str_starts_with( $clean, '[image]' ) ) {
					$clean = '[+image] ' . $clean;
				}

				$flush();
				$stamp = '';
				if ( ! empty( $obj['timestamp'] ) ) {
					$stamp = substr( $obj['timestamp'], 11, 5 );
				}
				$turns[] = [ 'role' => 'user', 'text' => $clean, 'kind' => $kind, 'ts' => $stamp, 'images' => count( $imgTypes ) ];
				$stats['images'] += count( $imgTypes );
				if ( $kind !== 'interrupt' ) {
					$stats['user_turns']++;
				}
			} elseif ( $type === 'assistant' ) {
				$msg = $obj['message'] ?? [];
				$stats['assistant_messages']++;
				if ( ! empty( $msg['model'] ) ) {
					$stats['models'][ $msg['model'] ] = ( $stats['models'][ $msg['model'] ] ?? 0 ) + 1;
				}
				if ( ! empty( $msg['usage'] ) ) {
					$u                                = $msg['usage'];
					$stats['tokens']['output']       += $u['output_tokens'] ?? 0;
					$stats['tokens']['fresh_input']  += ( $u['input_tokens'] ?? 0 ) + ( $u['cache_creation_input_tokens'] ?? 0 );
					$stats['tokens']['cache_read']   += $u['cache_read_input_tokens'] ?? 0;
				}
				foreach ( ( $msg['content'] ?? [] ) as $b ) {
					if ( ( $b['type'] ?? '' ) === 'tool_use' ) {
						$name    = $b['name'] ?? '?';
						$label   = ClaudeSessions::describeToolCall( $name, $b['input'] ?? [] );
						$pending[] = [ $name, $label ];
						$stats['tool_calls']++;
						$stats['top_tools'][ $name ] = ( $stats['top_tools'][ $name ] ?? 0 ) + 1;
					}
				}
			}
		}
		fclose( $fp );
		$flush();

		arsort( $stats['top_tools'] );
		return [ 'turns' => $turns, 'stats' => $stats ];
	}

	/**
	 * Decode every base64 image embedded in a session's user turns to $outDir.
	 * Images are stored inline in the transcript (not the transient image-cache),
	 * so they are always recoverable. Feeds the editor pass that captions them.
	 * Returns a manifest: [ [ file, turn, image_index, media_type, bytes, paired_text ], ... ].
	 */
	public static function decodeImages( string $file, string $outDir ): array {
		if ( ! is_dir( $outDir ) ) {
			@mkdir( $outDir, 0777, true );
		}
		$fp = fopen( $file, 'r' );
		if ( ! $fp ) {
			return [];
		}
		$manifest = [];
		$turn     = 0;
		while ( ( $line = fgets( $fp ) ) !== false ) {
			$obj = json_decode( $line, true );
			if ( ! $obj || ( $obj['type'] ?? '' ) !== 'user' ) {
				continue;
			}
			$content = $obj['message']['content'] ?? '';
			if ( ! is_array( $content ) ) {
				continue;
			}
			$imgs = array_values( array_filter( $content, fn( $b ) => is_array( $b ) && ( $b['type'] ?? '' ) === 'image' ) );
			if ( empty( $imgs ) ) {
				continue;
			}
			$turn++;
			$paired = '';
			foreach ( $content as $b ) {
				if ( ( $b['type'] ?? '' ) === 'text' ) {
					$paired = mb_substr( $b['text'] ?? '', 0, 200 );
				}
			}
			foreach ( $imgs as $j => $b ) {
				$src = $b['source'] ?? [];
				if ( ( $src['type'] ?? '' ) !== 'base64' || empty( $src['data'] ) ) {
					continue;
				}
				$ext  = str_contains( $src['media_type'] ?? '', 'jpeg' ) ? 'jpg' : 'png';
				$name = sprintf( 't%02d_img%d.%s', $turn, $j + 1, $ext );
				$raw  = base64_decode( $src['data'] );
				file_put_contents( $outDir . '/' . $name, $raw );
				$manifest[] = [
					'file'       => $name,
					'turn'       => $turn,
					'index'      => $j + 1,
					'media_type' => $src['media_type'] ?? '',
					'bytes'      => strlen( $raw ),
					'paired'     => $paired,
				];
			}
		}
		fclose( $fp );
		return $manifest;
	}

	/** Normalize a raw user string; classify and drop injected non-utterances. */
	private static function cleanUserText( string $text ): array {
		if ( $text === '' ) {
			return [ null, null ];
		}
		// Slash-command invocation.
		if ( preg_match( '#<command-name>\s*(/[^<]+?)\s*</command-name>#', $text, $m ) ) {
			$cmd  = trim( $m[1] );
			$args = '';
			if ( preg_match( '#<command-args>(.*?)</command-args>#s', $text, $a ) ) {
				$args = trim( $a[1] );
			}
			return [ '[slash] ' . $cmd . ( $args !== '' ? ' ' . $args : '' ), 'slash' ];
		}
		// Strip harness-injected wrappers.
		$text = preg_replace( '#<system-reminder>.*?</system-reminder>#s', '', $text );
		$text = preg_replace( '#<command-message>.*?</command-message>#s', '', $text );
		$text = preg_replace( '#<task-notification>.*?</task-notification>#s', '', $text );
		$text = preg_replace( '#<local-command-[^>]*>.*?</local-command-[^>]*>#s', '', $text );
		$text = trim( $text );

		if ( str_starts_with( $text, '[Request interrupted' ) ) {
			return [ $text, 'interrupt' ];
		}
		if ( $text === '' ) {
			return [ null, null ];
		}
		foreach ( [ 'Caveat:', '[Image: source:', 'Base directory for this skill:', 'The following is the result' ] as $skip ) {
			if ( str_starts_with( $text, $skip ) ) {
				return [ null, null ];
			}
		}
		return [ $text, 'prompt' ];
	}

	// ─── Project-level cliffnotes ───────────────────────────────

	/** Heuristic: is this user turn a substantive course-correction worth learning from? */
	public static function isCorrection( array $turn ): bool {
		if ( $turn['kind'] !== 'prompt' ) {
			return false; // interrupts are tallied separately; they carry no text to learn from
		}
		$t = strtolower( ltrim( $turn['text'], '[+image] ' ) );
		$starts = [ 'no ', 'no,', 'no.', 'nope', 'actually', 'instead', "don't", 'dont', 'revert',
			'undo', "that's not", 'thats not', 'not quite', 'wait', 'hmm', 'stop', 'wrong',
			'hold on', "let's not", 'rather', 'why ', 'why\'', 'seems to be a bug', 'this is wrong' ];
		foreach ( $starts as $s ) {
			if ( str_starts_with( $t, $s ) ) {
				return true;
			}
		}
		foreach ( [ ' bug', 'broken', "doesn't work", 'not working', 'is wrong', 'still not', 'still broken' ] as $c ) {
			if ( str_contains( $t, $c ) ) {
				return true;
			}
		}
		return false;
	}

	/** Build the whole-project flow payload. */
	public static function projectFlow( string $dir ): array {
		$sessions = self::listProjectSessions( $dir );
		$out = [
			'project'     => [ 'name' => self::dirToName( basename( $dir ) ), 'dir' => $dir ],
			'totals'      => [ 'sessions' => 0, 'user_turns' => 0, 'tool_calls' => 0, 'assistant_messages' => 0 ],
			'models'      => [],
			'tokens'      => [ 'output' => 0, 'fresh_input' => 0, 'cache_read' => 0 ],
			'steering'    => [ 'slash_sessions' => 0, 'images' => 0, 'urls' => 0, 'interrupts' => 0, 'corrections' => 0 ],
			'spine'       => [],
			'corrections' => [],
			'sessions'    => [],
			'span'        => [ 'start' => null, 'end' => null ],
		];

		foreach ( $sessions as $s ) {
			$p = self::parseSession( $s['file'] );
			$userTurns = array_values( array_filter( $p['turns'], fn( $t ) => $t['role'] === 'user' ) );
			$realTurns = array_values( array_filter( $userTurns, fn( $t ) => $t['kind'] !== 'interrupt' ) );
			if ( empty( $realTurns ) ) {
				continue;
			}

			$st = $p['stats'];
			$out['totals']['sessions']++;
			$out['totals']['user_turns']         += $st['user_turns'];
			$out['totals']['tool_calls']         += $st['tool_calls'];
			$out['totals']['assistant_messages'] += $st['assistant_messages'];
			foreach ( $st['models'] as $m => $c ) {
				$out['models'][ $m ] = ( $out['models'][ $m ] ?? 0 ) + $c;
			}
			foreach ( $st['tokens'] as $k => $v ) {
				$out['tokens'][ $k ] += $v;
			}

			$opener = $realTurns[0]['text'];
			if ( str_starts_with( $opener, '[slash]' ) ) {
				$out['steering']['slash_sessions']++;
			}
			$out['steering']['images'] += $st['images'];
			foreach ( $userTurns as $t ) {
				if ( preg_match( '#https?://#', $t['text'] ) ) {
					$out['steering']['urls']++;
				}
				if ( $t['kind'] === 'interrupt' ) {
					$out['steering']['interrupts']++;
				}
				if ( self::isCorrection( $t ) ) {
					$out['steering']['corrections']++;
					if ( count( $out['corrections'] ) < 14 ) {
						$out['corrections'][] = [
							'ts'   => substr( $s['ts'] ?? '', 0, 10 ),
							'id'   => substr( $s['id'], 0, 8 ),
							'text' => mb_substr( $t['text'], 0, 220 ),
						];
					}
				}
			}

			$out['spine'][] = [
				'ts'     => substr( $s['ts'] ?? '', 0, 16 ),
				'id'     => substr( $s['id'], 0, 8 ),
				'prompt' => mb_substr( $opener, 0, 240 ),
			];

			$top = [];
			foreach ( array_slice( $st['top_tools'], 0, 3, true ) as $name => $c ) {
				$top[] = [ $name, $c ];
			}
			$out['sessions'][] = [
				'id'         => substr( $s['id'], 0, 8 ),
				'ts'         => substr( $s['ts'] ?? '', 0, 16 ),
				'user_turns' => $st['user_turns'],
				'tool_calls' => $st['tool_calls'],
				'images'     => $st['images'],
				'top_tools'  => $top,
				'opener'     => mb_substr( $opener, 0, 160 ),
				'model'      => array_key_first( $st['models'] ) ?: '',
				'tokens'     => $st['tokens'],
			];

			$sdate = substr( $s['ts'] ?? '', 0, 10 );
			if ( $sdate ) {
				$out['span']['start'] = $out['span']['start'] ? min( $out['span']['start'], $sdate ) : $sdate;
				$out['span']['end']   = $out['span']['end'] ? max( $out['span']['end'], $sdate ) : $sdate;
			}
		}

		$out['span']['days'] = self::daysBetween( $out['span']['start'], $out['span']['end'] );
		arsort( $out['models'] );
		return $out;
	}

	/**
	 * Editor brief - a single deterministic bundle an AI agent uses to WRITE the recap.
	 * Does NOT write prose itself. Bundles: identity, manifest, arc (the wake), spine,
	 * tacks, the genesis session's interleaved flow (verbatim headings for quotes), and
	 * the decoded landmark screenshots (paths + paired text for the agent to caption).
	 * Returns [] if the project has no sessions with user turns.
	 */
	public static function editorBrief( string $dir, string $imagesDir ): array {
		$flow = self::projectFlow( $dir );
		if ( $flow['totals']['sessions'] === 0 ) {
			return [];
		}

		// Map session id-prefix → file (listProjectSessions gives full ids + files).
		$files = [];
		foreach ( self::listProjectSessions( $dir ) as $s ) {
			$files[ $s['id'] ] = $s['file'];
		}
		$fileForPrefix = function ( string $prefix ) use ( $files ) {
			foreach ( $files as $full => $file ) {
				if ( str_starts_with( $full, $prefix ) ) {
					return $file;
				}
			}
			return null;
		};

		// Feature session: the most representative session (headings + screenshots),
		// NOT merely the earliest - some projects open with a throwaway setup session.
		// Its interleaved flow supplies the verbatim quotes, and its screenshots supply
		// the landmarks, so quotes and landmarks always line up in the same story.
		$featureMeta = $flow['sessions'][0];
		$best        = -1;
		foreach ( $flow['sessions'] as $s ) {
			$score = (int) ( $s['user_turns'] ?? 0 ) + 2 * (int) ( $s['images'] ?? 0 );
			if ( $score > $best ) {
				$best        = $score;
				$featureMeta = $s;
			}
		}
		$featureFile = $fileForPrefix( $featureMeta['id'] );
		$featureSeq  = [];
		if ( $featureFile ) {
			$parsed = self::parseSession( $featureFile );
			$hn     = 0;
			foreach ( $parsed['turns'] as $t ) {
				if ( $t['role'] === 'user' ) {
					if ( $t['kind'] === 'interrupt' ) {
						continue;
					}
					$hn++;
					$featureSeq[] = [
						'heading' => $hn, 'ts' => $t['ts'], 'kind' => $t['kind'],
						'images'  => $t['images'] ?? 0, 'text' => $t['text'],
					];
				} else {
					$counts = [];
					foreach ( $t['tools'] as [ $name, $label ] ) {
						$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
					}
					$featureSeq[] = [ 'agent' => $counts, 'calls' => array_sum( $counts ) ];
				}
			}
		}
		$landmarks = [ 'session' => $featureMeta['id'], 'dir' => $imagesDir, 'items' => [] ];
		if ( ( $featureMeta['images'] ?? 0 ) > 0 && $featureFile ) {
			$landmarks['items'] = self::decodeImages( $featureFile, $imagesDir );
		}

		$topModel = array_key_first( array_filter( $flow['models'], fn( $m ) => $m[0] !== '<', ARRAY_FILTER_USE_KEY ) ) ?? array_key_first( $flow['models'] );

		return [
			'_note'    => 'Editor brief for writing a Rutter recap. Read the landmark images from `landmarks.dir` and caption them; quote from `feature.sequence` verbatim (the most representative session, which is also where the landmarks come from); follow house style (short sentences, NO em-dashes).',
			'identity' => [
				'suggested_slug' => self::dirToSlug( basename( $dir ) ),
				'project_name'   => $flow['project']['name'],
				'span'           => $flow['span'],
				'model'          => $topModel,
			],
			'manifest' => [
				'sessions'     => $flow['totals']['sessions'],
				'headings'     => $flow['totals']['user_turns'],
				'agent_tools'  => $flow['totals']['tool_calls'],
				'landmarks'    => $flow['steering']['images'],
				'tokens_out'   => $flow['tokens']['output'],
				'tokens_in'    => $flow['tokens']['fresh_input'],
				'corrections'  => $flow['steering']['corrections'],
				'slash'        => $flow['steering']['slash_sessions'],
			],
			'arc'      => array_column( $flow['sessions'], 'tool_calls' ),
			'spine'    => $flow['spine'],
			'tacks'    => $flow['corrections'],
			'feature'  => [
				'id'       => $featureMeta['id'],
				'ts'       => $featureMeta['ts'],
				'headings' => $featureMeta['user_turns'],
				'tools'    => $featureMeta['tool_calls'],
				'sequence' => $featureSeq,
			],
			'landmarks' => $landmarks,
		];
	}

	private static function dirToSlug( string $encoded ): string {
		$name = self::dirToName( $encoded );
		return preg_replace( '/-(localhost-public|localhost|public)$/', '', $name );
	}

	private static function dirToName( string $encoded ): string {
		// -Users-name-Cove-Sites-dismissed-localhost-public → dismissed-localhost-public
		$parts = explode( '-Sites-', $encoded );
		return $parts[1] ?? ltrim( $encoded, '-' );
	}

	private static function daysBetween( ?string $a, ?string $b ): int {
		if ( ! $a || ! $b ) {
			return 0;
		}
		return (int) round( ( strtotime( $b ) - strtotime( $a ) ) / 86400 ) + 1;
	}
}
