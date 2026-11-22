<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\RestoreAsk;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\Stopped;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Restore\IncomingQuestions;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\TextMask;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A restore asks its questions through RestoreAsk::ask() only, which is where a restore nobody attends is kept from
 * waiting for an answer nobody gives: a step that called StepResult::ask() itself would pause an unattended restore.
 * The scan holds the plugin to it (the export's review is the one other caller, listed with its reason); the rest is
 * what the entry does with each kind of question.
 */
final class RestoreAskUsageTest extends TestCase {

	/**
	 * The files that may call StepResult::ask() themselves, how often, and why.
	 */
	const CALLERS = array(
		'src/Jobs/RestoreAsk.php' => 1, // The entry.
		'src/Jobs/ReviewStep.php' => 1, // The export's review: not a restore; an unattended export gives ExportOptions::UNATTENDED's policy, under which the review decides everything it would ask.
	);

	/** @var string */
	private $dir = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'restore-ask' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * The plugin's PHP files.
	 *
	 * @return array<string, string> Path relative to the plugin => content.
	 */
	private static function plugin_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		foreach ( array( 'wp-checkpoint.php', 'uninstall.php' ) as $entry ) {
			$files[ $entry ] = (string) file_get_contents( $root . '/' . $entry );
		}
		$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $walk as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		return $files;
	}

	/**
	 * Where a question is asked without the entry: a call of ask() on StepResult under any spelling of the class
	 * (the name, a qualified name, an alias of an import), or the class named for a callable (StepResult::class).
	 * Comments are not code, and the constant StepResult::ASK is not a call.
	 *
	 * @param array<string, string> $files Path => content.
	 * @return array<string, int> Path => how often.
	 */
	public static function asks( array $files ): array {
		$out = array();
		foreach ( $files as $path => $content ) {
			$tokens = array_values(
				array_filter(
					token_get_all( $content ),
					static function ( $token ): bool {
						return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
					}
				)
			);
			$names  = array( 'stepresult' => true );
			$count  = 0;
			foreach ( $tokens as $i => $token ) {
				// An import under another name: "use …\StepResult as R;".
				if ( is_array( $token ) && T_AS === $token[0] && isset( $tokens[ $i - 1 ], $tokens[ $i + 1 ] ) && is_array( $tokens[ $i - 1 ] ) && is_array( $tokens[ $i + 1 ] ) && 1 === preg_match( '/(?:\A|\\\\)StepResult\z/i', $tokens[ $i - 1 ][1] ) ) {
					$names[ strtolower( $tokens[ $i + 1 ][1] ) ] = true;
				}
				if ( ! is_array( $token ) || T_DOUBLE_COLON !== $token[0] || ! isset( $tokens[ $i - 1 ], $tokens[ $i + 1 ] ) || ! is_array( $tokens[ $i - 1 ] ) || ! is_array( $tokens[ $i + 1 ] ) ) {
					continue;
				}
				$class = strtolower( (string) preg_replace( '/\A.*\\\\/s', '', $tokens[ $i - 1 ][1] ) );
				$after = strtolower( $tokens[ $i + 1 ][1] );
				if ( isset( $names[ $class ] ) && ( 'class' === $after || ( 'ask' === $after && '(' === ( $tokens[ $i + 2 ] ?? '' ) ) ) ) {
					++$count;
				}
			}
			if ( $count > 0 ) {
				$out[ $path ] = $count;
			}
		}
		ksort( $out );
		return $out;
	}

	public function test_only_the_entry_and_the_listed_callers_ask(): void {
		$this->assertSame( self::CALLERS, self::asks( self::plugin_files() ) );
	}

	public function test_the_scan_finds_a_question_asked_without_the_entry(): void {
		$files = array(
			'call.php'      => '<?php return StepResult::ask( $cursor, $questions, "m" );',
			'spaced.php'    => "<?php return StepResult ::\n ask( \$cursor, \$questions, 'm' );",
			'qualified.php' => '<?php return \WPCheckpoint\Jobs\StepResult::ask( $c, $q, "m" );',
			'alias.php'     => '<?php use WPCheckpoint\Jobs\StepResult as R; return R::ask( $c, $q, "m" );',
			'callable.php'  => '<?php return call_user_func( array( StepResult::class, "ask" ), $c, $q, "m" );',
			'case.php'      => '<?php return stepresult::ASK( $c, $q, "m" );',
			'entry.php'     => '<?php return RestoreAsk::ask( $context, $cursor, $questions, "m" );',
			'other.php'     => '<?php return StepResult::done( "m" ); // StepResult::ask() in a comment',
			'constant.php'  => '<?php return StepResult::ASK === $result->kind;',
			'docblock.php'  => "<?php /** StepResult::ask() */ function f() { return StepResult::progress( array(), 1 ); }",
		);
		$this->assertSame(
			array(
				'alias.php'     => 1,
				'call.php'      => 1,
				'callable.php'  => 1,
				'case.php'      => 1,
				'qualified.php' => 1,
				'spaced.php'    => 1,
			),
			self::asks( $files ),
			'every spelling of the call is found; the entry\'s own call, another method and the kind\'s constant and a comment are not'
		);
	}

	/**
	 * The kinds a file gives its questions, read from its source: every `'kind' => …` of a file that calls the
	 * entry is a question's (the files that call it name nothing else so). A value that is not a literal or a
	 * constant of a class cannot be read, and is reported as such.
	 *
	 * @param string $file    Path relative to the plugin.
	 * @param string $content Its content.
	 * @return array{kinds: string[], unreadable: string[]}
	 */
	private static function kinds_of( string $file, string $content ): array {
		$out = array(
			'kinds'      => array(),
			'unreadable' => array(),
		);
		if ( ! preg_match_all( '/\'kind\'\s*=>\s*([^,\n]+),/', $content, $found ) ) {
			return $out;
		}
		$class = 'WPCheckpoint\\' . str_replace( '/', '\\', substr( $file, strlen( 'src/' ), -strlen( '.php' ) ) );
		foreach ( $found[1] as $value ) {
			$value = trim( $value );
			if ( 1 === preg_match( '/\A\'([a-z_]+)\'\z/', $value, $m ) ) {
				$out['kinds'][] = $m[1];
			} elseif ( 1 === preg_match( '/\A(self|[A-Z][A-Za-z]*)::([A-Z_]+)\z/', $value, $m ) ) {
				$name = ( 'self' === $m[1] ? $class : ( false !== strpos( $content, 'use WPCheckpoint\\Restore\\' . $m[1] . ';' ) ? 'WPCheckpoint\\Restore\\' . $m[1] : substr( $class, 0, (int) strrpos( $class, '\\' ) + 1 ) . $m[1] ) ) . '::' . $m[2];
				if ( defined( $name ) && is_string( constant( $name ) ) ) {
					$out['kinds'][] = constant( $name );
				} else {
					$out['unreadable'][] = $value;
				}
			} else {
				$out['unreadable'][] = $value;
			}
		}
		return $out;
	}

	/**
	 * The callers of the entry whose questions are built elsewhere, and the kinds those are given there.
	 *
	 * @return array<string, string[]>
	 */
	private static function built_elsewhere(): array {
		return array(
			'src/Jobs/RestorePreflightStep.php' => array_values( IncomingQuestions::QUESTION_KINDS ), // IncomingQuestions::questions().
		);
	}

	public function test_every_kind_the_restore_asks_is_registered(): void {
		$asked   = array();
		$callers = 0;
		foreach ( self::plugin_files() as $file => $content ) {
			if ( 'src/Jobs/RestoreAsk.php' === $file || false === strpos( $content, 'RestoreAsk::ask(' ) ) {
				continue;
			}
			++$callers;
			$read = self::kinds_of( $file, $content );
			$this->assertSame( array(), $read['unreadable'], $file . ' gives a question a kind the scan cannot read' );
			$kinds = array_merge( $read['kinds'], self::built_elsewhere()[ $file ] ?? array() );
			$this->assertNotSame( array(), $kinds, $file . ' asks, and the scan finds no kind in it: list where its questions are built' );
			foreach ( $kinds as $kind ) {
				$this->assertArrayHasKey( $kind, RestoreAsk::KINDS, $file . ' asks a kind that is not registered' );
				$asked[ $kind ] = true;
			}
		}
		$this->assertGreaterThanOrEqual( 3, $callers, 'the control: the scan finds the steps that ask' );
		foreach ( array( LinkedTargets::KIND, SwapStep::UNVERIFIED_KIND, SwapStep::CARRY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND, RestoreFilesPreflightStep::EMPTY_KIND, 'free_space_unknown' ) as $kind ) {
			$this->assertArrayHasKey( $kind, $asked, 'the control: the scan reads this kind from the source' );
		}
		$this->assertSame( array(), array_values( array_diff( array_keys( RestoreAsk::KINDS ), array_keys( $asked ) ) ), 'a registered kind that no step asks' );
		foreach ( RestoreAsk::KINDS as $kind => $entry ) {
			$this->assertContains( $entry[0], array( RestoreAsk::POLICY, RestoreAsk::FAILS, RestoreAsk::ASKS ), $kind );
			$this->assertNotSame( '', trim( (string) $entry[1] ), $kind . ' says its policy, its subject or its reason' );
			if ( RestoreAsk::POLICY === $entry[0] ) {
				$this->assertArrayHasKey( $entry[1], RestoreJob::POLICIES, $kind . ' names a policy the restore has' );
			}
		}
	}

	public function test_the_scan_of_kinds_reads_literals_and_constants_and_reports_what_it_cannot_read(): void {
		$read = self::kinds_of(
			'src/Jobs/SwapStep.php',
			"<?php\n\$a = array(\n'kind' => 'free_space_unknown',\n'kind'    => self::CARRY_KIND,\n'kind' => LinkedTargets::KIND,\n'kind' => \$kind,\n'kind' => self::NOT_A_CONSTANT,\n'kind' => self::KINDS[ \$key ],\n);\nuse WPCheckpoint\\Restore\\LinkedTargets;"
		);
		$this->assertSame( array( 'free_space_unknown', SwapStep::CARRY_KIND, LinkedTargets::KIND ), $read['kinds'] );
		$this->assertSame( array( '$kind', 'self::NOT_A_CONSTANT', 'self::KINDS[ $key ]' ), $read['unreadable'] );
	}

	/**
	 * @param bool $unattended Whether nobody attends the restore.
	 */
	private function context( bool $unattended ): JobContext {
		$job          = new Job();
		$job->id      = 7;
		$job->options = array(
			'base'       => 'x',
			'unattended' => $unattended,
		);
		return new JobContext(
			$job,
			array(),
			new Budget( 10, 33554432, false ),
			new Logger( $this->dir . '/job.log', TextMask::redact_only( new Redactor() ) ),
			static function (): float {
				return microtime( true );
			},
			static function (): int {
				return memory_get_usage( true );
			},
			microtime( true ),
			-1,
			static function (): void {}
		);
	}

	/**
	 * @param string $kind Kind.
	 * @return array<int, array<string, mixed>>
	 */
	private static function question( string $kind ): array {
		return array(
			array(
				'id'      => 'q1',
				'kind'    => $kind,
				'choices' => array( 'a', 'b' ),
			),
		);
	}

	/**
	 * The first registered kind of a class.
	 *
	 * @param string $class RestoreAsk::POLICY, FAILS or ASKS.
	 * @return string '' when there is none.
	 */
	private static function kind_of( string $class ): string {
		foreach ( RestoreAsk::KINDS as $kind => $entry ) {
			if ( $class === $entry[0] ) {
				return (string) $kind;
			}
		}
		return '';
	}

	public function test_an_attended_restore_is_asked_every_registered_kind(): void {
		foreach ( array_keys( RestoreAsk::KINDS ) as $kind ) {
			$result = RestoreAsk::ask( $this->context( false ), array( 'phase' => 'p' ), self::question( (string) $kind ), 'm' );
			$this->assertSame( StepResult::ASK, $result->kind, (string) $kind );
			$this->assertSame( self::question( (string) $kind ), $result->questions );
		}
	}

	public function test_a_kind_that_is_not_registered_is_refused_attended_or_not(): void {
		foreach ( array( false, true ) as $unattended ) {
			try {
				RestoreAsk::ask( $this->context( $unattended ), array(), self::question( 'a_new_kind' ), 'm' );
				$this->fail( 'asked a kind that is not registered' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'a_new_kind', $e->getMessage() );
			}
		}
	}

	public function test_an_unattended_restore_fails_where_a_policy_was_due_and_names_it(): void {
		$kind = self::kind_of( RestoreAsk::POLICY );
		$this->assertNotSame( '', $kind );
		try {
			RestoreAsk::ask( $this->context( true ), array(), self::question( $kind ), 'm' );
			$this->fail( 'asked an unattended restore' );
		} catch ( Stopped $e ) {
			$this->assertStringContainsString( 'restore policy "' . RestoreAsk::KINDS[ $kind ][1] . '"', $e->getMessage() );
			$this->assertSame( array( 'q1' ), $e->questions() );
		}
	}

	public function test_an_unattended_restore_fails_on_a_question_without_a_policy_and_says_what_it_was(): void {
		$kind = self::kind_of( RestoreAsk::FAILS );
		$this->assertNotSame( '', $kind );
		try {
			RestoreAsk::ask( $this->context( true ), array(), self::question( $kind ), 'm' );
			$this->fail( 'asked an unattended restore' );
		} catch ( Stopped $e ) {
			$this->assertStringContainsString( 'This restore runs unattended, and it came to a decision only a person can make: ' . RestoreAsk::KINDS[ $kind ][1], $e->getMessage() );
			$this->assertStringNotContainsString( 'restore policy', $e->getMessage(), 'there is no policy to name' );
			$this->assertSame( array( 'q1' ), $e->questions() );
		}
		foreach ( array( 'free_space_unknown', 'unmapped_paths', 'empty_groups' ) as $fails ) {
			$this->assertSame( RestoreAsk::FAILS, RestoreAsk::KINDS[ $fails ][0], $fails . ' has no policy and nothing answers it' );
		}
	}

	public function test_an_unattended_restore_is_asked_what_is_registered_as_asked_all_the_same(): void {
		$kind = self::kind_of( RestoreAsk::ASKS );
		$this->assertNotSame( '', $kind );
		$this->assertSame( StepResult::ASK, RestoreAsk::ask( $this->context( true ), array(), self::question( $kind ), 'm' )->kind );
	}

	public function test_one_question_nobody_answers_fails_an_unattended_restore_that_asks_several(): void {
		$policy    = self::kind_of( RestoreAsk::POLICY );
		$asks      = self::kind_of( RestoreAsk::ASKS );
		$questions = array(
			array(
				'id'      => 'first',
				'kind'    => $asks,
				'choices' => array( 'a' ),
			),
			array(
				'id'      => 'second',
				'kind'    => $policy,
				'choices' => array( 'a' ),
			),
		);
		try {
			RestoreAsk::ask( $this->context( true ), array(), $questions, 'm' );
			$this->fail( 'asked an unattended restore' );
		} catch ( Stopped $e ) {
			$this->assertSame( array( 'second' ), $e->questions(), 'the question that is asked all the same is not what stopped it' );
		}
	}
}
