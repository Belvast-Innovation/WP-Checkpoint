<?php
/**
 * Mutation checks: each mutation changes one place of the code, the tests that should catch it are run, and the file
 * is written back exactly as it was.
 *
 *   php bin/mutate.php <spec.json> [name ...]
 *
 * Run from the repository (any directory in it). The spec:
 *
 *   {
 *     "command": "a shell command that runs the tests; {junit} is replaced by the JUnit log it must write",
 *     "mutations": [ { "name": "short-name", "file": "src/…", "old": "text found exactly once", "new": "its replacement" } ]
 *   }
 *
 * The command runs from the repository's top directory with MUTATION set to the mutation's name; {junit} is
 * build/junit-mut-<name>.xml there (build/ is ignored by git). Without "command", the integration suite is run through
 * wp-env with "filter" (a PHPUnit --filter), the ports from WP_ENV_PORT and WP_ENV_TESTS_PORT. Names after the spec run
 * only those mutations.
 *
 * Refuses to start unless the working tree is clean (git status --porcelain prints nothing): commit the code first, so
 * that what each mutation is measured against is a commit, and so that nothing but the mutation is ever written. A
 * mutated file is restored by writing back the content read before the mutation, and compared byte for byte; never
 * from git, which would throw away whatever the file held that was not committed. A mutation whose text is not found
 * exactly once, or whose file does not pass php -l, is not run. The tree must be clean again at the end.
 *
 * Exit status: 0 when every mutation was caught (a failure or error in the log); 1 when one survived, was not run, or
 * left no log; 2 when it refused to start; 3 when a file could not be restored (the run stops there).
 *
 * @package WPCheckpoint
 */

'cli' === PHP_SAPI || exit( 2 );

/**
 * Print a line.
 *
 * @param string $text Text.
 * @return void
 */
function wcpm_say( string $text ): void {
	fwrite( STDOUT, $text . "\n" );
}

/**
 * Stop with a message.
 *
 * @param string $text   Text.
 * @param int    $status Exit status.
 * @return void
 */
function wcpm_stop( string $text, int $status ): void {
	fwrite( STDERR, $text . "\n" );
	exit( $status );
}

/**
 * Run a command; its output and exit status.
 *
 * @param string $command Command.
 * @return array{0: string, 1: int}
 */
function wcpm_run( string $command ): array {
	$output = array();
	$status = 0;
	exec( $command . ' 2>&1', $output, $status );
	return array( implode( "\n", $output ), $status );
}

/**
 * The working tree's changes (git status --porcelain), or null when git cannot tell.
 *
 * @return string|null
 */
function wcpm_dirty() {
	list( $out, $status ) = wcpm_run( 'git status --porcelain' );
	return 0 === $status ? trim( $out ) : null;
}

/**
 * The failures and errors in a JUnit log: "test: first line of the message"; null when there is no log to read.
 *
 * @param string $path Log.
 * @return string[]|null
 */
function wcpm_failures( string $path ) {
	clearstatcache( true, $path );
	if ( ! is_file( $path ) || 0 === filesize( $path ) ) {
		return null;
	}
	$xml = @simplexml_load_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported as no log.
	if ( false === $xml ) {
		return null;
	}
	$out = array();
	foreach ( $xml->xpath( '//testcase[failure or error]' ) ?: array() as $case ) {
		$text  = trim( (string) ( $case->failure ?? '' ) . (string) ( $case->error ?? '' ) );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) );
		$out[] = (string) $case['name'] . ': ' . ( $lines[1] ?? ( $lines[0] ?? '' ) );
	}
	return $out;
}

$args = array_slice( $argv, 1 );
if ( array() === $args ) {
	wcpm_stop( 'Usage: php bin/mutate.php <spec.json> [name ...]', 2 );
}
$spec_path = (string) array_shift( $args );
$spec_text = is_file( $spec_path ) ? (string) file_get_contents( $spec_path ) : '';
list( $top, $status ) = wcpm_run( 'git rev-parse --show-toplevel' );
if ( 0 !== $status ) {
	wcpm_stop( 'Not in a git repository: the tool measures mutations against a commit.', 2 );
}
$top = trim( $top );
$spec = json_decode( $spec_text, true );
if ( ! is_array( $spec ) || ! isset( $spec['mutations'] ) || ! is_array( $spec['mutations'] ) ) {
	wcpm_stop( 'The spec could not be read: ' . $spec_path . ' (JSON with "mutations").', 2 );
}
if ( ! chdir( $top ) ) {
	wcpm_stop( 'Could not change to ' . $top . '.', 2 );
}
$dirty = wcpm_dirty();
if ( null === $dirty ) {
	wcpm_stop( 'git status failed: whether the working tree is clean cannot be told.', 2 );
}
if ( '' !== $dirty ) {
	wcpm_stop( "The working tree is not clean; commit (or set aside) these first, so that mutations are measured against a commit and nothing but the mutation is written:\n" . $dirty, 2 );
}
$command = isset( $spec['command'] ) ? (string) $spec['command'] : sprintf(
	"WP_ENV_PORT=%s WP_ENV_TESTS_PORT=%s npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-checkpoint sh -c %s",
	escapeshellarg( (string) ( getenv( 'WP_ENV_PORT' ) ?: '8888' ) ),
	escapeshellarg( (string) ( getenv( 'WP_ENV_TESTS_PORT' ) ?: '8889' ) ),
	escapeshellarg( 'WPCHECKPOINT_TEST_LOOPBACK_HOST=http://tests-wordpress sh bin/test-integration.sh --log-junit {junit} --filter ' . escapeshellarg( (string) ( $spec['filter'] ?? '' ) ) )
);
if ( false === strpos( $command, '{junit}' ) ) {
	wcpm_stop( 'The command must write a JUnit log where {junit} says.', 2 );
}

