<?php

/**
 * Central registry / dispatcher for session providers.
 *
 * Provider contract - every provider class must implement these static methods:
 *
 *   sourceId()                                     : string  // 'claude', 't3code', ...
 *   sourceLabel()                                  : string  // human-readable name
 *   listSessions(?string $project)                 : array   // session records
 *   listProjects()                                 : array   // project records
 *   getConversation(string $id)                    : array   // parsed message/event list
 *   hasSession(string $id)                         : bool    // ownership probe
 *   findSessionFile(string $id, ?string $project)  : ?string // canonical path or null
 *   fingerprint(array $session)                    : ?array  // ['mtime'=>int,'size'=>int]
 *   extractSessionText(array $session)             : string  // searchable text for FTS
 *   handleStream(string $id, int $runnerPid)       : void    // SSE endpoint (may echo history and exit)
 *
 * Optional (providers whose storage records token usage):
 *   extractUsage(array $session)                   : ?array  // ['input','output','cache_read','cache_creation']
 *
 * Optional (providers whose storage records per-event timestamps):
 *   extractTimings(array $session)                 : ?array  // ['started_at','ended_at','active_seconds','longest_active_seconds']
 *                                                            // build via Helpers::computeTimings()
 *
 * Session record shape (all providers):
 *   id, display, timestamp (ms), timestamp_s, project (path), projectName, size, source, sourceLabel
 * Optional: account + config_dir on Claude Code sessions from a non-primary
 *   config dir (CLAUDE_CONFIG_DIR accounts, see ClaudeSessions::homes()).
 * Optional: machine on sessions read from another computer's mirror (see
 *   Machines). Every call below runs the provider on the session's machine.
 *
 * Project record shape:
 *   path, name, sessions (count), latest (ms), sources[] (added by registry when aggregating)
 */
class SessionRegistry {

	/**
	 * Ordered provider registry. Keys are source IDs.
	 * Order matters for detectSource() probing (first match wins).
	 */
	public static function providers(): array {
		return [
			'amp'         => 'AmpSessions',
			'claude'      => 'ClaudeSessions',
			'commandcode' => 'CommandCodeSessions',
			't3code'      => 'T3CodeSessions',
			'opencode'    => 'OpenCodeSessions',
			'kimi'        => 'KimiSessions',
			'antigravity' => 'AntigravitySessions',
			'gemini'      => 'GeminiSessions',
			'grok'        => 'GrokSessions',
			'grokbot'     => 'GrokBotSessions',
			'codex'       => 'CodexSessions',
		];
	}

	/**
	 * Resolve source id → class name. Returns null for unknown.
	 */
	public static function provider( string $source ): ?string {
		$providers = self::providers();
		return $providers[ $source ] ?? null;
	}

	/**
	 * Detect which provider owns a session id.
	 * Cached per request since the same id may be probed multiple times.
	 */
	public static function detectSource( string $sessionId ): ?string {
		return self::locate( $sessionId )['source'] ?? null;
	}

