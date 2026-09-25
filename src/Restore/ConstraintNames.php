<?php
/**
 * The names a restore gives foreign keys and CHECK constraints in its temporary tables.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL, and MariaDB up to 11.8, want constraint names unique in the whole
 * database, and SHOW CREATE TABLE writes even generated names out, so a
 * backup's CREATE TABLE run as it is next to the live table fails on the
 * live table's own names. MariaDB 12 wants them unique per table only and
 * no longer renames generated names with their table. The rule here works
 * on all of them without knowing which one it runs on (measured on each:
 * tests/Fixtures/Restore/foreign-keys.php):
 *
 * 1. A name in the generated form of the backup's table, "<table>_ibfk_N"
 *    (or "_chk_N"), becomes the generated form of the temporary table when
 *    that and the generated form of the final name both fit 64 bytes.
 *    Servers that rename generated names with their table turn it into the
 *    final table's generated name during the swap, which is the name the
 *    server would have given that table here; MariaDB 12 keeps it, and the
 *    cleanup after the swap renames it.
 * 2. Any other name gets a marker in front: "wcp{random}_{table number}_"
 *    followed by the name, unchanged. The marker goes only in front, never
 *    in the middle and never replacing any part: code that looks for a
 *    constraint by its name looks for it as a substring (WooCommerce
 *    3.5.2-6.x runs strpos() over SHOW CREATE TABLE for its download log
 *    key and adds another key when it is not found), so the original name
 *    must stay whole. The table number keeps the names of two tables apart
 *    when a backup has the same name in several (MariaDB 12 names foreign
 *    keys "1", every MariaDB names CHECK constraints "CONSTRAINT_1"). A name
 *    that does not fit 64 bytes with the marker is cut and given a short
 *    hash, and is reported as shortened: the substring is lost, so the
 *    importer warns about that table.
 *
 * Each name comes with the name it is meant to have in the end (the final
 * table's generated name, or the original), which the cleanup after the
 * swap restores once the old tables, which hold those names until then,
 * are gone.
 */
final class ConstraintNames {

	const MAX_NAME = 64;
	const HASH_LEN = 7;

	/**
	 * Restore's random part (4 lowercase hex characters, as in the temporary table names).
	 *
	 * @var string
	 */
	private $random;

	/**
	 * Constructor.
	 *
	 * @param string $random The restore's random part.
	 * @throws \InvalidArgumentException When it is not 4 lowercase hex characters.
	 */
	public function __construct( string $random ) {
		if ( 1 !== preg_match( '/\A[0-9a-f]{4}\z/', $random ) ) {
			throw new \InvalidArgumentException( 'The random part must be four lowercase hex characters.' );
		}
		$this->random = $random;
	}

	/**
	 * The name for one constraint of an imported table.
	 *
	 * @param string $name      The constraint's name in the backup.
	 * @param string $kind      "ibfk" (a foreign key) or "chk" (a CHECK constraint).
	 * @param string $backup    The table's name in the backup.
	 * @param string $temporary The table's temporary name.
	 * @param string $final_name     The table's final name.
	 * @param int    $number    The table's position in the backup (0-based).
	 * @return array{name: string, intended: string, shortened: bool}
	 */
	public function choose( string $name, string $kind, string $backup, string $temporary, string $final_name, int $number ): array {
		$generated = $backup . '_' . $kind . '_';
		if ( 0 === strpos( $name, $generated ) ) {
			$suffix = substr( $name, strlen( $backup ) );
			if ( 1 === preg_match( '/\A_' . $kind . '_[1-9][0-9]*\z/', $suffix ) && strlen( $temporary . $suffix ) <= self::MAX_NAME && strlen( $final_name . $suffix ) <= self::MAX_NAME ) {
				return array(
					'name'      => $temporary . $suffix,
					'intended'  => $final_name . $suffix,
					'shortened' => false,
				);
			}
		}
		$marker = 'wcp' . $this->random . '_' . base_convert( (string) $number, 10, 36 ) . '_';
		if ( strlen( $marker . $name ) <= self::MAX_NAME ) {
			return array(
				'name'      => $marker . $name,
				'intended'  => $name,
				'shortened' => false,
			);
		}
		$room = self::MAX_NAME - strlen( $marker ) - self::HASH_LEN - 1;
		while ( $room > 0 && 0x80 === ( ord( $name[ $room ] ) & 0xC0 ) ) {
			--$room; // Never cut a UTF-8 character in two.
		}
		return array(
			'name'      => $marker . substr( $name, 0, $room ) . '_' . substr( hash( 'sha256', $name ), 0, self::HASH_LEN ),
			'intended'  => $name,
			'shortened' => true,
		);
	}
}
