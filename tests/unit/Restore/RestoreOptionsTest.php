<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\RestoreJob;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The restore's options: what to do with each kind of table another installation in the same database may use, and
 * an unattended restore, which nobody asks, saying both.
 */
final class RestoreOptionsTest extends TestCase {

	const BASE = 'example-20260918-100000-a1b2';

	public function test_by_default_both_kinds_are_asked_about(): void {
		$options = RestoreJob::options( array( 'base' => self::BASE ) );
		$this->assertSame(
			array(
				'uncertain_tables' => 'ask',
				'shared_tables'    => 'ask',
				'linked_targets'   => 'ask',
			),
			$options['policy']
		);
		$this->assertFalse( $options['unattended'] );
	}

	public function test_an_unattended_restore_must_say_all_three_and_is_told_which_is_missing(): void {
		$all = array(
			'uncertain_tables' => 'exclude',
			'shared_tables'    => 'restore',
			'linked_targets'   => 'swap',
		);
		$said = RestoreJob::options(
			array(
				'base'       => self::BASE,
				'unattended' => true,
				'policy'     => $all,
			)
		);
		$this->assertSame( $all, $said['policy'], 'the control: all said' );
		foreach ( array_keys( $all ) as $missing ) {
			$policy = $all;
			unset( $policy[ $missing ] );
			$asked = 'linked_targets' === $missing ? array() : array( array_merge( $policy, array( $missing => 'ask' ) ) ); // "ask" is no choice for links.
			foreach ( array_merge( array( $policy ), $asked ) as $given ) {
				try {
					RestoreJob::options(
						array(
							'base'       => self::BASE,
							'unattended' => true,
							'policy'     => $given,
						)
					);
					$this->fail( 'refused without ' . $missing );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( '"' . $missing . '"', $e->getMessage() );
					foreach ( array_keys( $policy ) as $given_key ) {
						$this->assertStringNotContainsString( '"' . $given_key . '"', $e->getMessage(), 'it names the one missing, not one given' );
					}
					$this->assertStringContainsString( 'linked_targets' === $missing ? '"swap" or "exclude"' : '"restore" or "exclude"', $e->getMessage(), 'and its own choices' );
				}
			}
		}
	}

	public function test_an_attended_restore_may_leave_both_to_be_asked(): void {
		$options = RestoreJob::options(
			array(
				'base'   => self::BASE,
				'policy' => array( 'shared_tables' => 'exclude' ),
			)
		);
		$this->assertSame( 'ask', $options['policy']['uncertain_tables'] );
		$this->assertSame( 'exclude', $options['policy']['shared_tables'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function refused(): array {
		return array(
			'an unknown kind'         => array( array( 'policy' => array( 'neighbour_tables' => 'restore' ) ), 'may only say' ),
			'an unknown choice'       => array( array( 'policy' => array( 'shared_tables' => 'yes' ) ), '"shared_tables" must be one of' ),
			'a policy not a list'     => array( array( 'policy' => 'restore' ), 'may only say' ),
			'unattended not a switch' => array( array( 'unattended' => 'yes' ), '"unattended" must be true or false' ),
			'ask said for links'      => array( array( 'policy' => array( 'linked_targets' => 'ask' ) ), '"linked_targets" must be one of: swap, exclude' ),
			'a table choice for links' => array( array( 'policy' => array( 'linked_targets' => 'restore' ) ), '"linked_targets" must be one of: swap, exclude' ),
		);
	}

	/**
	 * @dataProvider refused
	 */
	public function test_what_the_options_cannot_say_is_refused( array $given, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		RestoreJob::options( array_merge( array( 'base' => self::BASE ), $given ) );
	}
}
