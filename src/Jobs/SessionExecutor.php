<?php
/**
 * JobStore's statements through a connection made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\ImportSession;

defined( 'ABSPATH' ) || exit;

/**
 * The same templates as WpdbExecutor sends, converted for a connection that binds "?" (Placeholders), sent through
 * Restore\ImportSession; an update in the form wpdb::update() gives it (UpdateSql), without wpdb's checks of each value
 * against its column (the values a run without WordPress writes are its own: identifiers and fixed texts). A statement
 * the connection refuses, or one it says may pass later, is null (as wpdb's false); a template in a form Placeholders
 * refuses is an error of this code, and is thrown.
 */
final class SessionExecutor implements StoreExecutor {

	/**
	 * Connection.
	 *
	 * @var ImportSession
	 */
	private $session;

	/**
	 * Constructor.
	 *
	 * @param ImportSession $session Connection.
	 */
	public function __construct( ImportSession $session ) {
		$this->session = $session;
	}

	/**
	 * Run one statement that changes rows.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return int|null
	 * @throws \InvalidArgumentException When the template is in a form Placeholders refuses.
	 */
	public function execute( string $template, array $params ) {
		list( $sql, $bound ) = Placeholders::convert( $template, $params );
		try {
			return $this->session->write( $sql, $bound );
		} catch ( \InvalidArgumentException $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Update rows of a table.
	 *
	 * @param string               $table         Table.
	 * @param array<string, mixed> $data          Column => value.
	 * @param string[]             $formats       Their formats.
	 * @param array<string, mixed> $where         Column => value.
	 * @param string[]             $where_formats Their formats.
	 * @return int|null
	 */
	public function update( string $table, array $data, array $formats, array $where, array $where_formats ) {
		list( $template, $params ) = UpdateSql::template( $table, $data, $formats, $where, $where_formats );
		return $this->execute( $template, $params );
	}

	/**
	 * Run one query.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return array<int, array<string, string|null>>|null
	 * @throws \InvalidArgumentException When the template is in a form Placeholders refuses.
	 */
	public function select( string $template, array $params ) {
		list( $sql, $bound ) = Placeholders::convert( $template, $params );
		try {
			return $this->session->named_rows( $sql, $bound );
		} catch ( \InvalidArgumentException $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			return null;
		}
	}
}
