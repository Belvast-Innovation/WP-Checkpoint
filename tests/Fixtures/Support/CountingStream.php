<?php

namespace WPCheckpoint\Tests\Fixtures\Support;

/**
 * A stream wrapper over the real file system that counts the bytes read
 * from each file: countfs:///tmp/x is /tmp/x. Lets a test assert that code
 * never read a file (not only that its result came out the same).
 *
 * Supports what the code under test uses: fopen/fread (hash_file,
 * file_get_contents), stat (is_file, filesize), directory listing,
 * file_put_contents and unlink.
 */
final class CountingStream {

	const SCHEME = 'countfs';

	/**
	 * Bytes read, by real path.
	 *
	 * @var array<string, int>
	 */
	public static $read = array();

	/** @var resource|null */
	public $context;

	/** @var resource|null */
	private $handle;

	/** @var string */
	private $path = '';

	/** @var resource|null */
	private $dir;

	public static function register(): void {
		if ( ! in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::SCHEME, self::class );
		}
		self::$read = array();
	}

	public static function unregister(): void {
		if ( in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( self::SCHEME );
		}
	}

	public static function url( string $path ): string {
		return self::SCHEME . '://' . $path;
	}

	public static function bytes_read( string $path ): int {
		return self::$read[ self::key( $path ) ] ?? 0;
	}

	/**
	 * One key per file whichever separator built the path (Windows mixes them).
	 */
	private static function key( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	private static function real( string $url ): string {
		return substr( $url, strlen( self::SCHEME . '://' ) );
	}

	public function stream_open( string $url, string $mode ): bool {
		$this->path   = self::real( $url );
		$this->handle = @fopen( $this->path, $mode );
		return is_resource( $this->handle );
	}

	public function stream_read( int $count ) {
		$data = fread( $this->handle, $count );
		if ( is_string( $data ) ) {
			$key                = self::key( $this->path );
			self::$read[ $key ] = ( self::$read[ $key ] ?? 0 ) + strlen( $data );
		}
		return $data;
	}

	public function stream_write( string $data ) {
		return fwrite( $this->handle, $data );
	}

	public function stream_eof(): bool {
		return feof( $this->handle );
	}

	public function stream_tell() {
		return ftell( $this->handle );
	}

	public function stream_seek( int $offset, int $whence ): bool {
		return 0 === fseek( $this->handle, $offset, $whence );
	}

	public function stream_stat() {
		return fstat( $this->handle );
	}

	public function stream_close(): void {
		fclose( $this->handle );
	}

	public function stream_set_option( int $option, int $arg1, $arg2 ): bool {
		return false;
	}

	public function url_stat( string $url, int $flags ) {
		$path = self::real( $url );
		return file_exists( $path ) ? @stat( $path ) : false;
	}

	public function unlink( string $url ): bool {
		return @unlink( self::real( $url ) );
	}

	public function rename( string $from, string $to ): bool {
		return @rename( self::real( $from ), self::real( $to ) );
	}

	public function mkdir( string $url, int $mode, int $options ): bool {
		return @mkdir( self::real( $url ), $mode, (bool) ( $options & STREAM_MKDIR_RECURSIVE ) );
	}

	public function dir_opendir( string $url, int $options ): bool {
		$this->dir = @opendir( self::real( $url ) );
		return is_resource( $this->dir );
	}

	public function dir_readdir() {
		return readdir( $this->dir );
	}

	public function dir_rewinddir(): bool {
		rewinddir( $this->dir );
		return true;
	}

	public function dir_closedir(): bool {
		closedir( $this->dir );
		return true;
	}
}
