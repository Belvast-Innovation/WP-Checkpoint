<?php
/**
 * Which tables of a backup a restore creates, and under which names.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Support\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are job errors with table names; the presenter cleans them.

/**
 * Pure PHP. Each table of the backup gets three names: its name in the
 * backup, its temporary name while it is imported (TempTables::name(),
 * which always fits), and its final name on this site: this site's prefix
 * followed by what comes after the backup's prefix (a table without the
 * backup's prefix, one of another installation added to the backup, keeps
 * its name). The final name cannot be shortened (WordPress and plugins
 * find tables by their exact names), so one longer than 64 bytes refuses
 * the restore, as does a final name two tables would share (compared
 * without case where the server ignores it).
 *
 * Left out of the plan, and reported: tables the user excluded, this
 * plugin's jobs table (a backup written before it was left out of backups
 * may hold it; it is never restored, see ARCHIVE-FORMAT), and tables named
 * like this plugin's temporary or old tables.
 *
 * The backup must hold the options table (and the network's sitemeta
 * table on multisite): this plugin's state is carried into them before the
 * swap, and a site restored without them would not know this plugin is
 * active.
 */
final class TablePlan {

	const MAX_NAME = 64;

	/**
	 * Tables to create, in the backup's order.
	 *
	 * @var array<int, array{table: string, temporary: string, final: string, number: int, chunks: int}>
	 */
	private $tables = array();

	/**
	 * Left out: name => reason ("excluded", "jobs", "temporary").
	 *
	 * @var array<string, string>
	 */
	private $skipped = array();

	/**
	 * Backup's prefix.
	 *
	 * @var string
	 */
	private $backup_prefix;

	/**
	 * This site's prefix.
	 *
	 * @var string
	 */
	private $site_prefix;

	/**
	 * Plan a restore.
	 *
	 * @param array<int, array{name: string, chunks: int}> $tables        The manifest's tables, in order.
	 * @param string                                       $backup_prefix The backup's table prefix (the network's base prefix on multisite).
	 * @param string                                       $site_prefix   This site's table prefix (base prefix on multisite).
	 * @param bool                                         $multisite     Whether the backup is of a network.
	 * @param string[]                                     $excluded      Tables (names in the backup) the user leaves out.
	 * @param string                                       $token         Storage token.
	 * @param int                                          $job_id        The restore job's id.
	 * @param string                                       $random        The restore's random part (4 hex).
	 * @param bool                                         $fold_case     Whether the server compares table names without case (lower_case_table_names <> 0).
	 * @return self
	 * @throws Refused When a final name does not fit or is shared, or the options (sitemeta) table is missing.
	 */
	public static function make( array $tables, string $backup_prefix, string $site_prefix, bool $multisite, array $excluded, string $token, int $job_id, string $random, bool $fold_case ): self {
		$plan                = new self();
		$plan->backup_prefix = $backup_prefix;
		$plan->site_prefix   = $site_prefix;
		$jobs                = $backup_prefix . Schema::JOBS_TABLE;
		$finals              = array();
		foreach ( $tables as $summary ) {
			$name = (string) $summary['name'];
			if ( in_array( $name, $excluded, true ) ) {
				$plan->skipped[ $name ] = 'excluded';
				continue;
			}
			if ( $name === $jobs ) {
				$plan->skipped[ $name ] = 'jobs';
				continue;
			}
			if ( 1 === preg_match( '/\A(?:wcptmp|wcpold)/', $name ) ) {
				$plan->skipped[ $name ] = 'temporary';
				continue;
			}
			$final = $plan->final_name( $name );
			if ( strlen( $final ) > self::MAX_NAME ) {
				throw new Refused(
					sprintf(
						'The table %1$s would be named %2$s on this site (%3$d bytes), longer than the %4$d bytes the database allows. Leave this table out of the restore. Only when restoring into a new, empty site, a shorter table prefix in wp-config.php avoids this as well; on a site that already has content, changing the prefix makes it lose track of its own data.',
						$name,
						$final,
						strlen( $final ),
						self::MAX_NAME
					)
				);
			}
			$key = $fold_case ? strtolower( $final ) : $final;
			if ( isset( $finals[ $key ] ) ) {
				throw new Refused( sprintf( 'The tables %1$s and %2$s would both be named %3$s on this site. Leave one of them out of the restore.', $finals[ $key ], $name, $final ) );
			}
			$finals[ $key ] = $name;
			$number         = count( $plan->tables );
			$plan->tables[] = array(
				'table'     => $name,
				'temporary' => TempTables::name( $token, $job_id, $random, self::strip( $name, $backup_prefix ) ),
				'final'     => $final,
				'number'    => $number,
				'chunks'    => (int) $summary['chunks'],
			);
		}
		$needed = array( $backup_prefix . 'options' );
		if ( $multisite ) {
			$needed[] = $backup_prefix . 'sitemeta';
		}
		foreach ( $needed as $table ) {
			if ( ! in_array( $table, array_column( $plan->tables, 'table' ), true ) ) {
				throw new Refused( sprintf( 'The backup has no table %s (or it is left out), so the restored site would not know which plugins are active, this one included. Restore it with that table.', $table ) );
			}
		}
		return $plan;
	}

