<?php
/**
 * The swap's rules: how the tables are renamed in batches, when the swap counts as made, and how each entry of
 * the plan is put back from what is there.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\OwnTables;
use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\TempTables;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. The plan (SwapPlan) has three kinds of entries: a directory
 * unit (DIR: live path L, staged path S, where the live one goes O), a
 * table of the backup (TABLE_OF: final name F, temporary name T, old name
 * O) and a live table the backup does not have (MOVE: F, O); each knows
 * whether the live one was there ("had_live").
 *
 * The tables are renamed by RENAME TABLE statements of at most limit()
 * bytes: a batch never splits an entry (its F to O and its T to F go in
 * one statement), and within a batch the live tables are moved aside
 * before the restored ones take their names. The swap is made (committed)
 * when every table entry is in its swapped state by a complete listing of
 * the names (committed()); short of that, nothing about the order of the
 * batches is assumed: each entry is put back from what is there
 * (table_back(), dir_back()), and each rule gives nothing more to do when
 * it is run again.
 */
final class SwapRules {

	/**
	 * The share of max_allowed_packet a batch may take, and the bounds of a batch.
	 */
	const PACKET_SHARE = 0.5;
	const MIN_BATCH    = 4096;
	const MAX_BATCH    = 1048576;

	/**
	 * What an entry is: before the swap, after it, or neither.
	 */
	const BEFORE  = 'before';
	const AFTER   = 'after';
	const NEITHER = 'neither';

	/**
	 * The bytes a batch may take.
	 *
	 * @param int $packet The server's max_allowed_packet.
	 * @return int
	 */
	public static function limit( int $packet ): int {
		return (int) max( self::MIN_BATCH, min( self::MAX_BATCH, floor( $packet * self::PACKET_SHARE ) ) );
	}

	/**
	 * The renames that swap the tables, in batches.
	 *
	 * @param array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}> $entries The plan's table entries (TABLE_OF, MOVE), in plan order.
	 * @param int                                                                                                 $limit   Most bytes of a statement (limit()).
	 * @return array<int, array{sql: string, seqs: int[]}>
	 */
	public static function batches( array $entries, int $limit ): array {
		$out   = array();
		$aside = array();
		$in    = array();
		$seqs  = array();
		$bytes = strlen( 'RENAME TABLE ' );
		foreach ( $entries as $entry ) {
			$move  = array();
			$enter = array();
			if ( SwapPlan::MOVE === $entry['kind'] || $entry['had_live'] ) {
				$move[] = self::pair( $entry['live'], $entry['old'] );
			}
			if ( SwapPlan::TABLE_OF === $entry['kind'] ) {
				$enter[] = self::pair( $entry['stage'], $entry['live'] );
			}
			$size = 0;
			foreach ( array_merge( $move, $enter ) as $part ) {
				$size += strlen( $part ) + 2;
			}
			if ( array() !== $seqs && $bytes + $size > $limit ) {
				$out[] = self::batch( $aside, $in, $seqs );
				$aside = array();
				$in    = array();
				$seqs  = array();
				$bytes = strlen( 'RENAME TABLE ' );
			}
			$aside  = array_merge( $aside, $move );
			$in     = array_merge( $in, $enter );
			$seqs[] = (int) $entry['seq'];
			$bytes += $size;
		}
		if ( array() !== $seqs ) {
			$out[] = self::batch( $aside, $in, $seqs );
		}
		return $out;
	}

