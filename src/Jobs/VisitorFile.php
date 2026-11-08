<?php
/**
 * The visitor file: when a request held by the maintenance file may next run the runtime, and the pace of held-up runs.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\AtomicFile;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a small file in ABSPATH, read and written in place under a lock.

/**
 * Restore\Maintenance::VISITOR, next to the maintenance file: written by the swap before the maintenance file
 * (create()), taken down with it. Its modification time is when the maintenance file's code may next run the runtime:
 * the code sets it THROTTLE_SECONDS on before the runtime opens any connection (so the database is asked at most once
 * in that time), and a run held up sets it to the end of its wait (WebPace). Its contents, "<?php exit; ?>", the
 * restore's mark (Restore\Maintenance::WEB_MARK) and a line of JSON: this plugin's basename, and the pace of each job
 * held up (how many in a row, until when). Each change is a read and a write of the whole file under an exclusive lock
 * where the file system has locks; the lock spares the other requests work, it is not what keeps two runs apart (the
 * database lease is): where it does not hold (a file system without locks), a write may be lost or read half made, and
 * a contents that cannot be read is no pace (the run is tried, and held up again if it must be).
 */
final class VisitorFile implements WebPace {

	/**
	 * Seconds after a request ran the runtime before another may (the maintenance file's code says 5 too).
	 */
	const THROTTLE_SECONDS = 5;

	/**
	 * The WordPress directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * The restore's mark.
	 *
	 * @var string
	 */
	private $mark;

	/**
	 * Constructor.
	 *
	 * @param string $dir  The WordPress directory.
	 * @param string $mark The restore's mark.
	 */
	public function __construct( string $dir, string $mark ) {
		$this->dir  = rtrim( $dir, '/\\' );
		$this->mark = $mark;
	}

	/**
	 * Write a restore's visitor file afresh (whole or not at all): no pace, its time now.
	 *
	 * @param string        $dir     The WordPress directory.
	 * @param string        $mark    The restore's mark.
	 * @param string        $plugin  This plugin's basename (what the carry back of its state needs: StateCarry).
	 * @param callable|null $confirm Called right before it goes in place (the lease; throws to stop).
	 * @return void
	 * @throws \RuntimeException When it cannot be written.
	 */
	public static function create( string $dir, string $mark, string $plugin, $confirm = null ): void {
		AtomicFile::write( $dir, Maintenance::VISITOR, self::contents( $mark, array( 'plugin' => $plugin ) ), null === $confirm ? array() : array( 'confirm' => $confirm ) );
	}

	/**
	 * The file's contents for a restore and its data.
	 *
	 * @param string               $mark Mark.
	 * @param array<string, mixed> $data Data.
	 * @return string
	 */
	private static function contents( string $mark, array $data ): string {
		$json = json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- without WordPress; ASCII only.
		return "<?php exit; ?>\n# " . $mark . "\n" . ( is_string( $json ) ? $json : '{}' ) . "\n";
	}

	/**
	 * The path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->dir . DIRECTORY_SEPARATOR . Maintenance::VISITOR;
	}

	/**
	 * This plugin's basename as the swap wrote it ('' when it cannot be read).
	 *
	 * @return string
	 */
	public function plugin(): string {
		$data = $this->read();
		return null !== $data && isset( $data['plugin'] ) && is_string( $data['plugin'] ) ? $data['plugin'] : '';
	}

	/**
	 * Seconds before a run may try the job again (0: now).
	 *
	 * @param int $job_id Job id.
	 * @param int $now    Unix time.
	 * @return int
	 */
	public function wait( int $job_id, int $now ): int {
		$data = $this->read();
		$pace = null === $data ? null : ( $data['pace'][ (string) $job_id ] ?? null );
		return is_array( $pace ) && isset( $pace['until'] ) ? max( 0, (int) $pace['until'] - $now ) : 0;
	}

	/**
	 * A run was held up on the job: one more in a row, and the seconds before the next may try (the file's time set to
	 * then, so the maintenance file's code waits that long before it runs the runtime again).
	 *
	 * @param int $job_id Job id.
	 * @param int $now    Unix time.
	 * @return int
	 */
	public function held_up( int $job_id, int $now ): int {
		$seconds = MemoryPace::seconds( 1 );
		$this->change(
			static function ( array $data ) use ( $job_id, $now, &$seconds ): array {
				$held                             = (int) ( $data['pace'][ (string) $job_id ]['held'] ?? 0 ) + 1;
				$seconds                          = MemoryPace::seconds( $held );
				$data['pace'][ (string) $job_id ] = array(
					'held'  => $held,
					'until' => $now + $seconds,
				);
				return $data;
			},
			static function ( array $data ) use ( $job_id ): int {
				return (int) ( $data['pace'][ (string) $job_id ]['until'] ?? 0 ); // The file's time: the end of the wait.
			}
		);
		return $seconds;
	}

	/**
	 * A run got through: the pace starts over.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function cleared( int $job_id ): void {
		$this->change(
			static function ( array $data ) use ( $job_id ): array {
				unset( $data['pace'][ (string) $job_id ] );
				return $data;
			},
			static function (): int {
				return 0; // The file's time stays as it is.
			}
		);
	}

	/**
	 * The data, or null when the file is not this restore's or cannot be read.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read() {
		$text = @file_get_contents( $this->path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable: no pace.
		return is_string( $text ) ? $this->parse( $text ) : null;
	}

	/**
	 * Contents read as this restore's data.
	 *
	 * @param string $text Contents.
	 * @return array<string, mixed>|null
	 */
	private function parse( string $text ) {
		$lines = explode( "\n", $text );
		if ( count( $lines ) < 3 || '<?php exit; ?>' !== $lines[0] || '# ' . $this->mark !== $lines[1] ) {
			return null;
		}
		$data = json_decode( $lines[2], true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Change the data under the file's lock, and keep its time at least $until (and never earlier than it was: a write
	 * of the contents moves it to now, which would let the maintenance file's code run the runtime before its time).
	 *
	 * @param callable $change function( array $data ): array.
	 * @param callable $until  function( array $data ): int, of the changed data: the Unix time the file's time is to be
	 *                         at least.
	 * @return void
	 */
	private function change( callable $change, callable $until ): void {
		$path   = $this->path();
		$handle = @fopen( $path, 'r+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there: no pace to keep.
		if ( false === $handle ) {
			return;
		}
		$busy   = 0;
		$locked = @flock( $handle, LOCK_EX, $busy ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file system without locks: written without.
		try {
			clearstatcache( true, $path );
			$time = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			$text = stream_get_contents( $handle );
			$data = is_string( $text ) ? $this->parse( $text ) : null;
			if ( null === $data ) {
				return; // Not this restore's, or half made where locks do not hold: left as it is.
			}
			$data = call_user_func( $change, $data );
			if ( ! is_array( $data ) ) {
				return;
			}
			$contents = self::contents( $this->mark, $data );
			if ( rewind( $handle ) && ftruncate( $handle, 0 ) ) {
				fwrite( $handle, $contents );
				fflush( $handle );
			}
			@touch( $path, max( (int) call_user_func( $until, $data ), false === $time ? 0 : (int) $time ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not allowed: the throttle is the code's own five seconds.
		} finally {
			if ( $locked ) {
				@flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			}
			@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		}
	}
}
