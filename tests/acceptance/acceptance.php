<?php
/**
 * Large-site export acceptance in a wp-env development environment, driven
 * the way the admin page drives a job (REST ticks), under 128 MB / 30 s.
 *
 *   php tests/acceptance/acceptance.php setup    --dir=DIR [--cpus=1]
 *   php tests/acceptance/acceptance.php run      --dir=DIR --name=NAME [--kills=standard]
 *   php tests/acceptance/acceptance.php check    --dir=DIR --name=NAME
 *   php tests/acceptance/acceptance.php compare  --dir=DIR --name=NAME --with=NAME
 *   php tests/acceptance/acceptance.php teardown --dir=DIR
 *
 * DIR is a scratch directory outside the repository (state, samples,
 * extracted archives). See README.md in this directory.
 *
 * @package WPCheckpoint
 */

// phpcs:ignoreFile -- development tooling, not part of the plugin.

declare( strict_types=1 );

const ACC_MARK_BEGIN = '# BEGIN wpcheckpoint-acceptance';
const ACC_MARK_END   = '# END wpcheckpoint-acceptance';
const ACC_EXCLUDE    = array( 'wp-content/plugins/wp-checkpoint', 'wp-content/wpcheckpoint-acceptance', 'wp-content/debug.log', 'wp-content/upgrade' );
const ACC_TICK_LIMIT = 20.0;  // Seconds: a tick above this is listed with its step and unit.
const ACC_TICK_MAX   = 25.0;  // Seconds: no tick may take longer.
const ACC_TTFB_LIMIT = 0.050; // Seconds: allowed median degradation during the export.
const ACC_DRIFT      = 0.020; // Seconds: before/after medians further apart than this (or 25 %) mean the environment drifted.

/**
 * The planned kills of --kills=standard, in the order the export reaches them: the windows between a
 * checkpoint and the next irreversible step that the packer, manifest and store steps are built around.
 * The probe (mu-plugin) fires each once, in the tick's own web process, before the matching query runs.
 */
const ACC_KILLS = array(
	array( 'id' => 'database-mid-table', 'event' => 'checkpoint', 'step' => 'DatabaseExportStep', 'contains' => 'acc_events', 'nth' => 5 ),
	array( 'id' => 'pack-between-chunks', 'event' => 'checkpoint', 'step' => 'PackStep', 'contains' => 'video one.mp4', 'nth' => 20 ),
	array( 'id' => 'seal-before-rename', 'event' => 'confirm', 'step' => 'PackStep', 'in' => 'seal_volume', 'nth' => 1 ),
	array( 'id' => 'seal-after-rename', 'event' => 'checkpoint', 'step' => 'PackStep', 'after_confirm_in' => 'seal_volume', 'nth' => 2 ),
	array( 'id' => 'manifest-audit-walk', 'event' => 'checkpoint', 'step' => 'ManifestStep', 'contains' => '"phase":"audit"', 'nth' => 2 ),
	array( 'id' => 'manifest-verify-walk', 'event' => 'checkpoint', 'step' => 'ManifestStep', 'contains' => '"phase":"verify"', 'nth' => 3 ),
	array( 'id' => 'store-between-renames', 'event' => 'confirm', 'step' => 'StoreStep', 'nth' => 2 ),
);

// ---------------------------------------------------------------- helpers

function acc_fail( string $message ): void {
	fwrite( STDERR, 'Error: ' . $message . PHP_EOL );
	exit( 1 );
}

function acc_say( string $message ): void {
	fwrite( STDOUT, '[' . gmdate( 'H:i:s' ) . '] ' . $message . PHP_EOL );
}

/**
 * Run a command; returns stdout. Fails on a non-zero exit unless $allow_fail.
 *
 * @param string[] $argv
 */
function acc_exec( array $argv, bool $allow_fail = false, ?string $stdin_file = null ): string {
	$spec = array( 0 => null !== $stdin_file ? array( 'file', $stdin_file, 'r' ) : array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( $argv, $spec, $pipes );
	if ( ! is_resource( $proc ) ) {
		acc_fail( 'Cannot run ' . $argv[0] );
	}
	if ( null === $stdin_file ) {
		fclose( $pipes[0] );
	}
	$out  = stream_get_contents( $pipes[1] );
	$err  = stream_get_contents( $pipes[2] );
	$code = proc_close( $proc );
	if ( 0 !== $code && ! $allow_fail ) {
		acc_fail( implode( ' ', array_map( 'escapeshellarg', $argv ) ) . " exited with {$code}: " . trim( $err . ' ' . $out ) );
	}
	return (string) $out;
}

function acc_container( string $suffix ): string {
	foreach ( explode( "\n", trim( acc_exec( array( 'docker', 'ps', '--format', '{{.Names}}' ) ) ) ) as $name ) {
		if ( 1 === preg_match( '/^wp-env-wp-checkpoint-[0-9a-f]+-' . preg_quote( $suffix, '/' ) . '-1$/', $name ) ) {
			return $name;
		}
	}
	acc_fail( "No running wp-env container '{$suffix}'. Start it: npx wp-env start" );
	return '';
}

/** @param array<string, mixed> $env */
function acc_wp( array $env, array $args, bool $allow_fail = false ): string {
	return acc_exec( array_merge( array( 'docker', 'exec', '-w', '/var/www/html', $env['cli'], 'wp' ), $args ), $allow_fail );
}

/** @param array<string, mixed> $env */
function acc_eval( array $env, string $php ): string {
	return trim( acc_wp( $env, array( 'eval', $php ) ) );
}

/**
 * One HTTP request. Returns code, body, total and time to first byte (seconds), curl error.
 *
 * @return array{code: int, body: string, seconds: float, ttfb: float, error: string}
 */
function acc_http( string $method, string $url, string $auth = '', float $timeout = 120.0 ): array {
	$h = curl_init( $url );
	curl_setopt_array(
		$h,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT_MS     => (int) ( $timeout * 1000 ),
			CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache' ),
			CURLOPT_FOLLOWLOCATION => false,
		)
	);
	if ( '' !== $auth ) {
		curl_setopt( $h, CURLOPT_USERPWD, $auth );
	}
	if ( 'POST' === $method ) {
		curl_setopt( $h, CURLOPT_POSTFIELDS, '' );
	}
	$body = curl_exec( $h );
	$out  = array(
		'code'    => (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE ),
		'body'    => is_string( $body ) ? $body : '',
		'seconds' => (float) curl_getinfo( $h, CURLINFO_TOTAL_TIME ),
		'ttfb'    => (float) curl_getinfo( $h, CURLINFO_STARTTRANSFER_TIME ),
		'error'   => curl_error( $h ),
	);
	curl_close( $h );
	return $out;
}

