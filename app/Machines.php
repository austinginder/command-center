<?php

/**
 * Other computers' sessions, read from a local copy pulled out of their
 * restic backups.
 *
 * Each machine in data/machines.json names the restic repository that backs
 * it up, and `command-center pull` restores only the session folders from its
 * newest snapshot into a local mirror (~/.local/share/command-center/machines/<name>
 * by default, outside the web root). The mirror is laid out like the machine's
 * home folder, whether a snapshot stored the home at its root (`restic backup .`
 * run inside it) or under its full path. Restores only add or update files, so the
 * mirror keeps every session the backups ever held, even after the other
 * computer deletes one. `pull --backfill` walks older snapshots for sessions
 * the newest no longer has.
 *
 * Providers never read $HOME directly: they call Machines::home(),
 * Machines::env() and Machines::os(), which answer for whichever machine
 * SessionRegistry is reading inside Machines::run(). Env overrides such as
 * CLAUDE_HOME only apply to this computer.
 *
 * data/machines.json:
 *   { "machines": [ {
 *       "name": "macbook-air", "os": "Darwin", "home": "/Users/me",
 *       "restic": { "repository": "rclone:remote:bucket/path",
 *                   "password_command": "secret-tool lookup ...",
 *                   "host": "Snapshot-Hostname" or [ "Name", "Name.local" ] }
 *   } ] }
 */
class Machines {

	/** The remote machine being read, or null for this computer. */
	private static ?array $current = null;

	private static ?array $config = null;

	/** Providers whose sessions live on a server, so every machine sees the same ones. */
	private const SERVER_SIDE = [ 'amp' ];

	/**
	 * Session folders, relative to the machine's home. {grokbot} is the Grok
	 * Bot app-support folder for that machine's OS. No credentials: auth files
	 * sit beside these folders, never inside them.
	 */
	private const SESSION_PATHS = [
		'.claude/projects',
		'.claude/history.jsonl',
		'.claude-*/projects',
		'.claude-*/history.jsonl',
		'.codex/sessions',
		'.codex/archived_sessions',
		'.codex/session_index.jsonl',
		'.codex/sqlite',
		'.codex/state_5.sqlite*',
		'.local/share/opencode/opencode.db*',
		'.local/share/opencode/storage',
		'.kimi/sessions',
		'.kimi/kimi.json',
		'.t3/userdata/state.sqlite*',
		'.t3/userdata/environment-id',
		'.grok/sessions',
		'.gemini/tmp',
		'.gemini/projects.json',
		'.gemini/antigravity-cli/brain',
		'.commandcode/projects',
		'{grokbot}/sand-client-persistence',
	];

	// ─── Configuration ────────────────────────────────────────

	public static function configPath(): string {
		return DATA_DIR . '/machines.json';
	}

	/** @return array<int,array> Remote machines from data/machines.json. */
	public static function remotes(): array {
		if ( self::$config !== null ) {
			return self::$config;
		}
		$out  = [];
		$raw  = is_file( self::configPath() ) ? @file_get_contents( self::configPath() ) : false;
		$data = $raw !== false ? json_decode( (string) $raw, true ) : null;
		foreach ( (array) ( $data['machines'] ?? [] ) as $m ) {
			$name = (string) ( $m['name'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $name ) || empty( $m['home'] ) ) {
				continue;
			}
			$m['os']     = ( $m['os'] ?? 'Darwin' ) === 'Linux' ? 'Linux' : 'Darwin';
			$m['home']   = rtrim( (string) $m['home'], '/' );
			$m['mirror'] = rtrim( (string) ( $m['mirror'] ?? self::defaultMirrorRoot() . '/' . $name ), '/' );
			$out[]       = $m;
		}
		return self::$config = $out;
	}

	public static function find( string $name ): ?array {
		foreach ( self::remotes() as $m ) {
			if ( $m['name'] === $name ) {
				return $m;
			}
		}
		return null;
	}

	/** @return array<int,?string> null (this computer) first, then each remote name. */
	public static function names(): array {
		return array_merge( [ null ], array_column( self::remotes(), 'name' ) );
	}

