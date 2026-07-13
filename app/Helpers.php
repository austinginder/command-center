<?php

class Helpers {

	public static function e( string $str ): string {
		return htmlspecialchars( $str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	public static function formatBytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1024 * 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return round( $bytes / ( 1024 * 1024 ), 1 ) . ' MB';
	}

	public static function timeAgo( int $timestamp ): string {
		$diff = time() - $timestamp;
		if ( $diff < 60 ) {
			return $diff . 's ago';
		}
		if ( $diff < 3600 ) {
			return floor( $diff / 60 ) . 'm ago';
		}
		if ( $diff < 86400 ) {
			return floor( $diff / 3600 ) . 'h ago';
		}
		return floor( $diff / 86400 ) . 'd ago';
	}

	public static function statusBadge( string $status ): string {
		$styles = [
			'running'  => 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400',
			'complete' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-400',
			'stale'    => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400',
			'waiting'  => 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-400',
			'idle'     => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-400',
		];
		$cls = $styles[ $status ] ?? 'bg-gray-100 text-gray-600';
		$label = self::e( ucfirst( $status ) );
		return "<span class=\"inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {$cls}\">{$label}</span>";
	}

	/**
	 * Human project label from a workspace path.
	 * Cove sites are almost always …/Sites/<name>.localhost/public, so "public"
	 * (or htdocs/www) as a basename is useless - prefer the parent folder.
	 */
	public static function projectDisplayName( string $path ): string {
		if ( $path === '' || $path === '(unknown)' ) {
			return $path === '(unknown)' ? '(unknown)' : '';
		}
		$base = basename( $path );
		if ( in_array( $base, [ 'public', 'htdocs', 'www' ], true ) ) {
			$parent = basename( dirname( $path ) );
			if ( $parent !== '' && $parent !== '/' && $parent !== '.' ) {
				return $parent;
			}
		}
		return $base !== '' ? $base : $path;
	}

	/**
	 * Restore on-disk casing for a path that was lowercased (or otherwise
	 * case-mangled) during encode/decode. On case-insensitive filesystems
	 * is_dir() accepts the wrong case and realpath() may echo it back, so we
	 * walk each segment against scandir() entries instead.
	 */
	public static function restorePathCase( string $path ): string {
		if ( $path === '' || $path[0] !== '/' ) {
			return $path;
		}
		$parts   = explode( '/', trim( $path, '/' ) );
		$current = '';
		foreach ( $parts as $part ) {
			if ( $part === '' ) {
				continue;
			}
			$parent = $current === '' ? '/' : $current;
			$found  = null;
			if ( is_dir( $parent ) ) {
				foreach ( scandir( $parent ) ?: [] as $entry ) {
					if ( $entry === '.' || $entry === '..' ) {
						continue;
					}
					if ( strcasecmp( $entry, $part ) === 0 ) {
						$found = $entry;
						break;
					}
				}
			}
			$current .= '/' . ( $found ?? $part );
		}
		return $current !== '' ? $current : $path;
	}

	/**
	 * Short model label for session list chips.
	 * "claude-opus-4-6" → "opus-4-6", "anthropic/claude-sonnet-4" → "sonnet-4",
	 * "grok-4.5" stays "grok-4.5".
	 */
	public static function shortModelName( string $model ): string {
		$raw = trim( $model );
		if ( $raw === '' ) {
			return '';
		}
		// provider/model → model
		$name = str_contains( $raw, '/' ) ? substr( $raw, strrpos( $raw, '/' ) + 1 ) : $raw;
		$name = preg_replace( '/\s+/', '-', $name ) ?? $name;
		// Keep grok-* and gpt-* recognizable.
		if ( preg_match( '/^(grok|gpt)[-_.]?/i', $name ) ) {
			return strtolower( $name );
		}
		// Drop claude-/google-/gemini-/openai-/xai- prefixes.
		$name = preg_replace( '/^(claude|google|gemini|openai|xai)[-_]/i', '', $name ) ?? $name;
		return $name !== '' ? $name : $raw;
	}
}
