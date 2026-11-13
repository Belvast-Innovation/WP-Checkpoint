<?php
/**
 * Runs made without WordPress (WebRun) in a process of their own that never loads WordPress: only this plugin's own
 * PSR-4 loader (as wp-checkpoint.php registers it, no Composer) and ABSPATH, the sandbox's WordPress directory, as
 * wp-config.php would define it. The database settings and the table prefix come from the config file, as
 * Credentials::from_values() takes them from wp-config.php's. Ticks the job until it ends, or a run leaves it, or
 * $config['max'] runs; prints one JSON line: what each run returned, what they noted, and whether WordPress was loaded
 * at the start and at the end (it must never be). A fatal error prints WEB-CHILD-FATAL and its message.
 *
 * Usage: php web-child.php <config.json>. Config: abspath, db (name, user, password, host, prefix), multisite, plugin,
 * type, job, max.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

$config = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "no config\n" );
	exit( 2 );
}

$wordpress = static function (): array {
	return array_merge(
		array_values(
			array_filter(
				array( 'add_action', '__', 'apply_filters', 'is_multisite', 'wp_normalize_path', 'get_option' ),
				'function_exists'
			)
		),
		defined( 'WPINC' ) ? array( 'WPINC' ) : array(),
		isset( $GLOBALS['wpdb'] ) ? array( '$wpdb' ) : array()
	);
};

register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			fwrite( STDOUT, 'WEB-CHILD-FATAL ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . "\n" );
		}
	}
);

define( 'ABSPATH', rtrim( (string) $config['abspath'], '/' ) . '/' );
if ( isset( $config['content'] ) ) {
	define( 'WP_CONTENT_DIR', (string) $config['content'] ); // As WordPress has defined it before its maintenance file.
}
$src = dirname( __DIR__, 3 ) . '/src/';
spl_autoload_register(
	static function ( $class_name ) use ( $src ) {
		$prefix = 'WPCheckpoint\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = $src . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

$at_start = $wordpress();
$notes    = array();
$run      = new \WPCheckpoint\Jobs\WebRun(
	\WPCheckpoint\Standalone\Credentials::from_values( (array) $config['db'] ),
	(string) $config['abspath'],
	! empty( $config['multisite'] ),
	(string) $config['plugin'],
	static function ( string $line ) use ( &$notes ): void {
		$notes[] = $line;
	},
	null,
	array( 'type' => (string) $config['type'] )
);
$results = array();
for ( $i = 0; $i < (int) $config['max']; $i++ ) {
	$result    = $run->tick( (int) $config['job'], microtime( true ) );
	$results[] = array(
		'status'  => $result->status,
		'message' => $result->message,
		'job'     => null === $result->job ? null : $result->job->status,
	);
	if ( \WPCheckpoint\Jobs\TickResult::MORE !== $result->status ) {
		break;
	}
}
echo json_encode(
	array(
		'wordpress_at_start' => $at_start,
		'wordpress_at_end'   => $wordpress(),
		'results'            => $results,
		'notes'              => $notes,
	)
) . "\n";
exit( 0 );
