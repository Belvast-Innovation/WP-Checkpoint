<?php

namespace WPCheckpoint\Tests\Fixtures\Support;

/**
 * An in-memory file system behind a stream wrapper ("wpcmem://"): enough of fopen, write, read, rename, stat and
 * unlink for a writer to run on it. PHP cannot fsync() a user stream, so a writer that syncs fails here: that
 * makes the sync observable.
 */
final class MemoryStream {

	const SCHEME = 'wpcmem';

	/** @var array<string, string> Path => contents. */
	public static $files = array();

	/** @var resource|null */
	public $context;

	/** @var string */
	private $path = '';

	/** @var int */
	private $at = 0;

	public static function register(): void {
		if ( ! in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::SCHEME, self::class );
		}
		self::$files = array();
	}

	public function stream_open( string $path, string $mode, int $options, &$opened ): bool {
		unset( $options, $opened );
		$this->path = $path;
		if ( 'x' === $mode[0] ) {
			if ( isset( self::$files[ $path ] ) ) {
				return false;
			}
			self::$files[ $path ] = '';
		} elseif ( 'w' === $mode[0] ) {
			self::$files[ $path ] = '';
		} elseif ( ! isset( self::$files[ $path ] ) ) {
			return false;
		}
		return true;
	}

	public function stream_write( string $data ): int {
		self::$files[ $this->path ] .= $data;
		return strlen( $data );
	}

	public function stream_read( int $count ) {
		$out       = (string) substr( self::$files[ $this->path ] ?? '', $this->at, $count );
		$this->at += strlen( $out );
		return $out;
	}

	public function stream_eof(): bool {
		return $this->at >= strlen( self::$files[ $this->path ] ?? '' );
	}

	public function stream_flush(): bool {
		return true;
	}

	public function stream_close(): void {
	}

	public function stream_stat() {
		return $this->url_stat( $this->path, 0 );
	}

	public function stream_set_option( int $option, int $arg1, $arg2 ): bool {
		unset( $option, $arg1, $arg2 );
		return false;
	}

	public function url_stat( string $path, int $flags ) {
		unset( $flags );
		if ( ! isset( self::$files[ $path ] ) ) {
			return false;
		}
		return array(
			'mode' => 0100644,
			'size' => strlen( self::$files[ $path ] ),
		);
	}

	public function rename( string $from, string $to ): bool {
		if ( ! isset( self::$files[ $from ] ) ) {
			return false;
		}
		self::$files[ $to ] = self::$files[ $from ];
		unset( self::$files[ $from ] );
		return true;
	}

	public function unlink( string $path ): bool {
		unset( self::$files[ $path ] );
		return true;
	}
}
