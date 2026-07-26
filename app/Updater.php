<?php

/**
 * Self-updater for Command Center (CLI + Web UI).
 *
 * Same idea as Disembark's manifest-based GitHub updater, without WordPress hooks:
 *
 *   1. Local version  = BASE_DIR/manifest.json
 *   2. Remote version = raw.githubusercontent.com/.../main/manifest.json
 *   3. Package URL    = remote download_url (prefer releases/download/.../command-center.zip)
 *
 * Apply paths:
 *   - git install  → git pull --ff-only origin main (refuses dirty working tree)
 *   - zip install  → download package, extract, copy over BASE_DIR (never data/ or .git)
 *
 * Network checks are cached under data/update-check.json for CACHE_TTL seconds
 * (default 1 day). COMMAND_CENTER_UPDATE_CHECK=0 disables remote fetches.
 */
class Updater {

	public const REMOTE_MANIFEST_URL = 'https://raw.githubusercontent.com/austinginder/command-center/main/manifest.json';
	public const CACHE_TTL           = 86400; // 1 day
	public const CACHE_FILE          = 'update-check.json';

	/** Allowed download hosts for package URLs (path must stay on this repo). */
	private const DOWNLOAD_HOSTS = [ 'github.com', 'objects.githubusercontent.com' ];
	private const DOWNLOAD_PATH_PREFIX = '/austinginder/command-center/';

	// ─── Public API ─────────────────────────────────────────────

