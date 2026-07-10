<?php

/**
 * SQLite FTS5 search index for session content across every provider.
 *
 * Each indexed row carries a `source` tag (claude, t3code, ...) so searches
 * can be scoped to a single provider or left wide-open across all of them.
 *
 * Fingerprinting + text extraction are delegated to the provider via
 * SessionRegistry, which keeps this file agnostic to the underlying storage.
 */
class SearchIndex {

	private static ?\SQLite3 $db = null;
	private static string $dbPath = '';

	private static function dbPath(): string {
		if ( self::$dbPath === '' ) {
			self::$dbPath = DATA_DIR . '/search-index.db';
		}
		return self::$dbPath;
	}

	private static function db(): \SQLite3 {
		if ( self::$db === null ) {
			self::$db = new \SQLite3( self::dbPath() );
			self::$db->busyTimeout( 5000 );
			self::$db->exec( 'PRAGMA journal_mode=WAL' );
			self::$db->exec( 'PRAGMA synchronous=NORMAL' );
			self::ensureSchema();
		}
		return self::$db;
	}

	private static function ensureSchema(): void {
		$db = self::$db;

		$db->exec( '
			CREATE TABLE IF NOT EXISTS session_files (
				session_id   TEXT NOT NULL,
				source       TEXT NOT NULL DEFAULT "claude",
				file_path    TEXT NOT NULL,
				project      TEXT NOT NULL DEFAULT "",
				project_name TEXT NOT NULL DEFAULT "",
				display      TEXT NOT NULL DEFAULT "",
				timestamp_ms INTEGER NOT NULL DEFAULT 0,
				file_size    INTEGER NOT NULL DEFAULT 0,
				file_mtime   INTEGER NOT NULL DEFAULT 0,
				indexed_at   INTEGER NOT NULL DEFAULT 0,
				tokens_input          INTEGER,
				tokens_output         INTEGER,
				tokens_cache_read     INTEGER,
				tokens_cache_creation INTEGER,
				PRIMARY KEY (source, session_id)
			)
		' );

		// Migrate older single-source schema that used session_id as the primary key.
		$cols = [];
		$res  = $db->query( 'PRAGMA table_info(session_files)' );
		if ( $res ) {
			while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
				$cols[] = $row['name'];
			}
		}
		if ( ! in_array( 'source', $cols, true ) ) {
			$db->exec( 'ALTER TABLE session_files ADD COLUMN source TEXT NOT NULL DEFAULT "claude"' );
		}

		// Token usage columns (NULL = not yet computed — see backfillUsage()).
		if ( ! in_array( 'tokens_output', $cols, true ) ) {
			$db->exec( 'ALTER TABLE session_files ADD COLUMN tokens_input INTEGER' );
			$db->exec( 'ALTER TABLE session_files ADD COLUMN tokens_output INTEGER' );
			$db->exec( 'ALTER TABLE session_files ADD COLUMN tokens_cache_read INTEGER' );
			$db->exec( 'ALTER TABLE session_files ADD COLUMN tokens_cache_creation INTEGER' );
		}

		$db->exec( 'CREATE INDEX IF NOT EXISTS idx_sf_project   ON session_files(project)' );
		$db->exec( 'CREATE INDEX IF NOT EXISTS idx_sf_source    ON session_files(source)' );
		$db->exec( 'CREATE INDEX IF NOT EXISTS idx_sf_timestamp ON session_files(timestamp_ms DESC)' );

		// FTS5 virtual table. `source` is UNINDEXED — we filter via JOIN on session_files.
		$r = $db->querySingle( "SELECT name FROM sqlite_master WHERE type='table' AND name='sessions_fts'" );
		if ( ! $r ) {
			$db->exec( "CREATE VIRTUAL TABLE sessions_fts USING fts5(session_id UNINDEXED, source UNINDEXED, content, tokenize = 'unicode61')" );
		} else {
			// Detect old single-source FTS and upgrade it by rebuilding next time rebuild() runs.
			$ftsCols   = [];
			$ftsRes    = $db->query( 'PRAGMA table_info(sessions_fts)' );
			if ( $ftsRes ) {
				while ( $row = $ftsRes->fetchArray( SQLITE3_ASSOC ) ) {
					$ftsCols[] = $row['name'];
				}
			}
			if ( ! in_array( 'source', $ftsCols, true ) ) {
				// Safe: drop and recreate — callers will repopulate via rebuild() on next search.
				$db->exec( 'DROP TABLE IF EXISTS sessions_fts' );
				$db->exec( "CREATE VIRTUAL TABLE sessions_fts USING fts5(session_id UNINDEXED, source UNINDEXED, content, tokenize = 'unicode61')" );
				$db->exec( 'DELETE FROM session_files' ); // Forces re-index on next stale check.
			}
		}
	}

