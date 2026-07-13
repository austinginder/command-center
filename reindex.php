<?php
/**
 * CLI: rebuild the deep search FTS5 index.
 *
 * Usage: php reindex.php
 */

if ( php_sapi_name() !== 'cli' ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}

define( 'BASE_DIR', __DIR__ );
define( 'DATA_DIR', __DIR__ . '/data' );

require BASE_DIR . '/app/Helpers.php';
require BASE_DIR . '/app/AmpSessions.php';
require BASE_DIR . '/app/ClaudeSessions.php';
require BASE_DIR . '/app/T3CodeSessions.php';
require BASE_DIR . '/app/OpenCodeSessions.php';
require BASE_DIR . '/app/KimiSessions.php';
require BASE_DIR . '/app/CommandCodeSessions.php';
require BASE_DIR . '/app/AntigravitySessions.php';
require BASE_DIR . '/app/GrokSessions.php';
require BASE_DIR . '/app/SessionRegistry.php';
require BASE_DIR . '/app/Retention.php';
require BASE_DIR . '/app/SearchIndex.php';

echo "Loading sessions...\n";
$sessions = SessionRegistry::listSessions();
echo count( $sessions ) . " sessions found.\n";

echo "Rebuilding index...\n";
$result = SearchIndex::rebuild( $sessions );
echo "Done: {$result['indexed']} sessions indexed in {$result['elapsed_ms']}ms\n";

$status = SearchIndex::status();
echo 'DB size: ' . round( $status['db_size_bytes'] / 1024 / 1024, 1 ) . " MB\n";