/** @param mixed $data */
function acc_append( string $file, $data ): void {
	file_put_contents( $file, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX );
}

/** @return array<int, array<string, mixed>> */
function acc_lines( string $file ): array {
	$out = array();
	if ( ! is_file( $file ) ) {
		return $out;
	}
	foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
		$row = json_decode( $line, true );
		if ( is_array( $row ) ) {
			$out[] = $row;
		}
	}
	return $out;
}

/** @param float[] $values */
function acc_quantile( array $values, float $q ): float {
	if ( array() === $values ) {
		return 0.0;
	}
	sort( $values );
	$i = (int) ceil( $q * count( $values ) ) - 1;
	return (float) $values[ max( 0, min( count( $values ) - 1, $i ) ) ];
}

function acc_dir_bytes( string $dir ): int {
	if ( ! is_dir( $dir ) ) {
		return 0;
	}
	$sum = 0;
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() ) {
			$sum += $file->getSize();
		}
	}
	return $sum;
}

/** @return array<string, mixed> */
function acc_state( string $dir ): array {
	$file = $dir . '/env.json';
	if ( ! is_file( $file ) ) {
		acc_fail( "No {$file}: run setup first." );
	}
	return json_decode( (string) file_get_contents( $file ), true );
}

/** Host path of a path inside the web container. */
function acc_host( array $env, string $path ): string {
	return 0 === strpos( $path, '/var/www/html' ) ? $env['wp_root'] . substr( $path, strlen( '/var/www/html' ) ) : $path;
}

function acc_ttfb_samples( array $env, string $file, int $count, float $every ): void {
	for ( $i = 0; $i < $count; $i++ ) {
		$r = acc_http( 'GET', $env['site'] . '/?acc=' . mt_rand(), '', 30.0 );
		acc_append( $file, array( 'at' => microtime( true ), 'ttfb' => $r['ttfb'], 'seconds' => $r['seconds'], 'code' => $r['code'] ) );
		usleep( (int) ( $every * 1000000 ) );
	}
}

// ---------------------------------------------------------------- commands

