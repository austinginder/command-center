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
}
