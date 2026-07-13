<?php

/**
 * Session retention monitor.
 *
 * Detects each provider's local cleanup policy (where known), computes
 * predicted expiry from last activity, and stores CC-side warning prefs in
 * data/retention.json.
 *
 * Claude Code is the main case: cleanupPeriodDays in ~/.claude/settings.json
 * (default 30). Other providers either keep sessions forever or have no
 * documented local TTL.
 *
 * v1 is read + warn only. Writing agent configs and archive-before-expiry
 * are left for a follow-up.
 */
class Retention {

	/** Built-in profiles when nothing is detected on disk. */
	private static function builtInProfiles(): array {
		return [
			// Detected dynamically - placeholder for kind/default only.
			'claude'      => [ 'kind' => 'days', 'default_days' => 30, 'writable' => true ],
			'grok'        => [ 'kind' => 'none', 'note' => 'No auto-delete; sessions stay until removed' ],
			'opencode'    => [ 'kind' => 'none', 'note' => 'No known auto-delete' ],
			'kimi'        => [ 'kind' => 'none', 'note' => 'No known auto-delete' ],
			'amp'         => [ 'kind' => 'none', 'note' => 'No known local auto-delete' ],
			'commandcode' => [ 'kind' => 'none', 'note' => 'No known auto-delete' ],
			't3code'      => [ 'kind' => 'none', 'note' => 'No known auto-delete' ],
			'antigravity' => [ 'kind' => 'unknown', 'note' => 'Retention policy not documented' ],
		];
	}

	// ─── Preferences (CC-side, data/retention.json) ───────────

	public static function prefsPath(): string {
		return DATA_DIR . '/retention.json';
	}