	/**
	 * A plan as to_array() gave it.
	 *
	 * @param array{backup_prefix: string, site_prefix: string, tables: array<int, array{table: string, temporary: string, final: string, number: int, chunks: int}>, skipped: array<string, string>} $data Data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$plan                = new self();
		$plan->backup_prefix = $data['backup_prefix'];
		$plan->site_prefix   = $data['site_prefix'];
		$plan->tables        = $data['tables'];
		$plan->skipped       = $data['skipped'];
		return $plan;
	}

	/**
	 * The plan as plain data (kept in the work directory).
	 *
	 * @return array{backup_prefix: string, site_prefix: string, tables: array<int, array{table: string, temporary: string, final: string, number: int, chunks: int}>, skipped: array<string, string>}
	 */
	public function to_array(): array {
		return array(
			'backup_prefix' => $this->backup_prefix,
			'site_prefix'   => $this->site_prefix,
			'tables'        => $this->tables,
			'skipped'       => $this->skipped,
		);
	}

	/**
	 * Tables to create, in the backup's order.
	 *
	 * @return array<int, array{table: string, temporary: string, final: string, number: int, chunks: int}>
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * Tables left out, name => reason.
	 *
	 * @return array<string, string>
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * The table of the plan by its name in the backup.
	 *
	 * @param string $name Name in the backup.
	 * @return array{table: string, temporary: string, final: string, number: int, chunks: int}|null
	 */
	public function find( string $name ) {
		foreach ( $this->tables as $table ) {
			if ( $table['table'] === $name ) {
				return $table;
			}
		}
		return null;
	}

	/**
	 * The name a foreign key of a restored table references instead of a
	 * table named in the backup: the temporary name of a restored table,
	 * else the name the table has on this site (the backup's prefix
	 * replaced).
	 *
	 * @param string $name Referenced table, as the backup names it.
	 * @return string
	 */
	public function reference( string $name ): string {
		$table = $this->find( $name );
		return null !== $table ? $table['temporary'] : $this->final_name( $name );
	}

	/**
	 * A table's name on this site.
	 *
	 * @param string $name Name in the backup.
	 * @return string
	 */
	public function final_name( string $name ): string {
		if ( '' !== $this->backup_prefix && 0 === strpos( $name, $this->backup_prefix ) ) {
			return $this->site_prefix . substr( $name, strlen( $this->backup_prefix ) );
		}
		return $name;
	}

	/**
	 * A name without the backup's prefix.
	 *
	 * @param string $name   Name.
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private static function strip( string $name, string $prefix ): string {
		$stripped = '' !== $prefix && 0 === strpos( $name, $prefix ) ? substr( $name, strlen( $prefix ) ) : $name;
		return '' === $stripped ? $name : $stripped;
	}
}
