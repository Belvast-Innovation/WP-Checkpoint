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
$at   = static function ( string $point ) use ( $config, &$seen ): void {
	if ( $point !== $config['seam'] ) {
		return;
	}
	++$seen;
	if ( $seen === (int) $config['nth'] ) {
		posix_kill( getmypid(), SIGKILL ); // No catch, finally, destructor or shutdown function runs.
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
\WPCheckpoint\Plugin::instance()->job_types()->add( new \WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType( (string) $config['type'], array( new \WPCheckpoint\Jobs\SwapStep( null, $parts ) ) ) );
wp_set_current_user( (int) $config['admin'] );

$runner = new \WPCheckpoint\Jobs\Runner(
	\WPCheckpoint\Plugin::instance()->jobs(),
	\WPCheckpoint\Plugin::instance()->job_types(),
	new \WPCheckpoint\Support\Redactor( \WPCheckpoint\Support\Redactor::installation_secrets() ),
	array( 'cli' => true )
);
fwrite( STDOUT, "WPCHECKPOINT-CHILD-READY\n" );
$result = $runner->tick( (int) $config['job'], microtime( true ) );
fwrite( STDOUT, 'WPCHECKPOINT-CHILD-DONE ' . $result->status . ' ' . $result->message . "\n" );
exit( 0 );