	private static function defaultMirrorRoot(): string {
		$override = getenv( 'COMMAND_CENTER_MACHINES_DIR' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$data = getenv( 'XDG_DATA_HOME' ) ?: self::localHome() . '/.local/share';
		return rtrim( $data, '/' ) . '/command-center/machines';
	}

	// ─── What providers read ──────────────────────────────────

	/** Name of the machine being read, or null for this computer. */
	public static function current(): ?string {
		return self::$current['name'] ?? null;
	}

	/** Cache key for providers that memoise per machine. */
	public static function key(): string {
		return self::$current['name'] ?? '';
	}

	/** Home directory to read sessions from: this computer's, or a mirror's copy of a remote one. */
	public static function home(): string {
		if ( self::$current ) {
			return self::$current['mirror'];
		}
		return self::localHome();
	}

	/** Operating system of the machine being read (PHP_OS_FAMILY values). */
	public static function os(): string {
		return self::$current['os'] ?? PHP_OS_FAMILY;
	}

	/** getenv() for this computer only, so local overrides never redirect a mirror. */
	public static function env( string $name ): string|false {
		return self::$current ? false : getenv( $name );
	}

	private static function localHome(): string {
		return rtrim( getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' ), '/' );
	}

	/** Grok Bot's app-support folder, relative to a home, for an OS. */
	public static function grokBotDir( string $os ): string {
		return $os === 'Linux' ? '.config/Grok Bot' : 'Library/Application Support/Grok Bot';
	}

	/**
	 * Run $fn while providers read machine $name (null = this computer).
	 * Provider caches are cleared on the way in and out.
	 */
	public static function run( ?string $name, callable $fn ) {
		$target = $name === null ? null : self::find( $name );
		if ( $name !== null && ! $target ) {
			throw new RuntimeException( 'Unknown machine: ' . $name );
		}
		if ( ( $target['name'] ?? null ) === self::current() ) {
			return $fn();
		}
		$previous      = self::$current;
		self::$current = $target;
		self::forgetProviderState();
		try {
			return $fn();
		} finally {
			self::$current = $previous;
			self::forgetProviderState();
		}
	}

	/** Whether a provider's sessions should be read from a remote machine. */
	public static function readsRemote( string $source ): bool {
		return ! in_array( $source, self::SERVER_SIDE, true );
	}

	private static function forgetProviderState(): void {
		foreach ( SessionRegistry::providers() as $class ) {
			if ( method_exists( $class, 'forgetMachine' ) ) {
				$class::forgetMachine();
			}
		}
	}

	// ─── Pulling from restic ──────────────────────────────────

	public static function stateFile( array $m ): string {
		return $m['mirror'] . '/.command-center-pull.json';
	}

	public static function state( array $m ): array {
		$raw = is_file( self::stateFile( $m ) ) ? @file_get_contents( self::stateFile( $m ) ) : false;
		$data = $raw !== false ? json_decode( (string) $raw, true ) : null;
		return is_array( $data ) ? $data : [];
	}

	private static function saveState( array $m, array $patch ): void {
		$state = array_merge( self::state( $m ), $patch );
		@file_put_contents( self::stateFile( $m ), json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	/** restic --include patterns for a machine's session folders, relative to its home. */
	public static function includePatterns( array $m ): array {
		$out = [];
		foreach ( self::SESSION_PATHS as $rel ) {
			$out[] = '/' . str_replace( '{grokbot}', self::grokBotDir( $m['os'] ), $rel );
		}
		return $out;
	}

	/**
	 * How a snapshot stores the home folder: '' when the home is the tree's
	 * root, or the home's path when the tree holds full paths. Remembered per
	 * snapshot, since finding out costs a listing.
	 */
	private static function homeInSnapshot( array $m, string $snapshot ): string {
		$known = self::state( $m )['layout'][ $snapshot ] ?? null;
		if ( is_string( $known ) ) {
			return $known;
		}
		$first = '/' . explode( '/', trim( $m['home'], '/' ) )[0];
		$top   = [];
		foreach ( explode( "\n", self::restic( $m, [ 'ls', '--json', $snapshot, '/' ] ) ) as $line ) {
			$node = json_decode( $line, true );
			if ( is_array( $node ) && isset( $node['path'] ) ) {
				$top[] = $node['path'];
			}
		}
		$prefix = ( in_array( $first, $top, true ) && count( $top ) <= 3 ) ? $m['home'] : '';
		$layout = self::state( $m )['layout'] ?? [];
		$layout[ $snapshot ] = $prefix;
		self::saveState( $m, [ 'layout' => $layout ] );
		return $prefix;
	}

	/**
	 * Restore the session folders from the machine's newest snapshot.
	 * Changed files are rewritten, new ones added, nothing is deleted.
	 *
	 * @return array{snapshot:string,time:string}
	 */
	public static function pull( array $m, ?callable $log = null ): array {
		$snaps = self::snapshots( $m );
		if ( ! $snaps ) {
			throw new RuntimeException( 'No snapshots found for ' . $m['name'] );
		}
		$latest = end( $snaps );
		self::restore( $m, $latest['short_id'], 'if-changed', $log );
		self::saveState( $m, [ 'last_pull' => date( 'c' ), 'snapshot' => $latest['short_id'], 'snapshot_time' => $latest['time'] ] );
		return [ 'snapshot' => $latest['short_id'], 'time' => $latest['time'] ];
	}

	/**
	 * Restore sessions only older snapshots hold (deleted on the machine since).
	 * Tools that prune do so after 30 days at the soonest, so one snapshot
	 * every $everyDays days misses nothing. Existing files are never touched.
	 *
	 * @return int Snapshots restored from.
	 */
	public static function backfill( array $m, int $everyDays = 14, ?string $since = null, ?callable $log = null ): int {
		$picked = [];
		$next   = null;
		$from   = $since ? strtotime( $since ) : 0;
		foreach ( array_reverse( self::snapshots( $m ) ) as $s ) {
			$t = strtotime( $s['time'] );
			if ( $t < $from ) {
				break;
			}
			if ( $next === null || $t <= $next ) {
				$picked[] = $s;
				$next     = $t - $everyDays * 86400;
			}
		}
		$done  = array_flip( self::state( $m )['backfilled'] ?? [] );
		$count = 0;
		foreach ( $picked as $s ) {
			if ( isset( $done[ $s['short_id'] ] ) ) {
				continue;
			}
			if ( $log ) {
				$log( 'Backfilling from ' . $s['short_id'] . ' (' . substr( $s['time'], 0, 10 ) . ')' );
			}
			self::restore( $m, $s['short_id'], 'never', $log );
			$done[ $s['short_id'] ] = true;
			self::saveState( $m, [ 'backfilled' => array_keys( $done ) ] );
			$count++;
		}
		return $count;
	}

	/** @return array<int,array{short_id:string,time:string}> Oldest first. */
	public static function snapshots( array $m ): array {
		// A computer's snapshot hostname drifts (Name.local, Name.lan, ...), so
		// "host" may list several.
		$args = [ 'snapshots', '--json' ];
		foreach ( (array) ( $m['restic']['host'] ?? [] ) as $host ) {
			$args[] = '--host';
			$args[] = (string) $host;
		}
		$out  = self::restic( $m, $args );
		$list = json_decode( $out, true );
		if ( ! is_array( $list ) ) {
			throw new RuntimeException( 'restic snapshots returned no JSON' );
		}
		$snaps = [];
		foreach ( $list as $s ) {
			if ( in_array( $m['home'], (array) ( $s['paths'] ?? [] ), true ) || in_array( dirname( $m['home'] ), (array) ( $s['paths'] ?? [] ), true ) ) {
				$snaps[] = [ 'short_id' => (string) ( $s['short_id'] ?? substr( (string) $s['id'], 0, 8 ) ), 'time' => (string) $s['time'] ];
			}
		}
		usort( $snaps, fn( $a, $b ) => strcmp( $a['time'], $b['time'] ) );
		return $snaps;
	}

	private static function restore( array $m, string $snapshot, string $overwrite, ?callable $log ): void {
		if ( ! is_dir( $m['mirror'] ) && ! @mkdir( $m['mirror'], 0700, true ) ) {
			throw new RuntimeException( 'Could not create ' . $m['mirror'] );
		}
		// snapshot:/path makes the home the restore root, so both layouts land
		// the same way. Extended attributes (macOS quarantine and provenance
		// tags) mean nothing here.
		$home = self::homeInSnapshot( $m, $snapshot );
		$args = [ 'restore', $snapshot . ( $home !== '' ? ':' . $home : '' ), '--target', $m['mirror'], '--overwrite', $overwrite, '--exclude-xattr', '*' ];
		foreach ( self::includePatterns( $m ) as $pattern ) {
			$args[] = '--include';
			$args[] = $pattern;
		}
		self::restic( $m, $args, $log );
	}

	/** Run restic for a machine, returning stdout. Throws on a non-zero exit. */
	private static function restic( array $m, array $args, ?callable $log = null ): string {
		$r   = (array) ( $m['restic'] ?? [] );
		$env = getenv();
		if ( empty( $r['repository'] ) ) {
			throw new RuntimeException( $m['name'] . ' has no restic.repository in ' . self::configPath() );
		}
		$env['RESTIC_REPOSITORY'] = (string) $r['repository'];
		if ( ! empty( $r['password_command'] ) ) {
			$env['RESTIC_PASSWORD_COMMAND'] = (string) $r['password_command'];
		} elseif ( ! empty( $r['password_file'] ) ) {
			$env['RESTIC_PASSWORD_FILE'] = (string) $r['password_file'];
		}
		$bin = (string) ( $r['bin'] ?? 'restic' );
		// stderr goes to a file: a restore can warn about thousands of files, and
		// a full stderr pipe would stall restic while stdout is being read.
		$errFile = tempnam( sys_get_temp_dir(), 'cc-restic-' );
		$proc    = proc_open( array_merge( [ $bin ], $args ), [ 1 => [ 'pipe', 'w' ], 2 => [ 'file', $errFile, 'w' ] ], $pipes, null, $env );
		if ( ! is_resource( $proc ) ) {
			@unlink( $errFile );
			throw new RuntimeException( 'Could not run restic' );
		}
		$out = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$code = proc_close( $proc );
		$err  = trim( (string) @file_get_contents( $errFile ) );
		@unlink( $errFile );
		if ( $log && $args[0] === 'restore' && trim( $out ) !== '' ) {
			$log( trim( $out ) );
		}
		if ( $log && $err !== '' ) {
			$lines = explode( "\n", $err );
			$log( implode( "\n", array_slice( $lines, -20 ) ) . ( count( $lines ) > 20 ? "\n(" . count( $lines ) . ' lines of restic output, last 20 shown)' : '' ) );
		}
		if ( $code !== 0 ) {
			throw new RuntimeException( 'restic ' . $args[0] . ' failed (' . $code . '): ' . substr( $err, -2000 ) );
		}
		return $out;
	}
}
