<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\OwnerMarker;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * Whose jobs a copy of the site runs, on generated sequences of what happens to a copy: requests, its operator
 * emptying the copied storage directory, acknowledging the clone notice ("keep the new directory"), moving the custom
 * directory or pointing it back at the original's, the original's ABSPATH vanishing (a copy on another host), and the
 * copy starting restores of its own.
 *
 * Invariants, after every action, as the copy's next request sees them:
 * - I1: a job the original started (one that holds the site changed, and one that does not) is never let through
 *   by the gate nor taken by acquire() on the copy;
 * - I2: a job that holds the site, started by the copy, is let through and taken.
 * And on sequences of the original alone (original_sequence()): its requests and jobs under three spellings of its
 * ABSPATH (the release, as the web server resolves it; the deployment's "current" link, as WP-CLI's --path may give
 * it; a link inside a directory that becomes unsearchable, where realpath() fails), deployments pointing "current" at
 * a new release, "continue with the original directory", and a start from what the version before wrote:
 * - I3: whenever no move waits for the administrator, every job the original started is let through and taken,
 *   under every spelling from which the answer can be told;
 * - I4: a request that cannot tell whether the state is its own (realpath() fails) chooses, records and saves nothing;
 * - I5: continuing with the original directory is never refused as the directory having been claimed since, also when
 *   an earlier attempt died after rewriting the marker;
 * - I6: a request that keeps the directory it had (marked for it, no move detected) never sets its tokens aside as
 *   copied (a web server worker still on the release before a deployment, among others).
 * The realpath cache is never cleared by the test: a web server worker keeps it across requests.
 *
 * The copy and the original share one stored state here, as a copy's database starts as the original's: the original
 * does not act once the copy does. Not covered, as not seen by the plugin: a copy at the very same ABSPATH on another
 * host.
 *
 * The rule under test (Directories): the tokens held when a clone is detected are the original's, and none of them is
 * ever this installation's again. Size: WPCHECKPOINT_CLONE_SEQUENCES sequences from seed
 * WPCHECKPOINT_CLONE_SEQUENCES_SEED (default 300 from 1).
 */
final class CloneTokensTest extends WP_UnitTestCase {

	/** @var string The test's directory; '' before set_up() made it. */
	private $root = '';

