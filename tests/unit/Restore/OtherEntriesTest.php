<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\OtherEntries;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Where an entry of the content directory is, as the restore sees and records it: absent, plain, somewhere else
 * than spelled, or not to be told; which of those are left out of the staged other content; the record written and
 * read back; and what counts as a change since.
 */
final class OtherEntriesTest extends TestCase {

	/** @var string */
	private $dir = '';

	/** @var string The content directory. */
	private $content = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir     = (string) Paths::real( Sandbox::make( 'other-entries' ) );
		$this->dir     = rtrim( Paths::normalize( $this->dir ), '/' );
		$this->content = $this->dir . '/site/wp-content';
		mkdir( $this->content, 0700, true );
		mkdir( $this->content . '/mu-plugins' );
		mkdir( $this->content . '/languages' );
		mkdir( $this->dir . '/shared/mu/deep', 0700, true );
		mkdir( $this->dir . '/elsewhere' );
		file_put_contents( $this->content . '/object-cache.php', '<?php' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * A link in the content directory, or the test skipped where this process may not make one.
	 */
	private function link( string $name, string $target ): void {
		if ( ! @symlink( $target, $this->content . '/' . $name ) ) {
			$this->markTestSkipped( 'This process cannot create a symbolic link here (on Windows it takes a privilege the runner may not have).' );
		}
		clearstatcache( true );
	}

	/** @var int */
	private $moved = 0;

	/**
	 * Take an entry of the content directory away: moved aside within the sandbox (tests do not delete).
	 */
	private function away( string $name ): void {
		rename( $this->content . '/' . $name, $this->dir . '/away-' . ( ++$this->moved ) );
		clearstatcache( true );
	}

	public function test_an_entry_is_absent_plain_or_somewhere_else(): void {
		$this->assertSame( OtherEntries::ABSENT, OtherEntries::state( $this->content, 'nothing-here' ) );
		$this->assertSame( OtherEntries::PLAIN, OtherEntries::state( $this->content, 'languages' ) );
		$this->assertSame( OtherEntries::PLAIN, OtherEntries::state( $this->content, 'object-cache.php' ), 'a file' );
		$this->link( 'mu-link', $this->dir . '/shared/mu' );
		$state = OtherEntries::state( $this->content, 'mu-link' );
		$this->assertSame( OtherEntries::AT . bin2hex( ExpectedPath::slashed( $this->dir, 'shared/mu' ) ), $state );
		$this->assertSame( ExpectedPath::slashed( $this->dir, 'shared/mu' ), OtherEntries::place( $state ) );
		$this->link( 'dangling', $this->dir . '/not-there' );
		$this->assertSame( OtherEntries::UNKNOWN, OtherEntries::state( $this->content, 'dangling' ), 'it is there, and where it leads cannot be resolved' );
		$this->assertFalse( OtherEntries::recorded( OtherEntries::ABSENT ) );
		$this->assertFalse( OtherEntries::recorded( OtherEntries::PLAIN ) );
		$this->assertTrue( OtherEntries::recorded( OtherEntries::UNKNOWN ) );
		$this->assertTrue( OtherEntries::recorded( $state ) );
	}

	public function test_an_entry_is_left_out_when_it_is_holds_or_is_inside_a_directory_of_the_site_or_cannot_be_told(): void {
		$dirs = array( $this->dir . '/shared/mu', $this->content . '/plugins' );
		$at   = static function ( string $place ): string {
			return OtherEntries::AT . bin2hex( $place );
		};
		$this->assertTrue( OtherEntries::off( $at( $this->dir . '/shared/mu' ), $dirs ), 'it is the directory' );
		$this->assertTrue( OtherEntries::off( $at( $this->dir . '/shared' ), $dirs ), 'it holds the directory' );
		$this->assertTrue( OtherEntries::off( $at( $this->dir . '/shared/mu/deep' ), $dirs ), 'it is inside the directory' );
		$this->assertTrue( OtherEntries::off( $at( strtoupper( $this->dir ) . '/shared/mu' ), $dirs ), 'without case: leaving out is the safe answer' );
		$this->assertTrue( OtherEntries::off( OtherEntries::UNKNOWN, $dirs ), 'not to be told' );
		$this->assertTrue( OtherEntries::off( OtherEntries::AT . 'zz', $dirs ), 'a state that names no place is not to be told' );
		$this->assertFalse( OtherEntries::off( $at( $this->dir . '/elsewhere' ), $dirs ), 'the control: a link to somewhere else' );
		$this->assertFalse( OtherEntries::off( $at( $this->dir . '/shared/music' ), $dirs ), 'a name that only starts alike' );
		$this->assertFalse( OtherEntries::off( OtherEntries::PLAIN, $dirs ) );
		$this->assertFalse( OtherEntries::off( OtherEntries::ABSENT, $dirs ) );
	}

	public function test_the_record_is_read_back_as_written_and_a_damaged_one_is_refused(): void {
		$file  = $this->dir . '/record.jsonl';
		$this->assertSame( array(), OtherEntries::read( $file ), 'no file: nothing was recorded' );
		$place = OtherEntries::AT . bin2hex( '/srv/shared/mu' );
		$name  = "caf\xC3\xA9 \"x\"\\ \xFF"; // Any bytes.
		file_put_contents( $file, OtherEntries::line( 'mu-link', $place, true ) . OtherEntries::line( $name, OtherEntries::UNKNOWN, true ) . OtherEntries::line( 'ext', OtherEntries::AT . bin2hex( '/srv/else' ), false ) );
		$this->assertSame(
			array(
				'mu-link' => array(
					'state' => $place,
					'off'   => true,
				),
				$name     => array(
					'state' => OtherEntries::UNKNOWN,
					'off'   => true,
				),
				'ext'     => array(
					'state' => OtherEntries::AT . bin2hex( '/srv/else' ),
					'off'   => false,
				),
			),
			OtherEntries::read( $file )
		);
		$whole = (string) file_get_contents( $file );
		$bad   = array(
			'a torn line'            => substr( $whole, 0, -5 ),
			'a line without its end' => rtrim( $whole, "\n" ),
			'a state it never wrote' => OtherEntries::line( 'a', 'plain', false ),
			'another field'          => '{"n":"61","s":"unknown","o":1,"x":1}' . "\n",
			'an empty name'          => '{"n":"","s":"unknown","o":1}' . "\n",
			'not json'               => "hello\n",
		);
		foreach ( $bad as $what => $text ) {
			file_put_contents( $file, $text );
			try {
				OtherEntries::read( $file );
				$this->fail( 'read ' . $what );
			} catch ( \UnexpectedValueException $e ) {
				$this->assertStringContainsString( 'damaged', $e->getMessage(), $what );
			}
		}
	}

	public function test_the_most_records_are_read_and_one_more_is_refused(): void {
		$file = $this->dir . '/record.jsonl';
		$text = '';
		for ( $i = 0; $i < OtherEntries::MAX_RECORDS; $i++ ) {
			$text .= OtherEntries::line( 'entry-' . $i, OtherEntries::AT . bin2hex( '/srv/else/' . $i ), false );
		}
		file_put_contents( $file, $text );
		$this->assertCount( OtherEntries::MAX_RECORDS, OtherEntries::read( $file ) );
		file_put_contents( $file, $text . OtherEntries::line( 'one-more', OtherEntries::UNKNOWN, true ) );
		try {
			OtherEntries::read( $file );
			$this->fail( 'read more records than a restore writes' );
		} catch ( \UnexpectedValueException $e ) {
			$this->assertStringContainsString( 'more entries than a restore records', $e->getMessage() );
		}
	}

	public function test_a_link_put_there_taken_away_or_pointed_elsewhere_is_a_change(): void {
		$this->link( 'ext', $this->dir . '/elsewhere' );
		$records = array(
			'ext' => array(
				'state' => OtherEntries::state( $this->content, 'ext' ),
				'off'   => false,
			),
		);
		$names   = array( 'languages', 'upgrade', 'ext' );
		$this->assertNull( OtherEntries::changed( $this->content, $records, $names ), 'the control: as recorded (languages is plain, upgrade absent)' );

		// A plain entry that comes or goes is not a change: only where an entry is counts.
		mkdir( $this->content . '/upgrade' );
		clearstatcache( true );
		$this->assertNull( OtherEntries::changed( $this->content, $records, $names ) );

		// The recorded link pointed elsewhere.
		$this->away( 'ext' );
		$this->link( 'ext', $this->dir . '/shared' );
		$this->assertSame( 'ext', OtherEntries::changed( $this->content, $records, $names ) );

		// The recorded link taken away, a directory in its place.
		$this->away( 'ext' );
		mkdir( $this->content . '/ext' );
		clearstatcache( true );
		$this->assertSame( 'ext', OtherEntries::changed( $this->content, $records, $names ) );

		// The recorded link gone.
		$this->away( 'ext' );
		clearstatcache( true );
		$this->assertSame( 'ext', OtherEntries::changed( $this->content, $records, $names ) );

		// Back as recorded; then a plain entry that became a link.
		$this->link( 'ext', $this->dir . '/elsewhere' );
		$this->assertNull( OtherEntries::changed( $this->content, $records, $names ), 'the control: as recorded again' );
		$this->away( 'languages' );
		$this->link( 'languages', $this->dir . '/shared/mu' );
		$this->assertSame( 'languages', OtherEntries::changed( $this->content, $records, $names ) );

		// An entry nobody names is not looked at.
		$this->assertNull( OtherEntries::changed( $this->content, $records, array( 'upgrade', 'ext' ) ) );
	}
}
