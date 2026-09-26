<?php
/**
 * How much free space a restore's staging needs on each file system.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. The staged copy is written next to the live directories and
 * swapped in by rename, so each file system must hold the bytes staged on
 * it (the backup's files for the groups staged there, and the copy of this
 * plugin for the plugins' file system), plus a margin for what the byte
 * counts leave out: directory entries, block rounding, other writers.
 *
 * Bytes are floats: a site's files add up past PHP_INT_MAX on 32-bit PHP
 * (2 GiB), and a float is exact far beyond any disk (2^53 bytes).
 */
final class StagingSpace {

	/**
	 * The margin: this share of the staged bytes, in percent ...
	 */
	const MARGIN_PERCENT = 5;

	/**
	 * ... and at least this much (100 MiB).
	 */
	const MARGIN_MIN_BYTES = 104857600;

	/**
	 * The free bytes a file system needs for $bytes staged on it.
	 *
	 * @param float $bytes Bytes staged on it.
	 * @return float
	 */
	public static function required( float $bytes ): float {
		return $bytes + max( ceil( $bytes * self::MARGIN_PERCENT / 100 ), (float) self::MARGIN_MIN_BYTES );
	}

	/**
	 * The file systems that are short: for each, what it needs and what it has.
	 *
	 * @param array<string, float>      $staged Bytes staged, by file system.
	 * @param array<string, float|null> $free   Free bytes, by file system (null: unknown).
	 * @return array{short: array<string, array{need: float, free: float}>, unknown: array<string, float>} Unknown: what each would need.
	 */
	public static function check( array $staged, array $free ): array {
		$out = array(
			'short'   => array(),
			'unknown' => array(),
		);
		foreach ( $staged as $fs => $bytes ) {
			$need = self::required( $bytes );
			$have = $free[ $fs ] ?? null;
			if ( null === $have ) {
				$out['unknown'][ $fs ] = $need;
			} elseif ( $have < $need ) {
				$out['short'][ $fs ] = array(
					'need' => $need,
					'free' => $have,
				);
			}
		}
		return $out;
	}
}
