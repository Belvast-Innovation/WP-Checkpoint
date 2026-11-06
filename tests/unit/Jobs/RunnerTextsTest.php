<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\RunnerTexts;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The Runner's texts are written twice in each method of RunnerTexts, the translatable literal and the English one
 * a run without WordPress gets: with the translation stand-in of the unit tests (which returns the text), both are
 * the same. And the Runner says nothing of its own but through RunnerTexts (no translation call left in it: in a run
 * without WordPress the function is not there).
 */
final class RunnerTextsTest extends TestCase {

	public function test_the_untranslated_text_is_the_translatable_one(): void {
		$translated = new RunnerTexts( true );
		$plain      = new RunnerTexts( false );
		$methods    = array_values(
			array_filter(
				get_class_methods( RunnerTexts::class ),
				static function ( string $method ): bool {
					return '__construct' !== $method;
				}
			)
		);
		$this->assertGreaterThanOrEqual( 9, count( $methods ), 'the control: the texts are found' );
		foreach ( $methods as $method ) {
			$parameters = ( new \ReflectionMethod( RunnerTexts::class, $method ) )->getParameters();
			$args       = array_map(
				static function ( \ReflectionParameter $parameter ) {
					return 'int' === (string) $parameter->getType() ? 42 : 'cursor_json';
				},
				$parameters
			);
			$this->assertSame( call_user_func_array( array( $translated, $method ), $args ), call_user_func_array( array( $plain, $method ), $args ), $method );
			$this->assertNotSame( '', call_user_func_array( array( $plain, $method ), $args ), $method );
		}
	}

	public function test_the_runner_translates_nothing_itself(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Jobs/Runner.php' );
		$this->assertSame( 0, preg_match_all( '/(?<![\w>:$])(?:__|_e|_x|_n|esc_html__|esc_attr__)\s*\(/', $source ), 'no translation call in Runner.php' );
		$texts = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Jobs/RunnerTexts.php' );
		$this->assertGreaterThanOrEqual( 9, preg_match_all( '/(?<![\w>:$])(?:__|_e|_x|_n|esc_html__|esc_attr__)\s*\(/', $texts ), 'the control: the same pattern finds them where they are' );
	}
}
