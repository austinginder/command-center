<?php
// JSON API dispatcher — session index endpoints.

header( 'Content-Type: application/json' );

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$path   = preg_replace( '#^/api#', '', $uri );
$path   = rtrim( $path, '/' ) ?: '/';

// GET /api/sessions — list sessions across providers (optionally filtered by source/project)
if ( $method === 'GET' && $path === '/sessions' ) {
	$project = $_GET['project'] ?? null;
	$source  = $_GET['source']  ?? null;
	echo json_encode( SessionRegistry::listSessions( $project ?: null, $source ?: null ) );
	exit;
}

// GET /api/sessions/projects — list unique projects across providers
if ( $method === 'GET' && $path === '/sessions/projects' ) {
	$source = $_GET['source'] ?? null;
	echo json_encode( SessionRegistry::listProjects( $source ?: null ) );
	exit;
}

// GET /api/sessions/sources — available providers
if ( $method === 'GET' && $path === '/sessions/sources' ) {
	$out = [];
	foreach ( SessionRegistry::providers() as $id => $class ) {
		$out[] = [ 'id' => $id, 'label' => $class::sourceLabel() ];
	}
	echo json_encode( $out );
	exit;
}

// POST /api/sessions/search/reindex — full index rebuild across all providers
if ( $method === 'POST' && $path === '/sessions/search/reindex' ) {
	set_time_limit( 120 );
	require_once BASE_DIR . '/app/SearchIndex.php';
	$sessions = SessionRegistry::listSessions();
	echo json_encode( SearchIndex::rebuild( $sessions ) );
	exit;
}

// GET /api/sessions/stats/daily — per-day session counts + token totals (heatmap)
if ( $method === 'GET' && $path === '/sessions/stats/daily' ) {
	require_once BASE_DIR . '/app/SearchIndex.php';
	$project = $_GET['project'] ?? null;
	$source  = $_GET['source']  ?? null;

	// Keep the index fresh so today's sessions show up on the heatmap.
	// Skipped when the backlog is large (first run) — the reindex button covers that.
	set_time_limit( 60 );
	$sessions = SessionRegistry::listSessions( $project ?: null, $source ?: null );
	$stale    = SearchIndex::getStaleSessions( $sessions );
	if ( ! empty( $stale ) && count( $stale ) <= 50 ) {
		SearchIndex::indexSessions( $stale );
	}

	echo json_encode( SearchIndex::statsDaily( $project ?: null, $source ?: null ) );
	exit;
}

// POST /api/sessions/tokens/backfill — compute token usage for rows indexed before the usage columns existed
if ( $method === 'POST' && $path === '/sessions/tokens/backfill' ) {
	set_time_limit( 600 );
	require_once BASE_DIR . '/app/SearchIndex.php';
	$limit = min( 2000, max( 1, (int) ( $_GET['limit'] ?? 500 ) ) );
	echo json_encode( SearchIndex::backfillUsage( $limit ) );
	exit;
}

// GET /api/sessions/search/status — index health/stats
if ( $method === 'GET' && $path === '/sessions/search/status' ) {
	require_once BASE_DIR . '/app/SearchIndex.php';
	echo json_encode( SearchIndex::status() );
	exit;
}

// GET /api/sessions/search?q=<term>&project=<optional>&source=<optional> — deep search conversation content
if ( $method === 'GET' && $path === '/sessions/search' ) {
	$q       = $_GET['q']       ?? '';
	$project = $_GET['project'] ?? null;
	$source  = $_GET['source']  ?? null;

	if ( mb_strlen( $q ) < 3 ) {
		http_response_code( 400 );
		echo json_encode( [ 'error' => 'Query must be at least 3 characters' ] );
		exit;
	}

	set_time_limit( 60 );
	echo json_encode( SessionRegistry::deepSearch( $q, $project ?: null, $source ?: null ) );
	exit;
}

// GET /api/sessions/{id}/conversation?source=<optional> — get full conversation
if ( $method === 'GET' && preg_match( '#^/sessions/([A-Za-z0-9_-]+)/conversation$#', $path, $m ) ) {
	$source = $_GET['source'] ?? null;
	echo json_encode( SessionRegistry::getConversation( $m[1], $source ?: null ) );
	exit;
}

// 404 fallback.
http_response_code( 404 );
echo json_encode( [ 'error' => 'Not found' ] );
