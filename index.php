<?php

define( 'BASE_DIR', __DIR__ );
define( 'DATA_DIR', __DIR__ . '/data' );

require BASE_DIR . '/app/Helpers.php';
require BASE_DIR . '/app/ClaudeSessions.php';
require BASE_DIR . '/app/T3CodeSessions.php';
require BASE_DIR . '/app/OpenCodeSessions.php';
require BASE_DIR . '/app/KimiSessions.php';
require BASE_DIR . '/app/CommandCodeSessions.php';
require BASE_DIR . '/app/AntigravitySessions.php';
require BASE_DIR . '/app/GrokSessions.php';
require BASE_DIR . '/app/SessionRegistry.php';
require BASE_DIR . '/app/Router.php';

Router::dispatch();
