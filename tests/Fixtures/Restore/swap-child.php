<?php
/**
 * One WP-CLI tick of a restore's swap in a process of its own, which kills itself (SIGKILL) at a seam of the swap
 * step: the crash tests (SwapTestCase::killed_at()). Loads WordPress the way the integration suite does, without
 * installing it again, registers the test's restore type with the swap step and its sandbox, and runs the tick.
 *
 * Usage: php swap-child.php <config.json>
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture run as a script.

$config = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "no config\n" );
	exit( 2 );
}

putenv( 'WPCHECKPOINT_TEST_SUITE=integration' );
putenv( 'WP_TESTS_SKIP_INSTALL=1' );
$_SERVER['argv'] = array( 'phpunit' );
ob_start();
require dirname( __DIR__, 2 ) . '/bootstrap.php';
ob_end_clean();

$seen = 0;
$ran  = false;
$at   = static function ( string $point ) use ( $config, &$seen, &$ran ): void {
	$sql_at = (array) ( $config['sql_at'] ?? array() );
	if ( ! $ran && $point === (string) ( $sql_at['seam'] ?? '' ) ) {
		$ran = true; // What happens meanwhile in this run (another installation's tables made, say), once.
		foreach ( (array) ( $sql_at['sql'] ?? array() ) as $statement ) {
			$GLOBALS['wpdb']->query( (string) $statement );
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
	}
	if ( '' !== (string) ( $config['trace'] ?? '' ) ) {
		file_put_contents( (string) $config['trace'], $point . "\n", FILE_APPEND ); // What this run passed, for the parent.
	}
	if ( $point !== $config['seam'] ) {
		return;
	}
	++$seen;
	if ( $seen === (int) $config['nth'] ) {
		posix_kill( getmypid(), 9 ); // SIGKILL (the constant is pcntl's). No catch, finally, destructor or shutdown function runs.
		sleep( 5 );
		fwrite( STDOUT, "WPCHECKPOINT-CHILD-NOT-KILLED\n" );
		exit( 3 );
	}
};

$parts = array(
	'abspath'   => (string) $config['abspath'],
	'site_dirs' => static function () use ( $config ): array {
		return (array) $config['dirs'];
	},
	'sleep'     => static function ( int $seconds ): void {
		unset( $seconds );
	},
	'flush'     => static function (): void {},
	'at'        => $at,
);
if ( '' !== (string) $config['plugin'] ) {
	$parts['plugin'] = (string) $config['plugin'];
}
if ( (int) $config['packet'] > 0 ) {
	$parts['packet'] = (int) $config['packet'];
}
if ( (int) ( $config['batch'] ?? 0 ) > 0 ) {
	$parts['batch'] = (int) $config['batch'];
}
if ( '' !== (string) ( $config['verify'] ?? '' ) || empty( $config['real'] ) ) {
	// The restored site's checks (4c-B) stood in for: "pass", or the id of the check that failed (the swap is reversed);
	// passed when the test gave no verdicts and does not check for real (SwapTestCase::$real_checks).
	$verdict         = '' !== (string) ( $config['verify'] ?? '' ) ? (string) $config['verify'] : 'pass';
	$parts['verify'] = static function () use ( $verdict ) {
		return 'pass' === $verdict ? true : $verdict;
	};
}
$offset       = (int) ( $config['offset'] ?? 0 );
$parts['now'] = static function () use ( $offset ): int {
	return time() + $offset;
};
// The installation the test's restore runs under (SwapTestCase::$storage): its jobs, with its token; else the plugin's.
$jobs = array() === (array) ( $config['storage'] ?? array() ) ? \WPCheckpoint\Plugin::instance()->jobs() : new \WPCheckpoint\Jobs\JobRepository( new \WPCheckpoint\Support\Directories( (array) $config['storage'] ) );
if ( '' !== (string) ( $config['redo'] ?? '' ) ) {
	// A redo's checks of the sandbox's site, as the parent's (SwapTestCase::redo_checks()): the version is the stand-in's.
	$parts['redo'] = new \WPCheckpoint\Jobs\Redo(
		$jobs,
		array(
			'abspath'       => (string) $config['abspath'],
			'restore_types' => array( (string) $config['type'] ),
			'plugin'        => array( 'version' => (string) $config['redo'] ),
		)
	);
}
\WPCheckpoint\Plugin::instance()->job_types()->add( new \WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType( (string) $config['type'], array( new \WPCheckpoint\Jobs\SwapStep( null, $parts ) ) ) );
wp_set_current_user( (int) $config['admin'] );

$runner = new \WPCheckpoint\Jobs\Runner(
	$jobs,
	\WPCheckpoint\Plugin::instance()->job_types(),
	new \WPCheckpoint\Support\Redactor( \WPCheckpoint\Support\Redactor::installation_secrets() ),
	array( 'cli' => true )
);
// This run's database connection, for the parent's diagnostics of what a killed run left on the server.
fwrite( STDOUT, 'WPCHECKPOINT-CHILD-READY connection ' . (int) $GLOBALS['wpdb']->get_var( 'SELECT CONNECTION_ID()' ) . "\n" );
$result = $runner->tick( (int) $config['job'], microtime( true ) );
fwrite( STDOUT, 'WPCHECKPOINT-CHILD-DONE ' . $result->status . ' ' . $result->message . "\n" );
exit( 0 );