	/**
	 * @return array{warning_days:int,show_badges:bool,show_strip:bool}
	 */
	public static function prefs(): array {
		$defaults = [
			'warning_days' => 7,
			'show_badges'  => true,
			'show_strip'   => true,
		];
		$path = self::prefsPath();
		if ( ! is_file( $path ) ) {
			return $defaults;
		}
		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			return $defaults;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return $defaults;
		}
		$days = isset( $data['warning_days'] ) ? (int) $data['warning_days'] : $defaults['warning_days'];
		if ( $days < 1 ) {
			$days = 1;
		}
		if ( $days > 3650 ) {
			$days = 3650;
		}
		return [
			'warning_days' => $days,
			'show_badges'  => array_key_exists( 'show_badges', $data ) ? (bool) $data['show_badges'] : $defaults['show_badges'],
			'show_strip'   => array_key_exists( 'show_strip', $data ) ? (bool) $data['show_strip'] : $defaults['show_strip'],
		];
	}

	/**
	 * Merge and persist prefs. Returns the saved prefs or throws on write failure.
	 *
	 * @param array $patch Partial prefs.
	 * @return array{warning_days:int,show_badges:bool,show_strip:bool}
	 */
	public static function savePrefs( array $patch ): array {
		$current = self::prefs();
		if ( array_key_exists( 'warning_days', $patch ) ) {
			$days = (int) $patch['warning_days'];
			if ( $days < 1 ) {
				$days = 1;
			}
			if ( $days > 3650 ) {
				$days = 3650;
			}
			$current['warning_days'] = $days;
		}
		if ( array_key_exists( 'show_badges', $patch ) ) {
			$current['show_badges'] = (bool) $patch['show_badges'];
		}
		if ( array_key_exists( 'show_strip', $patch ) ) {
			$current['show_strip'] = (bool) $patch['show_strip'];
		}

		if ( ! is_dir( DATA_DIR ) ) {
			@mkdir( DATA_DIR, 0755, true );
		}
		$json = json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( $json === false || @file_put_contents( self::prefsPath(), $json . "\n" ) === false ) {
			throw new RuntimeException( 'Could not write retention prefs' );
		}
		return $current;
	}

	// ─── Policy detection ─────────────────────────────────────

	/**
	 * Policy for one provider.
	 *
	 * @return array{
	 *   id:string,label:string,kind:string,days:?int,default_days:?int,
	 *   source:?string,writable:bool,note:?string,last_cleanup:?string,settings_path:?string
	 * }
	 */
	public static function policyFor( string $sourceId ): array {
		$profiles = self::builtInProfiles();
		$base     = $profiles[ $sourceId ] ?? [ 'kind' => 'unknown', 'note' => 'Unknown provider' ];
		$label    = SessionRegistry::provider( $sourceId )
			? ( SessionRegistry::provider( $sourceId ) )::sourceLabel()
			: $sourceId;

		$out = [
			'id'            => $sourceId,
			'label'         => $label,
			'kind'          => $base['kind'] ?? 'unknown',
			'days'          => null,
			'default_days'  => $base['default_days'] ?? null,
			'source'        => null,
			'writable'      => ! empty( $base['writable'] ),
			'note'          => $base['note'] ?? null,
			'last_cleanup'  => null,
			'settings_path' => null,
		];

		if ( $sourceId === 'claude' ) {
			return self::detectClaude( $out );
		}

		return $out;
	}

	/**
	 * All providers that currently appear in the registry.
	 *
	 * @return array<int,array>
	 */
	public static function policies(): array {
		$out = [];
		foreach ( SessionRegistry::providers() as $id => $class ) {
			$out[] = self::policyFor( $id );
		}
		return $out;
	}

	/**
	 * Claude: read cleanupPeriodDays from user settings (default 30).
	 * Also surfaces .last-cleanup timestamp when present.
	 */
	private static function detectClaude( array $out ): array {
		$home = self::claudeDir();
		$path = $home . '/settings.json';
		$out['settings_path'] = $path;
		$out['default_days']  = 30;
		$out['writable']      = true;

		$days   = 30;
		$source = 'default';

		if ( is_file( $path ) ) {
			$raw = @file_get_contents( $path );
			$data = $raw !== false ? json_decode( $raw, true ) : null;
			if ( ! is_array( $data ) ) {
				$out['kind'] = 'days';
				$out['days'] = 30;
				$out['source'] = 'default-unreadable';
				$out['note'] = 'settings.json unreadable; Claude may pause cleanup or fall back to 30d';
				$out['last_cleanup'] = self::claudeLastCleanup( $home );
				return $out;
			}
			if ( isset( $data['cleanupPeriodDays'] ) && is_numeric( $data['cleanupPeriodDays'] ) ) {
				$days   = max( 1, (int) $data['cleanupPeriodDays'] );
				$source = 'user-settings';
			}
		}

		$out['kind']         = 'days';
		$out['days']         = $days;
		$out['source']       = $source;
		$out['last_cleanup'] = self::claudeLastCleanup( $home );
		if ( $source === 'default' ) {
			$out['note'] = 'Default 30-day cleanup; set cleanupPeriodDays in ~/.claude/settings.json to change';
		} else {
			$out['note'] = 'Claude deletes local transcripts older than this at startup';
		}
		return $out;
	}

	private static function claudeDir(): string {
		$override = getenv( 'CLAUDE_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		return rtrim( $home, '/' ) . '/.claude';
	}

	private static function claudeLastCleanup( string $home ): ?string {
		$file = $home . '/.last-cleanup';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw = trim( (string) @file_get_contents( $file ) );
		return $raw !== '' ? $raw : null;
	}

	// ─── Session annotation ───────────────────────────────────

	/**
	 * Attach retention fields to a session list (mutates copies).
	 *
	 * Fields added when policy kind=days and timestamp known:
	 *   expires_at (unix s), days_left (int), retention_risk ('ok'|'warn'|'critical'|'expired')
	 *
	 * @param array $sessions Tagged sessions from SessionRegistry::listSessions().
	 * @param array|null $prefs Prefs (loaded if null).
	 * @return array
	 */
	public static function annotateSessions( array $sessions, ?array $prefs = null ): array {
		$prefs = $prefs ?? self::prefs();
		$warn  = (int) $prefs['warning_days'];
		$now   = time();

		// Cache policies per source for this call.
		$policies = [];
		foreach ( $sessions as $i => $s ) {
			$src = $s['source'] ?? '';
			if ( $src === '' ) {
				continue;
			}
			if ( ! isset( $policies[ $src ] ) ) {
				$policies[ $src ] = self::policyFor( $src );
			}
			$pol = $policies[ $src ];
			if ( ( $pol['kind'] ?? '' ) !== 'days' || empty( $pol['days'] ) ) {
				continue;
			}
			$ts = self::sessionUnix( $s );
			if ( $ts <= 0 ) {
				continue;
			}
			$days      = (int) $pol['days'];
			$expiresAt = $ts + ( $days * 86400 );
			$daysLeft  = (int) floor( ( $expiresAt - $now ) / 86400 );

			$risk = 'ok';
			if ( $daysLeft < 0 ) {
				$risk = 'expired';
			} elseif ( $daysLeft <= 1 ) {
				$risk = 'critical';
			} elseif ( $daysLeft <= $warn ) {
				$risk = 'warn';
			}

			$sessions[ $i ]['expires_at']      = $expiresAt;
			$sessions[ $i ]['days_left']       = $daysLeft;
			$sessions[ $i ]['retention_risk']  = $risk;
			$sessions[ $i ]['retention_days']  = $days;
		}

		return $sessions;
	}

	/**
	 * Filter annotated (or raw) sessions to those within the warning window
	 * (includes expired). Sessions without retention data are dropped.
	 *
	 * @param array $sessions
	 * @param int|null $withinDays null = use prefs.warning_days
	 * @return array
	 */
	public static function expiringSessions( array $sessions, ?int $withinDays = null ): array {
		$prefs  = self::prefs();
		$within = $withinDays !== null ? max( 0, $withinDays ) : (int) $prefs['warning_days'];
		// Ensure annotation present.
		$needAnnotate = false;
		foreach ( $sessions as $s ) {
			if ( ! array_key_exists( 'days_left', $s ) ) {
				$needAnnotate = true;
				break;
			}
		}
		if ( $needAnnotate ) {
			$sessions = self::annotateSessions( $sessions, $prefs );
		}
		$out = [];
		foreach ( $sessions as $s ) {
			if ( ! isset( $s['days_left'] ) ) {
				continue;
			}
			if ( (int) $s['days_left'] <= $within ) {
				$out[] = $s;
			}
		}
		usort( $out, fn( $a, $b ) => ( $a['days_left'] ?? 0 ) <=> ( $b['days_left'] ?? 0 ) );
		return $out;
	}

	/**
	 * Full retention report for the dashboard strip.
	 *
	 * @param array|null $sessions Preloaded list (or load all).
	 * @return array
	 */
	public static function report( ?array $sessions = null ): array {
		$prefs    = self::prefs();
		$sessions = $sessions ?? SessionRegistry::listSessions();
		$sessions = self::annotateSessions( $sessions, $prefs );
		$warn     = (int) $prefs['warning_days'];

		// Group by source for stats.
		$bySource = [];
		foreach ( $sessions as $s ) {
			$src = $s['source'] ?? '';
			if ( $src === '' ) {
				continue;
			}
			if ( ! isset( $bySource[ $src ] ) ) {
				$bySource[ $src ] = [];
			}
			$bySource[ $src ][] = $s;
		}

		$providers = [];
		foreach ( SessionRegistry::providers() as $id => $class ) {
			$pol   = self::policyFor( $id );
			$list  = $bySource[ $id ] ?? [];
			$total = count( $list );
			$stats = [
				'total'       => $total,
				'expiring'    => 0, // within warning window (incl. expired)
				'critical'    => 0, // <= 1 day or expired
				'expired'     => 0,
				'soonest'     => null, // min days_left when kind=days
			];

			if ( ( $pol['kind'] ?? '' ) === 'days' ) {
				$minLeft = null;
				foreach ( $list as $s ) {
					if ( ! isset( $s['days_left'] ) ) {
						continue;
					}
					$left = (int) $s['days_left'];
					if ( $minLeft === null || $left < $minLeft ) {
						$minLeft = $left;
					}
					if ( $left <= $warn ) {
						$stats['expiring']++;
					}
					if ( $left <= 1 ) {
						$stats['critical']++;
					}
					if ( $left < 0 ) {
						$stats['expired']++;
					}
				}
				$stats['soonest'] = $minLeft;
			}

			$providers[] = array_merge( $pol, [ 'stats' => $stats ] );
		}

		// Only providers that currently have sessions, plus any with active day TTL.
		// Keep full list so the configure panel can show everything; strip can filter client-side.
		$atRisk = 0;
		foreach ( $providers as $p ) {
			$atRisk += (int) ( $p['stats']['expiring'] ?? 0 );
		}

		return [
			'prefs'     => $prefs,
			'providers' => $providers,
			'at_risk'   => $atRisk,
			'generated' => time(),
		];
	}

	private static function sessionUnix( array $s ): int {
		if ( ! empty( $s['timestamp_s'] ) ) {
			return (int) $s['timestamp_s'];
		}
		if ( ! empty( $s['timestamp'] ) ) {
			$t = (float) $s['timestamp'];
			// Heuristic: values > 1e12 are ms.
			return $t > 1e12 ? (int) floor( $t / 1000 ) : (int) $t;
		}
		return 0;
	}
}
