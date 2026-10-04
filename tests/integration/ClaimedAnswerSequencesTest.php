<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\StorageReclaim;
use WPCheckpoint\Tests\Fixtures\Leftovers;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * Answering the "claimed" question (Directories::identity_question(): a take-over of the original storage directory
 * rewrote its marker and died, and the site moved again) on generated sequences of attempts, each of which may fail
 * or die at any point of the take-over and of the answer's writes: the prechecks refusing (a job working there), the
 * take-over lock busy, dying after the take-over recorded the hash it is about to write, losing the lock at its last
 * check, the marker write failing, dying after the marker was replaced, dying after the answer was recorded; and
 * answering "copy", which may die after the acknowledgement; and the notice's plain "continue with the original
 * directory" mixed in. Between attempts the site may be deployed to a new release or go back to an earlier one, so
 * attempts come from several WordPress directories. On multisite, half of the attempts are a subsite's requests (the
 * state and the jobs are the network's). Not covered: concurrent requests.
 *
 * A custom storage directory (WPCHECKPOINT_STORAGE_DIR), which every release shares and every request detects again.
 * Invariant, from a fresh request after every attempt (the interference gone, the lock's time passed): either the
 * take-over is done (the original directory in use with its token, the job holding the site runs, nothing asked, no
 * move left waiting), or a question is asked (the same one while the site has not moved since)
 * and answering it, with nothing in the way, does it. Never neither: no state
 * leaves the administrator without a way forward. After "copy": nothing asked, the directory left alone.
 *
 * The default directory, where each release that moves takes a directory of its own and a newer move replaces the
 * detection of the one before (only the latest move is undone): no question about a take-over is asked there, the
 * notice's "continue" is the way back. Invariant after every attempt: another place (a copy of the database) gets none
 * of the site's directories and runs none of its jobs; the jobs run only with the original directory and token; and
 * the state is one of: done; the move from the original directory still waiting, which a "continue" with nothing in
 * the way finishes; the original token recorded as lost, after which, once the latest move is resolved, the job that
 * does not hold the site fails with the reason and the one that holds it runs nowhere; or the new directory kept by
 * the administrator, the original's jobs not run here. Never a job silently waiting for nothing.
 *
 * The job holding the site is a real restore under the sequence's own installation (its storage directory and
 * token), run up to its swap and killed there (a child process, SIGKILL) once the maintenance file is up: its row,
 * cursor and plan are the swap's own, never written into the table by the test (held_job()).
 *
 * Fixed seeds; size: WPCHECKPOINT_IDENTITY_SEQUENCES (default 200) for each.
 */
final class ClaimedAnswerSequencesTest extends SwapTestCase {

	const POINTS = array( 'none', 'prechecks', 'lock_busy', 'after_hash', 'lock_lost', 'write_fail', 'taken', 'recorded' );

	/** Where a "continue" in the default directory can fail or die ("renamed": after the marker, before the state). */
	const CONTINUE_POINTS = array( 'none', 'prechecks', 'lock_busy', 'after_hash', 'lock_lost', 'write_fail', 'renamed' );

	const REASON = 'identity changed during a deployment';

	/** @var string The test's directory; '' before set_up() made it. */
	private $root = '';

	/** @var array<string, int> How often each point came up (the controls). */
	private $seen = array();

	/** @var int A subsite of the network (multisite only). */
	private $blog = 0;

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
		$this->tables_at_start = Leftovers::tables();
		if ( is_multisite() ) {
			$this->blog = self::factory()->blog->create();
		}
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

	/** @var string[] The restore's tables there before the test (Leftovers::tables()). */
	private $tables_at_start = array();

	private function dirs( string $site, string $store, array $extra = array() ): Directories {
		return new Directories( $this->custom_context( $site, $store, $extra ) );
	}

