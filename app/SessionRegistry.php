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
 * Session record shape (all providers):
 *   id, display, timestamp (ms), timestamp_s, project (path), projectName, size, source, sourceLabel
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
			'claude'      => 'ClaudeSessions',
			'commandcode' => 'CommandCodeSessions',
			't3code'      => 'T3CodeSessions',
			'opencode'    => 'OpenCodeSessions',
			'kimi'        => 'KimiSessions',
			'antigravity' => 'AntigravitySessions',
			'grok'        => 'GrokSessions',
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
		static $cache = [];
		if ( array_key_exists( $sessionId, $cache ) ) {
			return $cache[ $sessionId ];
		}
		foreach ( self::providers() as $id => $class ) {
			try {
				if ( method_exists( $class, 'hasSession' ) && $class::hasSession( $sessionId ) ) {
					$cache[ $sessionId ] = $id;
					return $id;
				}
			} catch ( \Throwable $e ) {
				// Provider unavailable - skip.
			}
		}
		$cache[ $sessionId ] = null;
		return null;
	}

	/**
	 * List sessions across providers, tagged with source.
	 * Pass $source to restrict to one provider.
	 */
	public static function listSessions( ?string $project = null, ?string $source = null ): array {
		$results = [];
		foreach ( self::providers() as $id => $class ) {
			if ( $source && $source !== $id ) {
				continue;
			}
			try {
				$sessions = $class::listSessions( $project );
			} catch ( \Throwable $e ) {
				continue;
			}
			$label = $class::sourceLabel();
			foreach ( $sessions as $s ) {
				$s['source']      = $id;
				$s['sourceLabel'] = $label;
				$results[]        = $s;
			}
		}
		usort( $results, fn( $a, $b ) => ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 ) );
		return $results;
	}

	/**
	 * List unique projects across providers.
	 * Duplicate paths are merged, with 'sources' tracking which providers have data.
	 */
	public static function listProjects( ?string $source = null ): array {
		$byPath = [];
		foreach ( self::providers() as $id => $class ) {
			if ( $source && $source !== $id ) {
				continue;
			}
			try {
				$projects = $class::listProjects();
			} catch ( \Throwable $e ) {
				continue;
			}
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
		$result = array_values( $byPath );
		usort( $result, fn( $a, $b ) => ( $b['latest'] ?? 0 ) <=> ( $a['latest'] ?? 0 ) );
		return $result;
	}

	/**
	 * Fetch a conversation by id. Source is auto-detected when omitted.
	 */
	public static function getConversation( string $id, ?string $source = null ): array {
		$src = $source ?: self::detectSource( $id );
		if ( ! $src ) {
			return [];
		}
		$class = self::provider( $src );
		if ( ! $class ) {
			return [];
		}
		return $class::getConversation( $id );
	}

	/**
	 * Locate the canonical storage path for a session.
	 */
	public static function findSessionFile( string $id, ?string $project = null, ?string $source = null ): ?string {
		$src = $source ?: self::detectSource( $id );
		if ( ! $src ) {
			return null;
		}
		$class = self::provider( $src );
		if ( ! $class ) {
			return null;
		}
		return $class::findSessionFile( $id, $project );
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
		return $class::fingerprint( $session );
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
		return $class::extractSessionText( $session );
	}

	/**
	 * Total token usage for a session, or null when the provider doesn't
	 * track it (CommandCode, T3 Code, Antigravity, Grok store no usable counts).
	 */
	public static function extractUsage( array $session ): ?array {
		$src   = $session['source'] ?? null;
		$class = $src ? self::provider( $src ) : null;
		if ( ! $class || ! method_exists( $class, 'extractUsage' ) ) {
			return null;
		}
		try {
			return $class::extractUsage( $session );
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
			if ( ! empty( $stale ) ) {
				SearchIndex::indexSessions( $stale );
			}

			return SearchIndex::search( $query, $project, $source );
		} catch ( \Throwable $e ) {
			// Fall back to Claude's legacy grep path - non-Claude providers have no
			// equivalent, so they return empty when FTS is broken rather than
			// surfacing Claude results under the wrong source.
			if ( in_array( $source, [ 't3code', 'kimi', 'opencode', 'antigravity', 'grok' ], true ) ) {
				return [];
			}
			return ClaudeSessions::deepSearch( $query, $project );
		}
	}
}