	/**
	 * Find sessions that need (re)indexing by comparing fingerprint (mtime/size)
	 * against the stored values. Delegates fingerprinting to the provider.
	 *
	 * @param array $sessions Tagged session list from SessionRegistry::listSessions().
	 * @return array Sessions needing index updates (with _mtime, _size attached).
	 */
	public static function getStaleSessions( array $sessions ): array {
		$db    = self::db();
		$stale = [];

		$stmt = $db->prepare( 'SELECT file_mtime, file_size FROM session_files WHERE source = :source AND session_id = :id' );

		foreach ( $sessions as $s ) {
			$source = $s['source'] ?? 'claude';
			$fp     = SessionRegistry::fingerprint( $s );
			if ( ! $fp ) {
				continue;
			}

			$stmt->bindValue( ':source', $source, SQLITE3_TEXT );
			$stmt->bindValue( ':id',     $s['id'], SQLITE3_TEXT );
			$row = $stmt->execute()->fetchArray( SQLITE3_ASSOC );
			$stmt->reset();

			if ( ! $row || (int) $row['file_mtime'] !== $fp['mtime'] || (int) $row['file_size'] !== $fp['size'] ) {
				$s['_mtime'] = $fp['mtime'];
				$s['_size']  = $fp['size'];
				$stale[]     = $s;
			}
		}

		return $stale;
	}

