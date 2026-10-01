<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\StorageReclaim;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * Answering the "claimed" question (Directories::identity_question(): a take-over of the original storage directory
 * rewrote its marker and died, and the site moved again) on generated sequences of attempts, each of which may fail
 * or die at any point of the take-over and of the answer's writes: the prechecks refusing (a job working there), the
 * take-over lock busy, dying after the take-over recorded the hash it is about to write, losing the lock at its last
 * check, the marker write failing, dying after the marker was replaced, dying after the answer was recorded; and
 * answering "copy", which may die after the acknowledgement. Between attempts the site may be deployed to a new
 * release, so attempts come from several WordPress directories.
 *
 * Invariant, from a fresh request after every attempt (the interference gone, the lock's time passed): either the
 * take-over is done (the original directory in use with its token, the job holding the site runs, nothing asked, no
 * move left waiting), or a question is asked (the same one while the site has not moved since)
 * and answering it, with nothing in the way, does it. Never neither: no state
 * leaves the administrator without a way forward. After "copy": nothing asked, the directory left alone.
 *
 * Fixed seeds; size: WPCHECKPOINT_IDENTITY_SEQUENCES (default 200).
 */
final class ClaimedAnswerSequencesTest extends WP_UnitTestCase {

	const POINTS = array( 'none', 'prechecks', 'lock_busy', 'after_hash', 'lock_lost', 'write_fail', 'taken', 'recorded' );

	/** @var string The test's directory; '' before set_up() made it. */
	private $root = '';

	/** @var array<string, int> How often each point came up (the controls). */
	private $seen = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$this->root = Sandbox::make( 'claimed-answers' );
		Schema::ensure();
	}

	public function tear_down(): void {
		global $wpdb;
		// Before the DROP TABLE, which commits them.
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		if ( '' !== $this->root ) {
			foreach ( glob( $this->root . '/*/store' ) ?: array() as $store ) {
				chmod( $store, 0755 );
			}
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	private function dirs( string $site, string $store, array $extra = array() ): Directories {
		return new Directories(
			array_merge(
				array(
					'is_web_request' => false,
					'document_root'  => '',
					'abspath'        => $this->root . '/' . $site . '/',
					'content_dir'    => $this->root . '/content',
					'custom_dir'     => $store,
				),
				$extra
			)
		);
	}

	private static function repo( Directories $dirs ): JobRepository {
		return new JobRepository( $dirs, null, static function (): int {
			return time();
		} );
	}

	private function runs( int $id, string $site, string $store ): bool {
		global $wpdb;
		$repo = self::repo( $this->dirs( $site, $store ) );
		$job  = $repo->find( $id );
		$let  = null !== $job && $repo->gate( $job )['allowed'];
		$took = null !== $repo->acquire( $id );
		$wpdb->update( Schema::jobs_table(), array( 'lock_token' => '', 'locked_until' => 0 ), array( 'id' => $id ) );
		return $let && $took;
	}

	/**
	 * Run one sequence; the invariant's violations.
	 *
	 * @return string[]
	 */
	private function sequence( int $seed ): array {
		global $wpdb;
		mt_srand( $seed );
		$tag = 'seq' . $seed;
		foreach ( array( 1, 2, 3 ) as $release ) {
			mkdir( $this->root . '/' . $tag . '/releases/' . $release . '/wp-includes', 0755, true );
		}
		$store   = $this->root . '/' . $tag . '/store';
		$release = 3;
		$here    = $tag . '/releases/3';
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DELETE FROM ' . Schema::jobs_table() );

		// The original at release 1, a job holding the site; the move to release 2 is detected, its take-over
		// rewrites the marker and dies; the site is at release 3 before anything finished it.
		$first = $this->dirs( $tag . '/releases/1', $store );
		if ( $store !== $first->base() ) {
			return array( 'setup: ' . $first->last_error() );
		}
		$token = (string) $first->state()['token'];
		$job   = self::repo( $first )->create( 'plain' );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING ), array( 'id' => $job->id ) );
		$this->dirs( $tag . '/releases/2', $store )->base();
		if ( ! $this->dirs( $tag . '/releases/2', $store )->reclaim()->reclaim( false )['ok'] ) {
			return array( 'setup: the first take-over' );
		}
		$question = $this->dirs( $here, $store )->identity_question();
		if ( null === $question ) {
			return array( 'setup: no question' );
		}
		$can_lock_out = ! ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ); // Root writes anywhere.
		$found        = array();
		$steps        = array();
		for ( $i = 0, $n = mt_rand( 1, 4 ); $i < $n; $i++ ) {
			if ( 0 === mt_rand( 0, 2 ) ) {
				// Another deployment before this attempt: the site answers from a new release, or goes back to one
				// it was at before (its earlier release, as a rollback or a worker that still runs it).
				if ( 0 === mt_rand( 0, 2 ) ) {
					$here    = $tag . '/releases/' . mt_rand( 2, $release );
					$steps[] = 'back to ' . basename( $here );
					$this->seen['back'] = ( $this->seen['back'] ?? 0 ) + 1;
				} else {
					++$release;
					mkdir( $this->root . '/' . $tag . '/releases/' . $release . '/wp-includes', 0755, true );
					$here = $tag . '/releases/' . $release;
				}
				$steps[]  = 'deploy';
				$this->seen['deploy'] = ( $this->seen['deploy'] ?? 0 ) + 1;
				$moved    = $this->dirs( $here, $store );
				$question = $moved->identity_question();
				if ( $store === $moved->base() && empty( $moved->state()['clone_detected'] ) ) {
					$this->seen['done'] = ( $this->seen['done'] ?? 0 ) + 1;
					return $found; // Back where a take-over had finished: done.
				}
				if ( null === $question ) {
					$found[] = sprintf( 'dead end (seed %d, after %s): nothing asked where the site is now', $seed, implode( ', ', $steps ) );
					return $found;
				}
			}
			$answer = 0 === mt_rand( 0, 9 ) ? Directories::ANSWER_COPY : Directories::ANSWER_SAME;
			$point  = self::POINTS[ mt_rand( 0, count( self::POINTS ) - 1 ) ];
			if ( 'write_fail' === $point && ! $can_lock_out ) {
				$point = 'none';
			}
			if ( Directories::ANSWER_COPY === $answer ) {
				$point = 0 === mt_rand( 0, 1 ) ? 'acknowledged' : 'none';
			}
			$steps[] = $answer . '@' . $point;
			$where   = sprintf( '(seed %d, after %s)', $seed, implode( ', ', $steps ) );
			$this->seen[ $answer . '@' . $point ] = ( $this->seen[ $answer . '@' . $point ] ?? 0 ) + 1;

			$hooks = array();
			if ( 'prechecks' === $point ) {
				file_put_contents( $store . '/tmp/working.tmp', 'x' ); // A job working there: the prechecks refuse.
			} elseif ( 'lock_busy' === $point ) {
				file_put_contents( StorageReclaim::lock_path( $store ), "other\n" ); // Another take-over holds the lock.
			} elseif ( in_array( $point, array( 'after_hash', 'lock_lost', 'write_fail' ), true ) ) {
				$hooks['before_rename'] = static function ( string $dir ) use ( $point ): void {
					if ( 'after_hash' === $point ) {
						throw new \RuntimeException( 'died' );
					}
					if ( 'lock_lost' === $point ) {
						file_put_contents( StorageReclaim::lock_path( $dir ), "intruder\n" );
						return;
					}
					chmod( $dir, 0555 ); // The marker cannot be written.
				};
			} elseif ( in_array( $point, array( 'taken', 'recorded', 'acknowledged' ), true ) ) {
				$hooks['identity_step'] = static function ( string $step ) use ( $point ): void {
					if ( $step === $point ) {
						throw new \RuntimeException( 'died' );
					}
				};
			}
			$asking = $this->dirs( $here, $store, $hooks );
			$shown  = $asking->identity_question();
			try {
				if ( null !== $shown ) {
					$asking->answer_identity( $answer, $shown['id'] );
				}
			} catch ( \RuntimeException $e ) {
				if ( 'died' !== $e->getMessage() ) {
					throw $e;
				}
			}
			// The interference ends; time passes (a lock left behind expires).
			chmod( $store, 0755 );
			if ( is_file( $store . '/tmp/working.tmp' ) ) {
				Sandbox::remove( $store . '/tmp/working.tmp' );
			}
			clearstatcache( true );
			if ( is_file( StorageReclaim::lock_path( $store ) ) ) {
				touch( StorageReclaim::lock_path( $store ), time() - 3600 );
			}

			$next = $this->dirs( $here, $store );
			$base = $next->base();
			if ( Directories::ANSWER_COPY === $answer && null !== $shown ) {
				if ( null !== $next->identity_question() || $store === $base ) {
					$found[] = sprintf( 'copy %s: still asked, or the directory in use', $where );
				}
				return $found; // Answered "copy": nothing more to ask.
			}
			if ( $store === $base ) {
				if ( $token !== (string) $next->state()['token'] || null !== $next->identity_question() || ! empty( $next->state()['clone_detected'] ) || ! $this->runs( $job->id, $here, $store ) ) {
					$found[] = sprintf( 'done %s: the token, the question, a move still waiting or the job is wrong', $where );
				}
				$this->seen['done'] = ( $this->seen['done'] ?? 0 ) + 1;
				return $found;
			}
			$again = $next->identity_question();
			if ( null === $again || $again['id'] !== $question['id'] ) {
				$found[] = sprintf( 'dead end %s: not done, and %s', $where, null === $again ? 'nothing is asked' : 'another question is asked' );
				return $found;
			}
		}
		// Not done yet: the same answer, with nothing in the way, does it.
		$last   = $this->dirs( $here, $store );
		$asked  = $last->identity_question();
		$result = null === $asked ? array( 'ok' => false, 'message' => 'nothing asked' ) : $last->answer_identity( Directories::ANSWER_SAME, $asked['id'] );
		$after  = $this->dirs( $here, $store );
		if ( ! $result['ok'] || $store !== $after->base() || $token !== (string) $after->state()['token'] || ! $this->runs( $job->id, $here, $store ) ) {
			$found[] = sprintf( 'no way forward (seed %d, after %s): %s', $seed, implode( ', ', $steps ), $result['message'] );
		}
		return $found;
	}

	public function test_every_failed_or_dead_attempt_leaves_a_way_forward(): void {
		$count = max( 1, (int) ( getenv( 'WPCHECKPOINT_IDENTITY_SEQUENCES' ) ?: 200 ) );
		$found = array();
		for ( $seed = 1; $seed <= $count; $seed++ ) {
			$found = array_merge( $found, $this->sequence( $seed ) );
		}
		$this->assertSame( array(), array_slice( $found, 0, 10 ), count( $found ) . ' violations' );
		// The controls: each point came up, and sequences ended both ways.
		$points = array( 'same@none', 'same@prechecks', 'same@lock_busy', 'same@after_hash', 'same@lock_lost', 'same@taken', 'same@recorded', 'copy@none', 'copy@acknowledged', 'done', 'deploy', 'back' );
		if ( ! ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$points[] = 'same@write_fail';
		}
		foreach ( $points as $point ) {
			$this->assertGreaterThan( 0, $this->seen[ $point ] ?? 0, 'the control: ' . $point . ' ' . wp_json_encode( $this->seen ) );
		}
	}
}
