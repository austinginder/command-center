<?php

class Router {

	public static function dispatch(): void {
		$uri  = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		$path = rtrim( $uri, '/' ) ?: '/';

		// Unified SSE stream - handles both agents and raw sessions.
		if ( $path === '/stream' ) {
			require BASE_DIR . '/stream.php';
			return;
		}

		// API routes.
		if ( str_starts_with( $path, '/api/' ) || $path === '/api' ) {
			require BASE_DIR . '/api.php';
			return;
		}

		// Everything else → SPA shell (client-side routing).
		require BASE_DIR . '/templates/shell.php';
	}
}
