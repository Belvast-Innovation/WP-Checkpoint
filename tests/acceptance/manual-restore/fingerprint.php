<?php
/**
 * Fingerprints for the manual restore acceptance: the same code measures the original site (build.php) and the copy
 * restored by hand (restore.sh), so the two can be compared line by line.
 *
 * Used as a library (require it) or from the command line:
 *   php fingerprint.php db <host> <port> <user> <password> <database> <table prefix> <tables file>
 *   php fingerprint.php files <directory>
 * Both print JSON on standard output.
 *
 * Plain PHP with mysqli, no WordPress and no plugin code: a restore by hand has neither.
 */

/**
 * Rows of the options table that change on their own while a site runs, or that the export itself writes (this
 * plugin's own state and the transients): they are not the backup's content and are left out of the comparison.
 */
const WPC_MANUAL_VOLATILE_OPTIONS = array( 'wpcheckpoint\\_%', '\\_transient\\_%', '\\_site\\_transient\\_%', 'cron' );

/**
 * A fingerprint of each table: its row count and the SHA-256 of its rows in a fixed order.
 *
 * @param mysqli   $db     Connection.
 * @param string   $prefix Table prefix (for the options table's volatile rows).
 * @param string[] $tables Table names.
 * @return array<string, array{rows: int, sha256: string}>
 */
function wpc_manual_tables( mysqli $db, string $prefix, array $tables ): array {
	$out = array();
	sort( $tables, SORT_STRING );
	foreach ( $tables as $table ) {
		$columns = array();
		$result  = $db->query( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`' );
		if ( false === $result ) {
			throw new RuntimeException( "Cannot read the columns of {$table}: {$db->error}" );
		}
		while ( $row = $result->fetch_assoc() ) {
			$columns[] = '`' . str_replace( '`', '``', (string) $row['Field'] ) . '`';
		}
		$where = '';
		if ( $prefix . 'options' === $table ) {
			$not = array();
			foreach ( WPC_MANUAL_VOLATILE_OPTIONS as $pattern ) {
				$not[] = "`option_name` NOT LIKE '" . $db->real_escape_string( $pattern ) . "'";
			}
			$where = ' WHERE ' . implode( ' AND ', $not );
		}
		// No ORDER BY: the order of text columns depends on the server's collation. Each row is hashed and the row
		// hashes are sorted as bytes, which gives the same answer for the same rows on any server.
		$select = 'SELECT ' . implode( ', ', $columns ) . ' FROM `' . str_replace( '`', '``', $table ) . '`' . $where;
		$result = $db->query( $select, MYSQLI_USE_RESULT );
		if ( false === $result ) {
			throw new RuntimeException( "Cannot read {$table}: {$db->error}" );
		}
		$hashes = array();
		while ( $row = $result->fetch_row() ) {
			$hash = hash_init( 'sha256' );
			foreach ( $row as $value ) {
				hash_update( $hash, null === $value ? "\x00N" : "\x00V" . strlen( (string) $value ) . ':' . $value );
			}
			$hashes[] = hash_final( $hash );
		}
		$result->free();
		sort( $hashes, SORT_STRING );
		$out[ $table ] = array(
			'rows'   => count( $hashes ),
			'sha256' => hash( 'sha256', implode( "\n", $hashes ) ),
		);
	}
	return $out;
}

/**
 * Every regular file below a directory: relative path (with /) => SHA-256.
 *
 * @param string $dir Directory.
 * @return array<string, string>
 */
function wpc_manual_files( string $dir ): array {
	$out  = array();
	$root = rtrim( $dir, '/' );
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() && ! $file->isLink() ) {
			$out[ substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 ) ] = hash_file( 'sha256', $file->getPathname() );
		}
	}
	ksort( $out, SORT_STRING );
	return $out;
}

if ( PHP_SAPI === 'cli' && isset( $argv ) && realpath( $argv[0] ) === __FILE__ ) {
	$mode = $argv[1] ?? '';
	if ( 'db' === $mode && 9 === count( $argv ) ) {
		mysqli_report( MYSQLI_REPORT_OFF );
		$db = @mysqli_connect( $argv[2], $argv[4], $argv[5], $argv[6], (int) $argv[3] );
		if ( ! $db ) {
			fwrite( STDERR, "Cannot connect to the database.\n" );
			exit( 1 );
		}
		$db->set_charset( 'utf8mb4' );
		$tables = json_decode( (string) file_get_contents( $argv[8] ), true );
		echo json_encode( wpc_manual_tables( $db, $argv[7], (array) $tables ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
		exit( 0 );
	}
	if ( 'files' === $mode && 3 === count( $argv ) ) {
		echo json_encode( wpc_manual_files( $argv[2] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
		exit( 0 );
	}
	fwrite( STDERR, "Usage: php fingerprint.php db <host> <port> <user> <password> <database> <prefix> <tables.json> | files <directory>\n" );
	exit( 2 );
}
