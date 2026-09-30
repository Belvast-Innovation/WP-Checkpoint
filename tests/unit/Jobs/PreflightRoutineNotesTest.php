<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Database\WpdbConnection;
use WPCheckpoint\Jobs\PreflightStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The notes on what the export does not take: triggers, stored procedures and functions, events.
 */
final class PreflightRoutineNotesTest extends TestCase {

	public function test_each_kind_is_named_and_counted_and_none_is_no_note(): void {
		$this->assertSame(
			array(),
			PreflightStep::routine_notes(
				array(
					'triggers' => array(),
					'capped'   => false,
					'routines' => array(),
					'events'   => array(),
				)
			),
			'nothing to say'
		);
		$notes = PreflightStep::routine_notes(
			array(
				'triggers' => array( array( 'count_posts', 'wp_posts' ) ),
				'capped'   => false,
				'routines' => array( array( 'PROCEDURE', 'tidy' ), array( 'FUNCTION', 'slugify' ) ),
				'events'   => array( 'nightly' ),
			)
		);
		$this->assertSame(
			array(
				'1 triggers on the tables of the backup are not part of it (triggers are not exported): count_posts (on wp_posts).',
				'2 stored procedures and functions in this database are not part of the backup (they are not exported): procedure tidy, function slugify.',
				'1 events in this database are not part of the backup (events are not exported): nightly.',
			),
			$notes
		);
	}

	public function test_a_name_that_is_not_utf_8_is_made_valid_for_the_plan(): void {
		$notes = PreflightStep::routine_notes(
			array(
				'triggers' => array(),
				'capped'   => false,
				'routines' => array( array( 'PROCEDURE', "caf\xE9" ) ),
				'events'   => array(),
			)
		);
		$this->assertStringContainsString( 'procedure caf', $notes[0], 'the control: the note names it' );
		$this->assertSame( 1, preg_match( '//u', $notes[0] ), 'valid UTF-8' );
		$this->assertNotFalse( json_encode( $notes ), 'and so written to the plan' );
	}

	public function test_many_are_counted_and_a_few_named_and_what_could_not_be_read_is_said(): void {
		$many  = array();
		$names = array();
		for ( $i = 0; $i < WpdbConnection::ROUTINES_READ; $i++ ) {
			$many[]  = array( 't' . $i, 'wp_posts' );
			$names[] = 'e' . $i;
		}
		$notes = PreflightStep::routine_notes(
			array(
				'triggers' => array_slice( $many, 0, 12 ),
				'capped'   => true,
				'routines' => null,
				'events'   => $names,
			)
		);
		$this->assertSame( 'Triggers, stored procedures and events could not be listed; any the site has are not part of the backup (they are not exported).', $notes[0] );
		$this->assertStringStartsWith( 'At least 12 triggers on the tables of the backup', $notes[1], 'the read was capped before the other tables were left out' );
		$this->assertStringEndsWith( 't9 (on wp_posts) and 2 more.', $notes[1], PreflightStep::MAX_ROUTINES_LISTED . ' named, the rest counted' );
		$this->assertStringStartsWith( 'At least ' . WpdbConnection::ROUTINES_READ . ' events', $notes[2] );
		$this->assertCount( 3, $notes );
	}
}