	/**
	 * Every table name the entries hold (to list which are there).
	 *
	 * @param array<int, array{kind: string, live: string, stage: string, old: string}> $entries Table entries.
	 * @return string[]
	 */
	public static function names( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			foreach ( array( $entry['live'], $entry['stage'], $entry['old'] ) as $name ) {
				if ( '' !== $name ) {
					$out[ $name ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Where a table entry stands, by which of its names are there.
	 *
	 * @param array{kind: string, live: string, stage: string, old: string, had_live: bool} $entry Entry.
	 * @param array<string, bool>                                                           $there Name => true for each name there.
	 * @return string BEFORE, AFTER or NEITHER.
	 */
	public static function table_state( array $entry, array $there ): string {
		$f = isset( $there[ $entry['live'] ] );
		$o = isset( $there[ $entry['old'] ] );
		if ( SwapPlan::MOVE === $entry['kind'] ) {
			if ( $f && ! $o ) {
				return self::BEFORE;
			}
			return ! $f && $o ? self::AFTER : self::NEITHER;
		}
		$t = isset( $there[ $entry['stage'] ] );
		if ( $t && ! $o && ( $f === $entry['had_live'] ) ) {
			return self::BEFORE;
		}
		return ! $t && $f && ( $o === $entry['had_live'] ) ? self::AFTER : self::NEITHER;
	}

	/**
	 * Whether the swap is made: every table entry in its swapped state.
	 *
	 * @param array<int, array{kind: string, live: string, stage: string, old: string, had_live: bool}> $entries Table entries.
	 * @param array<string, bool>                                                                       $there   Name => true.
	 * @return bool
	 */
	public static function committed( array $entries, array $there ): bool {
		foreach ( $entries as $entry ) {
			if ( self::AFTER !== self::table_state( $entry, $there ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The renames that put a table entry back, in one statement (none: nothing to do).
	 *
	 * A table of the backup: the restored table under F goes back to T and the old one comes back to F (with a live
	 * one, only when the old one is there: F alone is no evidence that it is the restored table); half a swap
	 * (T and O there, F not) is finished backwards; a table someone made under F while the old one was away is
	 * moved out of the way ($stray) first. A live table moved aside comes back the same way.
	 *
	 * @param array{kind: string, live: string, stage: string, old: string, had_live: bool} $entry Entry.
	 * @param array<string, bool>                                                           $there Name => true.
	 * @param string                                                                        $stray Where a table in the way goes.
	 * @return array<int, array{0: string, 1: string}> From => to, in order.
	 */
	public static function table_back( array $entry, array $there, string $stray ): array {
		$f = isset( $there[ $entry['live'] ] );
		$o = isset( $there[ $entry['old'] ] );
		if ( SwapPlan::MOVE === $entry['kind'] ) {
			if ( ! $o ) {
				return array();
			}
			return $f ? array( array( $entry['live'], $stray ), array( $entry['old'], $entry['live'] ) ) : array( array( $entry['old'], $entry['live'] ) );
		}
		$t = isset( $there[ $entry['stage'] ] );
		if ( ! $t && $f && $entry['had_live'] && ! $o ) {
			// The live table had to go to O before the restored one could take F: with neither T nor O there, F is
			// no evidence of the restored table (T may have gone before the swap, and F be the live one). Left alone.
			return array();
		}
		if ( ! $t && $f ) {
			$out = array( array( $entry['live'], $entry['stage'] ) );
			if ( $entry['had_live'] && $o ) {
				$out[] = array( $entry['old'], $entry['live'] );
			}
			return $out;
		}
		if ( $o && ! $f ) {
			return array( array( $entry['old'], $entry['live'] ) );
		}
		if ( $t && $o && $f ) {
			return array( array( $entry['live'], $stray ), array( $entry['old'], $entry['live'] ) );
		}
		return array();
	}

	/**
	 * The renames that put a directory unit back, in order (none: nothing to do). The old one (O) comes back to
	 * L; whatever is at L first goes back to S when S is free (the staged copy), or else out of the way ($stray).
	 * Without an old one: a unit that had no live directory gets its staged copy back from L, and one that had
	 * one still has it at L.
	 *
	 * @param array{live: string, stage: string, old: string, had_live: bool} $entry Entry.
	 * @param bool                                                            $l     Whether L is there.
	 * @param bool                                                            $s     Whether S is there.
	 * @param bool                                                            $o     Whether O is there.
	 * @param string                                                          $stray Where an entry in the way goes.
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function dir_back( array $entry, bool $l, bool $s, bool $o, string $stray ): array {
		if ( $o ) {
			$out = array();
			if ( $l ) {
				$out[] = array( $entry['live'], $s ? $stray : $entry['stage'] );
			}
			$out[] = array( $entry['old'], $entry['live'] );
			return $out;
		}
		if ( ! $entry['had_live'] && $l && ! $s ) {
			return array( array( $entry['live'], $entry['stage'] ) );
		}
		return array();
	}

	/**
	 * Why a plan entry does not have the shape of one the final check writes for this job ('' when it has): the
	 * plan is read from the database and its paths and names become rename targets. A shape, not the entry
	 * itself: a live path is only held to be in the directory of this job's staging root, which holds other
	 * things too (the swap checks every entry exactly against the staging layout before it starts:
	 * SwapStep::check_plan(); the rollback, which must not read the work directory, has only this).
	 * A directory unit: absolute paths without "." or ".." segments; the staged and the old path under one staging
	 * root of this job (StagingLayout's name, with the job's token and id), the old one under its "old" directory,
	 * and the live path in the directory that holds that root. A table entry: the temporary and the old name of
	 * this job's grammar (TempTables), and a live name that is none of the plugin's own tables.
	 *
	 * @param array{kind: string, live: string, stage: string, old: string} $entry  Entry.
	 * @param string                                                        $token  The job's storage token.
	 * @param int                                                           $job_id The job's id.
	 * @return string
	 */
	public static function invalid( array $entry, string $token, int $job_id ): string {
		if ( SwapPlan::DIR === $entry['kind'] ) {
			foreach ( array( $entry['live'], $entry['stage'], $entry['old'] ) as $path ) {
				$path = str_replace( '\\', '/', $path );
				if ( '' === $path || '/' !== $path[0] || 1 === preg_match( '#(?:\A|/)\.{1,2}(?:/|\z)#', $path ) ) {
					return 'a path that is not absolute, or has . or .. segments';
				}
			}
			$root = self::root_of( $entry['stage'], $token, $job_id );
			if ( '' === $root || self::root_of( $entry['old'], $token, $job_id ) !== $root ) {
				return 'a staged or old path outside this job\'s staging root';
			}
			if ( 0 !== strpos( $entry['old'], $root . '/old/' ) ) {
				return 'an old path outside the staging root\'s old directory';
			}
			if ( dirname( $entry['live'] ) !== dirname( $root ) ) {
				return 'a live path that is not next to its staging root';
			}
			return '';
		}
		if ( ! in_array( $entry['kind'], array( SwapPlan::TABLE_OF, SwapPlan::MOVE ), true ) ) {
			return 'an entry of an unknown kind';
		}
		if ( 0 !== strncmp( $entry['old'], TempTables::OLD_PREFIX . substr( $token, 0, TempTables::TOKEN_LEN ) . '_' . $job_id . '_', strlen( TempTables::OLD_PREFIX ) + TempTables::TOKEN_LEN + strlen( (string) $job_id ) + 2 ) || ! OwnTables::generated( $entry['old'] ) ) {
			return 'an old table name that is not this job\'s';
		}
		if ( SwapPlan::TABLE_OF === $entry['kind'] && ( TempTables::job_id_of( $token, $entry['stage'] ) !== $job_id || ! OwnTables::generated( $entry['stage'] ) ) ) {
			return 'a temporary table name that is not this job\'s';
		}
		return '' === $entry['live'] || OwnTables::is_own( $entry['live'] ) ? 'a live table name that is the plugin\'s own' : '';
	}

	/**
	 * The staging root of this job a path is in (the nearest directory above it that StagingLayout names with the
	 * job's token and id), or ''.
	 *
	 * @param string $path   Path.
	 * @param string $token  Storage token.
	 * @param int    $job_id Job id.
	 * @return string
	 */
	private static function root_of( string $path, string $token, int $job_id ): string {
		$dir = dirname( $path );
		while ( dirname( $dir ) !== $dir ) {
			$parsed = StagingLayout::parse( basename( $dir ) );
			if ( null !== $parsed ) {
				return 'stage' === $parsed['kind'] && $parsed['token'] === $token && $parsed['job_id'] === $job_id ? $dir : '';
			}
			$dir = dirname( $dir );
		}
		return '';
	}

	/**
	 * One RENAME TABLE statement for a list of renames.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs From => to.
	 * @return string
	 */
	public static function rename_sql( array $pairs ): string {
		$parts = array();
		foreach ( $pairs as $pair ) {
			$parts[] = self::pair( $pair[0], $pair[1] );
		}
		return 'RENAME TABLE ' . implode( ', ', $parts );
	}

	/**
	 * "`a` TO `b`".
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return string
	 */
	private static function pair( string $from, string $to ): string {
		return SqlWriter::identifier( $from ) . ' TO ' . SqlWriter::identifier( $to );
	}

	/**
	 * A batch's statement.
	 *
	 * @param string[] $aside The live tables moved aside.
	 * @param string[] $in    The restored tables moved in.
	 * @param int[]    $seqs  The entries.
	 * @return array{sql: string, seqs: int[]}
	 */
	private static function batch( array $aside, array $in, array $seqs ): array {
		return array(
			'sql'  => 'RENAME TABLE ' . implode( ', ', array_merge( $aside, $in ) ),
			'seqs' => $seqs,
		);
	}
}
