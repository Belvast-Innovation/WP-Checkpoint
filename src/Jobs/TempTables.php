<?php
/**
 * Names of the temporary database tables a job creates.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * A temporary table is named wcptmp{token6}_{job id}_{hex4}_{table}: the
 * first six characters of the storage token identify this installation
 * (two sites sharing one database must never reclaim each other's
 * tables; ownership of tables and of files thus rests on the same
 * identity), the job id ties the table to the job that owns it, the
 * random part keeps two runs of the same job apart, and the rest is the
 * original table name without the site prefix. Names are kept at most
 * MAX_NAME bytes: MySQL allows 64, but the per-table file name also has
 * to fit the file system, and some Windows hosts fail earlier.
 *
 * The name only ever contains [A-Za-z0-9_]: the creating side obeys the
 * rule the dropping side checks (TempTableDropper::drop()), so a
 * table that can be created can always be reclaimed. MySQL allows
 * umlauts and CJK in table names and real sites have them; such
 * characters are replaced, and a short hash of the original name keeps
 * two names that collapse to the same replacement apart. Pure PHP; the
 * SQL that creates and drops the tables lives in the repository.
 */
final class TempTables {

	const PREFIX     = 'wcptmp';
	const TOKEN_LEN  = 6;
	const RANDOM_LEN = 4;
	const MAX_NAME   = 60;
	const HASH_LEN   = 7;
	const SAFE_CHARS = '/[^A-Za-z0-9_]/';
	const NAME_RULE  = '/\A[A-Za-z0-9_]{1,64}\z/';

	/**
	 * The prefix shared by every temporary table of this installation.
	 *
	 * @param string $token Storage token.
	 * @return string
	 * @throws \InvalidArgumentException When the token is not usable.
	 */
	public static function owner_prefix( string $token ): string {
		// Six hex characters: two installations sharing a database collide with a chance of one in 16.7 million,
		// and the cost of a collision is one dropping the other's temporary tables, never anything else.
		if ( 1 !== preg_match( '/\A[0-9a-f]{' . self::TOKEN_LEN . ',}\z/', $token ) ) {
			throw new \InvalidArgumentException( 'The storage token must be lowercase hex.' );
		}
		return self::PREFIX . substr( $token, 0, self::TOKEN_LEN ) . '_';
	}

	/**
	 * The prefix shared by every temporary table of one job.
	 *
	 * @param string $token  Storage token.
	 * @param int    $job_id Job id.
	 * @return string
	 * @throws \InvalidArgumentException When the token or id is not usable.
	 */
	public static function job_prefix( string $token, int $job_id ): string {
		if ( $job_id <= 0 ) {
			throw new \InvalidArgumentException( 'A temporary table needs a saved job.' );
		}
		return self::owner_prefix( $token ) . $job_id . '_';
	}

	/**
	 * The name of a temporary table.
	 *
	 * @param string $token  Storage token.
	 * @param int    $job_id Job id.
	 * @param string $random RANDOM_LEN lowercase hex characters, the same for every table of one run.
	 * @param string $table  Original table name without the site prefix.
	 * @return string At most MAX_NAME bytes of [A-Za-z0-9_]; a name that had other characters or was too long is cut and given a short hash of the original.
	 * @throws \InvalidArgumentException When an argument is not usable.
	 */
	public static function name( string $token, int $job_id, string $random, string $table ): string {
		if ( 1 !== preg_match( '/\A[0-9a-f]{' . self::RANDOM_LEN . '}\z/', $random ) ) {
			throw new \InvalidArgumentException( 'The random part must be four lowercase hex characters.' );
		}
		if ( '' === $table || 1 === preg_match( '/[\x00-\x1F\x7F\/\\\\]/', $table ) ) {
			throw new \InvalidArgumentException( 'Not a table name.' );
		}
		$prefix = self::job_prefix( $token, $job_id ) . $random . '_';
		$safe   = preg_replace( self::SAFE_CHARS, '_', $table );
		if ( ! is_string( $safe ) ) {
			throw new \InvalidArgumentException( 'Not a table name.' );
		}
		if ( $safe === $table && strlen( $prefix . $safe ) <= self::MAX_NAME ) {
			return $prefix . $safe;
		}
		$hash = substr( hash( 'sha256', $table ), 0, self::HASH_LEN );
		$room = self::MAX_NAME - strlen( $prefix ) - self::HASH_LEN - 1;
		if ( $room < 1 ) {
			throw new \InvalidArgumentException( 'The job id leaves no room for a table name.' );
		}
		return $prefix . substr( $safe, 0, $room ) . '_' . $hash;
	}

	/**
	 * The name of a restore's ledger table: the job's prefix and the run's
	 * random part with nothing after it. name() never gives this (it wants
	 * a table name after the random part), so no restored table can take
	 * it, and job_id_of() attributes it to the job like its other tables.
	 *
	 * @param string $token  Storage token.
	 * @param int    $job_id Job id.
	 * @param string $random The run's random part.
	 * @return string
	 * @throws \InvalidArgumentException When an argument is not usable.
	 */
	public static function ledger( string $token, int $job_id, string $random ): string {
		if ( 1 !== preg_match( '/\A[0-9a-f]{' . self::RANDOM_LEN . '}\z/', $random ) ) {
			throw new \InvalidArgumentException( 'The random part must be four lowercase hex characters.' );
		}
		return self::job_prefix( $token, $job_id ) . $random . '_';
	}

	/**
	 * Whether a name is one this class could have produced and the
	 * repository may drop: nothing but [A-Za-z0-9_], at most 64 bytes.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public static function is_safe_name( string $name ): bool {
		return 1 === preg_match( self::NAME_RULE, $name );
	}

	/**
	 * The job a temporary table belongs to, or 0 when the name is not one
	 * of this installation's temporary tables.
	 *
	 * @param string $token Storage token.
	 * @param string $name  Table name as the database lists it.
	 * @return int
	 */
	public static function job_id_of( string $token, string $name ): int {
		// Not anchored at the end on purpose: the tail is the table name. A name with characters outside
		// is_safe_name() is still attributed here and then refused by the dropping side as a failure.
		try {
			$prefix = self::owner_prefix( $token );
		} catch ( \InvalidArgumentException $e ) {
			return 0;
		}
		if ( 1 !== preg_match( '/\A' . preg_quote( $prefix, '/' ) . '([1-9][0-9]{0,18})_[0-9a-f]{' . self::RANDOM_LEN . '}_/', $name, $m ) ) {
			return 0;
		}
		return (int) $m[1];
	}
}