function acc_setup( string $dir, int $cpus ): void {
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
		acc_fail( "Cannot create {$dir}" );
	}
	$env = array(
		'web'   => acc_container( 'wordpress' ),
		'cli'   => acc_container( 'cli' ),
		'mysql' => acc_container( 'mysql' ),
	);
	$root = '';
	foreach ( json_decode( acc_exec( array( 'docker', 'inspect', $env['web'], '--format', '{{json .Mounts}}' ) ), true ) as $mount ) {
		if ( '/var/www/html' === $mount['Destination'] ) {
			$root = $mount['Source'];
		}
	}
	if ( '' === $root || ! is_dir( $root . '/wp-content' ) ) {
		acc_fail( 'Cannot find the WordPress directory on the host.' );
	}
	$env['wp_root']  = $root;
	$env['site']     = trim( acc_wp( $env, array( 'option', 'get', 'home' ) ) );
	$env['db']       = trim( acc_wp( $env, array( 'config', 'get', 'DB_NAME' ) ) );
	$env['db_root']  = trim( acc_exec( array( 'docker', 'exec', $env['mysql'], 'printenv', 'MYSQL_ROOT_PASSWORD' ) ) );
	$env['storage']  = acc_host( $env, acc_eval( $env, 'echo \WPCheckpoint\Plugin::instance()->directories()->base();' ) );
	$user            = trim( acc_wp( $env, array( 'user', 'list', '--role=administrator', '--field=user_login', '--number=1' ) ) );
	$env['auth']     = $user . ':' . trim( acc_wp( $env, array( 'user', 'application-password', 'create', $user, 'wpcheckpoint-acceptance-' . gmdate( 'YmdHis' ), '--porcelain' ) ) );

	// The probe (mu-plugin) and the limits of a shared host, for web requests only.
	@mkdir( $root . '/wp-content/mu-plugins', 0755, true );
	copy( __DIR__ . '/mu-plugin/wpcheckpoint-acceptance-probe.php', $root . '/wp-content/mu-plugins/wpcheckpoint-acceptance-probe.php' );
	$htaccess = is_file( $root . '/.htaccess' ) ? (string) file_get_contents( $root . '/.htaccess' ) : '';
	$htaccess = (string) preg_replace( '/\n?' . preg_quote( ACC_MARK_BEGIN, '/' ) . '.*?' . preg_quote( ACC_MARK_END, '/' ) . '\n?/s', "\n", $htaccess );
	file_put_contents( $root . '/.htaccess', ACC_MARK_BEGIN . "\nphp_value memory_limit 128M\nphp_value max_execution_time 30\n" . ACC_MARK_END . "\n" . ltrim( $htaccess ) );
	acc_exec( array( 'docker', 'update', '--cpus', (string) $cpus, $env['web'] ) );
	$env['cpus'] = $cpus;
	file_put_contents( $dir . '/env.json', json_encode( $env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	chmod( $dir . '/env.json', 0600 );

	// Confirm the limits apply to a web request.
	@unlink( $root . '/wp-content/wpcheckpoint-acceptance/requests.jsonl' );
	acc_http( 'POST', $env['site'] . '/wp-json/wp-checkpoint/v1/jobs/999999/tick', $env['auth'] );
	$seen = acc_lines( $root . '/wp-content/wpcheckpoint-acceptance/requests.jsonl' );
	$last = end( $seen );
	if ( ! is_array( $last ) || '128M' !== $last['limit'] || 30 !== $last['max_seconds'] ) {
		acc_fail( 'The probe did not see memory_limit=128M and max_execution_time=30 on a web request: ' . json_encode( $last ) );
	}
	acc_say( "Set up: {$env['site']}, web container limited to {$cpus} CPU, web requests at 128M / 30 s, probe installed." );
}

function acc_teardown( string $dir ): void {
	$env      = acc_state( $dir );
	$htaccess = (string) file_get_contents( $env['wp_root'] . '/.htaccess' );
	file_put_contents( $env['wp_root'] . '/.htaccess', ltrim( (string) preg_replace( '/' . preg_quote( ACC_MARK_BEGIN, '/' ) . '.*?' . preg_quote( ACC_MARK_END, '/' ) . '\n?/s', '', $htaccess ) ) );
	@unlink( $env['wp_root'] . '/wp-content/mu-plugins/wpcheckpoint-acceptance-probe.php' );
	acc_exec( array( 'docker', 'update', '--cpus', '0', $env['web'] ) );
	list( $user ) = explode( ':', $env['auth'] );
	foreach ( json_decode( acc_wp( $env, array( 'user', 'application-password', 'list', $user, '--format=json' ) ), true ) as $pw ) {
		if ( 0 === strpos( $pw['name'], 'wpcheckpoint-acceptance-' ) ) {
			acc_wp( $env, array( 'user', 'application-password', 'delete', $user, $pw['uuid'] ) );
		}
	}
	acc_say( 'Removed the probe, the limits and the application passwords. Generated data stays (wp-content/uploads/acceptance, {prefix}acc_* tables, acc_page posts).' );
}

/**
 * Background helpers of a run: TTFB during the export and disk use of the
 * work directory and backups.
 */
function acc_helper( string $kind, string $out, int $job ): void {
	$env  = acc_state( dirname( $out ) );
	$stop = $out . '/stop';
	while ( ! is_file( $stop ) ) {
		if ( 'ttfb' === $kind ) {
			acc_ttfb_samples( $env, $out . '/ttfb-during.jsonl', 1, 2.0 );
			continue;
		}
		if ( 'disk' === $kind ) {
			acc_append( $out . '/disk.jsonl', array( 'at' => microtime( true ), 'work' => acc_dir_bytes( $env['storage'] . '/tmp/job-' . $job ), 'backups' => acc_dir_bytes( $env['storage'] . '/backups' ) ) );
			sleep( 3 );
			continue;
		}
		return;
	}
}

function acc_run( string $dir, string $name, string $kills ): void {
	$env = acc_state( $dir );
	$out = $dir . '/' . $name;
	if ( is_dir( $out ) ) {
		acc_fail( "{$out} exists; choose another name." );
	}
	mkdir( $out, 0700 );
	$probe = $env['wp_root'] . '/wp-content/wpcheckpoint-acceptance';
	if ( is_file( $probe . '/requests.jsonl' ) ) {
		rename( $probe . '/requests.jsonl', $probe . '/requests-' . gmdate( 'YmdHis' ) . '.jsonl' );
	}
	$running = acc_eval( $env, 'echo count( \WPCheckpoint\Plugin::instance()->jobs()->list_jobs( array( "queued", "running", "paused" ), 10 ) );' );
	if ( '0' !== $running ) {
		acc_fail( 'Another job is queued, running or paused; finish or cancel it first.' );
	}

	acc_say( 'TTFB before the export (60 samples).' );
	acc_ttfb_samples( $env, $out . '/ttfb-before.jsonl', 60, 1.0 );
	acc_say( 'Idle tick baseline (10 requests for a job that does not exist).' );
	$t0 = microtime( true );
	for ( $i = 0; $i < 10; $i++ ) {
		acc_http( 'POST', $env['site'] . '/wp-json/wp-checkpoint/v1/jobs/999999/tick', $env['auth'] );
	}
	$baseline_window = array( $t0, microtime( true ) );

	foreach ( array( 'kills.json', 'kills-state.json', 'kills.jsonl' ) as $file ) {
		@unlink( $probe . '/' . $file );
	}
	if ( 'standard' === $kills ) {
		file_put_contents( $probe . '/kills.json', json_encode( ACC_KILLS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		acc_say( count( ACC_KILLS ) . ' planned kills installed.' );
	} elseif ( '' !== $kills ) {
		acc_fail( 'Unknown kill plan: ' . $kills );
	}
	$before  = glob( $env['storage'] . '/backups/*.manifest.json' ) ?: array();
	$options = var_export( array( 'exclusions' => ACC_EXCLUDE ), true );
	$job     = (int) acc_eval( $env, '$o = \WPCheckpoint\Jobs\ExportOptions::normalize( array_merge( ' . $options . ', array( "policy" => \WPCheckpoint\Jobs\ExportOptions::UNATTENDED ) ) ); echo \WPCheckpoint\Plugin::instance()->jobs()->create( "export", 1, array(), $o )->id;' );
	acc_say( "Job {$job} created; ticking." );

	$helpers = array();
	foreach ( array( 'ttfb', 'disk' ) as $kind ) {
		$helpers[ $kind ] = proc_open( array( PHP_BINARY, __FILE__, 'helper', '--kind=' . $kind, '--out=' . $out, '--job=' . $job ), array( 1 => array( 'file', $out . '/helper-' . $kind . '.log', 'a' ), 2 => array( 'file', $out . '/helper-' . $kind . '.log', 'a' ) ), $pipes );
	}
	$started = microtime( true );
	$result  = '';
	$last    = -1;
	while ( true ) {
		$r    = acc_http( 'POST', $env['site'] . '/wp-json/wp-checkpoint/v1/jobs/' . $job . '/tick', $env['auth'] );
		$body = json_decode( $r['body'], true );
		$row  = array( 'at' => microtime( true ), 'seconds' => $r['seconds'], 'code' => $r['code'], 'error' => $r['error'] );
		if ( is_array( $body ) && isset( $body['result'] ) ) {
			$row += array( 'result' => $body['result'], 'retry_after' => $body['retry_after'], 'progress' => $body['job']['progress'] ?? null, 'step' => $body['job']['step'] ?? null, 'status' => $body['job']['status'] ?? null );
		}
		acc_append( $out . '/ticks.jsonl', $row );
		$result = (string) ( $row['result'] ?? '' );
		if ( isset( $row['progress'] ) && (int) $row['progress'] !== $last ) {
			$last = (int) $row['progress'];
			acc_say( sprintf( '%3d%%  %s  (%s, %.1f s)', $last, (string) $row['step'], $result, $r['seconds'] ) );
		}
		if ( '' !== $r['error'] || 200 !== $r['code'] ) {
			acc_say( sprintf( 'Tick request ended without an answer (HTTP %d, %s).', $r['code'], $r['error'] ) );
			sleep( 1 );
			continue;
		}
		if ( in_array( $result, array( 'completed', 'failed', 'finished', 'lost', 'paused' ), true ) ) {
			break;
		}
		if ( in_array( $result, array( 'waiting', 'blocked', 'busy' ), true ) ) {
			sleep( max( 1, (int) $row['retry_after'] ) );
		}
	}
	$finished = microtime( true );
	touch( $out . '/stop' );
	foreach ( $helpers as $proc ) {
		proc_close( $proc );
	}
	acc_say( "Job ended: {$result}. TTFB after the export (60 samples)." );
	acc_ttfb_samples( $env, $out . '/ttfb-after.jsonl', 60, 1.0 );

	copy( $probe . '/requests.jsonl', $out . '/requests.jsonl' );
	if ( is_file( $probe . '/kills.jsonl' ) ) {
		copy( $probe . '/kills.jsonl', $out . '/kills.jsonl' );
	}
	@unlink( $probe . '/kills.json' );
	$row    = json_decode( acc_eval( $env, '$j = \WPCheckpoint\Plugin::instance()->jobs()->find( ' . $job . ' ); echo wp_json_encode( array( "status" => $j->status, "last_error" => $j->last_error, "attempts" => $j->attempts, "takeovers" => $j->takeovers, "log_path" => $j->log_path, "storage_path" => $j->storage_path ) );' ), true );
	$log    = acc_host( $env, $row['storage_path'] ) . '/' . $row['log_path'];
	if ( is_file( $log ) ) {
		copy( $log, $out . '/job.log' );
	}
	$new  = array_values( array_diff( glob( $env['storage'] . '/backups/*.manifest.json' ) ?: array(), $before ) );
	$base = 1 === count( $new ) ? basename( $new[0], '.manifest.json' ) : '';
	file_put_contents(
		$out . '/run.json',
		json_encode(
			array(
				'job'             => $job,
				'base'            => $base,
				'kills'           => $kills,
				'planned_kills'   => 'standard' === $kills ? array_column( ACC_KILLS, 'id' ) : array(),
				'started'         => $started,
				'finished'        => $finished,
				'baseline_window' => $baseline_window,
				'job_row'         => $row,
				'exclusions'      => ACC_EXCLUDE,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		)
	);
	acc_say( sprintf( 'Run %s: %s in %.0f s, backup %s.', $name, $row['status'], $finished - $started, '' === $base ? '(none)' : $base ) );
}

/**
 * Every regular file the backup should contain: wp-content minus the
 * built-in and the run's exclusions, walked inside the web container (its
 * bind mounts are only complete there) independently of the plugin, with
 * size and sha256.
 *
 * @return array<string, array{bytes: int, sha256: string}> archive path => facts
 */
function acc_source_files( array $env, array $run ): array {
	$defaults = array( 'wp-content/cache', 'wp-content/wp-checkpoint-*', 'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/wpvividbackups', 'wp-content/backups-dup-lite', 'wp-content/backups-dup-pro', 'wp-content/uploads/backwpup-*', 'wp-content/uploads/backupbuddy_backups', 'wp-content/uploads/backupbuddy_temp', 'wp-content/uploads/wp-migrate-db', 'wp-content/uploads/snapshots', 'wp-content/uploads/wp-staging' );
	$globs    = array_merge( $defaults, $run['exclusions'] );
	$prune    = array();
	foreach ( $globs as $g ) {
		$prune[] = '-path ' . escapeshellarg( $g );
	}
	$list  = acc_exec( array( 'docker', 'exec', '-w', '/var/www/html', $env['web'], 'sh', '-c', 'find wp-content \\( ' . implode( ' -o ', $prune ) . ' \\) -prune -o -type f -print0 | xargs -0 -r sha256sum -z -- ; true' ) );
	$files = array();
	foreach ( explode( "\0", $list ) as $line ) {
		if ( 1 !== preg_match( '/^([0-9a-f]{64})  (.+)$/s', $line, $m ) ) {
			continue;
		}
		$files[ $m[2] ] = array( 'sha256' => $m[1] );
	}
	$sizes = acc_exec( array( 'docker', 'exec', '-w', '/var/www/html', $env['web'], 'sh', '-c', 'find wp-content \\( ' . implode( ' -o ', $prune ) . ' \\) -prune -o -type f -printf "%s %p\\0"' ) );
	foreach ( explode( "\0", $sizes ) as $line ) {
		if ( 1 === preg_match( '/^(\d+) (.+)$/s', $line, $m ) && isset( $files[ $m[2] ] ) ) {
			$files[ $m[2] ]['bytes'] = (int) $m[1];
		}
	}
	ksort( $files, SORT_STRING );
	return $files;
}

/** Ordered content hash and row count of every table in a database. */
function acc_table_hashes( array $env, string $db, array $tables ): array {
	$m   = new mysqli( '127.0.0.1', 'root', $env['db_root'], $db, (int) $env['db_port'] );
	$out = array();
	$m->set_charset( 'utf8mb4' );
	foreach ( $tables as $t ) {
		$keys = array();
		$res  = $m->query( "SHOW KEYS FROM `{$t}` WHERE Key_name = 'PRIMARY'" );
		while ( $res && ( $k = $res->fetch_assoc() ) ) {
			$keys[ (int) $k['Seq_in_index'] ] = '`' . $k['Column_name'] . '`';
		}
		ksort( $keys );
		if ( array() === $keys ) {
			$res = $m->query( "SHOW COLUMNS FROM `{$t}`" );
			while ( $res && ( $c = $res->fetch_assoc() ) ) {
				$keys[] = '`' . $c['Field'] . '`';
			}
		}
		$ctx  = hash_init( 'sha256' );
		$rows = 0;
		$res  = $m->query( "SELECT * FROM `{$t}` ORDER BY " . implode( ', ', $keys ), MYSQLI_USE_RESULT );
		if ( false === $res ) {
			$out[ $t ] = array( 'rows' => -1, 'sha256' => 'missing' );
			continue;
		}
		while ( $r = $res->fetch_row() ) {
			hash_update( $ctx, json_encode( array_map( static function ( $v ) { return null === $v ? null : bin2hex( (string) $v ); }, $r ) ) . "\n" );
			++$rows;
		}
		$res->free();
		$out[ $t ] = array( 'rows' => $rows, 'sha256' => hash_final( $ctx ) );
	}
	$m->close();
	return $out;
}

function acc_check( string $dir, string $name ): void {
	$env = acc_state( $dir );
	$out = $dir . '/' . $name;
	$run = json_decode( (string) file_get_contents( $out . '/run.json' ), true );
	if ( '' === $run['base'] ) {
		acc_fail( 'The run left no backup.' );
	}
	$env['db_port'] = (int) trim( explode( ':', trim( acc_exec( array( 'docker', 'port', $env['mysql'], '3306/tcp' ) ) ) )[1] ?? '0' );
	$report         = array( 'name' => $name, 'base_ok' => true );
	$backups        = $env['storage'] . '/backups';
	$manifest_path  = $backups . '/' . $run['base'] . '.manifest.json';
	$manifest       = json_decode( (string) file_get_contents( $manifest_path ), true );
	$volumes        = array_map( static function ( $v ) { return $v['path']; }, $manifest['volumes'] );
	$archive_bytes  = array_sum( array_map( static function ( $v ) { return (int) $v['bytes']; }, $manifest['volumes'] ) );

	// (i) Completed; the log has no zero progress, no takeover, no concurrent writer (a killed run expects exactly its takeover).
	$log              = is_file( $out . '/job.log' ) ? (string) file_get_contents( $out . '/job.log' ) : '';
	$report['i']      = array(
		'status'            => $run['job_row']['status'],
		'no_progress'       => substr_count( $log, 'Step made no progress' ),
		'takeovers_logged'  => substr_count( $log, 'The previous run ended without finishing' ),
		'takeovers_counted' => (int) $run['job_row']['takeovers'],
		'concurrent_writer' => substr_count( $log, 'Another process wrote the same work directory' ),
		'budget'            => preg_match( '/"budget_seconds":(\d+),"budget_mb":(\d+)/', $log, $bm ) ? array( 'seconds' => (int) $bm[1], 'mb' => (int) $bm[2] ) : null,
	);
	$kills              = acc_lines( $out . '/kills.jsonl' );
	$expected_takeovers = count( $kills );
	$report['i']['kills_fired']   = array_column( $kills, 'rule' );
	$report['i']['kills_missed']  = array_values( array_diff( $run['planned_kills'], array_column( $kills, 'rule' ) ) );
	$report['i']['pass'] = array() === $report['i']['kills_missed'] && 'completed' === $run['job_row']['status'] && 0 === $report['i']['no_progress'] && $expected_takeovers === $report['i']['takeovers_logged'] && 0 === $report['i']['concurrent_writer'];

	// (ii) and (iii) from the probe: every web tick of this job.
	$requests = acc_lines( $out . '/requests.jsonl' );
	$ticks    = array_values( array_filter( $requests, static function ( $r ) use ( $run ) { return in_array( $r['kind'], array( 'tick', 'loopback', 'cron' ), true ) && $r['start'] >= $run['started'] - 1 && $r['start'] <= $run['finished'] + 1; } ) );
	$base     = array_values( array_filter( $requests, static function ( $r ) use ( $run ) { return 'tick' === $r['kind'] && $r['start'] >= $run['baseline_window'][0] - 1 && $r['start'] <= $run['baseline_window'][1] + 1; } ) );
	$seconds  = array_map( static function ( $r ) { return (float) $r['seconds']; }, $ticks );
	$over     = array();
	foreach ( $ticks as $t ) {
		if ( $t['seconds'] > ACC_TICK_LIMIT ) {
			$over[] = array( 'seconds' => $t['seconds'], 'before' => $t['before'] ?? null, 'after' => $t['after'] ?? null );
		}
	}
	$report['ii'] = array(
		'ticks'   => count( $ticks ),
		'killed'  => count( $kills ) . ' killed ticks have no probe line (the process died before its shutdown function)',
		'p50'     => acc_quantile( $seconds, 0.50 ),
		'p99'     => acc_quantile( $seconds, 0.99 ),
		'max'     => array() === $seconds ? 0.0 : max( $seconds ),
		'over_20' => $over,
	);
	$report['ii']['pass'] = $report['ii']['p99'] <= ACC_TICK_LIMIT && $report['ii']['max'] <= ACC_TICK_MAX;
	$peaks                = array_map( static function ( $r ) { return (int) $r['peak_real']; }, $ticks );
	$base_peak            = array() === $base ? 0 : max( array_map( static function ( $r ) { return (int) $r['peak_real']; }, $base ) );
	$report['iii']        = array(
		'max_peak_mb'      => round( ( array() === $peaks ? 0 : max( $peaks ) ) / 1048576, 1 ),
		'baseline_peak_mb' => round( $base_peak / 1048576, 1 ),
		'limit'            => array_values( array_unique( array_map( static function ( $r ) { return $r['limit'] . ' / ' . $r['max_seconds'] . ' s'; }, $ticks ) ) ),
		'fatal'            => array_values( array_filter( array_map( static function ( $r ) { return $r['fatal'] ?? null; }, $ticks ) ) ),
	);
	$report['iii']['over_baseline_mb'] = round( $report['iii']['max_peak_mb'] - $report['iii']['baseline_peak_mb'], 1 );
	$report['iii']['pass']             = $report['iii']['max_peak_mb'] <= 128 && $report['iii']['over_baseline_mb'] <= 32 + 8 && array() === $report['iii']['fatal'];

	// (iv) TTFB.
	$med = static function ( string $file ): float {
		return acc_quantile( array_map( static function ( $r ) { return (float) $r['ttfb']; }, acc_lines( $file ) ), 0.5 );
	};
	$before       = $med( $out . '/ttfb-before.jsonl' );
	$during       = $med( $out . '/ttfb-during.jsonl' );
	$after        = $med( $out . '/ttfb-after.jsonl' );
	$drift        = abs( $after - $before ) > max( ACC_DRIFT, 0.25 * $before );
	$report['iv'] = array(
		'before_ms'      => round( $before * 1000, 1 ),
		'during_ms'      => round( $during * 1000, 1 ),
		'after_ms'       => round( $after * 1000, 1 ),
		'during_samples' => count( acc_lines( $out . '/ttfb-during.jsonl' ) ),
		'drift'          => $drift,
		'pass'           => ! $drift && ( $during - $before ) < ACC_TTFB_LIMIT,
	);

	// (v) Full verification by the plugin.
	$verify       = acc_wp( $env, array( 'wpcheckpoint', 'verify', '/var/www/html' . substr( $manifest_path, strlen( $env['wp_root'] ) ), '--depth=full', '--format=json' ), true );
	$verdict      = json_decode( trim( (string) substr( $verify, (int) strpos( $verify, '{' ) ) ), true );
	$report['v']  = array( 'outcome' => $verdict['outcome'] ?? trim( $verify ), 'depth' => $verdict['depth'] ?? null, 'complete' => $verdict['complete'] ?? null, 'counts' => $verdict['counts'] ?? null, 'pass' => 'passed' === ( $verdict['outcome'] ?? '' ) && 'full' === ( $verdict['depth'] ?? '' ) && true === ( $verdict['complete'] ?? false ) );

	// (vi) unzip -t on every volume.
	$report['vi'] = array( 'volumes' => count( $volumes ), 'pass' => true );
	foreach ( $volumes as $v ) {
		$t = acc_exec( array( 'unzip', '-tqq', $backups . '/' . $v ), true );
		if ( '' !== trim( $t ) && false === strpos( $t, 'No errors detected' ) ) {
			$report['vi']['pass']     = false;
			$report['vi']['errors'][] = trim( $t );
		}
	}

	// (vii) Extract, compare files by sha256, load the database chunks into a scratch database and compare tables.
	$x = $out . '/extract';
	acc_exec( array( 'rm', '-rf', $x ) );
	mkdir( $x, 0700 );
	foreach ( $volumes as $v ) {
		acc_exec( array( 'unzip', '-qq', '-o', $backups . '/' . $v, '-d', $x ) );
	}
	$source    = acc_source_files( $env, $run );
	$mismatch  = array();
	$extracted = 0;
	foreach ( $source as $p => $facts ) {
		$a = $x . '/files/' . $p;
		if ( ! is_file( $a ) ) {
			$mismatch[] = 'missing: ' . $p;
			continue;
		}
		++$extracted;
		if ( filesize( $a ) !== ( $facts['bytes'] ?? -1 ) || hash_file( 'sha256', $a ) !== $facts['sha256'] ) {
			$mismatch[] = 'differs: ' . $p;
		}
	}
	$in_archive = array();
	$extracted_hashes = array();
	$it         = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $x . '/files', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() ) {
			$rel                      = substr( $f->getPathname(), strlen( $x . '/files/' ) );
			$in_archive[]             = $rel;
			$extracted_hashes[ $rel ] = hash_file( 'sha256', $f->getPathname() );
		}
	}
	ksort( $extracted_hashes, SORT_STRING );
	file_put_contents( $out . '/extracted.sha256.json', json_encode( $extracted_hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	copy( $manifest_path, $out . '/manifest.json' );
	foreach ( array_diff( $in_archive, array_keys( $source ) ) as $extra ) {
		$mismatch[] = 'not in the source: ' . $extra;
	}
	$scratch = 'wpcacc_restore';
	$mysql   = array( 'docker', 'exec', '-i', $env['mysql'], 'mariadb', '-uroot', '-p' . $env['db_root'] );
	acc_exec( array_merge( $mysql, array( '-e', "DROP DATABASE IF EXISTS `{$scratch}`; CREATE DATABASE `{$scratch}` DEFAULT CHARACTER SET utf8mb4" ) ) );
	$chunks = 0;
	foreach ( file( $x . '/database.index.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
		$chunk = json_decode( $line, true );
		acc_exec( array_merge( $mysql, array( $scratch ) ), false, $x . '/' . $chunk['p'] );
		++$chunks;
	}
	$tables = array_map( static function ( $t ) { return $t['name']; }, $manifest['database']['tables'] );
	$src    = acc_table_hashes( $env, $env['db'], $tables );
	$dst    = acc_table_hashes( $env, $scratch, $tables );
	$tdiff  = array();
	foreach ( $tables as $t ) {
		if ( $src[ $t ] !== $dst[ $t ] ) {
			$tdiff[ $t ] = array( 'source' => $src[ $t ], 'restored' => $dst[ $t ] );
		}
	}
	$volatile      = array( $GLOBALS['acc_prefix'] . 'options', $GLOBALS['acc_prefix'] . 'usermeta' );
	$report['vii'] = array(
		'files_compared'  => $extracted,
		'file_mismatches' => $mismatch,
		'chunks_loaded'   => $chunks,
		'tables'          => count( $tables ),
		'tables_differ'   => $tdiff,
		'volatile'        => $volatile,
		'restored'        => $dst,
		'pass'            => array() === $mismatch && array() === array_diff( array_keys( $tdiff ), $volatile ),
	);

	// (viii) Manifest totals against the independent walk.
	$report['viii'] = array(
		'manifest' => array( 'count' => (int) $manifest['files']['count'], 'bytes' => (int) $manifest['files']['bytes'] ),
		'source'   => array( 'count' => count( $source ), 'bytes' => array_sum( array_map( static function ( $f ) { return (int) ( $f['bytes'] ?? 0 ); }, $source ) ) ),
	);
	$report['viii']['pass'] = $report['viii']['manifest'] === $report['viii']['source'];

	// (ix) Work directory: peak against the archive, reclaimed by the next maintenance.
	$disk = acc_lines( $out . '/disk.jsonl' );
	$peak = array() === $disk ? 0 : max( array_map( static function ( $r ) { return (int) $r['work']; }, $disk ) );
	acc_eval( $env, 'delete_site_transient( "wpcheckpoint_jobs_reaped" ); \WPCheckpoint\Plugin::instance()->jobs()->maintenance();' );
	clearstatcache();
	$report['ix'] = array(
		'work_peak_mb'    => round( $peak / 1048576, 1 ),
		'archive_mb'      => round( $archive_bytes / 1048576, 1 ),
		'reclaimed_after' => ! is_dir( $env['storage'] . '/tmp/job-' . $run['job'] ),
		'pass'            => $peak <= $archive_bytes * 1.05 + 64 * 1048576 && ! is_dir( $env['storage'] . '/tmp/job-' . $run['job'] ),
	);

	// After each kill: the tick that took the job over (the first one to find the dead run's lease expired).
	$log_lines        = explode( "\n", $log );
	$report['kills'] = array();
	foreach ( $kills as $n => $kill ) {
		$until    = isset( $kills[ $n + 1 ] ) ? (float) $kills[ $n + 1 ]['at'] : $run['finished'] + 1;
		$resumed  = null;
		foreach ( $ticks as $t ) {
			if ( $t['start'] > $kill['at'] && ! empty( $t['before']['lease_expired'] ) ) {
				$resumed = $t;
				break;
			}
		}
		$concurrent = 0;
		foreach ( $log_lines as $line ) {
			if ( 1 === preg_match( '/^\[([0-9T:-]+)Z\]/', $line, $lm ) ) {
				$at = strtotime( $lm[1] . 'Z' );
				if ( $at >= floor( $kill['at'] ) && $at <= $until && false !== strpos( $line, 'Another process wrote the same work directory' ) ) {
					++$concurrent;
				}
			}
		}
		$report['kills'][] = array(
			'rule'              => $kill['rule'],
			'event'             => $kill['event'],
			'cursor_at_kill'    => json_decode( (string) $kill['cursor'], true ),
			'stack'             => array_slice( $kill['stack'], 0, 8 ),
			'resumed_after_s'   => null === $resumed ? null : round( $resumed['start'] - $kill['at'], 1 ),
			'resumed_tick_s'    => null === $resumed ? null : $resumed['seconds'],
			'resumed_peak_mb'   => null === $resumed ? null : round( $resumed['peak_real'] / 1048576, 1 ),
			'takeovers_before'  => null === $resumed ? null : $resumed['before']['takeovers'],
			'takeovers_after'   => null === $resumed ? null : ( $resumed['after']['takeovers'] ?? null ),
			'takeover_mark'     => null === $resumed ? null : ( $resumed['after']['takeover_mark'] ?? null ),
			'step_before_after' => null === $resumed ? null : array( $resumed['before']['step'], $resumed['after']['step'] ?? null ),
			'concurrent_writer' => $concurrent,
		);
	}

	// Raw distributions for the record: every tick, every peak, every TTFB sample.
	$ttfb_raw = static function ( string $file ): array {
		return array_map( static function ( $r ) { return round( (float) $r['ttfb'] * 1000, 2 ); }, acc_lines( $file ) );
	};
	file_put_contents(
		$out . '/distributions.json',
		json_encode(
			array(
				'tick_seconds'  => array_map( static function ( $t ) { return $t['seconds']; }, $ticks ),
				'tick_peak_mb'  => array_map( static function ( $t ) { return round( $t['peak_real'] / 1048576, 1 ); }, $ticks ),
				'tick_steps'    => array_map( static function ( $t ) { return ( $t['before']['step'] ?? '' ) . '>' . ( $t['after']['step'] ?? '' ); }, $ticks ),
				'baseline_peak_mb' => array_map( static function ( $t ) { return round( $t['peak_real'] / 1048576, 1 ); }, $base ),
				'ttfb_ms'       => array( 'before' => $ttfb_raw( $out . '/ttfb-before.jsonl' ), 'during' => $ttfb_raw( $out . '/ttfb-during.jsonl' ), 'after' => $ttfb_raw( $out . '/ttfb-after.jsonl' ) ),
				'work_dir_mb'   => array_map( static function ( $r ) { return round( $r['work'] / 1048576, 1 ); }, $disk ),
			),
			JSON_UNESCAPED_SLASHES
		)
	);

	$report['archive'] = array( 'volumes' => count( $volumes ), 'bytes' => $archive_bytes, 'tables' => count( $tables ), 'files' => (int) $manifest['files']['count'], 'seconds' => round( $run['finished'] - $run['started'] ) );
	file_put_contents( $out . '/check.json', json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	foreach ( array( 'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix' ) as $k ) {
		acc_say( sprintf( '(%s) %s  %s', $k, $report[ $k ]['pass'] ? 'PASS' : 'FAIL', json_encode( array_diff_key( $report[ $k ], array( 'restored' => 1, 'pass' => 1 ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}
}

/**
 * The fields of the standalone manifest that may differ between two runs of
 * the same data, each with its reason. Everything else must be equal,
 * including fields added later: an allow list, not a deny list.
 */
const ACC_MAY_DIFFER = array(
	'#^/created_at$#'                                   => 'clock',
	'#^/database/exported/(started_at|finished_at)$#'   => 'clock',
	'#^/database/index/(bytes|sha256)$#'                => 'database chunk boundaries',
	'#^/database/tables/[^/]+/(bytes|chunks|sha256)$#'  => 'database chunk boundaries',
	'#^/volumes(/.*)?$#'                                => 'base name, and the volume layout follows the chunk sizes',
	'#^/database/tables/(wp_options|wp_usermeta)/rows$#' => 'written while the export runs',
);

/**
 * A manifest as path => scalar; tables keyed by name, so an order change is a difference of every path.
 *
 * @return array<string, mixed>
 */
function acc_flatten( $value, string $path = '' ): array {
	if ( ! is_array( $value ) ) {
		return array( $path => $value );
	}
	if ( array() === $value ) {
		return array( $path => array() );
	}
	$out = array();
	foreach ( $value as $key => $item ) {
		$name = '/database/tables' === $path && is_array( $item ) && isset( $item['name'] ) ? $item['name'] . '#' . $key : $key;
		$out  = array_merge( $out, acc_flatten( $item, $path . '/' . $name ) );
	}
	return $out;
}

/**
 * Two runs of the same data, compared by what they restore, not byte for
 * byte: the extracted files by sha256 (independent of the plugin's reader),
 * the files index, every non-volatile table after loading the chunks, and
 * the standalone manifest outside ACC_MAY_DIFFER.
 */
function acc_compare( string $dir, string $a, string $b ): void {
	$diff = array();
	$ha   = json_decode( (string) file_get_contents( $dir . '/' . $a . '/extracted.sha256.json' ), true );
	$hb   = json_decode( (string) file_get_contents( $dir . '/' . $b . '/extracted.sha256.json' ), true );
	foreach ( array_unique( array_merge( array_keys( $ha ), array_keys( $hb ) ) ) as $p ) {
		if ( ( $ha[ $p ] ?? null ) !== ( $hb[ $p ] ?? null ) ) {
			$diff[] = 'extracted file differs: ' . $p;
		}
	}
	if ( file_get_contents( $dir . '/' . $a . '/extract/files.index.jsonl' ) !== file_get_contents( $dir . '/' . $b . '/extract/files.index.jsonl' ) ) {
		$diff[] = 'files.index.jsonl differs';
	}
	$ca = json_decode( (string) file_get_contents( $dir . '/' . $a . '/check.json' ), true );
	$cb = json_decode( (string) file_get_contents( $dir . '/' . $b . '/check.json' ), true );
	foreach ( $ca['vii']['restored'] as $t => $h ) {
		if ( ! in_array( $t, $ca['vii']['volatile'], true ) && ( $cb['vii']['restored'][ $t ] ?? null ) !== $h ) {
			$diff[] = 'table differs after restore: ' . $t;
		}
	}
	$ma      = acc_flatten( json_decode( (string) file_get_contents( $dir . '/' . $a . '/manifest.json' ), true ) );
	$mb      = acc_flatten( json_decode( (string) file_get_contents( $dir . '/' . $b . '/manifest.json' ), true ) );
	$allowed = array();
	foreach ( array_unique( array_merge( array_keys( $ma ), array_keys( $mb ) ) ) as $path ) {
		if ( array_key_exists( $path, $ma ) && array_key_exists( $path, $mb ) && $ma[ $path ] === $mb[ $path ] ) {
			continue;
		}
		$plain = (string) preg_replace( '~^/database/tables/([^/#]+)#\d+~', '/database/tables/$1', $path );
		foreach ( ACC_MAY_DIFFER as $pattern => $reason ) {
			if ( 1 === preg_match( $pattern, $plain ) ) {
				$allowed[ $reason ][] = $plain;
				continue 2;
			}
		}
		$diff[] = 'manifest field differs: ' . $plain;
	}
	$report = array( 'identical_files' => count( $ha ), 'allowed_differences' => array_map( 'count', $allowed ), 'differences' => $diff );
	file_put_contents( $dir . '/compare-' . $a . '-' . $b . '.json', json_encode( array_merge( $report, array( 'allowed_paths' => $allowed ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	acc_say( array() === $diff ? "Runs {$a} and {$b} restore the same content: " . json_encode( $report ) : "Runs {$a} and {$b} differ:\n  " . implode( "\n  ", $diff ) );
}

// ---------------------------------------------------------------- main

$command = $argv[1] ?? '';
$opts    = array();
foreach ( array_slice( $argv, 2 ) as $arg ) {
	if ( 1 === preg_match( '/^--([a-z-]+)=(.*)$/s', $arg, $m ) ) {
		$opts[ $m[1] ] = $m[2];
	}
}
$GLOBALS['acc_prefix'] = 'wp_';
switch ( $command ) {
	case 'setup':
		acc_setup( (string) ( $opts['dir'] ?? acc_fail( '--dir is required' ) ), (int) ( $opts['cpus'] ?? 1 ) );
		break;
	case 'run':
		acc_run( (string) ( $opts['dir'] ?? '' ), (string) ( $opts['name'] ?? acc_fail( '--name is required' ) ), (string) ( $opts['kills'] ?? '' ) );
		break;
	case 'check':
		acc_check( (string) ( $opts['dir'] ?? '' ), (string) ( $opts['name'] ?? acc_fail( '--name is required' ) ) );
		break;
	case 'compare':
		acc_compare( (string) ( $opts['dir'] ?? '' ), (string) ( $opts['name'] ?? '' ), (string) ( $opts['with'] ?? acc_fail( '--with is required' ) ) );
		break;
	case 'teardown':
		acc_teardown( (string) ( $opts['dir'] ?? '' ) );
		break;
	case 'helper':
		acc_helper( (string) $opts['kind'], (string) $opts['out'], (int) $opts['job'] );
		break;
	default:
		fwrite( STDERR, "Usage: see the header of this file.\n" );
		exit( 2 );
}
