<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\IncomingQuestions;
use WPCheckpoint\Restore\IncomingTables;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SiteTables;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * The invariant of the answers about tables another installation may use (I-A), on the restore itself rather than on
 * a model of it: when a restore is planned, a kind of table decided by an answer was decided by an answer given to a
 * question that listed exactly the tables of that kind as they are then, and for the shared kind the same other
 * installations' prefixes. Generated sequences change the neighbour's tables and the other installations' keys in
 * the usermeta table, also while the restore waits for an answer, run the restore, and answer some or all of the
 * questions asked. The expected kinds are worked out again from the database at the moment the plan is written (what
 * changes after it is the swap's to judge again, not the plan's).
 */
final class IncomingAnswersSequencesTest extends RestoreTestCase {

	const SEQUENCES = 15;

	/** @var int[] Rows this test added to the usermeta table. */
	private $meta = array();

	/** @var array<string, int> The controls. */
	private $seen = array();

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->meta as $id ) {
			$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => $id ) );
		}
		$wpdb->query( 'COMMIT' ); // The restores committed the rows (RestoreTestCase::run_restore()); so is their removal.
		parent::tear_down();
	}

	private function note( string $what ): void {
		$this->seen[ $what ] = ( $this->seen[ $what ] ?? 0 ) + 1;
	}

	/**
	 * What the work file listed when the job paused: kind => a snapshot as the invariant compares them.
	 *
	 * @return array<string, string>
	 */
	private function shown( Job $job ): array {
		$file = json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::INCOMING ) ), true );
		$out  = array();
		foreach ( $job->questions as $question ) {
			$key                  = (string) array_search( $question['kind'], IncomingQuestions::QUESTION_KINDS, true );
			$kind                 = IncomingQuestions::KINDS[ $key ];
			$out[ $question['id'] ] = array( $kind, self::snapshot( $kind, (array) $file[ $kind ], (array) $file['evidence'] ) );
		}
		return $out;
	}

	private static function snapshot( string $kind, array $tables, array $evidence ): string {
		$tables = array_values( array_unique( array_map( 'strval', $tables ) ) );
		sort( $tables, SORT_STRING );
		$evidence = array_values( array_unique( array_map( 'strval', $evidence ) ) );
		sort( $evidence, SORT_STRING );
		return (string) wp_json_encode( IncomingTables::SHARED === $kind ? array( $tables, $evidence ) : array( $tables ) );
	}

	/**
	 * The kinds as the database shows them now, for the tables of the plan.
	 *
	 * @return array{0: array<string, string[]>, 1: string[]} Kind => final names; the evidence.
	 */
	private function kinds_now( Job $job ): array {
		global $wpdb;
		$plan   = RestorePreflightStep::load_plan( $this->work( $job ) )['plan'];
		$finals = array_column( $plan->tables(), 'final' );
		foreach ( array_keys( $plan->skipped() ) as $name ) {
			$finals[] = $plan->final_name( (string) $name );
		}
		$keys     = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", '%' . $wpdb->esc_like( IncomingTables::CAPABILITIES ) ) );
		$evidence = IncomingTables::evidence(
			$wpdb->base_prefix,
			is_multisite(),
			$keys,
			static function ( int $id ): bool {
				return SiteTables::blog_exists( $id );
			}
		);
		$live     = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) );
		$kinds    = IncomingTables::classify( $wpdb->base_prefix, is_multisite(), $live, $finals, SiteTables::core(), SiteTables::users(), SiteTables::usermeta(), array() !== $evidence );
		$listed   = array(
			IncomingTables::UNCERTAIN => array(),
			IncomingTables::SHARED    => array(),
		);
		foreach ( $kinds as $final => $kind ) {
			if ( isset( $listed[ $kind ] ) ) {
				$listed[ $kind ][] = (string) $final;
			}
		}
		return array( $listed, $evidence );
	}

	/**
	 * One sequence; the violations of the invariant.
	 *
	 * @return string[]
	 */
	private function sequence( int $seed, string $base, array $pool ): array {
		global $wpdb;
		mt_srand( $seed );
		foreach ( $pool as $table ) {
			if ( array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$this->create( $table, '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
			}
		}
		$keys   = array(); // Key => umeta_id of the other installations' keys of this sequence.
		$job    = $this->start_restore( $base );
		$paused = null;
		$given  = array();
		$steps  = array();
		$found  = array();
		$now    = null; // The kinds as the database showed them when the plan was written.
		$plan   = function () use ( &$job, &$now ): void {
			if ( null === $now && file_exists( RestoreFiles::path( $this->work( $job ), RestoreFiles::PLAN ) ) ) {
				$now = $this->kinds_now( $job );
			}
		};
		$answer = function ( Job $job, bool $all ) use ( &$paused, &$given, &$steps, $plan ): void {
			$ids  = array_keys( $paused );
			$pick = ! $all && count( $ids ) > 1 && 0 === mt_rand( 0, 1 ) ? array( $ids[ mt_rand( 0, count( $ids ) - 1 ) ] ) : $ids;
			$new  = array();
			foreach ( $pick as $id ) {
				$new[ $id ] = 0 === mt_rand( 0, 1 ) ? 'restore' : 'exclude';
				$given[]    = array( $paused[ $id ][0], $paused[ $id ][1], $new[ $id ] );
			}
			if ( count( $pick ) < count( $ids ) ) {
				$this->note( 'partial answer' );
			}
			$steps[] = ( count( $pick ) < count( $ids ) ? 'partial answer ' : 'answer ' ) . wp_json_encode( array_values( $new ) );
			Plugin::instance()->jobs()->answer( Plugin::instance()->jobs()->find( $job->id ), $new );
			Plugin::instance()->runner()->tick( $job->id, microtime( true ) ); // Taken up again (still "paused" until then).
			$GLOBALS['wpdb']->query( 'COMMIT' );
			$plan();
			$paused = null;
		};
		$run = function () use ( &$job, &$paused, &$steps, $plan ): string {
			$job = $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
			$plan();
			if ( Job::PAUSED === $job->status && array() !== $job->questions ) {
				$paused  = $this->shown( $job );
				$steps[] = 'run: asked ' . count( $paused );
				$this->note( 'asked' );
			}
			return $job->status;
		};
		for ( $i = 0, $n = mt_rand( 3, 8 ); $i < $n; $i++ ) {
			$roll = mt_rand( 0, 9 );
			if ( null !== $paused && $roll < 4 ) {
				$answer( $job, false );
				continue;
			}
			if ( $roll < 7 ) {
				if ( 0 === mt_rand( 0, 1 ) ) {
					$table = $pool[ mt_rand( 0, count( $pool ) - 1 ) ];
					if ( array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
						$this->create( $table, '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
					} else {
						$wpdb->query( "DROP TABLE `{$table}`" );
					}
					$steps[] = 'tables';
					if ( null !== $paused ) {
						$this->note( 'tables changed while asked' );
					}
				} else {
					$key = 'wp' . mt_rand( 2, 3 ) . '_capabilities';
					if ( isset( $keys[ $key ] ) ) {
						$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => $keys[ $key ] ) );
						unset( $keys[ $key ] );
					} else {
						$wpdb->insert(
							$wpdb->usermeta,
							array(
								'user_id'    => 1,
								'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture.
								'meta_value' => 'a:0:{}', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture.
							)
						);
						$keys[ $key ] = (int) $wpdb->insert_id;
						$this->meta[] = $keys[ $key ];
					}
					$wpdb->query( 'COMMIT' );
					$steps[] = 'evidence';
					if ( null !== $paused ) {
						$this->note( 'evidence changed while asked' );
					}
				}
				continue;
			}
			if ( null === $paused && in_array( $run(), array( Job::COMPLETED, Job::FAILED ), true ) ) {
				break;
			}
		}
		// To the end: the questions asked from now on are all answered.
		for ( $i = 0; $i < 10 && ! in_array( $job->status, array( Job::COMPLETED, Job::FAILED ), true ); $i++ ) {
			if ( null !== $paused ) {
				$answer( $job, true );
			}
			$run();
		}
		$where = sprintf( '(seed %d, after %s)', $seed, implode( ', ', $steps ) );
		if ( Job::COMPLETED !== $job->status ) {
			return array( 'not completed ' . $where . ': ' . $job->status . ' ' . $job->last_error );
		}
		if ( null === $now ) {
			return array( 'no plan seen ' . $where );
		}
		list( $listed, $evidence ) = $now;
		$plan                      = RestorePreflightStep::load_plan( $this->work( $job ) )['plan'];
		foreach ( $listed as $kind => $tables ) {
			if ( array() === $tables ) {
				continue;
			}
			$left    = 0;
			$planned = 0;
			foreach ( $tables as $final ) {
				$name = null;
				foreach ( $plan->tables() as $table ) {
					if ( $table['final'] === $final ) {
						$name = $table['table'];
					}
				}
				if ( null !== $name ) {
					++$planned;
				} elseif ( in_array( $kind, $plan->skipped(), true ) ) {
					++$left;
				}
			}
			$decision = count( $tables ) === $planned ? 'restore' : ( count( $tables ) === $left ? 'exclude' : '' );
			if ( '' === $decision || ! in_array( array( $kind, self::snapshot( $kind, $tables, $evidence ), $decision ), $given, true ) ) {
				$found[] = sprintf( '%s %s: %s for %s, without an answer given for them', $kind, $where, '' === $decision ? 'mixed' : $decision, wp_json_encode( $tables ) );
				continue;
			}
			$this->note( 'decided by an answer: ' . $kind );
		}
		foreach ( $keys as $id ) {
			$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
		return $found;
	}

	public function test_a_restore_decides_by_an_answer_only_for_the_tables_and_installations_it_was_given_for(): void {
		global $wpdb;
		$n = $wpdb->base_prefix . 'old_';
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' ) as $name ) {
			$this->create( $n . $name, 'LIKE `' . $wpdb->base_prefix . $name . '`' );
		}
		$pool = array( $n . 'shop_a', $n . 'shop_b', $n . 'shop_c' );
		foreach ( $pool as $table ) {
			$this->create( $table, '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		}
		$base  = $this->backup( array_merge( self::site_tables(), $pool, array( $wpdb->users, $wpdb->usermeta ) ) );
		$found = array();
		for ( $seed = 1; $seed <= self::SEQUENCES; $seed++ ) {
			$found = array_merge( $found, $this->sequence( $seed, $base, $pool ) );
		}
		$this->assertSame( array(), array_slice( $found, 0, 5 ), count( $found ) . ' violations' );
		foreach ( array( 'asked', 'partial answer', 'tables changed while asked', 'evidence changed while asked', 'decided by an answer: ' . IncomingTables::UNCERTAIN, 'decided by an answer: ' . IncomingTables::SHARED ) as $point ) {
			$this->assertGreaterThan( 0, $this->seen[ $point ] ?? 0, 'the control: ' . $point . ' ' . wp_json_encode( $this->seen ) );
		}
	}
}
