<?php

/**
 * Grok Bot (SpaceXAI desktop) session provider.
 *
 * Closed-source Electron app (bundle id com.anysphere.sand, marketed as
 * Grok Bot). One long-lived chat per sidebar agent (Hunter, MoeBot, ...),
 * not a cwd-scoped coding session. Source of truth is a remote box SQLite
 * store; the Mac client keeps a plaintext JSON replica we can read:
 *
 *   ~/Library/Application Support/Grok Bot/sand-client-persistence/*.blob
 *
 * Blob filenames are unpadded RFC 4648 base32 of slice keys such as
 *   sand.client.slice.account.<acct>.roster.last-roster
 *   sand.client.slice.account.<acct>.transcript.replicas.<agent-uuid>
 *
 * Each blob is {"schemaVersion":N,"value":...}. GROKBOT_HOME overrides the
 * app-support directory. iPhone chats only appear here if they synced into
 * this cache. sand:// deep links open the app, not a specific thread.
 */
class GrokBotSessions {

	// ─── Provider Contract ──────────────────────────────────────

	public static function sourceId(): string {
		return 'grokbot';
	}

	public static function sourceLabel(): string {
		return 'Grok Bot';
	}

	public static function hasSession( string $sessionId ): bool {
		return self::agentRow( $sessionId ) !== null;
	}

	public static function findSessionFile( string $id, ?string $project = null ): ?string {
		$row = self::agentRow( $id );
		if ( ! $row ) {
			return null;
		}
		$path = $row['_replica'] ?? null;
		return ( is_string( $path ) && is_file( $path ) ) ? $path : null;
	}

	public static function fingerprint( array $session ): ?array {
		$file = self::findSessionFile( $session['id'] ?? '' );
		if ( ! $file ) {
			return null;
		}
		clearstatcache( true, $file );
		return [
			'mtime' => (int) ( @filemtime( $file ) ?: 0 ),
			'size'  => (int) ( @filesize( $file ) ?: 0 ),
		];
	}

	/**
	 * Estimated usage. Replicas store text only, no token bills.
	 * Same ~4 chars/token model as T3 Code / Command Code.
	 */
	public static function extractUsage( array $session ): ?array {
		$entries = self::replicaEntries( $session['id'] ?? '' );
		if ( $entries === null ) {
			return null;
		}

		$total    = 0;
		$outChars = 0;
		$ctxChars = 0;

		foreach ( $entries as $entry ) {
			$text  = self::entrySearchText( $entry );
			$chars = strlen( $text );
			if ( $chars === 0 ) {
				continue;
			}
			if ( self::isUserEntry( $entry ) ) {
				$total += $chars;
				continue;
			}
			$outChars += $chars;
			$ctxChars += $total;
			$total    += $chars;
		}

		if ( $total === 0 ) {
			return null;
		}

		return [
			'input'          => intdiv( $total, 4 ),
			'output'         => intdiv( $outChars, 4 ),
			'cache_read'     => intdiv( $ctxChars, 4 ),
			'cache_creation' => 0,
		];
	}

	public static function extractTimings( array $session ): ?array {
		$entries = self::replicaEntries( $session['id'] ?? '' );
		if ( ! $entries ) {
			return null;
		}
		$ts = [];
		foreach ( $entries as $entry ) {
			$ms = (int) ( $entry['timestampMs'] ?? 0 );
			if ( $ms > 0 ) {
				$ts[] = intdiv( $ms, 1000 );
			}
		}
		return Helpers::computeTimings( $ts );
	}

