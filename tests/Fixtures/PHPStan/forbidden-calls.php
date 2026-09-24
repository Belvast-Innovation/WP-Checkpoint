<?php
/**
 * Calls the plugin's PHPStan rules must report. Never loaded: RulesTest
 * analyses this file with the rules and counts what they find.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures\PHPStan;

/**
 * Forbidden calls, one per line.
 *
 * @return void
 */
function forbidden_calls(): void {
	unserialize( 'a:0:{}', array( 'allowed_classes' => false ) );
	maybe_unserialize( 'a:0:{}' );
	call_user_func( 'unserialize', 'a:0:{}' );
	exec( 'true' );
	call_user_func( 'shell_exec', 'true' );
}
