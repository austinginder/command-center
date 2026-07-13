<?php
// JSON API dispatcher - session index endpoints.

header( 'Content-Type: application/json' );

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$path   = preg_replace( '#^/api#', '', $uri );
$path   = rtrim( $path, '/' ) ?: '/';

// GET /api/sessions - list sessions across providers (optionally filtered by source/project)
// Optional: expiring=<days> keeps only sessions at risk within that window (incl. expired).
if ( $method === 'GET' && $path === '/sessions' ) {
	$project  = $_GET['project'] ?? null;
	$source   = $_GET['source']  ?? null;
	$expiring = isset( $_GET['expiring'] ) ? (int) $_GET['expiring'] : null;
	$sessions = SessionRegistry::listSessions( $project ?: null, $source ?: null );
	$sessions = Retention::annotateSessions( $sessions );
	if ( $expiring !== null ) {
		$sessions = Retention::expiringSessions( $sessions, max( 0, $expiring ) );
	}
	echo json_encode( $sessions );
	exit;
}

// Fixed /sessions/* paths MUST come before the /sessions/{id} catch-all.
// GET /api/sessions/projects - list unique projects across providers
if ( $method === 'GET' && $path === '/sessions/projects' ) {
	$source = $_GET['source'] ?? null;
	echo json_encode( SessionRegistry::listProjects( $source ?: null ) );
	exit;
}

// GET /api/sessions/sources - available providers
if ( $method === 'GET' && $path === '/sessions/sources' ) {
	$out = [];
	foreach ( SessionRegistry::providers() as $id => $class ) {
		$out[] = [ 'id' => $id, 'label' => $class::sourceLabel(), 'usage' => SessionRegistry::usageType( $id ) ];
	}
	echo json_encode( $out );
	exit;
}

// GET /api/retention - per-provider retention policies + at-risk stats
if ( $method === 'GET' && $path === '/retention' ) {
	// Reuse the session list so stats match the dashboard.
	$sessions = SessionRegistry::listSessions();
	echo json_encode( Retention::report( $sessions ) );
	exit;
}

// PUT /api/retention/preferences - save CC-side warning prefs (data/retention.json)
if ( $method === 'PUT' && $path === '/retention/preferences' ) {
	$raw  = file_get_contents( 'php://input' );
	$body = json_decode( $raw ?: '', true );
	if ( ! is_array( $body ) ) {
		http_response_code( 400 );
		echo json_encode( [ 'error' => 'JSON body required' ] );
		exit;
	}
	try {
		$prefs = Retention::savePrefs( $body );
		echo json_encode( [ 'ok' => true, 'prefs' => $prefs ] );
	} catch ( \Throwable $e ) {
		http_response_code( 500 );
		echo json_encode( [ 'error' => $e->getMessage() ] );
	}
	exit;
}

// POST /api/sessions/search/reindex - full index rebuild across all providers
if ( $method === 'POST' && $path === '/sessions/search/reindex' ) {
	set_time_limit( 120 );
	require_once BASE_DIR . '/app/SearchIndex.php';
	$sessions = SessionRegistry::listSessions();
	echo json_encode( SearchIndex::rebuild( $sessions ) );
	exit;
}

// GET /api/sessions/stats/daily - per-day session counts + token totals (heatmap)
if ( $method === 'GET' && $path === '/sessions/stats/daily' ) {
	require_once BASE_DIR . '/app/SearchIndex.php';
	$project = $_GET['project'] ?? null;
	$source  = $_GET['source']  ?? null;

	// Keep the index fresh so today's sessions show up on the heatmap.
	// Skipped when the backlog is large (first run) - the reindex button covers that.
	set_time_limit( 60 );
	$sessions = SessionRegistry::listSessions( $project ?: null, $source ?: null );
	$stale    = SearchIndex::getStaleSessions( $sessions );
	if ( ! empty( $stale ) && count( $stale ) <= 50 ) {
		SearchIndex::indexSessions( $stale );
	}

	echo json_encode( SearchIndex::statsDaily( $project ?: null, $source ?: null ) );
	exit;
}

// GET /api/sessions/stats/monthly - per-month token totals by source (usage view)
if ( $method === 'GET' && $path === '/sessions/stats/monthly' ) {
	require_once BASE_DIR . '/app/SearchIndex.php';
	$project = $_GET['project'] ?? null;
	echo json_encode( SearchIndex::statsMonthly( $project ?: null ) );
	exit;
}

// POST /api/sessions/tokens/backfill - compute token usage for rows indexed before the usage columns existed
if ( $method === 'POST' && $path === '/sessions/tokens/backfill' ) {
	set_time_limit( 600 );
	require_once BASE_DIR . '/app/SearchIndex.php';
	$limit = min( 2000, max( 1, (int) ( $_GET['limit'] ?? 500 ) ) );
	echo json_encode( SearchIndex::backfillUsage( $limit ) );
	exit;
}

// GET /api/sessions/search/status - index health/stats (+ listed/skipped/stale)
if ( $method === 'GET' && $path === '/sessions/search/status' ) {
	require_once BASE_DIR . '/app/SearchIndex.php';
	set_time_limit( 60 );
	// Compare against the live list so the UI can show "skipped N" / stale.
	$sessions = SessionRegistry::listSessions();
	echo json_encode( SearchIndex::status( $sessions ) );
	exit;
}

// GET /api/sessions/search?q=<term>&project=<optional>&source=<optional> - deep search conversation content
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

// GET /api/sessions/{id}/conversation?source=<optional> - get full conversation
if ( $method === 'GET' && preg_match( '#^/sessions/([A-Za-z0-9_-]+)/conversation$#', $path, $m ) ) {
	$source = $_GET['source'] ?? null;
	echo json_encode( SessionRegistry::getConversation( $m[1], $source ?: null ) );
	exit;
}

// GET /api/sessions/{id} - single session meta (incl. nested subagents / parent link).
// Must stay AFTER fixed paths like /sessions/projects, /sessions/sources, /sessions/search.
if ( $method === 'GET' && preg_match( '#^/sessions/([A-Za-z0-9_-]+)$#', $path, $m ) ) {
	$source  = $_GET['source'] ?? null;
	$session = SessionRegistry::getSession( $m[1], $source ?: null );
	if ( ! $session ) {
		http_response_code( 404 );
		echo json_encode( [ 'error' => 'Session not found' ] );
		exit;
	}
	$annotated = Retention::annotateSessions( [ $session ] );
	echo json_encode( $annotated[0] ?? $session );
	exit;
}

// 404 fallback.
http_response_code( 404 );
echo json_encode( [ 'error' => 'Not found' ] );
