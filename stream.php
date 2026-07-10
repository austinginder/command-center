<?php
/**
 * Unified SSE endpoint — replays a session's conversation as events.
 *
 * Usage:
 *   /stream?session=<session-id>                  — stream a session (auto-detect source)
 *   /stream?session=<session-id>&source=<source>  — stream a session with explicit source
 */

$sessionId = preg_replace( '/[^A-Za-z0-9_-]/', '', $_GET['session'] ?? '' );
$source    = preg_replace( '/[^a-z0-9_-]/', '', $_GET['source'] ?? '' );

if ( ! $sessionId ) {
	http_response_code( 400 );
	echo json_encode( [ 'error' => 'Missing session parameter' ] );
	exit;
}

$resolvedSource = $source ?: SessionRegistry::detectSource( $sessionId );
if ( ! $resolvedSource ) {
	http_response_code( 404 );
	echo json_encode( [ 'error' => 'Session not found' ] );
	exit;
}

$class = SessionRegistry::provider( $resolvedSource );
if ( ! $class ) {
	http_response_code( 400 );
	echo json_encode( [ 'error' => 'Unknown source: ' . $resolvedSource ] );
	exit;
}

$class::handleStream( $sessionId, 0 );