	/** @var array<string, int> How often each case the invariants speak of came up (the controls). */
	private $seen = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$this->root = Sandbox::make( 'clone-tokens' );
	}

	public function tear_down(): void {
		global $wpdb;
		// Before the DROP TABLE, which commits them.
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		if ( '' !== $this->root ) {
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	/**
	 * The directories of an installation at an ABSPATH.
	 */
	private function dirs( string $site, string $custom ): Directories {
		return new Directories(
			array(
				'is_web_request' => false,
				'document_root'  => '',
				'abspath'        => $this->root . '/' . $site . '/',
				'content_dir'    => $this->root . '/content',
				'custom_dir'     => $custom,
			)
		);
	}

	private static function repo( Directories $dirs ): JobRepository {
		return new JobRepository( $dirs, null, static function (): int {
			return time();
		} );
	}

	private static function hold( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING ), array( 'id' => $id ) );
	}

	/**
	 * Whether the copy's next request lets a job through and takes it (the lock given back after).
	 */
	private function runs_on_copy( int $id, string $site, string $custom, bool $both = false ): bool {
		global $wpdb;
		$repo = self::repo( $this->dirs( $site, $custom ) );
		$job  = $repo->find( $id );
		$let  = null !== $job && $repo->gate( $job )['allowed'];
		$took = null !== $repo->acquire( $id );
		$wpdb->update( Schema::jobs_table(), array( 'lock_token' => '', 'locked_until' => 0 ), array( 'id' => $id ) );
		// Run: let through and taken (a driver needs both). Not run: neither (either alone would be a way in).
		return $both ? $let && $took : $let || $took;
	}

	/**
	 * Run one sequence; the invariants it breaks.
	 *
	 * @return string[]
	 */
	private function sequence( int $seed ): array {
		global $wpdb;
		mt_srand( $seed );
		$custom_mode = 1 === mt_rand( 0, 1 );
		$tag         = 'seq' . $seed;
		foreach ( array( 'a', 'b' ) as $site ) {
			mkdir( $this->root . '/' . $tag . '/' . $site . '/wp-includes', 0755, true );
		}
		$store  = $custom_mode ? $this->root . '/' . $tag . '/store' : '';
		$custom = $store;
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DELETE FROM ' . Schema::jobs_table() );

		// The original: its directory, a job that holds the site and one that does not.
		$original = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/' . $tag . '/a/', 'content_dir' => $this->root . '/content', 'custom_dir' => $store ) );
		if ( '' === $original->base() ) {
			return array( 'setup: the original has no directory: ' . $original->last_error() );
		}
		$first    = $original->base();
		$repo     = self::repo( $original );
		$held     = $repo->create( 'plain' );
		$plain    = $repo->create( 'plain' );
		self::hold( $held->id );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $plain->id ) );
		$theirs   = array( $held->id => 'the original\'s job that holds the site', $plain->id => 'the original\'s job' );
		$ours     = array();
		$found    = array();
		$actions  = $custom_mode ? array( 'request', 'empty', 'acknowledge', 'move', 'back', 'start', 'start', 'vanish', 'relocate', 'continue' ) : array( 'request', 'acknowledge', 'start', 'start', 'vanish', 'relocate', 'continue' );
		$moved    = array(); // The copy's jobs from before it moved: they follow the move (continue gives them back).
		$steps    = array();
		$site_dir = $tag . '/b';
		for ( $i = 0, $n = mt_rand( 1, 8 ); $i < $n; $i++ ) {
			$action  = $actions[ mt_rand( 0, count( $actions ) - 1 ) ];
			$steps[] = $action;
			$copy    = $this->dirs( $site_dir, $custom );
			switch ( $action ) {
				case 'request':
					$copy->base();
					break;
				case 'empty': // The copy's operator empties the directory the notice said is another installation's.
					if ( is_dir( $store ) ) {
						Sandbox::remove( $store );
						mkdir( $store );
					}
					break;
				case 'acknowledge':
					$copy->acknowledge_clone();
					$moved = array();
					break;
				case 'relocate': // The copy itself moves to another directory (its own move, or a deployment).
					$site_dir = $tag . '/b' . $i;
					mkdir( $this->root . '/' . $site_dir . '/wp-includes', 0755, true );
					$moved = $ours; // What an earlier move set aside, never continued from, stays aside (the copy moved on).
					$ours  = array();
					break;
				case 'continue': // Its administrator continues with the directory it had before it moved.
					$copy->base();
					$state = $copy->state();
					if ( empty( $state['clone_detected'] ) || '' === (string) $state['previous_path'] || Paths::same_location( (string) $state['previous_path'], $first ) ) {
						break; // Not after a move of its own (the original's directory: that would be the copy claiming to be the original).
					}
					foreach ( glob( rtrim( (string) $state['previous_path'], '/' ) . '/tmp/job-*.lock' ) ?: array() as $lock ) {
						Sandbox::remove( $lock );
					}
					if ( $copy->reclaim()->reclaim( false )['ok'] ) {
						$copy->finish_reclaim();
						$ours  = $ours + $moved;
						$moved = array();
						$this->seen['a copy continued after its own move'] = ( $this->seen['a copy continued after its own move'] ?? 0 ) + 1;
					}
					break;
				case 'move':
					$custom = $this->root . '/' . $tag . '/store-' . $i;
					break;
				case 'back':
					$custom = $store;
					break;
				case 'vanish': // The copy is on another host: the original's ABSPATH is nowhere here.
					if ( is_dir( $this->root . '/' . $tag . '/a' ) ) {
						Sandbox::remove( $this->root . '/' . $tag . '/a' );
						$this->seen['vanished'] = ( $this->seen['vanished'] ?? 0 ) + 1;
					}
					break;
				case 'start':
					if ( '' !== $copy->base() ) {
						$job = self::repo( $copy )->create( 'plain' );
						self::hold( $job->id );
						$ours[ $job->id ] = 'the copy\'s job started after "' . implode( ', ', $steps ) . '"';
					}
					break;
			}
			foreach ( $theirs as $id => $what ) {
				if ( $this->runs_on_copy( $id, $site_dir, $custom ) ) {
					$found[] = sprintf( 'I1 (seed %d, %s, after %s): %s runs on the copy', $seed, $custom_mode ? 'custom' : 'default', implode( ', ', $steps ), $what );
				}
			}
			foreach ( $ours as $id => $what ) {
				if ( ! $this->runs_on_copy( $id, $site_dir, $custom, true ) ) {
					$found[] = sprintf( 'I2 (seed %d, %s, after %s): %s does not run on the copy', $seed, $custom_mode ? 'custom' : 'default', implode( ', ', $steps ), $what );
				}
			}
		}
		return $found;
	}

	/**
	 * The ABSPATH of the original under one of its spellings: the release "current" points at, as the web server
	 * resolves it; "current" itself, as WP-CLI's --path may give it; and a link to "current" inside a directory that
	 * may be made unsearchable, where realpath() fails.
	 */
	private function spelled( string $tag, string $as, int $release ): string {
		$paths = array(
			'release'  => 'releases/' . $release,
			'previous' => 'releases/' . max( 1, $release - 1 ), // A web server worker still on the release before.
			'current' => 'current',
			'locked'  => 'locked/site',
		);
		return $tag . '/' . $paths[ $as ];
	}

	/**
	 * A marker as the version before wrote it: the hash of ABSPATH as spelled (computed here, not by the code under test).
	 */
	private static function old_marker( string $id, string $abspath ): string {
		return $id . "\n" . hash( 'sha256', rtrim( str_replace( '\\', '/', $abspath ), '/' ) ) . "\n";
	}

	/**
	 * Make the original's state and marker what the version before left: the marker hashing ABSPATH as it was spelled
	 * when it was written, and no hash recorded in the state.
	 */
	private function downgrade( string $abspath ): void {
		$state = Options::get( Directories::OPTION, array() );
		$dir   = rtrim( (string) $state['path'], '/\\' );
		file_put_contents( $dir . '/.wpcheckpoint-owner', self::old_marker( (string) $state['install_id'], $abspath ) );
		unset( $state['marker_hash'], $state['previous_marker_hash'], $state['abspath_real'], $state['previous_abspath_real'], $state['reclaim_tokens'], $state['reclaim_marker_hash'] ); // Keys the version before did not have.
		Options::set( Directories::OPTION, $state );
	}

	/**
	 * What a request can change: the stored state and the directories in the content and custom places.
	 *
	 * @return array{state: mixed, dirs: string[]}
	 */
	private function footprint( string $tag ): array {
		$dirs = array_merge( glob( $this->root . '/content/*' ) ?: array(), glob( $this->root . '/' . $tag . '/store*' ) ?: array() );
		sort( $dirs );
		return array(
			'state' => Options::get( Directories::OPTION, array() ),
			'dirs'  => $dirs,
		);
	}

	/**
	 * Run one sequence of the original alone; the invariants it breaks. It is reached under three spellings of its
	 * ABSPATH (spelled()); a deployment points "current" at a new release; the directory holding the third spelling
	 * becomes unsearchable and searchable again; the administrator continues with the original directory when a
	 * move is detected; and it may start from a marker and state the version before wrote.
	 *
	 * @return string[]
	 */
	private function original_sequence( int $seed ): array {
		global $wpdb;
		mt_srand( $seed );
		$custom_mode = 1 === mt_rand( 0, 1 );
		$upgraded    = 1 === mt_rand( 0, 1 );
		$tag         = 'orig' . $seed;
		$root        = $this->root . '/' . $tag;
		$release     = 1;
		mkdir( $root . '/releases/1/wp-includes', 0755, true );
		mkdir( $root . '/locked' );
		symlink( $root . '/releases/1', $root . '/current' );
		symlink( $root . '/current', $root . '/locked/site' );
		$custom = $custom_mode ? $root . '/store' : '';
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DELETE FROM ' . Schema::jobs_table() );
		$jobs   = array();
		$found  = array();
		$steps  = array();
		$locked = false;
		// What "continue with the original directory" promises: the jobs held when the move it undoes was detected.
		// A second move detected while one waits (workers of two releases taking turns) sets the first one's aside
		// for good, unless the deployment root is trusted: those jobs are no longer expected to run.
		$mark    = 0;
		$waiting = null;
		$observe = static function () use ( &$jobs, &$mark, &$waiting ): void {
			$state = Options::get( Directories::OPTION, array() );
			if ( empty( $state['clone_detected'] ) ) {
				$waiting = null;
				return;
			}
			if ( $waiting === $state['previous_path'] ) {
				return;
			}
			if ( null !== $waiting ) {
				$jobs = array_values( array_slice( $jobs, $mark ) );
			}
			$mark    = count( $jobs );
			$waiting = $state['previous_path'];
		};
		$mode   = ( $custom_mode ? 'custom' : 'default' ) . ( $upgraded ? ', upgraded' : '' );
		$can_lock = ! ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ); // Root searches every directory.
		try {
			for ( $i = 0, $n = mt_rand( 2, 10 ); $i < $n; $i++ ) {
				$roll = mt_rand( 0, 11 );
				$as   = array( 'release', 'current', 'locked' )[ mt_rand( 0, 2 ) ];
				if ( 0 === $i || $roll < 5 ) {
					$action = ( 0 === $i || 0 === mt_rand( 0, 2 ) ? 'start' : 'request' ) . ' as ' . $as;
				} else {
					$action = array( 5 => 'switch', 6 => 'lock', 7 => 'unlock', 8 => 'reclaim', 9 => 'reclaim', 10 => 'request as previous', 11 => 'reclaim, dying before the state is saved' )[ $roll ];
				}
				$steps[] = $action;
				$where   = sprintf( '(seed %d, %s, after %s)', $seed, $mode, implode( ', ', $steps ) );
				switch ( $action ) {
					case 'switch': // A deployment: a new release, "current" pointed at it.
						++$release;
						mkdir( $root . '/releases/' . $release . '/wp-includes', 0755, true );
						Sandbox::remove( $root . '/current' );
						symlink( $root . '/releases/' . $release, $root . '/current' );
						break; // The realpath cache is not cleared here: a web server worker keeps it across requests.
					case 'lock':
						$locked = $can_lock && chmod( $root . '/locked', 0 );
						break;
					case 'unlock':
						chmod( $root . '/locked', 0755 );
						$locked = false;
						break;
					case 'reclaim': // The administrator continues with the original directory (from the web server).
					case 'reclaim, dying before the state is saved':
						$dirs = $this->dirs( $this->spelled( $tag, 'release', $release ), $custom );
						$dirs->base();
						$observe();
						$state = $dirs->state();
						if ( empty( $state['clone_detected'] ) || '' === (string) $state['previous_path'] ) {
							break;
						}
						// No job is running at the moment of the decision: their lock files are gone.
						foreach ( glob( rtrim( (string) $state['previous_path'], '/' ) . '/tmp/job-*.lock' ) ?: array() as $lock ) {
							Sandbox::remove( $lock );
						}
						$result = $dirs->reclaim()->reclaim( false );
						if ( $result['ok'] && 'reclaim' !== $action ) {
							$this->seen['died after the marker was rewritten'] = ( $this->seen['died after the marker was rewritten'] ?? 0 ) + 1;
						} elseif ( $result['ok'] ) {
							$this->seen['reclaimed'] = ( $this->seen['reclaimed'] ?? 0 ) + 1;
							$dirs->finish_reclaim();
							$observe();
						} elseif ( false !== strpos( $result['message'], 'already claimed' ) || false !== strpos( $result['message'], 'changed in the meantime' ) ) {
							$found[] = sprintf( 'I5 %s: continuing with the original directory is refused: %s', $where, $result['message'] );
						}
						break;
					default:
						list( $what, $as ) = explode( ' as ', $action );
						$site   = $this->spelled( $tag, $as, $release );
						$before = $this->footprint( $tag );
						$dirs   = $this->dirs( $site, $custom );
						$base   = $dirs->base();
						$observe();
						// A request that keeps the directory it had, marked for it, sets none of its tokens aside.
						$now = $this->footprint( $tag )['state'];
						if ( '' !== $base && empty( $now['clone_detected'] ) && ( $before['state']['path'] ?? '' ) === $now['path'] && count( (array) ( $now['copied_tokens'] ?? array() ) ) > count( (array) ( $before['state']['copied_tokens'] ?? array() ) ) ) {
							$found[] = sprintf( 'I6 %s: its own directory kept, its tokens set aside as copied', $where );
						}
						if ( 'previous' === $as && $release > 1 ) {
							$this->seen['a worker on the release before'] = ( $this->seen['a worker on the release before'] ?? 0 ) + 1;
						}
						// Its ABSPATH, or the one the state was written under, cannot be resolved: whether the state is
						// this installation's cannot be told from the paths. Never a clone or a move for that.
						$blind = $locked && ( 'locked' === $as || $this->root . '/' . $this->spelled( $tag, 'locked', $release ) . '/' === (string) ( $before['state']['abspath'] ?? '' ) );
						if ( $blind ) {
							$this->seen[ '' === $base ? 'blind, not told' : 'blind, told by the marker' ] = ( $this->seen[ '' === $base ? 'blind, not told' : 'blind, told by the marker' ] ?? 0 ) + 1;
							$after = $this->footprint( $tag )['state'];
							foreach ( array( 'token', 'path', 'copied_tokens', 'clone_detected' ) as $key ) {
								if ( ( $before['state'][ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
									$found[] = sprintf( 'I4 %s: a request that could not resolve an ABSPATH changed %s', $where, $key );
								}
							}
						}
						if ( '' === $base ) {
							$error = $dirs->last_error();
							if ( false !== strpos( $error, 'cannot be told' ) ) {
								if ( ! $locked ) {
									$found[] = sprintf( 'I4 %s: could not tell with every directory searchable: %s', $where, $error );
								} elseif ( $this->footprint( $tag ) !== $before ) {
									$found[] = sprintf( 'I4 %s: a request that could not tell changed the state or the directories', $where );
								}
							} elseif ( empty( $dirs->state()['clone_detected'] ) ) {
								$found[] = sprintf( 'I3 %s: no directory: %s', $where, $error );
							}
							break;
						}
						if ( $upgraded && 0 === $i ) {
							$this->downgrade( $this->root . '/' . $site . '/' );
						}
						if ( 'start' === $what ) {
							$job = self::repo( $dirs )->create( 'plain' );
							self::hold( $job->id );
							$jobs[] = $job->id;
						}
						break;
				}
				// Whenever no move is waiting for the administrator: every job the original started runs, under every
				// spelling from which the answer can be told.
				if ( ! empty( Options::get( Directories::OPTION, array() )['clone_detected'] ) ) {
					continue;
				}
				$order = array( 'release', 'current', 'locked' );
				shuffle( $order ); // Seeded (mt_srand()): which spelling asks first varies, reproducibly.
				foreach ( $jobs as $id ) {
					foreach ( $order as $as ) {
						$site   = $this->spelled( $tag, $as, $release );
						$before = Options::get( Directories::OPTION, array() );
						$blind  = $locked && ( 'locked' === $as || $this->root . '/' . $this->spelled( $tag, 'locked', $release ) . '/' === (string) ( $before['abspath'] ?? '' ) );
						$probe  = $this->dirs( $site, $custom );
						$here   = $probe->base(); // The request first: it may be the one that sees a deployment.
						$observe();
						$after  = Options::get( Directories::OPTION, array() );
						if ( $blind ) {
							$case                = ( '' === $here ? 'blind, not told' : 'blind, told by the marker' ) . ( 'locked' === $as ? ', as the unresolvable spelling' : '' );
							$this->seen[ $case ] = ( $this->seen[ $case ] ?? 0 ) + 1;
							foreach ( array( 'token', 'path', 'copied_tokens', 'clone_detected' ) as $key ) {
								if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
									$found[] = sprintf( 'I4 %s: a request as %s that could not resolve an ABSPATH changed %s', $where, $as, $key );
								}
							}
						}
						if ( ! empty( $after['clone_detected'] ) ) {
							continue 3;
						}
						if ( '' === $here ) {
							if ( ! $blind ) {
								$found[] = sprintf( 'I3 %s: no directory as %s: %s', $where, $as, $probe->last_error() );
							}
							continue; // Could not tell, and changed nothing (I4).
						}
						if ( $upgraded && 'current' === $as && false !== strpos( (string) @file_get_contents( rtrim( (string) Options::get( Directories::OPTION, array() )['path'], '/' ) . '/.wpcheckpoint-owner' ), hash( 'sha256', $root . '/current' ) ) ) {
							$this->seen['an old marker under a link, matched'] = ( $this->seen['an old marker under a link, matched'] ?? 0 ) + 1;
						}
						if ( ! $this->runs_on_copy( $id, $site, $custom, true ) ) {
							$found[] = sprintf( 'I3 %s: the original\'s job %d does not run as %s', $where, $id, $as );
						}
					}
				}
			}
		} finally {
			chmod( $root . '/locked', 0755 );
			clearstatcache( true );
		}
		return $found;
	}

	public function test_the_original_runs_its_jobs_under_every_spelling_across_deployments_and_upgrades(): void {
		Schema::ensure();
		$count = max( 1, (int) ( getenv( 'WPCHECKPOINT_CLONE_SEQUENCES' ) ?: 300 ) / 3 );
		$first = (int) ( getenv( 'WPCHECKPOINT_CLONE_SEQUENCES_SEED' ) ?: 1 );
		$found = array();
		for ( $seed = $first; $seed < $first + $count; $seed++ ) {
			$found = array_merge( $found, $this->original_sequence( $seed ) );
		}
		$this->assertSame( array(), array_slice( $found, 0, 10 ), count( $found ) . ' violations' );
		global $wpdb;
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::jobs_table() ), 'the control: there were jobs to check' );
		// The controls: each case came up (not as root, which searches every directory).
		$cases = array( 'reclaimed', 'an old marker under a link, matched', 'died after the marker was rewritten', 'a worker on the release before' );
		if ( ! ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$cases = array_merge( $cases, array( 'blind, not told', 'blind, told by the marker', 'blind, not told, as the unresolvable spelling' ) );
		}
		foreach ( $cases as $case ) {
			$this->assertGreaterThan( 0, $this->seen[ $case ] ?? 0, 'the control: ' . $case . ' ' . wp_json_encode( $this->seen ) );
		}
	}

	/**
	 * A deployment's layout: releases/1 and releases/2, "current" pointing at the first.
	 */
	private function deployment(): string {
		$root = $this->root . '/deploy';
		mkdir( $root . '/releases/1/wp-includes', 0755, true );
		mkdir( $root . '/releases/2/wp-includes', 0755, true );
		symlink( $root . '/releases/1', $root . '/current' );
		return $root;
	}

	public function test_continuing_with_the_original_directory_after_current_points_at_a_new_release(): void {
		$root = $this->deployment();
		// Chosen from WP-CLI with --path through the link.
		$cli  = $this->dirs( 'deploy/current', '' );
		$base = $cli->base();
		$this->assertNotSame( '', $base, $cli->last_error() );
		// The deployment points "current" at the next release; the web server resolves it.
		Sandbox::remove( $root . '/current' );
		symlink( $root . '/releases/2', $root . '/current' );
		$web = $this->dirs( 'deploy/releases/2', '' );
		$this->assertNotSame( $base, $web->base(), 'the control: the move is detected' );
		$this->assertTrue( $web->state()['clone_detected'] );
		$result = $web->reclaim()->reclaim( false );
		$this->assertTrue( $result['ok'], $result['message'] );
		$web->finish_reclaim();
		$this->assertSame( $base, $this->dirs( 'deploy/releases/2', '' )->base(), 'the original directory is in use again' );
		$this->assertSame( $base, $this->dirs( 'deploy/current', '' )->base(), 'under either spelling' );
	}

	public function test_a_marker_written_before_under_a_link_is_still_this_installations(): void {
		$this->deployment();
		$cli   = $this->dirs( 'deploy/current', '' );
		$base  = $cli->base();
		$token = (string) $cli->state()['token'];
		$this->assertNotSame( '', $base, $cli->last_error() );
		$this->downgrade( $this->root . '/deploy/current/' );
		$this->assertStringContainsString( hash( 'sha256', $this->root . '/deploy/current' ), (string) file_get_contents( $base . '/.wpcheckpoint-owner' ), 'the control: the marker is as the version before wrote it' );
		$again = $this->dirs( 'deploy/current', '' );
		$this->assertSame( $base, $again->base(), $again->last_error() );
		$this->assertFalse( $again->state()['clone_detected'], 'no move nor clone' );
		$this->assertSame( $token, (string) $again->state()['token'] );
		$this->assertStringContainsString( hash( 'sha256', $this->root . '/deploy/current' ), (string) file_get_contents( $base . '/.wpcheckpoint-owner' ), 'and the marker is left as it is' );
	}

	public function test_an_abspath_that_cannot_be_resolved_changes_nothing(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root searches every directory.' );
		}
		$root = $this->deployment();
		mkdir( $root . '/locked' );
		symlink( $root . '/current', $root . '/locked/site' );
		$empty = $this->footprint( 'deploy' );
		$cli   = $this->dirs( 'deploy/locked/site', '' );
		$base  = $cli->base();
		$this->assertNotSame( '', $base, $cli->last_error() );
		$this->assertNotSame( $empty, $this->footprint( 'deploy' ), 'the control: a request that decides is seen to' );
		chmod( $root . '/locked', 0 );
		try {
			// The state's ABSPATH cannot be resolved; the directory it names carries this installation's marker for
			// this one (written where it resolved): that shows the state to be its own.
			$this->assertSame( $base, $this->dirs( 'deploy/releases/1', '' )->base(), 'the marker tells' );
		} finally {
			chmod( $root . '/locked', 0755 );
		}
		$this->assertSame( $base, $this->dirs( 'deploy/locked/site', '' )->base() );
		// This request's own ABSPATH cannot be resolved, the state was written under that very spelling, and the
		// marker holds the resolved form: whether it is this ABSPATH's cannot be told.
		chmod( $root . '/locked', 0 );
		try {
			$before = $this->footprint( 'deploy' );
			$blind  = $this->dirs( 'deploy/locked/site', '' );
			$this->assertSame( '', $blind->base() );
			$this->assertStringContainsString( 'cannot be told', $blind->last_error() );
			$this->assertSame( $before, $this->footprint( 'deploy' ), 'nothing chosen, recorded or saved' );
		} finally {
			chmod( $root . '/locked', 0755 );
		}
		$this->downgrade( $root . '/locked/site/' ); // A marker that holds the spelling only: nothing else says whose it is.
		chmod( $root . '/locked', 0 );
		try {
			$before = $this->footprint( 'deploy' );
			$web    = $this->dirs( 'deploy/releases/1', '' );
			$this->assertSame( '', $web->base() );
			$this->assertStringContainsString( 'cannot be told', $web->last_error() );
			$this->assertSame( $before, $this->footprint( 'deploy' ), 'no token, no directory, nothing saved' );
		} finally {
			chmod( $root . '/locked', 0755 );
		}
		$this->assertSame( $base, $this->dirs( 'deploy/locked/site', '' )->base(), 'searchable again: as before' );
	}

	public function test_a_copy_never_takes_back_a_copied_restores_token(): void {
		global $wpdb;
		Schema::ensure();
		foreach ( array( 'a', 'b' ) as $site ) {
			mkdir( $this->root . '/fixed/' . $site . '/wp-includes', 0755, true );
		}
		$store    = $this->root . '/fixed/store';
		$original = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/fixed/a/', 'content_dir' => $this->root . '/content', 'custom_dir' => $store ) );
		$this->assertSame( $store, $original->base(), $original->last_error() );
		$copied = (string) $original->state()['token'];
		$plain  = self::repo( $original )->create( 'plain' );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $plain->id ) );
		// A restore of the original's, copied with the database, keeps its files in the original's directory.
		$restores = static function () use ( $store, $copied ): array {
			return array(
				array(
					'path'  => $store,
					'token' => $copied,
				),
			);
		};
		$copy_at = function ( string $custom ) use ( $restores ): Directories {
			return new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/fixed/b/', 'content_dir' => $this->root . '/content', 'custom_dir' => $custom, 'restores' => $restores ) );
		};
		// The copy's operator removes the copied directory (a restore's files there keep the copy from moving while it
		// is there), moves the copy elsewhere, then makes the directory again, empty, and names it.
		Sandbox::remove( $store );
		$elsewhere = $this->root . '/fixed/store-2';
		$moved     = $copy_at( $elsewhere );
		$this->assertSame( $elsewhere, $moved->base(), 'the control: the copy has a directory of its own: ' . $moved->last_error() );
		mkdir( $store );
		$copy = $copy_at( $store );
		// Not taken back with the restore's token, the original's: the directory of a restore not this
		// installation's is not used (the refusal of a restore's directory it cannot confirm as its own).
		$this->assertSame( '', $copy->base() );
		$this->assertStringContainsString( 'A restore is in progress in a storage directory', $copy->last_error() );
		$this->assertNotSame( $copied, (string) $copy->state()['token'], 'the restore\'s token, the original\'s, is not taken back' );
		$this->assertFalse( $this->runs_on_copy( $plain->id, 'fixed/b', $store ), 'nor the original\'s job run' );
	}

	public function test_a_copy_never_runs_the_originals_jobs_and_always_runs_its_own(): void {
		Schema::ensure();
		$count  = max( 1, (int) ( getenv( 'WPCHECKPOINT_CLONE_SEQUENCES' ) ?: 300 ) );
		$first  = (int) ( getenv( 'WPCHECKPOINT_CLONE_SEQUENCES_SEED' ) ?: 1 );
		$found  = array();
		$starts = 0;
		for ( $seed = $first; $seed < $first + $count; $seed++ ) {
			$found = array_merge( $found, $this->sequence( $seed ) );
			global $wpdb;
			$starts += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::jobs_table() ) - 2;
		}
		$this->assertSame( array(), array_slice( $found, 0, 10 ), count( $found ) . ' violations' );
		$this->assertGreaterThan( $count / 4, $starts, 'the control: the copies started jobs of their own to check' );
		$this->assertGreaterThan( 0, $this->seen['vanished'] ?? 0, 'the control: copies whose original\'s ABSPATH is nowhere here' );
		$this->assertGreaterThan( 0, $this->seen['a copy continued after its own move'] ?? 0, 'the control: copies that moved and continued' );
	}

	public function test_a_copy_that_moves_and_continues_never_takes_the_originals_tokens_back(): void {
		Schema::ensure();
		foreach ( array( 'a', 'b', 'b2' ) as $site ) {
			mkdir( $this->root . '/h1/' . $site . '/wp-includes', 0755, true );
		}
		$original = $this->dirs( 'h1/a', '' );
		$first    = $original->base();
		$theirs   = (string) $original->state()['token'];
		$held     = self::repo( $original )->create( 'plain' );
		self::hold( $held->id );
		// The copy sees the clone and its administrator keeps the new directory.
		$copy = $this->dirs( 'h1/b', '' );
		$this->assertNotSame( $first, $copy->base() );
		$copy->acknowledge_clone();
		$own = self::repo( $copy )->create( 'plain' );
		self::hold( $own->id );
		// Later the copy moves, and its administrator continues with the directory it had.
		$moved = $this->dirs( 'h1/b2', '' );
		$moved->base();
		$state = $moved->state();
		$this->assertTrue( $state['clone_detected'], 'the control: the move is detected' );
		$this->assertFalse( Paths::same_location( (string) $state['previous_path'], $first ), 'the control: its own directory, not the original\'s' );
		$result = $moved->reclaim()->reclaim( false );
		$this->assertTrue( $result['ok'], $result['message'] );
		$moved->finish_reclaim();
		$this->assertTrue( $this->runs_on_copy( $own->id, 'h1/b2', '', true ), 'the control: its own job runs again' );
		$this->assertNotContains( $theirs, Directories::own_tokens(), 'the original\'s token is not the copy\'s' );
		$this->assertFalse( $this->runs_on_copy( $held->id, 'h1/b2', '' ), 'nor does the original\'s job run on it' );
	}

	public function test_a_worker_still_on_the_release_before_keeps_its_tokens(): void {
		Schema::ensure();
		$root = $this->deployment();
		$cli  = $this->dirs( 'deploy/current', '' ); // WP-CLI through the link, while it points at release 1.
		$base = $cli->base();
		$job  = self::repo( $cli )->create( 'plain' );
		self::hold( $job->id );
		Sandbox::remove( $root . '/current' );
		symlink( $root . '/releases/2', $root . '/current' );
		clearstatcache( true ); // A worker that never resolved "current" itself.
		$worker = $this->dirs( 'deploy/releases/1', '' );
		$this->assertSame( $base, $worker->base(), $worker->last_error() );
		$this->assertFalse( $worker->state()['clone_detected'] );
		$this->assertSame( array(), $worker->state()['copied_tokens'], 'none of its tokens set aside' );
		$this->assertTrue( $this->runs_on_copy( $job->id, 'deploy/releases/1', '', true ), 'its job runs' );
	}

	public function test_a_deployment_after_wp_cli_chose_the_directory_is_taken_over_under_a_trusted_root(): void {
		$root = $this->deployment();
		$cli  = $this->dirs( 'deploy/current', '' );
		$base = $cli->base();
		$this->assertNotSame( '', $base, $cli->last_error() );
		$state                        = Options::get( Directories::OPTION, array() );
		$state['trusted_deploy_root'] = (string) realpath( $root . '/releases' );
		Options::set( Directories::OPTION, $state );
		Sandbox::remove( $root . '/current' );
		symlink( $root . '/releases/2', $root . '/current' );
		$web = $this->dirs( 'deploy/releases/2', '' );
		$this->assertSame( $base, $web->base(), 'taken over without asking: ' . $web->last_error() );
		$this->assertNotEmpty( $web->state()['auto_reclaimed'] );
	}

	public function test_continuing_again_after_a_request_died_between_the_marker_and_the_state(): void {
		$root = $this->deployment();
		$web  = $this->dirs( 'deploy/releases/1', '' );
		$base = $web->base();
		$next = $this->dirs( 'deploy/releases/2', '' );
		$this->assertNotSame( $base, $next->base(), 'the control: the move is detected' );
		$this->assertTrue( $next->reclaim()->reclaim( false )['ok'] );
		// The request dies here: the marker names release 2, the state was never saved.
		$again = $this->dirs( 'deploy/releases/2', '' );
		$again->base();
		$this->assertTrue( $again->state()['clone_detected'], 'the control: still waiting for the administrator' );
		$result = $again->reclaim()->reclaim( false );
		$this->assertTrue( $result['ok'], $result['message'] );
		$again->finish_reclaim();
		$this->assertSame( $base, $this->dirs( 'deploy/releases/2', '' )->base() );
	}

	public function test_a_copy_goes_on_where_the_originals_directory_cannot_be_looked_at(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root searches every directory.' );
		}
		Schema::ensure();
		mkdir( $this->root . '/h2/home/a/wp-includes', 0755, true ); // The original, in a home directory the copy cannot search.
		mkdir( $this->root . '/h2/b/wp-includes', 0755, true );
		$original = $this->dirs( 'h2/home/a', '' );
		$first    = $original->base();
		$held     = self::repo( $original )->create( 'plain' );
		self::hold( $held->id );
		chmod( $this->root . '/h2/home', 0 );
		try {
			// Where the original resolved to when it chose its directory is on record: not where the copy is.
			$copy = $this->dirs( 'h2/b', '' );
			$base = $copy->base();
			$this->assertNotSame( '', $base, $copy->last_error() );
			$this->assertNotSame( $first, $base );
			$this->assertTrue( $copy->state()['clone_detected'] );
			$this->assertFalse( $this->runs_on_copy( $held->id, 'h2/b', '' ), 'the original\'s job does not run on it' );
		} finally {
			chmod( $this->root . '/h2/home', 0755 );
		}
	}

	public function test_a_take_over_that_died_before_saving_the_state_keeps_the_sites_tokens(): void {
		Schema::ensure();
		$root = $this->deployment();
		$web  = $this->dirs( 'deploy/releases/1', '' );
		$base = $web->base();
		$job  = self::repo( $web )->create( 'plain' );
		self::hold( $job->id );
		// A deployment to release 2 under a trusted root: the take-over rewrote the marker for release 2, and the
		// request died before the state was saved (it still names release 1).
		$state = Options::get( Directories::OPTION, array() );
		file_put_contents( $base . '/.wpcheckpoint-owner', OwnerMarker::build( (string) $state['install_id'], $root . '/releases/2/' ) );
		$next = $this->dirs( 'deploy/releases/2', '' );
		$this->assertSame( $base, $next->base(), $next->last_error() );
		$this->assertSame( array(), $next->state()['copied_tokens'], 'none of its tokens set aside' );
		$this->assertTrue( $this->runs_on_copy( $job->id, 'deploy/releases/2', '', true ), 'its job runs' );
	}
}