	/**
	 * Which machine and provider own a session id. This computer is probed
	 * first, then each remote machine. Inside Machines::run() only that machine
	 * is probed. A given $source that no machine claims is assumed local, as
	 * before machines existed.
	 *
	 * @return array{machine:?string,source:string}|null
	 */
	public static function locate( string $sessionId, ?string $source = null ): ?array {
		static $cache = [];
		$inside = Machines::current();
		$key    = ( $inside ?? '*' ) . "\0" . $sessionId . "\0" . ( $source ?? '' );
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}
		$machines = $inside !== null ? [ $inside ] : Machines::names();
		foreach ( $machines as $machine ) {
			$found = Machines::run( $machine, function () use ( $sessionId, $source, $machine ) {
				foreach ( self::providers() as $id => $class ) {
					if ( ( $source && $source !== $id ) || ( $machine !== null && ! Machines::readsRemote( $id ) ) ) {
						continue;
					}
					try {
						if ( method_exists( $class, 'hasSession' ) && $class::hasSession( $sessionId ) ) {
							return $id;
						}
					} catch ( \Throwable $e ) {
						// Provider unavailable - skip.
					}
				}
				return null;
			} );
			if ( $found ) {
				return $cache[ $key ] = [ 'machine' => $machine, 'source' => $found ];
			}
		}
		return $cache[ $key ] = $source ? [ 'machine' => $inside, 'source' => $source ] : null;
	}

	/**
	 * Look up a single session by id (and optional source). Falls back to
	 * probing providers. Used for session viewer meta when the id is a nested
	 * subagent not present in the flat list.
	 */
	public static function getSession( string $sessionId, ?string $source = null ): ?array {
		$machine = self::locate( $sessionId, $source )['machine'] ?? null;
		$session = Machines::run( $machine, fn() => self::getSessionHere( $sessionId, $source ) );
		if ( $session && $machine !== null ) {
			$session['machine'] = $machine;
		}
		return $session;
	}

	private static function getSessionHere( string $sessionId, ?string $source = null ): ?array {
		$providers = self::providers();
		if ( $source && isset( $providers[ $source ] ) ) {
			$class = $providers[ $source ];
			if ( method_exists( $class, 'getSession' ) ) {
				try {
					$s = $class::getSession( $sessionId );
					if ( $s ) {
						$s['source']      = $source;
						$s['sourceLabel'] = $class::sourceLabel();
						return $s;
					}
				} catch ( \Throwable $e ) {
					// fall through
				}
			}
			// Fallback: scan that provider's list (and nested children).
			try {
				foreach ( $class::listSessions() as $s ) {
					if ( ( $s['id'] ?? '' ) === $sessionId ) {
						$s['source']      = $source;
						$s['sourceLabel'] = $class::sourceLabel();
						return $s;
					}
					foreach ( $s['children'] ?? [] as $child ) {
						if ( ( $child['id'] ?? '' ) === $sessionId ) {
							$child['source']      = $source;
							$child['sourceLabel'] = $class::sourceLabel();
							$child['project']     = $child['project'] ?? $s['project'] ?? '';
							$child['projectName'] = $child['projectName'] ?? $s['projectName'] ?? '';
							$child['parent_id']   = $s['id'];
							$child['is_subagent'] = true;
							return $child;
						}
					}
				}
			} catch ( \Throwable $e ) {
				// fall through
			}
		}

		$detected = self::detectSource( $sessionId );
		if ( $detected && $detected !== $source ) {
			return self::getSessionHere( $sessionId, $detected );
		}

		// Last resort: walk all providers' lists + nested children.
		foreach ( $providers as $id => $class ) {
			if ( $source && $source !== $id ) {
				continue;
			}
			try {
				if ( method_exists( $class, 'getSession' ) ) {
					$s = $class::getSession( $sessionId );
					if ( $s ) {
						$s['source']      = $id;
						$s['sourceLabel'] = $class::sourceLabel();
						return $s;
					}
				}
				foreach ( $class::listSessions() as $s ) {
					if ( ( $s['id'] ?? '' ) === $sessionId ) {
						$s['source']      = $id;
						$s['sourceLabel'] = $class::sourceLabel();
						return $s;
					}
					foreach ( $s['children'] ?? [] as $child ) {
						if ( ( $child['id'] ?? '' ) === $sessionId ) {
							$child['source']      = $id;
							$child['sourceLabel'] = $class::sourceLabel();
							$child['project']     = $child['project'] ?? $s['project'] ?? '';
							$child['projectName'] = $child['projectName'] ?? $s['projectName'] ?? '';
							$child['parent_id']   = $s['id'];
							$child['is_subagent'] = true;
							return $child;
						}
					}
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return null;
	}

	/**
	 * List sessions across providers, tagged with source.
	 * Pass $source to restrict to one provider.
	 */
	/**
	 * Seconds a built list stays servable. A dashboard load fires five endpoints
	 * at once and each one used to walk every provider's transcript directory
	 * independently, so the same scan ran five times concurrently and the copies
	 * fought each other for disk. They now share one walk. This has to comfortably
	 * exceed how long a walk takes, or the entry expires before anything can reuse
	 * it and the cache does nothing but add a lock.
	 */
	private const LIST_CACHE_SECONDS = 90;

	/**
	 * Run $build once and share the result with everything that asks for the
	 * same $key in the next LIST_CACHE_SECONDS. Concurrent callers serialise on
	 * a lock file: the first walks the providers, the rest wait and then read
	 * what it wrote instead of running more copies of the same scan.
	 */
	private static function cachedList( string $key, bool $fresh, callable $build ): array {
		static $memo = [];
		if ( ! $fresh && isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$file = null;
		$lock = null;
		if ( defined( 'DATA_DIR' ) ) {
			$dir = DATA_DIR . '/list-cache';
			if ( is_dir( $dir ) || @mkdir( $dir, 0700, true ) ) {
				$file = $dir . '/' . $key . '.json';
				$lock = @fopen( $file . '.lock', 'c' );
				if ( $lock ) {
					@flock( $lock, LOCK_EX );
				}
			}
		}

		if ( ! $fresh && $file !== null && is_file( $file )
			&& ( time() - (int) @filemtime( $file ) ) < self::LIST_CACHE_SECONDS ) {
			$cached = json_decode( (string) @file_get_contents( $file ), true );
			if ( is_array( $cached ) ) {
				self::releaseLock( $lock );
				$memo[ $key ] = $cached;
				return $cached;
			}
		}

		$results = $build();

		if ( $file !== null ) {
			@file_put_contents( $file, json_encode( $results, JSON_UNESCAPED_SLASHES ), LOCK_EX );
		}
		self::releaseLock( $lock );
		$memo[ $key ] = $results;
		return $results;
	}

	private static function releaseLock( $lock ): void {
		if ( $lock ) {
			@flock( $lock, LOCK_UN );
			@fclose( $lock );
		}
	}

	/**
	 * Assemble the cross-provider session list, reusing a recent build when one
	 * is available. Pass $fresh to force a walk (explicit rebuilds only).
	 */
	public static function listSessions( ?string $project = null, ?string $source = null, bool $fresh = false ): array {
		$key = 's-' . sha1( ( $project ?? '' ) . "\0" . ( $source ?? '' ) );
		return self::cachedList( $key, $fresh, fn() => self::buildSessionList( $project, $source ) );
	}

	private static function buildSessionList( ?string $project, ?string $source ): array {
		$results = [];
		$seen    = [];
		// This computer first, so a session both machines hold shows as local.
		foreach ( Machines::names() as $machine ) {
			Machines::run( $machine, function () use ( $machine, $project, $source, &$results, &$seen ) {
				foreach ( self::providers() as $id => $class ) {
					if ( ( $source && $source !== $id ) || ( $machine !== null && ! Machines::readsRemote( $id ) ) ) {
						continue;
					}
					try {
						$sessions = $class::listSessions( $project );
					} catch ( \Throwable $e ) {
						continue;
					}
					$label = $class::sourceLabel();
					foreach ( $sessions as $s ) {
						$key = $id . "\0" . ( $s['id'] ?? '' );
						if ( isset( $seen[ $key ] ) ) {
							continue;
						}
						$seen[ $key ]     = true;
						$s['source']      = $id;
						$s['sourceLabel'] = $label;
						if ( $machine !== null ) {
							$s['machine'] = $machine;
							foreach ( $s['children'] ?? [] as $i => $child ) {
								$s['children'][ $i ]['machine'] = $machine;
							}
						}
						$results[] = $s;
					}
				}
			} );
		}
		usort( $results, fn( $a, $b ) => ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 ) );
		return $results;
	}

	/**
	 * List unique projects across providers.
	 * Duplicate paths are merged, with 'sources' tracking which providers have data.
	 */
	public static function listProjects( ?string $source = null, bool $fresh = false ): array {
		$key = 'p-' . sha1( $source ?? '' );
		return self::cachedList( $key, $fresh, fn() => self::buildProjectList( $source ) );
	}

	private static function buildProjectList( ?string $source ): array {
		$byPath = [];
		$lists  = [];
		foreach ( Machines::names() as $machine ) {
			Machines::run( $machine, function () use ( $machine, $source, &$lists ) {
				foreach ( self::providers() as $id => $class ) {
					if ( ( $source && $source !== $id ) || ( $machine !== null && ! Machines::readsRemote( $id ) ) ) {
						continue;
					}
					try {
						$lists[] = [ $id, $class::listProjects() ];
					} catch ( \Throwable $e ) {
						continue;
					}
				}
			} );
		}
		foreach ( $lists as [ $id, $projects ] ) {
			foreach ( $projects as $p ) {
				$key = $p['path'] ?? '';
				if ( $key === '' ) {
					continue;
				}
				if ( isset( $byPath[ $key ] ) ) {
					$byPath[ $key ]['sessions'] += ( $p['sessions'] ?? 0 );
					$byPath[ $key ]['latest']    = max( $byPath[ $key ]['latest'], $p['latest'] ?? 0 );
					$byPath[ $key ]['sources'][] = $id;
				} else {
					$p['sources']   = [ $id ];
					$byPath[ $key ] = $p;
				}
			}
		}
		$result = array_values( array_map( function ( $p ) {
			$p['sources'] = array_values( array_unique( $p['sources'] ) );
			return $p;
		}, $byPath ) );
		usort( $result, fn( $a, $b ) => ( $b['latest'] ?? 0 ) <=> ( $a['latest'] ?? 0 ) );
		return $result;
	}

	/**
	 * Fetch a conversation by id. Source is auto-detected when omitted.
	 */
	public static function getConversation( string $id, ?string $source = null ): array {
		$loc   = self::locate( $id, $source ?: null );
		$class = $loc ? self::provider( $loc['source'] ) : null;
		if ( ! $class ) {
			return [];
		}
		return Machines::run( $loc['machine'], fn() => $class::getConversation( $id ) );
	}

	/** SSE replay of a session (stream.php), on the machine that holds it. */
	public static function stream( string $id, ?string $source = null ): bool {
		$loc   = self::locate( $id, $source ?: null );
		$class = $loc ? self::provider( $loc['source'] ) : null;
		if ( ! $class ) {
			return false;
		}
		Machines::run( $loc['machine'], fn() => $class::handleStream( $id, 0 ) );
		return true;
	}

	/**
	 * Locate the canonical storage path for a session.
	 */
	public static function findSessionFile( string $id, ?string $project = null, ?string $source = null ): ?string {
		$loc   = self::locate( $id, $source ?: null );
		$class = $loc ? self::provider( $loc['source'] ) : null;
		if ( ! $class ) {
			return null;
		}
		return Machines::run( $loc['machine'], fn() => $class::findSessionFile( $id, $project ) );
	}

	/**
	 * Fingerprint a single session (mtime + size) for stale-detection in the search index.
	 */
	public static function fingerprint( array $session ): ?array {
		$src   = $session['source'] ?? null;
		$class = $src ? self::provider( $src ) : null;
		if ( ! $class ) {
			return null;
		}
		return Machines::run( $session['machine'] ?? null, fn() => $class::fingerprint( $session ) );
	}

	/**
	 * Extract searchable text (used by SearchIndex during re-indexing).
	 */
	public static function extractSessionText( array $session ): string {
		$src   = $session['source'] ?? null;
		$class = $src ? self::provider( $src ) : null;
		if ( ! $class ) {
			return '';
		}
		return Machines::run( $session['machine'] ?? null, fn() => $class::extractSessionText( $session ) );
	}

	/**
	 * How trustworthy a provider's token numbers are.
	 * 'measured'  - real API-reported usage from the transcripts
	 * 'estimated' - derived from transcript text (~4 chars/token); kept out of All rollups
	 * 'none'      - nothing to compute from (Antigravity stores no transcripts)
	 */
	public static function usageType( string $source ): string {
		switch ( $source ) {
			case 'claude':
			case 'opencode':
			case 'kimi':
			case 'amp':
			case 'gemini':
			case 'codex':
			case 'grok':
				return 'measured';
			case 'commandcode':
			case 't3code':
			case 'grokbot':
				return 'estimated';
			default:
				return 'none';
		}
	}

	/**
	 * Provider ids whose usage numbers are estimates.
	 */
	public static function estimatedSources(): array {
		$out = [];
		foreach ( self::providers() as $id => $class ) {
			if ( self::usageType( $id ) === 'estimated' ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Total token usage for a session, or null when the provider doesn't
	 * track it. Measured for Claude Code, OpenCode, Kimi, Amp, Gemini, Codex,
	 * Grok Build (see usageType()); estimated for Command Code, T3 Code, and Grok Bot;
	 * Antigravity has nothing.
	 */
	public static function extractUsage( array $session ): ?array {
		$src   = $session['source'] ?? null;
		$class = $src ? self::provider( $src ) : null;
		if ( ! $class || ! method_exists( $class, 'extractUsage' ) ) {
			return null;
		}
		try {
			return Machines::run( $session['machine'] ?? null, fn() => $class::extractUsage( $session ) );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Session runtime stats (active time, longest uninterrupted stretch), or
	 * null when the provider's storage has no per-event timestamps. Currently
	 * Claude Code, OpenCode, and Codex; Grok Build carries its own duration_ms
	 * signal on the session record instead.
	 */
	public static function extractTimings( array $session ): ?array {
		$src   = $session['source'] ?? null;
		$class = $src ? self::provider( $src ) : null;
		if ( ! $class || ! method_exists( $class, 'extractTimings' ) ) {
			return null;
		}
		try {
			return Machines::run( $session['machine'] ?? null, fn() => $class::extractTimings( $session ) );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Deep search across providers, powered by the FTS index.
	 * Transparently indexes stale sessions on first query.
	 */
	public static function deepSearch( string $query, ?string $project = null, ?string $source = null ): array {
		if ( mb_strlen( $query ) < 3 ) {
			return [];
		}

		try {
			require_once BASE_DIR . '/app/SearchIndex.php';

			$sessions = self::listSessions( $project, $source );

			$stale = SearchIndex::getStaleSessions( $sessions );
			$stale = array_values( array_filter( $stale, function ( $session ) {
				return ( $session['source'] ?? '' ) !== 'amp' || AmpSessions::canIndexWithoutFetch( $session );
			} ) );
			if ( ! empty( $stale ) ) {
				SearchIndex::indexSessions( $stale );
			}

			return SearchIndex::search( $query, $project, $source );
		} catch ( \Throwable $e ) {
			// Fall back to Claude's legacy grep path - non-Claude providers have no
			// equivalent, so they return empty when FTS is broken rather than
			// surfacing Claude results under the wrong source.
			if ( in_array( $source, [ 'amp', 't3code', 'kimi', 'opencode', 'antigravity', 'grok', 'grokbot' ], true ) ) {
				return [];
			}
			return ClaudeSessions::deepSearch( $query, $project );
		}
	}
}
