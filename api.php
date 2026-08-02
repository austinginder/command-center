<?php
// JSON API dispatcher - session index endpoints.

header( 'Content-Type: application/json' );

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$path   = preg_replace( '#^/api#', '', $uri );
$path   = rtrim( $path, '/' ) ?: '/';

// GET /api/version - local manifest only (no network)
if ( $method === 'GET' && $path === '/version' ) {
	try {
		echo json_encode( Updater::localManifest() );
	} catch ( \Throwable $e ) {
		http_response_code( 500 );
		echo json_encode( [ 'error' => $e->getMessage() ] );
	}
	exit;
}

// GET /api/changelog - local CHANGELOG.md for the in-app changelog page
if ( $method === 'GET' && $path === '/changelog' ) {
	$file = BASE_DIR . '/CHANGELOG.md';
	if ( ! is_readable( $file ) ) {
		http_response_code( 404 );
		echo json_encode( [ 'error' => 'CHANGELOG.md not found' ] );
		exit;
	}
	$md = (string) file_get_contents( $file );
	// Drop the leading H1 title - the page header supplies it.
	$md = preg_replace( '/^#\s+Changelog\s*\n+/i', '', $md, 1 );
	$version = null;
	try {
		$version = Updater::localManifest()['version'] ?? null;
	} catch ( \Throwable $e ) {
		// non-fatal
	}
	echo json_encode( [
		'markdown' => $md,
		'version'  => $version,
	] );
	exit;
}

// GET /api/update/check - status (+ daily cache). ?force=1 bypasses cache.
if ( $method === 'GET' && $path === '/update/check' ) {
	$force = isset( $_GET['force'] ) && ( $_GET['force'] === '1' || $_GET['force'] === 'true' );
	try {
		echo json_encode( Updater::status( $force ) );
	} catch ( \Throwable $e ) {
		http_response_code( 500 );
		echo json_encode( [ 'error' => $e->getMessage() ] );
	}
	exit;
}

// POST /api/update - apply available update (git pull or zip package)
if ( $method === 'POST' && $path === '/update' ) {
	set_time_limit( 180 );
	$result = Updater::apply();
	if ( empty( $result['ok'] ) ) {
		http_response_code( 409 );
	}
	echo json_encode( $result );
	exit;
}

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
	// Indexed runtime stats (active time / longest stretch); best-effort.
	try {
		require_once BASE_DIR . '/app/SearchIndex.php';
		$sessions = SearchIndex::annotateTimings( $sessions );
	} catch ( \Throwable $e ) {
		// Index unavailable - list still works without timing fields.
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
	// Explicit rebuild - walk the providers rather than trusting a recent list.
	$sessions = SessionRegistry::listSessions( null, null, true );
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
	$stale    = SearchIndex::filterSettled( SearchIndex::getStaleSessions( $sessions ) );
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

// POST /api/sessions/timings/backfill - compute runtime stats for rows indexed before the timing columns existed
if ( $method === 'POST' && $path === '/sessions/timings/backfill' ) {
	set_time_limit( 600 );
	require_once BASE_DIR . '/app/SearchIndex.php';
	$limit = min( 2000, max( 1, (int) ( $_GET['limit'] ?? 500 ) ) );
	echo json_encode( SearchIndex::backfillTimings( $limit ) );
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

// GET /api/sessions/{id}/conversation?source=&limit=&offset=
// Paginated conversation events. Default is the *tail* (latest N events) so
// huge sessions (multi-MB Grok/Claude transcripts) do not freeze the browser.
// Response: { events, total, offset, limit, has_more_before, has_more_after }
if ( $method === 'GET' && preg_match( '#^/sessions/([A-Za-z0-9_-]+)/conversation$#', $path, $m ) ) {
	set_time_limit( 120 );
	$source = $_GET['source'] ?? null;
	$events = SessionRegistry::getConversation( $m[1], $source ?: null );
	if ( ! is_array( $events ) ) {
		$events = [];
	}
	// Re-index numerically; some providers may leave holes.
	$events = array_values( $events );
	$total  = count( $events );

	$limit = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : 200;
	$limit = max( 1, min( 1000, $limit ) );

	if ( isset( $_GET['offset'] ) && $_GET['offset'] !== '' ) {
		$offset = max( 0, (int) $_GET['offset'] );
	} else {
		// Tail window: most recent $limit events (or entire session if smaller).
		$offset = max( 0, $total - $limit );
	}
	if ( $offset > $total ) {
		$offset = $total;
	}

	$slice = array_slice( $events, $offset, $limit );
	$count = count( $slice );

	echo json_encode( [
		'events'          => $slice,
		'total'           => $total,
		'offset'          => $offset,
		'limit'           => $limit,
		'count'           => $count,
		'has_more_before' => $offset > 0,
		'has_more_after'  => ( $offset + $count ) < $total,
	] );
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
	try {
		require_once BASE_DIR . '/app/SearchIndex.php';
		$annotated = SearchIndex::annotateTimings( $annotated );
	} catch ( \Throwable $e ) {
		// Index unavailable - meta still works without timing fields.
	}
	echo json_encode( $annotated[0] ?? $session );
	exit;
}

// 404 fallback.
http_response_code( 404 );
echo json_encode( [ 'error' => 'Not found' ] );