	/**
	 * Index a batch of sessions. Extract-text is delegated to the provider.
	 *
	 * @param array $sessions Sessions with source, _mtime, _size attached.
	 * @return int Number indexed.
	 */
	public static function indexSessions( array $sessions ): int {
		if ( empty( $sessions ) ) {
			return 0;
		}

		$db    = self::db();
		$count = 0;

		$db->exec( 'BEGIN IMMEDIATE' );

		try {
			$metaStmt = $db->prepare( '
				INSERT OR REPLACE INTO session_files
					(session_id, source, file_path, project, project_name, display, timestamp_ms, file_size, file_mtime, indexed_at,
					 tokens_input, tokens_output, tokens_cache_read, tokens_cache_creation)
				VALUES
					(:id, :source, :path, :project, :pname, :display, :ts, :size, :mtime, :now,
					 :tok_in, :tok_out, :tok_cr, :tok_cc)
			' );

			$ftsDeleteStmt = $db->prepare( 'DELETE FROM sessions_fts WHERE session_id = :id AND source = :source' );
			$ftsStmt       = $db->prepare( 'INSERT INTO sessions_fts (session_id, source, content) VALUES (:id, :source, :content)' );

			$now = time();

			foreach ( $sessions as $s ) {
				$source  = $s['source'] ?? 'claude';
				$content = SessionRegistry::extractSessionText( $s );
				if ( $content === '' ) {
					continue;
				}

				$path = SessionRegistry::findSessionFile( $s['id'] ?? '', $s['project'] ?? null, $source ) ?? '';

				// Zero (not NULL) when the provider tracks nothing, so the row
				// isn't endlessly re-visited by backfillUsage().
				$usage = SessionRegistry::extractUsage( $s ) ?: [ 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0 ];

				$metaStmt->bindValue( ':id',      $s['id'],                SQLITE3_TEXT );
				$metaStmt->bindValue( ':source',  $source,                 SQLITE3_TEXT );
				$metaStmt->bindValue( ':path',    $path,                   SQLITE3_TEXT );
				$metaStmt->bindValue( ':project', $s['project'] ?? '',     SQLITE3_TEXT );
				$metaStmt->bindValue( ':pname',   $s['projectName'] ?? '', SQLITE3_TEXT );
				$metaStmt->bindValue( ':display', $s['display'] ?? '',     SQLITE3_TEXT );
				$metaStmt->bindValue( ':ts',      $s['timestamp'] ?? 0,    SQLITE3_INTEGER );
				$metaStmt->bindValue( ':size',    $s['_size'] ?? 0,        SQLITE3_INTEGER );
				$metaStmt->bindValue( ':mtime',   $s['_mtime'] ?? 0,       SQLITE3_INTEGER );
				$metaStmt->bindValue( ':now',     $now,                    SQLITE3_INTEGER );
				$metaStmt->bindValue( ':tok_in',  $usage['input'],          SQLITE3_INTEGER );
				$metaStmt->bindValue( ':tok_out', $usage['output'],         SQLITE3_INTEGER );
				$metaStmt->bindValue( ':tok_cr',  $usage['cache_read'],     SQLITE3_INTEGER );
				$metaStmt->bindValue( ':tok_cc',  $usage['cache_creation'], SQLITE3_INTEGER );
				$metaStmt->execute();
				$metaStmt->reset();

				$ftsDeleteStmt->bindValue( ':id',     $s['id'], SQLITE3_TEXT );
				$ftsDeleteStmt->bindValue( ':source', $source,  SQLITE3_TEXT );
				$ftsDeleteStmt->execute();
				$ftsDeleteStmt->reset();

				$ftsStmt->bindValue( ':id',      $s['id'], SQLITE3_TEXT );
				$ftsStmt->bindValue( ':source',  $source,  SQLITE3_TEXT );
				$ftsStmt->bindValue( ':content', $content, SQLITE3_TEXT );
				$ftsStmt->execute();
				$ftsStmt->reset();

				$count++;
			}

			$db->exec( 'COMMIT' );
		} catch ( \Exception $e ) {
			$db->exec( 'ROLLBACK' );
			throw $e;
		}

		return $count;
	}

	/**
	 * Search the FTS5 index. Pass $source to restrict to one provider.
	 */
	public static function search( string $query, ?string $project = null, ?string $source = null, int $limit = 50 ): array {
		$db = self::db();

		$ftsQuery = self::escapeFtsQuery( $query );
		if ( $ftsQuery === '' ) {
			return [];
		}

		$sql = "
			SELECT sf.session_id, sf.source, sf.project, sf.project_name, sf.display, sf.timestamp_ms, sf.file_size,
			       snippet(sessions_fts, 2, '<mark>', '</mark>', '...', 20) AS snippet
			FROM sessions_fts
			JOIN session_files sf
			  ON sf.session_id = sessions_fts.session_id
			 AND sf.source     = sessions_fts.source
			WHERE sessions_fts MATCH :query
		";

		if ( $project ) {
			$sql .= ' AND sf.project = :project';
		}
		if ( $source ) {
			$sql .= ' AND sf.source = :source';
		}

		$sql .= ' ORDER BY sf.timestamp_ms DESC LIMIT :limit';

		$stmt = $db->prepare( $sql );
		$stmt->bindValue( ':query', $ftsQuery, SQLITE3_TEXT );
		if ( $project ) {
			$stmt->bindValue( ':project', $project, SQLITE3_TEXT );
		}
		if ( $source ) {
			$stmt->bindValue( ':source', $source, SQLITE3_TEXT );
		}
		$stmt->bindValue( ':limit', $limit, SQLITE3_INTEGER );

		$result  = $stmt->execute();
		$results = [];

		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$src   = $row['source'] ?? 'claude';
			$class = SessionRegistry::provider( $src );
			$label = $class ? $class::sourceLabel() : $src;

			$results[] = [
				'id'          => $row['session_id'],
				'display'     => $row['display'],
				'timestamp'   => (int) $row['timestamp_ms'],
				'timestamp_s' => intval( $row['timestamp_ms'] / 1000 ),
				'project'     => $row['project'],
				'projectName' => $row['project_name'],
				'size'        => (int) $row['file_size'],
				'snippet'     => $row['snippet'] ?? '',
				'matchType'   => 'fts',
				'source'      => $src,
				'sourceLabel' => $label,
			];
		}

		return $results;
	}

	/**
	 * Drop and rebuild the entire index from the tagged session list.
	 *
	 * @param array $sessions Output of SessionRegistry::listSessions().
	 * @return array Stats.
	 */
	public static function rebuild( array $sessions ): array {
		$start = microtime( true );
		$db    = self::db();

		$db->exec( 'DROP TABLE IF EXISTS sessions_fts' );
		$db->exec( 'DROP TABLE IF EXISTS session_files' );
		self::ensureSchema();

		$toIndex = [];
		foreach ( $sessions as $s ) {
			$fp = SessionRegistry::fingerprint( $s );
			if ( ! $fp ) {
				continue;
			}
			$s['_mtime'] = $fp['mtime'];
			$s['_size']  = $fp['size'];
			$toIndex[]   = $s;
		}

		$count   = self::indexSessions( $toIndex );
		$elapsed = round( ( microtime( true ) - $start ) * 1000 );

		return [
			'indexed'    => $count,
			'elapsed_ms' => $elapsed,
		];
	}