	/**
	 * The context of a request of the site at $site with the custom storage directory $store.
	 *
	 * @return array<string, mixed>
	 */
	private function custom_context( string $site, string $store, array $extra = array() ): array {
		return array_merge(
			array(
				'is_web_request' => false,
				'document_root'  => '',
				'abspath'        => $this->root . '/' . $site . '/',
				'content_dir'    => $this->root . '/content',
				'custom_dir'     => $store,
			),
			$extra
		);
	}

	/**
	 * The context of a request of the site at $site with the default storage directory in $content.
	 *
	 * @return array<string, mixed>
	 */
	private function default_context( string $site, string $content, array $extra = array() ): array {
		return array_merge(
			array(
				'is_web_request' => false,
				'document_root'  => '',
				'abspath'        => $this->root . '/' . $site . '/',
				'content_dir'    => $content,
				'custom_dir'     => '',
			),
			$extra
		);
	}

	/**
	 * A job that holds the site, made as the swap makes one: a restore under the installation of $context (its
	 * storage directory, its token) run up to its swap, and killed there (a child process, SIGKILL) once the
	 * maintenance file is up. Its row, cursor and plan are the swap's.
	 *
	 * @param array<string, mixed> $context The installation's Directories context.
	 * @return int The job's id.
	 */
	private function held_job( array $context ): int {
		$this->storage = $context;
		$job           = $this->at_swap();
		$this->killed_at( $job, 'maintenance' );
		$this->storage = array();
		$held          = self::repo( new Directories( $context ) )->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $held->site_state, 'the control: the killed swap holds the site' );
		$this->assertSame( Job::RUNNING, $held->status );
		$this->assertSame( (string) ( new Directories( $context ) )->state()['token'], $held->storage_token, 'the control: under the installation\'s token' );
		$this->after_activity_window( ( new Directories( $context ) )->base() );
		return $job->id;
	}

	/**
	 * Time passes after the kill, past the window in which a take-over takes the original directory for busy
	 * (StorageReclaim::is_busy(): the killed run's lock file, and the entries its work wrote in tmp/): as when the
	 * administrator answers some minutes later. The lock files say an older lease, the entries an older time; the job
	 * row is not touched.
	 *
	 * @param string $base The storage directory.
	 * @return void
	 */
	private function after_activity_window( string $base ): void {
		$then = time() - StorageReclaim::ACTIVITY_WINDOW - 60;
		$tmp  = $base . '/tmp';
		$this->assertTrue( StorageReclaim::is_busy( $base ), 'the control: right after the kill, the directory is taken for busy' );
		foreach ( glob( $tmp . '/*' ) ?: array() as $entry ) {
			if ( \WPCheckpoint\Jobs\LockFile::job_id_from_path( $entry ) > 0 ) {
				file_put_contents( $entry, (string) preg_replace( '/^locked_until:\d+$/m', 'locked_until:' . $then, (string) file_get_contents( $entry ) ) );
			}
			touch( $entry, $then );
		}
		clearstatcache();
		$this->assertFalse( StorageReclaim::is_busy( $base ), 'once the window passed, it is not' );
	}

	/**
	 * Between sequences: the swap's sandbox again, the restore's tables and plan rows gone.
	 *
	 * @return void
	 */
	private function next_swap(): void {
		global $wpdb;
		$this->storage = array();
		$this->release_backups();
		$this->tear_down_swap();
		$wpdb->query( 'DELETE FROM `' . $wpdb->base_prefix . 'wpcheckpoint_swap_plan`' );
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( array_diff( Leftovers::tables(), $this->tables_at_start ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		$wpdb->query( 'COMMIT' );
		$this->set_up_swap();
	}

	private static function repo( Directories $dirs ): JobRepository {
		return new JobRepository( $dirs, null, static function (): int {
			return time();
		} );
	}

	private function runs( int $id, string $site, string $store ): bool {
		return $this->runs_with( $id, $this->dirs( $site, $store ) );
	}

	private function runs_with( int $id, Directories $dirs ): bool {
		global $wpdb;
		$repo = self::repo( $dirs );
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
		$job   = self::repo( $first )->find( $this->held_job( $this->custom_context( $tag . '/releases/1', $store ) ) );
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
					$here    = $tag . '/releases/' . mt_rand( 1, $release );
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
					// Back where a take-over had finished: done, with what done means.
					if ( $token !== (string) $moved->state()['token'] || null !== $question || ! $this->runs( $job->id, $here, $store ) ) {
						$found[] = sprintf( 'done (seed %d, after %s): the token, the question or the job is wrong', $seed, implode( ', ', $steps ) );
					}
					$this->seen['done'] = ( $this->seen['done'] ?? 0 ) + 1;
					return $found;
				}
				if ( null === $question ) {
					$found[] = sprintf( 'dead end (seed %d, after %s): nothing asked where the site is now', $seed, implode( ', ', $steps ) );
					return $found;
				}
			}
			$roll   = mt_rand( 0, 9 );
			$answer = 0 === $roll ? Directories::ANSWER_COPY : ( 1 === $roll ? 'continue' : Directories::ANSWER_SAME );
			$point  = self::POINTS[ mt_rand( 0, count( self::POINTS ) - 1 ) ];
			if ( 'write_fail' === $point && ! $can_lock_out ) {
				$point = 'none';
			}
			if ( Directories::ANSWER_COPY === $answer ) {
				$point = 0 === mt_rand( 0, 1 ) ? 'acknowledged' : 'none';
			}
			if ( 'continue' === $answer ) {
				$point = 'none'; // The notice's own "continue with the original directory", as ReclaimActions runs it.
			}
			$steps[] = $answer . '@' . $point;
			$where   = sprintf( '(seed %d, after %s)', $seed, implode( ', ', $steps ) );
			$this->seen[ $answer . '@' . $point ] = ( $this->seen[ $answer . '@' . $point ] ?? 0 ) + 1;

			$hooks = array();
			$seen  = &$this->seen;
			if ( 'prechecks' === $point ) {
				file_put_contents( $store . '/tmp/working.tmp', 'x' ); // A job working there: the prechecks refuse.
			} elseif ( 'lock_busy' === $point ) {
				file_put_contents( StorageReclaim::lock_path( $store ), "other\n" ); // Another take-over holds the lock.
			} elseif ( in_array( $point, array( 'after_hash', 'lock_lost', 'write_fail' ), true ) ) {
				$hooks['before_rename'] = static function ( string $dir ) use ( $point, &$seen ): void {
					$seen[ 'fired: ' . $point ] = ( $seen[ 'fired: ' . $point ] ?? 0 ) + 1; // It took effect.
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
				$hooks['identity_step'] = static function ( string $step ) use ( $point, &$seen ): void {
					if ( $step === $point ) {
						$seen[ 'fired: ' . $point ] = ( $seen[ 'fired: ' . $point ] ?? 0 ) + 1; // It took effect.
						throw new \RuntimeException( 'died' );
					}
				};
			}
			$shown = null;
			$this->maybe_on_subsite(
				function () use ( $here, $store, $hooks, $answer, $point, &$shown ): void {
					$asking = $this->dirs( $here, $store, $hooks );
					$shown  = $asking->identity_question();
					try {
						if ( 'continue' === $answer ) {
							$result = $asking->reclaim()->reclaim( false );
							if ( $result['ok'] ) {
								$asking->finish_reclaim();
							}
						} elseif ( null !== $shown ) {
							$result = $asking->answer_identity( $answer, $shown['id'] );
							if ( ! $result['ok'] && in_array( $point, array( 'prechecks', 'lock_busy' ), true ) ) {
								$this->seen[ 'refused: ' . $point ] = ( $this->seen[ 'refused: ' . $point ] ?? 0 ) + 1; // It took effect.
							}
						}
					} catch ( \RuntimeException $e ) {
						if ( 'died' !== $e->getMessage() ) {
							throw $e;
						}
					}
				}
			);
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
				// Answered "copy" (possibly dying after the acknowledgement): nothing asked, the directory let go.
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
			$this->next_swap();
		}
		$this->assertSame( array(), array_slice( $found, 0, 10 ), count( $found ) . ' violations' );
		// The controls: each point came up, and sequences ended both ways.
		// Counted where they take effect: the hook fired, the attempt was refused; not merely chosen.
		$points = array( 'same@none', 'refused: prechecks', 'refused: lock_busy', 'fired: after_hash', 'fired: lock_lost', 'fired: taken', 'fired: recorded', 'copy@none', 'fired: acknowledged', 'continue@none', 'done', 'deploy', 'back' );
		if ( ! ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$points[] = 'fired: write_fail';
		}
		if ( is_multisite() ) {
			$points[] = 'subsite';
		}
		foreach ( $points as $point ) {
			$this->assertGreaterThan( 0, $this->seen[ $point ] ?? 0, 'the control: ' . $point . ' ' . wp_json_encode( $this->seen ) );
		}
	}

	/**
	 * Run $fn as a request of a subsite of the network, half the time on multisite. Nothing is drawn on a single site,
	 * so the sequences there stay as they are.
	 *
	 * @return void
	 */
	private function maybe_on_subsite( callable $fn ): void {
		if ( ! is_multisite() || 0 === mt_rand( 0, 1 ) ) {
			$fn();
			return;
		}
		switch_to_blog( $this->blog );
		try {
			if ( get_current_blog_id() === $this->blog && false === get_option( Directories::OPTION ) && array() !== (array) Options::get( Directories::OPTION, array() ) ) {
				$this->seen['subsite'] = ( $this->seen['subsite'] ?? 0 ) + 1; // It took effect: a subsite's request, reading the network's state.
			}
			$fn();
		} finally {
			restore_current_blog();
		}
	}

	private function in_default( string $site, string $content, array $extra = array() ): Directories {
		return new Directories( $this->default_context( $site, $content, $extra ) );
	}

	/** The site's directories so far. */
	private static function taken( string $content ): array {
		return glob( $content . '/' . Directories::DIR_PREFIX . '*', GLOB_ONLYDIR ) ?: array();
	}

	/**
	 * Another place with the same database (a copy of it) gets none of the site's directories and runs none of its jobs.
	 * The state is put back and the copy's own directory removed afterwards, so the sequence goes on as if it had not
	 * asked.
	 *
	 * @param int[] $jobs Job IDs.
	 * @return string[]
	 */
	private function copy_check( string $tag, string $content, string $install_id, array $jobs, string $where ): array {
		$found = array();
		$saved = Options::get( Directories::OPTION, array() );
		$taken = self::taken( $content );
		if ( ! is_dir( $this->root . '/' . $tag . '/copy/wp-includes' ) ) {
			mkdir( $this->root . '/' . $tag . '/copy/wp-includes', 0755, true );
		}
		$copy = $this->in_default( $tag . '/copy', $content );
		$base = $copy->base();
		if ( $install_id === (string) $copy->state()['install_id'] && '' !== $base ) {
			$this->seen['default copy: read the database, took a directory'] = ( $this->seen['default copy: read the database, took a directory'] ?? 0 ) + 1;
		}
		if ( in_array( $base, $taken, true ) ) {
			$found[] = sprintf( 'copy %s: it got a directory of the site', $where );
		}
		foreach ( $jobs as $id ) {
			if ( $this->runs_with( $id, $this->in_default( $tag . '/copy', $content ) ) ) {
				$found[] = sprintf( 'copy %s: it runs job %d', $where, $id );
			}
		}
		Options::set( Directories::OPTION, $saved );
		foreach ( array_diff( self::taken( $content ), $taken ) as $new ) {
			Sandbox::remove( $new );
		}
		return $found;
	}

	/**
	 * Check a fresh request at $here (and another place): see the class docblock. $final also resolves what is left and
	 * checks where that ends.
	 *
	 * @return array{0: string[], 1: string} Violations, and the state's kind (done, waiting, lost, kept).
	 */
	private function default_check( string $tag, string $here, string $content, array $site, string $where, bool $final ): array {
		$found = $this->copy_check( $tag, $content, $site['install_id'], array( $site['held'], $site['plain'] ), $where );
		$next  = $this->in_default( $here, $content );
		$base  = $next->base();
		$state = $next->state();
		$home  = $site['one'] === $base && $site['token'] === (string) $state['token'];
		foreach ( array( $site['held'], $site['plain'] ) as $id ) {
			if ( ! $home && $this->runs_with( $id, $this->in_default( $here, $content ) ) ) {
				$found[] = sprintf( 'runs %s: job %d runs without the original directory and token', $where, $id );
			}
		}
		if ( $home && empty( $state['clone_detected'] ) ) {
			if ( ! $this->runs_with( $site['held'], $this->in_default( $here, $content ) ) ) {
				$found[] = sprintf( 'done %s: the job holding the site does not run', $where );
			}
			return array( $found, 'done' );
		}
		$waiting = ! empty( $state['clone_detected'] ) && $site['one'] === (string) $state['previous_path'] && in_array( $site['token'], (array) $state['reclaim_tokens'], true );
		$lost    = isset( $state['lost_tokens'][ $site['token'] ] );
		$kept    = ! $waiting && ! $lost && empty( $state['clone_detected'] ) && in_array( $site['token'], (array) $state['copied_tokens'], true );
		if ( ! $waiting && ! $lost && ! $kept ) {
			$found[] = sprintf( 'unexplained %s: not done, nothing waiting, nothing recorded', $where );
			return array( $found, '' );
		}
		$kind = $waiting ? 'waiting' : ( $lost ? 'lost' : 'kept' );
		if ( ! $final ) {
			return array( $found, $kind );
		}
		if ( 'waiting' === $kind ) {
			// A "continue" with nothing in the way finishes it.
			$last   = $this->in_default( $here, $content );
			$result = $last->reclaim()->reclaim( false );
			if ( $result['ok'] ) {
				$last->finish_reclaim();
			}
			$after = $this->in_default( $here, $content );
			if ( ! $result['ok'] || $site['one'] !== $after->base() || $site['token'] !== (string) $after->state()['token'] || ! $this->runs_with( $site['held'], $this->in_default( $here, $content ) ) ) {
				$found[] = sprintf( 'no way back %s: %s', $where, $result['message'] );
			}
			return array( $found, $kind );
		}
		if ( ! empty( $state['clone_detected'] ) ) {
			// The latest move resolved: "continue" with the directory before.
			$last   = $this->in_default( $here, $content );
			$result = $last->reclaim()->reclaim( false );
			if ( ! $result['ok'] ) {
				$found[] = sprintf( 'latest move %s: %s', $where, $result['message'] );
				return array( $found, $kind );
			}
			$last->finish_reclaim();
		}
		self::repo( $this->in_default( $here, $content ) )->settle_storage();
		$plain = self::repo( $this->in_default( $here, $content ) )->find( $site['plain'] );
		$held  = self::repo( $this->in_default( $here, $content ) )->find( $site['held'] );
		if ( null === $plain || Job::FAILED !== $plain->status || ( 'lost' === $kind && false === strpos( (string) $plain->last_error, self::REASON ) ) ) {
			$found[] = sprintf( '%s %s: the job that does not hold the site is not failed%s', $kind, $where, 'lost' === $kind ? ' with the reason' : '' );
		}
		if ( null === $held || Job::RUNNING !== $held->status || $this->runs_with( $site['held'], $this->in_default( $here, $content ) ) ) {
			$found[] = sprintf( '%s %s: the job holding the site is not left as it is, or runs', $kind, $where );
		}
		return array( $found, $kind );
	}

	/**
	 * One sequence in the default directory; the invariant's violations.
	 *
	 * @return string[]
	 */
	private function default_sequence( int $seed ): array {
		global $wpdb;
		mt_srand( $seed );
		$tag     = 'def' . $seed;
		$content = $this->root . '/' . $tag . '/content';
		foreach ( array( 1, 2 ) as $release ) {
			mkdir( $this->root . '/' . $tag . '/releases/' . $release . '/wp-includes', 0755, true );
		}
		mkdir( $content );
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DELETE FROM ' . Schema::jobs_table() );

		// The original at release 1, with a job holding the site and one that does not; the move to release 2.
		$first = $this->in_default( $tag . '/releases/1', $content );
		$one   = $first->base();
		if ( '' === $one ) {
			return array( 'setup: ' . $first->last_error() );
		}
		$held  = $this->held_job( $this->default_context( $tag . '/releases/1', $content ) );
		$plain = self::repo( $first )->create( 'plain' )->id;
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $plain ) ); // A job that does not hold the site.
		$site    = array(
			'one'        => $one,
			'token'      => (string) $first->state()['token'],
			'install_id' => (string) $first->state()['install_id'],
			'held'       => $held,
			'plain'      => $plain,
		);
		$release = 2;
		$here    = $tag . '/releases/2';
		$moved   = $this->in_default( $here, $content );
		$moved->base();
		if ( empty( $moved->state()['clone_detected'] ) ) {
			return array( 'setup: the move is not detected' );
		}
		$can_lock_out = ! ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() );
		$found        = array();
		$steps        = array();
		$kind         = '';
		for ( $i = 0, $n = mt_rand( 1, 4 ); $i < $n; $i++ ) {
			if ( 0 === mt_rand( 0, 2 ) ) {
				if ( 0 === mt_rand( 0, 2 ) ) {
					$here    = $tag . '/releases/' . mt_rand( 1, $release );
					$steps[] = 'back to ' . basename( $here );
					$this->seen['default back'] = ( $this->seen['default back'] ?? 0 ) + 1;
				} else {
					++$release;
					mkdir( $this->root . '/' . $tag . '/releases/' . $release . '/wp-includes', 0755, true );
					$here    = $tag . '/releases/' . $release;
					$steps[] = 'deploy';
					$this->seen['default deploy'] = ( $this->seen['default deploy'] ?? 0 ) + 1;
				}
			}
			$action = 0 === mt_rand( 0, 9 ) ? 'keep' : 'continue';
			$point  = 'keep' === $action ? 'none' : self::CONTINUE_POINTS[ mt_rand( 0, count( self::CONTINUE_POINTS ) - 1 ) ];
			if ( 'write_fail' === $point && ! $can_lock_out ) {
				$point = 'none';
			}
			$steps[] = $action . '@' . $point;
			$where   = sprintf( '(seed %d, after %s)', $seed, implode( ', ', $steps ) );
			$target  = '';
			$seen    = &$this->seen;
			$this->maybe_on_subsite(
				function () use ( $here, $content, $action, $point, &$target, &$seen ): void {
					$probe = $this->in_default( $here, $content );
					$probe->base();
					if ( empty( $probe->state()['clone_detected'] ) ) {
						return; // Nothing to continue or keep: the notice is not shown.
					}
					$target = (string) $probe->state()['previous_path'];
					if ( 'keep' === $action ) {
						$probe->acknowledge_clone(); // The notice dismissed: the new directory kept.
						$seen['default kept'] = ( $seen['default kept'] ?? 0 ) + 1;
						return;
					}
					$hooks = array();
					if ( 'prechecks' === $point ) {
						file_put_contents( $target . '/tmp/working.tmp', 'x' );
					} elseif ( 'lock_busy' === $point ) {
						file_put_contents( StorageReclaim::lock_path( $target ), "other\n" );
					} elseif ( in_array( $point, array( 'after_hash', 'lock_lost', 'write_fail' ), true ) ) {
						$hooks['before_rename'] = static function ( string $dir ) use ( $point, &$seen ): void {
							$seen[ 'default fired: ' . $point ] = ( $seen[ 'default fired: ' . $point ] ?? 0 ) + 1; // It took effect.
							if ( 'after_hash' === $point ) {
								throw new \RuntimeException( 'died' );
							}
							if ( 'lock_lost' === $point ) {
								file_put_contents( StorageReclaim::lock_path( $dir ), "intruder\n" );
								return;
							}
							chmod( $dir, 0555 ); // The marker cannot be written.
						};
					}
					$asking = $this->in_default( $here, $content, $hooks );
					try {
						$result = $asking->reclaim()->reclaim( false );
						if ( ! $result['ok'] && in_array( $point, array( 'prechecks', 'lock_busy' ), true ) ) {
							$seen[ 'default refused: ' . $point ] = ( $seen[ 'default refused: ' . $point ] ?? 0 ) + 1; // It took effect.
						}
						if ( $result['ok'] && 'renamed' === $point ) {
							$seen['default fired: renamed'] = ( $seen['default fired: renamed'] ?? 0 ) + 1; // The request dies before the state.
						} elseif ( $result['ok'] ) {
							$asking->finish_reclaim();
						}
					} catch ( \RuntimeException $e ) {
						if ( 'died' !== $e->getMessage() ) {
							throw $e;
						}
					}
				}
			);
			// The interference ends; time passes (a lock left behind expires).
			if ( '' !== $target && is_dir( $target ) ) {
				chmod( $target, 0755 );
				if ( is_file( $target . '/tmp/working.tmp' ) ) {
					Sandbox::remove( $target . '/tmp/working.tmp' );
				}
				clearstatcache( true );
				if ( is_file( StorageReclaim::lock_path( $target ) ) ) {
					touch( StorageReclaim::lock_path( $target ), time() - 3600 );
				}
			}
			list( $violations, $kind ) = $this->default_check( $tag, $here, $content, $site, $where, false );
			$found                     = array_merge( $found, $violations );
			if ( array() !== $violations || 'done' === $kind || 'kept' === $kind ) {
				break;
			}
		}
		if ( array() !== $found ) {
			return $found;
		}
		list( $violations, $kind ) = $this->default_check( $tag, $here, $content, $site, sprintf( '(seed %d, at the end, after %s)', $seed, implode( ', ', $steps ) ), true );
		$this->seen[ 'default ended: ' . $kind ] = ( $this->seen[ 'default ended: ' . $kind ] ?? 0 ) + 1;
		return $violations;
	}

	public function test_in_the_default_directory_nothing_goes_elsewhere_and_nothing_waits_unexplained(): void {
		$count = max( 1, (int) ( getenv( 'WPCHECKPOINT_IDENTITY_SEQUENCES' ) ?: 200 ) );
		$found = array();
		for ( $seed = 1; $seed <= $count; $seed++ ) {
			$found = array_merge( $found, $this->default_sequence( $seed ) );
			$this->next_swap();
		}
		$this->assertSame( array(), array_slice( $found, 0, 10 ), count( $found ) . ' violations' );
		// The controls, counted where they take effect: each point, each way a sequence ends, the copy's request.
		$points = array( 'default refused: prechecks', 'default refused: lock_busy', 'default fired: after_hash', 'default fired: lock_lost', 'default fired: renamed', 'default kept', 'default deploy', 'default back', 'default ended: done', 'default ended: waiting', 'default ended: lost', 'default ended: kept', 'default copy: read the database, took a directory' );
		if ( ! ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$points[] = 'default fired: write_fail';
		}
		if ( is_multisite() ) {
			$points[] = 'subsite';
		}
		foreach ( $points as $point ) {
			$this->assertGreaterThan( 0, $this->seen[ $point ] ?? 0, 'the control: ' . $point . ' ' . wp_json_encode( $this->seen ) );
		}
	}
}
