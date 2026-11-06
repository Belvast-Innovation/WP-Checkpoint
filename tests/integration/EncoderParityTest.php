<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\PlainJson;
use WPCheckpoint\Jobs\SwapStep;

/**
 * A run without WordPress encodes its cursors with PlainJson, WordPress with wp_json_encode(): for every value both
 * can encode the bytes are the same, so the lookups by a cursor's first bytes (cursor_json LIKE '{"phase":…,%', in
 * JobRepository) find what either wrote. Where wp_json_encode() rewrites what it cannot encode (invalid UTF-8),
 * PlainJson throws.
 */
final class EncoderParityTest extends WP_UnitTestCase {

	/**
	 * Every phase the swap writes, and where its cursors start.
	 *
	 * @return string[]
	 */
	private static function phases(): array {
		$phases = array_merge(
			array( 'start', 'judge', 'recount', 'ready', 'rejudged', 'reverted' ),
			SwapStep::CHANGING,
			SwapStep::SWAPPED,
			SwapStep::BEFORE_REDO
		);
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Jobs/SwapStep.php' );
		preg_match_all( "/'phase'\\s*=>\\s*'([a-z_]+)'/", $source, $written );
		self::assertSubset( $written[1], $phases );
		return array_values( array_unique( $phases ) );
	}

	/**
	 * Every value of $sub is in $set (the list above holds every phase the swap writes).
	 *
	 * @param string[] $sub Values.
	 * @param string[] $set Set.
	 * @return void
	 */
	private static function assertSubset( array $sub, array $set ): void {
		$missing = array_diff( array_unique( $sub ), $set );
		self::assertSame( array(), array_values( $missing ), 'a phase the swap writes is missing from the list' );
	}

	public function test_valid_cursors_are_the_same_bytes_from_both_encoders(): void {
		$cursors = array();
		foreach ( self::phases() as $phase ) {
			foreach ( array( '', 'cache', 'verify', 'forget', 'release', 'rewrite', 'cron', 'ask', 'exit' ) as $post ) {
				foreach ( array_merge( array( '' ), array_keys( SwapStep::REASONS ) ) as $reason ) {
					$cursor = array(
						'phase'   => $phase,
						'attempt' => 3,
						'mark'    => 'WP Checkpoint restore 0123456789abcdef0123456789abcdef',
					);
					if ( '' !== $post ) {
						$cursor['post'] = $post;
					}
					if ( '' !== $reason ) {
						$cursor['reason'] = $reason;
					}
					$cursors[] = $cursor;
				}
			}
		}
		$cursors[] = array(
			'phase'  => 'rollback',
			'name'   => '中文表名/with/slashes\\and\\backslashes "quoted" é',
			'deep'   => array(
				'a' => array( 1, 2, array( 'b' => null, 'c' => true, 'd' => false ) ),
				'e' => array(),
				'f' => 0.1,
				'g' => -0,
			),
			'busy'   => 2,
			'emoji'  => "\u{1F600}",
			'nl'     => "line\nbreak\ttab",
			'slash/' => '/',
		);
		$this->assertGreaterThan( 500, count( $cursors ), 'the control: every phase, stage and reason' );
		foreach ( $cursors as $cursor ) {
			$wp    = wp_json_encode( $cursor );
			$plain = PlainJson::encode( $cursor );
			$this->assertIsString( $wp );
			$this->assertSame( $wp, $plain, (string) $wp );
			$this->assertStringStartsWith( '{"phase":' . wp_json_encode( $cursor['phase'] ) . ',', $plain, 'what the lookups by the first bytes match' );
		}
	}

	public function test_what_wordpress_rewrites_the_plain_encoder_refuses(): void {
		$cursor = array(
			'phase' => 'recount',
			'key'   => array( "\xff\xfe" ),
		);
		$this->assertIsString( wp_json_encode( $cursor ), 'the control: wp_json_encode() gives something for invalid UTF-8 (it rewrites it)' );
		$this->expectException( \JsonException::class );
		PlainJson::encode( $cursor );
	}
}