	/**
	 * Local manifest only (no network). Throws RuntimeException if missing/invalid.
	 *
	 * @return array<string,mixed>
	 */
	public static function localManifest(): array {
		$file = BASE_DIR . '/manifest.json';
		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( 'Missing manifest.json - is this a complete install?' );
		}
		$manifest = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['version'] ) ) {
			throw new RuntimeException( 'Invalid manifest.json.' );
		}
		return $manifest;
	}

	/**
	 * Status for UI / CLI. Uses cache unless $forceRefresh.
	 *
	 * @return array{
	 *   current:string,
	 *   latest:?string,
	 *   update_available:bool,
	 *   install_kind:string,
	 *   requires_php:?string,
	 *   requires_php_ok:bool,
	 *   dirty:bool,
	 *   download_url:?string,
	 *   changelog:?string,
	 *   homepage:?string,
	 *   checked_at:int,
	 *   cached:bool,
	 *   remote_error:?string
	 * }
	 */
	public static function status( bool $forceRefresh = false ): array {
		$local   = self::localManifest();
		$current = (string) $local['version'];
		$kind    = self::installKind();
		$dirty   = $kind === 'git' ? self::gitIsDirty() : false;

		$base = [
			'current'          => $current,
			'latest'           => null,
			'update_available' => false,
			'install_kind'     => $kind,
			'requires_php'     => null,
			'requires_php_ok'  => true,
			'dirty'            => $dirty,
			'download_url'     => null,
			'changelog'        => $local['changelog'] ?? null,
			'homepage'         => $local['homepage'] ?? null,
			'checked_at'       => time(),
			'cached'           => false,
			'remote_error'     => null,
		];

		if ( ! self::checksEnabled() ) {
			$base['remote_error'] = 'Update checks disabled (COMMAND_CENTER_UPDATE_CHECK=0).';
			return $base;
		}

		$remoteResult = self::remoteManifest( $forceRefresh );
		if ( ! empty( $remoteResult['error'] ) ) {
			$base['remote_error'] = (string) $remoteResult['error'];
			$base['checked_at']   = (int) ( $remoteResult['checked_at'] ?? time() );
			$base['cached']       = ! empty( $remoteResult['cached'] );
			// Still surface last-known remote if cache had a body.
			if ( empty( $remoteResult['manifest'] ) || ! is_array( $remoteResult['manifest'] ) ) {
				return $base;
			}
		}

		$remote = $remoteResult['manifest'] ?? null;
		if ( ! is_array( $remote ) || empty( $remote['version'] ) ) {
			if ( empty( $base['remote_error'] ) ) {
				$base['remote_error'] = 'Could not read remote manifest.';
			}
			return $base;
		}

		$latest = (string) $remote['version'];
		$phpReq = isset( $remote['requires_php'] ) ? (string) $remote['requires_php'] : null;
		$phpOk  = $phpReq === null || $phpReq === '' || version_compare( PHP_VERSION, $phpReq, '>=' );

		$base['latest']           = $latest;
		$base['update_available'] = version_compare( $latest, $current, '>' );
		$base['requires_php']     = $phpReq;
		$base['requires_php_ok']  = $phpOk;
		$base['download_url']     = isset( $remote['download_url'] ) ? (string) $remote['download_url'] : null;
		$base['changelog']        = $remote['changelog'] ?? $base['changelog'];
		$base['homepage']         = $remote['homepage'] ?? $base['homepage'];
		$base['checked_at']       = (int) ( $remoteResult['checked_at'] ?? time() );
		$base['cached']           = ! empty( $remoteResult['cached'] );

		return $base;
	}

	/**
	 * Apply an available update. Returns a result array; never throws for expected failures.
	 *
	 * @return array{ok:bool,from:?string,to:?string,method:?string,message:string}
	 */
	public static function apply(): array {
		try {
			$status = self::status( true ); // always re-check before mutating
		} catch ( \Throwable $e ) {
			return self::fail( $e->getMessage() );
		}

		$from = $status['current'];
		$to   = $status['latest'];

		if ( ! empty( $status['remote_error'] ) && $to === null ) {
			return self::fail( $status['remote_error'] );
		}
		if ( empty( $status['update_available'] ) ) {
			return [
				'ok'      => true,
				'from'    => $from,
				'to'      => $from,
				'method'  => null,
				'message' => "Already up to date (v{$from}).",
			];
		}
		if ( empty( $status['requires_php_ok'] ) ) {
			$req = $status['requires_php'] ?? '?';
			return self::fail( "v{$to} requires PHP {$req}+ (you have " . PHP_VERSION . ').' );
		}

		if ( $status['install_kind'] === 'git' ) {
			$result = self::applyGit();
		} else {
			$url = $status['download_url'] ?? '';
			if ( $url === '' ) {
				return self::fail( 'Remote manifest has no download_url.' );
			}
			$result = self::applyZip( $url );
		}

		if ( empty( $result['ok'] ) ) {
			return $result;
		}

		// Re-read local version after apply.
		try {
			$new = self::localManifest();
			$ver = (string) ( $new['version'] ?? $to );
		} catch ( \Throwable $e ) {
			$ver = (string) $to;
		}

		// Force cache refresh so UI/CLI don't show a stale "update available".
		self::clearCache();
		try {
			self::remoteManifest( true );
		} catch ( \Throwable $e ) {
			// non-fatal
		}

		return [
			'ok'      => true,
			'from'    => $from,
			'to'      => $ver,
			'method'  => $result['method'] ?? null,
			'message' => "Updated to v{$ver}.",
		];
	}

	// ─── Remote + cache ─────────────────────────────────────────

	public static function checksEnabled(): bool {
		$env = getenv( 'COMMAND_CENTER_UPDATE_CHECK' );
		if ( $env === false || $env === '' ) {
			return true;
		}
		$v = strtolower( trim( (string) $env ) );
		return ! in_array( $v, [ '0', 'false', 'off', 'no' ], true );
	}

	/**
	 * @return array{manifest:?array,checked_at:int,cached:bool,error:?string}
	 */
	public static function remoteManifest( bool $forceRefresh = false ): array {
		$now = time();
		if ( ! $forceRefresh ) {
			$cached = self::readCache();
			if ( is_array( $cached )
				&& isset( $cached['checked_at'], $cached['manifest'] )
				&& ( $now - (int) $cached['checked_at'] ) < self::CACHE_TTL
			) {
				return [
					'manifest'   => is_array( $cached['manifest'] ) ? $cached['manifest'] : null,
					'checked_at' => (int) $cached['checked_at'],
					'cached'     => true,
					'error'      => isset( $cached['error'] ) ? (string) $cached['error'] : null,
				];
			}
		}

		$body = self::httpGet( self::REMOTE_MANIFEST_URL, 15 );
		if ( $body === null ) {
			// Keep last good cache if any, but mark error.
			$stale = self::readCache();
			$out   = [
				'manifest'   => is_array( $stale['manifest'] ?? null ) ? $stale['manifest'] : null,
				'checked_at' => $now,
				'cached'     => false,
				'error'      => 'Could not reach GitHub to check for updates.',
			];
			self::writeCache( $out );
			return $out;
		}

		$manifest = json_decode( $body, true );
		if ( ! is_array( $manifest ) || empty( $manifest['version'] ) ) {
			$out = [
				'manifest'   => null,
				'checked_at' => $now,
				'cached'     => false,
				'error'      => 'Remote manifest is invalid.',
			];
			self::writeCache( $out );
			return $out;
		}

		$out = [
			'manifest'   => $manifest,
			'checked_at' => $now,
			'cached'     => false,
			'error'      => null,
		];
		self::writeCache( $out );
		return $out;
	}

	public static function clearCache(): void {
		$path = self::cachePath();
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
	}

	// ─── Install detection ──────────────────────────────────────

	public static function installKind(): string {
		return is_dir( BASE_DIR . '/.git' ) ? 'git' : 'zip';
	}

	public static function gitIsDirty(): bool {
		if ( self::installKind() !== 'git' ) {
			return false;
		}
		$out  = [];
		$code = 0;
		exec( 'git -C ' . escapeshellarg( BASE_DIR ) . ' status --porcelain 2>&1', $out, $code );
		if ( $code !== 0 ) {
			// Treat unknown as dirty so we refuse to clobber.
			return true;
		}
		return ! empty( $out );
	}

	// ─── Apply: git ─────────────────────────────────────────────

	/**
	 * @return array{ok:bool,from:?string,to:?string,method:?string,message:string}
	 */
	private static function applyGit(): array {
		$out  = [];
		$code = 0;
		exec( 'git -C ' . escapeshellarg( BASE_DIR ) . ' status --porcelain 2>&1', $out, $code );
		if ( $code !== 0 ) {
			return self::fail( 'git status failed - update manually with git pull.' );
		}
		if ( ! empty( $out ) ) {
			return self::fail( 'Working tree has local changes - commit or stash them, then update again.' );
		}

		$pullOut = [];
		exec( 'git -C ' . escapeshellarg( BASE_DIR ) . ' pull --ff-only origin main 2>&1', $pullOut, $code );
		if ( $code !== 0 ) {
			return self::fail( "git pull failed:\n" . implode( "\n", $pullOut ) );
		}

		return [
			'ok'      => true,
			'from'    => null,
			'to'      => null,
			'method'  => 'git',
			'message' => implode( "\n", $pullOut ) ?: 'git pull ok',
		];
	}

	// ─── Apply: zip ─────────────────────────────────────────────

	/**
	 * @return array{ok:bool,from:?string,to:?string,method:?string,message:string}
	 */
	private static function applyZip( string $url ): array {
		if ( ! self::isAllowedDownloadUrl( $url ) ) {
			return self::fail( 'download_url is not an allowed Command Center release URL.' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::fail( 'PHP zip extension is required for non-git updates.' );
		}

		$data = self::httpGet( $url, 60 );
		if ( $data === null || $data === '' ) {
			return self::fail( 'Download failed: ' . $url );
		}

		$tmpZip = tempnam( sys_get_temp_dir(), 'cc-update-' );
		if ( $tmpZip === false ) {
			return self::fail( 'Could not create temp file for update.' );
		}
		$tmpZipPath = $tmpZip . '.zip';
		@unlink( $tmpZip );
		if ( file_put_contents( $tmpZipPath, $data ) === false ) {
			return self::fail( 'Could not write update archive.' );
		}

		$tmpDir = sys_get_temp_dir() . '/cc-update-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
		if ( ! @mkdir( $tmpDir, 0755, true ) && ! is_dir( $tmpDir ) ) {
			@unlink( $tmpZipPath );
			return self::fail( 'Could not create temp extract directory.' );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $tmpZipPath ) !== true ) {
			self::rmTree( $tmpDir );
			@unlink( $tmpZipPath );
			return self::fail( 'Could not open update archive.' );
		}
		if ( ! $zip->extractTo( $tmpDir ) ) {
			$zip->close();
			self::rmTree( $tmpDir );
			@unlink( $tmpZipPath );
			return self::fail( 'Could not extract update archive.' );
		}
		$zip->close();
		@unlink( $tmpZipPath );

		// Package may wrap files in command-center/ or command-center-<tag>/.
		$entries = array_values( array_diff( scandir( $tmpDir ) ?: [], [ '.', '..' ] ) );
		$root    = ( count( $entries ) === 1 && is_dir( $tmpDir . '/' . $entries[0] ) )
			? $tmpDir . '/' . $entries[0]
			: $tmpDir;

		if ( ! is_readable( $root . '/manifest.json' ) && ! is_readable( $root . '/index.php' ) ) {
			self::rmTree( $tmpDir );
			return self::fail( 'Update archive does not look like a Command Center package.' );
		}

		self::copyTree( $root, BASE_DIR );
		self::rmTree( $tmpDir );

		return [
			'ok'      => true,
			'from'    => null,
			'to'      => null,
			'method'  => 'zip',
			'message' => 'Package installed.',
		];
	}

	/**
	 * Copy package files over the install. Never touch data/ or .git.
	 */
	public static function copyTree( string $src, string $dest ): void {
		$skip = [ 'data', '.git' ];
		foreach ( array_diff( scandir( $src ) ?: [], [ '.', '..' ] ) as $entry ) {
			if ( in_array( $entry, $skip, true ) ) {
				continue;
			}
			$from = $src . '/' . $entry;
			$to   = $dest . '/' . $entry;
			if ( is_dir( $from ) ) {
				if ( ! is_dir( $to ) ) {
					mkdir( $to, 0755, true );
				}
				self::copyTree( $from, $to );
			} else {
				// Skip copying over the running update cache path under data - already skipped via data/.
				copy( $from, $to );
				if ( is_executable( $from ) ) {
					chmod( $to, 0755 );
				}
			}
		}
	}

	// ─── HTTP + cache IO ────────────────────────────────────────

	private static function httpGet( string $url, int $timeout ): ?string {
		// Manifest URL is fixed; packages must pass the allow-list.
		if ( $url !== self::REMOTE_MANIFEST_URL && ! self::isAllowedDownloadUrl( $url ) ) {
			return null;
		}

		$context = stream_context_create( [
			'http' => [
				'timeout'         => $timeout,
				'follow_location' => 1,
				'header'          => "Accept: application/json,*/*\r\nUser-Agent: command-center-updater\r\n",
			],
			'ssl'  => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
			],
		] );
		$body = @file_get_contents( $url, false, $context );
		if ( $body === false ) {
			return null;
		}
		return $body;
	}

	public static function isAllowedDownloadUrl( string $url ): bool {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );
		if ( $scheme !== 'https' ) {
			return false;
		}
		if ( $host === 'raw.githubusercontent.com' ) {
			return str_starts_with( $path, '/austinginder/command-center/' );
		}
		if ( ! in_array( $host, self::DOWNLOAD_HOSTS, true ) ) {
			return false;
		}
		// github.com/austinginder/command-center/... or redirected object storage
		if ( $host === 'github.com' ) {
			return str_starts_with( $path, self::DOWNLOAD_PATH_PREFIX );
		}
		// objects.githubusercontent.com - release asset CDN after redirect; allow https only
		return $host === 'objects.githubusercontent.com';
	}

	private static function cachePath(): string {
		return rtrim( DATA_DIR, '/' ) . '/' . self::CACHE_FILE;
	}

	/** @return array<string,mixed>|null */
	private static function readCache(): ?array {
		$path = self::cachePath();
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/** @param array<string,mixed> $data */
	private static function writeCache( array $data ): void {
		$dir = dirname( self::cachePath() );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}
		@file_put_contents(
			self::cachePath(),
			json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n",
			LOCK_EX
		);
	}

	private static function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			$path = $file->getPathname();
			if ( $file->isDir() ) {
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * @return array{ok:bool,from:?string,to:?string,method:?string,message:string}
	 */
	private static function fail( string $message ): array {
		return [
			'ok'      => false,
			'from'    => null,
			'to'      => null,
			'method'  => null,
			'message' => $message,
		];
	}
}