	public static function extractSessionText( array $session ): string {
		$parts = [];

		if ( ! empty( $session['display'] ) ) {
			$parts[] = $session['display'];
		}

		$row = self::agentRow( $session['id'] ?? '' );
		if ( $row ) {
			foreach ( [ 'name', 'title', 'description' ] as $field ) {
				$val = trim( (string) ( $row[ $field ] ?? '' ) );
				if ( $val !== '' && $val !== ( $session['display'] ?? '' ) ) {
					$parts[] = $val;
				}
			}
		}

		// These chats are long-lived (one transcript per agent). Cap high
		// enough to keep recent work searchable; replicas today are tens of KB.
		$maxChars = 200000;
		$used     = strlen( implode( "\n", $parts ) );
		foreach ( self::replicaEntries( $session['id'] ?? '' ) ?? [] as $entry ) {
			$text = self::entrySearchText( $entry );
			if ( $text === '' ) {
				continue;
			}
			$parts[] = $text;
			$used   += strlen( $text );
			if ( $used >= $maxChars ) {
				break;
			}
		}

		return implode( "\n", $parts );
	}

	// ─── Listing ────────────────────────────────────────────────

	public static function listSessions( ?string $project = null ): array {
		$home = self::dataDir();
		if ( $project && ! self::projectMatches( $project, $home ) ) {
			return [];
		}

		$index = self::loadIndex();
		if ( empty( $index['agents'] ) ) {
			return [];
		}

		$liveAgent = self::liveAgentId();
		$projectName = Helpers::projectDisplayName( $home );
		$out = [];

		foreach ( $index['agents'] as $row ) {
			$id   = (string) ( $row['id'] ?? '' );
			$name = trim( (string) ( $row['name'] ?? '' ) );
			if ( $name === '' ) {
				$name = 'Grok Bot';
			}

			$ms = (int) ( $row['lastActivityAt'] ?? $row['updatedAt'] ?? $row['createdAt'] ?? 0 );
			if ( $ms <= 0 ) {
				$replica = $row['_replica'] ?? '';
				$ms      = $replica && is_file( $replica ) ? ( (int) @filemtime( $replica ) * 1000 ) : 0;
			}

			$size = 0;
			if ( ! empty( $row['_replica'] ) && is_file( $row['_replica'] ) ) {
				$size = (int) @filesize( $row['_replica'] );
			}

			$record = [
				'id'          => $id,
				'display'     => $name,
				'timestamp'   => $ms,
				'timestamp_s' => $ms > 0 ? intdiv( $ms, 1000 ) : 0,
				'project'     => $home,
				'projectName' => $projectName,
				'size'        => $size,
				'source'      => self::sourceId(),
				'sourceLabel' => self::sourceLabel(),
				'model'       => 'grok-bot',
			];

			$title = trim( (string) ( $row['title'] ?? '' ) );
			if ( $title !== '' && strcasecmp( $title, $name ) !== 0 && strcasecmp( $title, 'assistant' ) !== 0 ) {
				$record['agent_name'] = $title;
			}

			if ( $liveAgent !== null && strcasecmp( $liveAgent, $id ) === 0 ) {
				$record['live'] = true;
			}

			$out[] = $record;
		}

		usort( $out, fn( $a, $b ) => ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 ) );
		return $out;
	}

	public static function listProjects(): array {
		$sessions = self::listSessions();
		if ( ! $sessions ) {
			return [];
		}
		$home = self::dataDir();
		$latest = 0;
		foreach ( $sessions as $s ) {
			$latest = max( $latest, (int) ( $s['timestamp'] ?? 0 ) );
		}
		return [
			[
				'path'     => $home,
				'name'     => Helpers::projectDisplayName( $home ),
				'sessions' => count( $sessions ),
				'latest'   => $latest,
			],
		];
	}

	// ─── Conversation ───────────────────────────────────────────

	public static function getConversation( string $sessionId ): array {
		$row = self::agentRow( $sessionId );
		if ( ! $row ) {
			return [];
		}

		$events = [
			[
				'type'       => 'init',
				'model'      => 'grok-bot',
				'session_id' => $sessionId,
				'skills'     => [],
			],
		];

		$name = trim( (string) ( $row['name'] ?? '' ) );
		$desc = trim( (string) ( $row['description'] ?? '' ) );
		$summary = $name;
		if ( $desc !== '' ) {
			$summary = $name !== '' ? ( $name . ' - ' . $desc ) : $desc;
		}
		if ( $summary !== '' ) {
			$events[] = [
				'type' => 'summary',
				'text' => $summary,
			];
		}

		foreach ( self::replicaEntries( $sessionId ) ?? [] as $entry ) {
			$mapped = self::mapEntry( $entry );
			if ( $mapped ) {
				$events[] = $mapped;
			}
		}

		return $events;
	}

	public static function handleStream( string $sessionId, int $runnerPid = 0 ): void {
		if ( ! self::hasSession( $sessionId ) ) {
			http_response_code( 404 );
			echo json_encode( [ 'error' => 'Session not found' ] );
			return;
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		$eventId     = 0;
		$lastEventId = intval( $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0 );
		foreach ( self::getConversation( $sessionId ) as $event ) {
			$eventId++;
			if ( $eventId <= $lastEventId ) {
				continue;
			}
			echo "id: $eventId\n";
			echo "event: {$event['type']}\n";
			echo 'data: ' . json_encode( $event ) . "\n\n";
		}

		echo 'id: ' . ( ++$eventId ) . "\n";
		echo "event: done\n";
		echo 'data: ' . json_encode( [ 'reason' => 'history-only' ] ) . "\n\n";
		flush();
	}

	public static function dataDir(): string {
		$override = getenv( 'GROKBOT_HOME' );
		if ( $override ) {
			return rtrim( $override, '/' );
		}
		$home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );
		return rtrim( $home, '/' ) . '/Library/Application Support/Grok Bot';
	}

	// ─── Internals ──────────────────────────────────────────────

	private static function persistenceDir(): string {
		return self::dataDir() . '/sand-client-persistence';
	}

	private static function isValidSessionId( string $id ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$id
		);
	}

	private static function agentRow( string $id ): ?array {
		if ( ! self::isValidSessionId( $id ) ) {
			return null;
		}
		$agents = self::loadIndex()['agents'];
		$key    = strtolower( $id );
		return $agents[ $key ] ?? null;
	}

	/**
	 * Scan persistence blobs once per request.
	 *
	 * @return array{agents: array<string,array>}
	 */
	private static function loadIndex(): array {
		static $cache = null;
		if ( is_array( $cache ) ) {
			return $cache;
		}

		$cache = [ 'agents' => [] ];
		$dir   = self::persistenceDir();
		if ( ! is_dir( $dir ) ) {
			return $cache;
		}

		$replicas = [];
		$rosters  = [];
		$files    = glob( $dir . '/*.blob' ) ?: [];
		foreach ( $files as $path ) {
			$stem = basename( $path, '.blob' );
			$key  = self::base32Decode( $stem );
			if ( $key === null ) {
				continue;
			}
			if ( preg_match( '/\.transcript\.replicas\.([0-9a-f-]{36})$/i', $key, $m ) ) {
				$replicas[ strtolower( $m[1] ) ] = $path;
			} elseif ( str_ends_with( $key, '.roster.last-roster' ) ) {
				$rosters[] = $path;
			}
		}

		foreach ( $rosters as $path ) {
			$data = self::readJson( $path );
			$rows = is_array( $data ) ? ( $data['value']['rows'] ?? null ) : null;
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = (string) ( $row['id'] ?? '' );
				if ( ! self::isValidSessionId( $id ) ) {
					continue;
				}
				if ( ! empty( $row['isHiddenFromSidebar'] ) ) {
					continue;
				}
				$key               = strtolower( $id );
				$row['_replica']   = $replicas[ $key ] ?? null;
				$cache['agents'][ $key ] = $row;
			}
		}

		foreach ( $replicas as $key => $path ) {
			if ( isset( $cache['agents'][ $key ] ) ) {
				continue;
			}
			$mtime = (int) @filemtime( $path );
			$cache['agents'][ $key ] = [
				'id'             => $key,
				'name'           => 'Grok Bot',
				'lastActivityAt' => $mtime > 0 ? $mtime * 1000 : 0,
				'_replica'       => $path,
			];
		}

		return $cache;
	}

	private static function replicaEntries( string $id ): ?array {
		$file = self::findSessionFile( $id );
		if ( ! $file ) {
			return null;
		}
		$data = self::readJson( $file );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$entries = $data['value']['entries'] ?? null;
		return is_array( $entries ) ? $entries : [];
	}

	private static function mapEntry( array $entry ): ?array {
		$kind = (string) ( $entry['kind'] ?? '' );

		if ( $kind === 'message' && self::isUserEntry( $entry ) ) {
			$text = trim( (string) ( $entry['content'] ?? '' ) );
			if ( $text === '' && ! empty( $entry['respondedValue'] ) ) {
				$text = trim( (string) $entry['respondedValue'] );
			}
			if ( $text === '' ) {
				return null;
			}
			return [ 'type' => 'user_message', 'text' => $text ];
		}

		if ( $kind === 'event' ) {
			$text = self::eventText( $entry['event'] ?? null );
			return $text !== '' ? [ 'type' => 'summary', 'text' => $text ] : null;
		}

		if ( $kind !== 'send-message' ) {
			return null;
		}

		$message = $entry['message'] ?? null;
		if ( ! is_array( $message ) ) {
			return null;
		}
		$type = (string) ( $message['type'] ?? '' );

		if ( $type === 'text' ) {
			$text = trim( (string) ( $message['content'] ?? '' ) );
			return $text !== '' ? [ 'type' => 'text', 'text' => $text ] : null;
		}

		if ( $type === 'widget' ) {
			$text = self::widgetText( $message['widget'] ?? null, $entry['respondedValue'] ?? null );
			return $text !== '' ? [ 'type' => 'text', 'text' => $text ] : null;
		}

		if ( $type === 'local-tool-permission' ) {
			$ask    = is_array( $message['ask'] ?? null ) ? $message['ask'] : [];
			$action = trim( (string) ( $ask['action'] ?? 'local-tool' ) );
			$target = trim( (string) ( $ask['target'] ?? '' ) );
			return [
				'type'     => 'tool_call',
				'tool'     => $action !== '' ? $action : 'local-tool',
				'category' => 'exec',
				'label'    => $target,
			];
		}

		return null;
	}

	private static function isUserEntry( array $entry ): bool {
		return ( $entry['kind'] ?? '' ) === 'message' && ( $entry['role'] ?? '' ) === 'user';
	}

	private static function entrySearchText( array $entry ): string {
		if ( self::isUserEntry( $entry ) ) {
			return trim( (string) ( $entry['content'] ?? '' ) );
		}
		$kind = (string) ( $entry['kind'] ?? '' );
		if ( $kind === 'event' ) {
			return self::eventText( $entry['event'] ?? null );
		}
		if ( $kind !== 'send-message' ) {
			return '';
		}
		$message = $entry['message'] ?? null;
		if ( ! is_array( $message ) ) {
			return '';
		}
		$type = (string) ( $message['type'] ?? '' );
		if ( $type === 'text' ) {
			return trim( (string) ( $message['content'] ?? '' ) );
		}
		if ( $type === 'widget' ) {
			return self::widgetText( $message['widget'] ?? null, $entry['respondedValue'] ?? null );
		}
		if ( $type === 'local-tool-permission' ) {
			$ask = is_array( $message['ask'] ?? null ) ? $message['ask'] : [];
			return trim( (string) ( $ask['action'] ?? '' ) . ' ' . (string) ( $ask['target'] ?? '' ) );
		}
		return '';
	}

	private static function widgetText( $widget, $responded ): string {
		if ( ! is_array( $widget ) ) {
			return '';
		}
		$lines = [];
		$prompt = trim( (string) ( $widget['prompt'] ?? '' ) );
		if ( $prompt !== '' ) {
			$lines[] = $prompt;
		}
		$help = trim( (string) ( $widget['helpText'] ?? '' ) );
		if ( $help !== '' ) {
			$lines[] = $help;
		}
		$n = 0;
		foreach ( $widget['options'] ?? [] as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$label = trim( (string) ( $opt['label'] ?? $opt['value'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			$n++;
			$lines[] = chr( 64 + min( $n, 26 ) ) . '. ' . $label;
		}
		$choice = trim( (string) ( $responded ?? '' ) );
		if ( $choice !== '' ) {
			$lines[] = 'Chose: ' . $choice;
		}
		return implode( "\n", $lines );
	}

	private static function eventText( $event ): string {
		if ( ! is_array( $event ) ) {
			return '';
		}
		$type = (string) ( $event['type'] ?? '' );
		if ( $type === 'name-changed' ) {
			$from = trim( (string) ( $event['from'] ?? '' ) );
			$to   = trim( (string) ( $event['to'] ?? '' ) );
			if ( $from !== '' && $to !== '' ) {
				return 'Renamed ' . $from . ' to ' . $to;
			}
		}
		if ( $type === 'automation-changed' ) {
			$action = trim( (string) ( $event['action'] ?? '' ) );
			$name   = trim( (string) ( $event['automationName'] ?? $event['automationId'] ?? '' ) );
			if ( $name !== '' ) {
				return 'Automation ' . ( $action !== '' ? $action . ': ' : '' ) . $name;
			}
		}
		return $type !== '' ? $type : '';
	}

	/**
	 * Agent the desktop app last had selected, only if the app process is live.
	 */
	private static function liveAgentId(): ?string {
		$marker = self::readJson( self::dataDir() . '/sand-session-marker.json' );
		$pid    = is_array( $marker ) ? (int) ( $marker['pid'] ?? 0 ) : 0;
		if ( $pid <= 0 || ! function_exists( 'posix_kill' ) || ! @posix_kill( $pid, 0 ) ) {
			return null;
		}

		$dir   = self::persistenceDir();
		$files = is_dir( $dir ) ? ( glob( $dir . '/*.blob' ) ?: [] ) : [];
		foreach ( $files as $path ) {
			$key = self::base32Decode( basename( $path, '.blob' ) );
			if ( $key === null || ! str_ends_with( $key, '.selection.last-agent' ) ) {
				continue;
			}
			$data = self::readJson( $path );
			$id   = is_array( $data ) ? (string) ( $data['value']['agentId'] ?? '' ) : '';
			return self::isValidSessionId( $id ) ? $id : null;
		}
		return null;
	}

	private static function projectMatches( string $project, string $home ): bool {
		if ( $project === $home ) {
			return true;
		}
		$name = Helpers::projectDisplayName( $home );
		if ( strcasecmp( $project, $name ) === 0 ) {
			return true;
		}
		return rtrim( $project, '/' ) === rtrim( $home, '/' );
	}

	private static function readJson( string $path ): ?array {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw = @file_get_contents( $path );
		if ( $raw === false || $raw === '' ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * RFC 4648 base32 (lowercase or upper, optional padding).
	 */
	private static function base32Decode( string $input ): ?string {
		$input = strtoupper( $input );
		$input = rtrim( $input, '=' );
		if ( $input === '' ) {
			return '';
		}
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$map      = array_flip( str_split( $alphabet ) );
		$buffer   = 0;
		$bits     = 0;
		$out      = '';
		$len      = strlen( $input );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $input[ $i ];
			if ( ! isset( $map[ $c ] ) ) {
				return null;
			}
			$buffer = ( $buffer << 5 ) | $map[ $c ];
			$bits  += 5;
			if ( $bits >= 8 ) {
				$bits -= 8;
				$out  .= chr( ( $buffer >> $bits ) & 0xFF );
			}
		}
		return $out;
	}
}