	/**
	 * Per-day session counts + token totals for the activity heatmap.
	 * Days are local-time; token sums treat NULL (not yet backfilled) as 0.
	 */
	public static function statsDaily( ?string $project = null, ?string $source = null ): array {
		$db = self::db();

		$sql = "
			SELECT date(timestamp_ms / 1000, 'unixepoch', 'localtime') AS day,
			       COUNT(*) AS sessions,
			       SUM(COALESCE(tokens_input, 0) + COALESCE(tokens_cache_read, 0) + COALESCE(tokens_cache_creation, 0)) AS tokens_in,
			       SUM(COALESCE(tokens_output, 0)) AS tokens_out
			FROM session_files
			WHERE timestamp_ms > 0
		";
		if ( $project ) {
			$sql .= ' AND project = :project';
		}
		if ( $source ) {
			$sql .= ' AND source = :source';
		}
		$sql .= ' GROUP BY day ORDER BY day';

		$stmt = $db->prepare( $sql );
		if ( $project ) {
			$stmt->bindValue( ':project', $project, SQLITE3_TEXT );
		}
		if ( $source ) {
			$stmt->bindValue( ':source', $source, SQLITE3_TEXT );
		}

		$result = $stmt->execute();
		$rows   = [];
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$rows[] = [
				'day'        => $row['day'],
				'sessions'   => (int) $row['sessions'],
				'tokens_in'  => (int) $row['tokens_in'],
				'tokens_out' => (int) $row['tokens_out'],
			];
		}
		return $rows;
	}

	/**
	 * Compute token usage for already-indexed rows that predate the usage
	 * columns (tokens_output IS NULL). Providers that track nothing get 0s so
	 * each row is only visited once. Batched — call repeatedly until
	 * remaining hits 0.
	 */
	public static function backfillUsage( int $limit = 500 ): array {
		$start = microtime( true );
		$db    = self::db();

		$res  = $db->query( 'SELECT session_id, source, project FROM session_files WHERE tokens_output IS NULL LIMIT ' . (int) $limit );
		$rows = [];
		while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
			$rows[] = $row;
		}

		$update = $db->prepare( '
			UPDATE session_files
			SET tokens_input = :tok_in, tokens_output = :tok_out, tokens_cache_read = :tok_cr, tokens_cache_creation = :tok_cc
			WHERE source = :source AND session_id = :id
		' );

		$processed = 0;
		foreach ( $rows as $row ) {
			$usage = SessionRegistry::extractUsage( [
				'id'      => $row['session_id'],
				'source'  => $row['source'],
				'project' => $row['project'],
			] ) ?: [ 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0 ];

			$update->bindValue( ':id',      $row['session_id'],       SQLITE3_TEXT );
			$update->bindValue( ':source',  $row['source'],           SQLITE3_TEXT );
			$update->bindValue( ':tok_in',  $usage['input'],          SQLITE3_INTEGER );
			$update->bindValue( ':tok_out', $usage['output'],         SQLITE3_INTEGER );
			$update->bindValue( ':tok_cr',  $usage['cache_read'],     SQLITE3_INTEGER );
			$update->bindValue( ':tok_cc',  $usage['cache_creation'], SQLITE3_INTEGER );
			$update->execute();
			$update->reset();
			$processed++;
		}

		$remaining = (int) $db->querySingle( 'SELECT COUNT(*) FROM session_files WHERE tokens_output IS NULL' );

		return [
			'processed'  => $processed,
			'remaining'  => $remaining,
			'elapsed_ms' => round( ( microtime( true ) - $start ) * 1000 ),
		];
	}

	/**
	 * Index status, broken out by source.
	 */
	public static function status(): array {
		$db = self::db();

		$total  = (int) $db->querySingle( 'SELECT COUNT(*) FROM session_files' );
		$dbSize = file_exists( self::dbPath() ) ? filesize( self::dbPath() ) : 0;
		$last   = (int) $db->querySingle( 'SELECT MAX(indexed_at) FROM session_files' );

		$bySource = [];
		$res      = $db->query( 'SELECT source, COUNT(*) AS n FROM session_files GROUP BY source' );
		if ( $res ) {
			while ( $row = $res->fetchArray( SQLITE3_ASSOC ) ) {
				$bySource[ $row['source'] ] = (int) $row['n'];
			}
		}

		return [
			'indexed'       => $total,
			'by_source'     => $bySource,
			'db_size_bytes' => $dbSize,
			'last_indexed'  => $last,
		];
	}

	/**
	 * Escape a user query for FTS5 MATCH syntax.
	 */
	private static function escapeFtsQuery( string $query ): string {
		$query = trim( $query );
		if ( $query === '' ) {
			return '';
		}
		$escaped = str_replace( '"', '""', $query );
		return '"' . $escaped . '"';
	}
}