// The one file mutated at a time, and its content before: written back on any way out (an exit, a fatal error, a
// signal where pcntl is there).
$pending = null;
register_shutdown_function(
	static function () use ( &$pending ): void {
		if ( null !== $pending ) {
			file_put_contents( $pending[0], $pending[1] );
			fwrite( STDERR, 'Restored ' . $pending[0] . " on the way out.\n" );
		}
	}
);
if ( function_exists( 'pcntl_async_signals' ) ) {
	pcntl_async_signals( true );
	foreach ( array( SIGINT, SIGTERM ) as $signal ) {
		pcntl_signal(
			$signal,
			static function () {
				exit( 130 );
			}
		);
	}
}

$only    = $args;
$results = array();
$worst   = 0;
foreach ( $spec['mutations'] as $mutation ) {
	$name = (string) ( $mutation['name'] ?? '' );
	if ( '' === $name || 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]*\z/', $name ) ) {
		wcpm_stop( 'A mutation needs a name of lowercase letters, digits and "-": ' . json_encode( $mutation ), 2 );
	}
	if ( array() !== $only && ! in_array( $name, $only, true ) ) {
		continue;
	}
	$file = $top . '/' . ltrim( (string) ( $mutation['file'] ?? '' ), '/' );
	$old  = (string) ( $mutation['old'] ?? '' );
	$new  = (string) ( $mutation['new'] ?? '' );
	if ( ! is_file( $file ) ) {
		$results[ $name ] = 'not run: no file ' . (string) ( $mutation['file'] ?? '' );
		$worst            = max( $worst, 1 );
		continue;
	}
	$before = (string) file_get_contents( $file );
	$found  = '' === $old ? 0 : substr_count( $before, $old );
	if ( 1 !== $found ) {
		$results[ $name ] = 'not run: its text is found ' . $found . ' times, not once';
		$worst            = max( $worst, 1 );
		continue;
	}
	$pending = array( $file, $before );
	file_put_contents( $file, str_replace( $old, $new, $before ) );
	try {
		if ( '.php' === substr( $file, -4 ) ) {
			list( $lint, $lint_status ) = wcpm_run( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) );
			if ( 0 !== $lint_status ) {
				$results[ $name ] = 'not run: php -l fails: ' . trim( $lint );
				$worst            = max( $worst, 1 );
				continue;
			}
		}
		$junit = 'build/junit-mut-' . $name . '.xml';
		if ( ! is_dir( $top . '/build' ) ) {
			mkdir( $top . '/build', 0755, true );
		}
		if ( is_file( $top . '/' . $junit ) ) {
			file_put_contents( $top . '/' . $junit, '' ); // An earlier run's log is not this one's result.
		}
		wcpm_say( '== ' . $name . ' (' . (string) $mutation['file'] . ')' );
		list( , $run_status ) = wcpm_run( 'MUTATION=' . escapeshellarg( $name ) . ' ' . str_replace( '{junit}', $junit, $command ) );
		$failures = wcpm_failures( $top . '/' . $junit );
		if ( null === $failures ) {
			$results[ $name ] = 'no result: the command left no JUnit log (exit ' . $run_status . ')';
			$worst            = max( $worst, 1 );
		} elseif ( array() === $failures ) {
			$results[ $name ] = 'SURVIVED: no test failed';
			$worst            = max( $worst, 1 );
		} else {
			$results[ $name ] = "caught:\n    " . implode( "\n    ", $failures );
		}
	} finally {
		file_put_contents( $file, $before );
		clearstatcache( true, $file );
		$pending = null;
		if ( (string) file_get_contents( $file ) !== $before ) {
			wcpm_stop( 'Could not restore ' . $file . ' to its content before the mutation; stopping.', 3 );
		}
	}
}

foreach ( $results as $name => $result ) {
	wcpm_say( $name . ': ' . $result );
}
$after = wcpm_dirty();
if ( '' !== $after ) {
	wcpm_stop( "The working tree is not as it was at the start:\n" . (string) $after, 3 );
}
wcpm_say( 'The working tree is as it was at the start.' );
exit( $worst );
