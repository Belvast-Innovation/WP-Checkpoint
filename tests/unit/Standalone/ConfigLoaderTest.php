<?php

namespace WPCheckpoint\Tests\Unit\Standalone;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * wp-config.php run the way WordPress runs it, without WordPress: the
 * values of each way real sites write it, and a failure (never a guess)
 * where it cannot be read on its own. Each case runs in a process of its
 * own, since constants cannot be defined twice.
 */
final class ConfigLoaderTest extends TestCase {

	/** @var string */
	private $root;

	protected function set_up(): void {
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-config-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/site', 0700, true );
	}

	protected function tear_down(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	/**
	 * Write files under the temporary root.
	 *
	 * @param array<string, string> $files Relative path => content.
	 * @return void
	 */
	private function files( array $files ): void {
		foreach ( $files as $path => $content ) {
			if ( ! is_dir( dirname( $this->root . '/' . $path ) ) ) {
				mkdir( dirname( $this->root . '/' . $path ), 0700, true );
			}
			file_put_contents( $this->root . '/' . $path, $content );
		}
	}

	/**
	 * Load in a process of its own.
	 *
	 * @param string                $abspath Relative WordPress directory.
	 * @param array<string, string> $env     Environment variables.
	 * @param string                $host    HTTP_HOST, or '' for none.
	 * @return array<string, mixed>
	 */
	private function load( string $abspath = 'site', array $env = array(), string $host = '' ): array {
		$command = array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/Standalone/load-config.php', $this->root . '/' . $abspath );
		if ( '' !== $host ) {
			$command[] = $host;
		}
		$process = proc_open( implode( ' ', array_map( 'escapeshellarg', $command ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array_merge( array( 'PATH' => (string) getenv( 'PATH' ) ), $env ) );
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		$data = json_decode( $out, true );
		$this->assertIsArray( $data, 'the runner answered: ' . $out . $err );
		return $data;
	}

	/**
	 * A wp-config.php around some lines, as WordPress's sample ends.
	 *
	 * @param string $body Lines.
	 * @return string
	 */
	private static function config( string $body ): string {
		return "<?php\n" . $body . "\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
	}

	const DB = "define( 'DB_NAME', 'site_db' );\ndefine( 'DB_USER', 'site_user' );\ndefine( 'DB_PASSWORD', 'secret' );\ndefine( 'DB_HOST', 'localhost' );\n";

	public function test_literals_as_the_sample_writes_them(): void {
		$this->files( array( 'site/wp-config.php' => self::config( "define( 'DB_NAME', 'site_db' );\ndefine( 'DB_USER', 'site_user' );\ndefine( 'DB_PASSWORD', \"p\\x41ss'\\\"\" );\ndefine( 'DB_HOST', 'localhost:3307' );\ndefine( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'DB_COLLATE', '' );\n\$table_prefix = 'wp_';" ) ) );
		$data = $this->load();
		$this->assertSame( 'site/wp-config.php', $data['config'] );
		$this->assertSame( array( 'site_db', 'site_user', "pAss'\"", 'localhost:3307', 'utf8mb4', '', 'wp_' ), array( $data['name'], $data['user'], $data['password'], $data['host'], $data['charset'], $data['collate'], $data['prefix'] ) );
		$this->assertFalse( $data['wordpress_loaded'] );
	}

	public function test_the_official_docker_image_reads_everything_from_the_environment(): void {
		// The helper of the official WordPress image, as in its wp-config-docker.php.
		$helper = <<<'PHP'
if (!function_exists('getenv_docker')) {
	function getenv_docker($env, $default) {
		if ($fileEnv = getenv($env . '_FILE')) {
			return rtrim(file_get_contents($fileEnv), "\r\n");
		}
		else if (($val = getenv($env)) !== false) {
			return $val;
		}
		else {
			return $default;
		}
	}
}
define( 'DB_NAME', getenv_docker('WORDPRESS_DB_NAME', 'wordpress') );
define( 'DB_USER', getenv_docker('WORDPRESS_DB_USER', 'example username') );
define( 'DB_PASSWORD', getenv_docker('WORDPRESS_DB_PASSWORD', 'example password') );
define( 'DB_HOST', getenv_docker('WORDPRESS_DB_HOST', 'mysql') );
$table_prefix = getenv_docker('WORDPRESS_TABLE_PREFIX', 'wp_');
if ($configExtra = getenv_docker('WORDPRESS_CONFIG_EXTRA', '')) {
	eval($configExtra);
}
PHP;
		$this->files(
			array(
				'site/wp-config.php' => self::config( $helper ),
				'secrets/password'   => "from-a-file\n",
			)
		);
		$data = $this->load(
			'site',
			array(
				'WORDPRESS_DB_NAME'          => 'env_db',
				'WORDPRESS_DB_USER'          => 'env_user',
				'WORDPRESS_DB_PASSWORD_FILE' => $this->root . '/secrets/password',
				'WORDPRESS_DB_HOST'          => 'db:3306',
				'WORDPRESS_TABLE_PREFIX'     => 'env_',
				'WORDPRESS_CONFIG_EXTRA'     => "define( 'DB_CHARSET', 'utf8' );",
			)
		);
		$this->assertSame( array( 'env_db', 'env_user', 'from-a-file', 'db:3306', 'utf8', 'env_' ), array( $data['name'], $data['user'], $data['password'], $data['host'], $data['charset'], $data['prefix'] ) );
	}

	public function test_conditions_includes_comments_and_late_assignments_resolve_as_wordpress_resolves_them(): void {
		$this->files(
			array(
				'site/wp-config.php'    => self::config(
					"// define( 'DB_NAME', 'old_commented' );\n# define( 'DB_NAME', 'old_hash' );\n/* define( 'DB_NAME', 'old_block' ); */\n"
					. "if ( isset( \$_SERVER['HTTP_HOST'] ) && 'staging.example' === \$_SERVER['HTTP_HOST'] ) {\n\tdefine( 'DB_NAME', 'staging_db' );\n} else {\n\tdefine( 'DB_NAME', 'live_db' );\n}\n"
					. "require __DIR__ . '/wp-config-db.php';\n\$table_prefix = 'first_';\n\$table_prefix = 'final_';"
				),
				'site/wp-config-db.php' => "<?php\ndefine( 'DB_USER', 'included_user' );\ndefine( 'DB_PASSWORD', 'included' );\ndefine( 'DB_HOST', '127.0.0.1' );\n",
			)
		);
		$live = $this->load( 'site', array(), 'www.example' );
		$this->assertSame( array( 'live_db', 'included_user', 'final_' ), array( $live['name'], $live['user'], $live['prefix'] ) );
		$staging = $this->load( 'site', array(), 'staging.example' );
		$this->assertSame( 'staging_db', $staging['name'], 'the branch the request takes' );
	}

	public function test_an_unguarded_abspath_and_a_prefix_set_through_globals(): void {
		$this->files( array( 'site/wp-config.php' => "<?php\n" . self::DB . "\$GLOBALS['table_prefix'] = 'glob_';\ndefine( 'ABSPATH', __DIR__ . '/' );\nrequire_once ABSPATH . 'wp-settings.php';\n" ) );
		$data = $this->load();
		$this->assertSame( 'glob_', $data['prefix'] );
		$this->assertFalse( $data['wordpress_loaded'], 'the redefinition was ignored: the stub stayed in place' );
	}

	public function test_the_file_is_found_where_wp_load_looks(): void {
		$this->files( array( 'wp-config.php' => self::config( self::DB . "\$table_prefix = 'up_';" ) ) );
		$this->assertSame( 'up_', $this->load( 'site' )['prefix'], 'one level up' );
		$this->files( array( 'wp-settings.php' => "<?php\n" ) );
		$this->assertSame( 'no_config', $this->load( 'site' )['failure'], 'one level up belongs to another installation' );
	}

	public function test_what_cannot_be_read_on_its_own_fails_with_a_reason(): void {
		$cases = array(
			'config_error'     => self::config( self::DB . "\$table_prefix = 'wp_';\nif ( ( ;" ),
			'config_error '    => self::config( self::DB . "\$table_prefix = 'wp_';\nadd_filter( 'x', 'y' );" ),
			'config_stopped'   => self::config( self::DB . "\$table_prefix = 'wp_';\nexit;" ),
			'missing_constant' => self::config( "define( 'DB_USER', 'u' );\ndefine( 'DB_PASSWORD', 'p' );\ndefine( 'DB_HOST', 'h' );\n\$table_prefix = 'wp_';" ),
			'bad_prefix'       => self::config( self::DB . "\$table_prefix = 'wp-';" ),
		);
		foreach ( $cases as $reason => $config ) {
			$this->files( array( 'site/wp-config.php' => $config ) );
			$data = $this->load();
			$this->assertSame( trim( $reason ), $data['failure'] ?? $data, $reason );
			if ( 'missing_constant' === $reason ) {
				$this->assertStringContainsString( 'DB_NAME', $data['message'], 'the missing constant is named' );
			}
		}
	}

	public function test_wordpress_loaded_by_a_fixed_path_is_refused_before_or_after_running(): void {
		$marker = $this->root . '/ran';
		$fake   = "<?php\nfunction add_action() {}\n";
		// The control: the observation sees a file run.
		$this->files( array( 'site/wp-config.php' => self::config( self::DB . "\$table_prefix = 'wp_';\ntouch( " . var_export( $marker, true ) . ' );' ) ) );
		$this->assertSame( 'wp_', $this->load()['prefix'] );
		$this->assertFileExists( $marker );
		unlink( $marker );
		// In wp-config.php itself: refused by the scan, before any of it runs.
		$this->files(
			array(
				'real/wp-settings.php' => $fake,
				'site/wp-config.php'   => "<?php\n" . self::DB . "\$table_prefix = 'wp_';\ntouch( " . var_export( $marker, true ) . " );\nrequire_once '" . $this->root . "/real/wp-settings.php';\n",
			)
		);
		$this->assertSame( 'loads_wordpress', $this->load()['failure'] );
		$this->assertFileDoesNotExist( $marker, 'nothing ran' );
		// In a file it includes: the scan cannot see it, the check after running does.
		$this->files(
			array(
				'site/wp-config.php' => self::config( self::DB . "\$table_prefix = 'wp_';\nrequire __DIR__ . '/host-settings.php';" ),
				'site/host-settings.php' => "<?php\nrequire_once '" . $this->root . "/real/wp-settings.php';\n",
			)
		);
		$this->assertSame( 'loads_wordpress', $this->load()['failure'] );
	}

	public function test_no_message_carries_a_value(): void {
		$this->files( array( 'site/wp-config.php' => self::config( "define( 'DB_NAME', 'MARKER_NAME' );\ndefine( 'DB_USER', 'MARKER_USER' );\ndefine( 'DB_PASSWORD', 'MARKER_PASSWORD' );\ndefine( 'DB_HOST', 'MARKER_HOST' );\n\$table_prefix = 'MARKER-PREFIX';" ) ) );
		$data = $this->load();
		$this->assertSame( 'bad_prefix', $data['failure'] );
		foreach ( array( 'MARKER', $this->root ) as $value ) {
			$this->assertStringNotContainsString( $value, $data['message'] );
		}
		// The control: the same values, with a valid prefix, are read.
		$this->files( array( 'site/wp-config.php' => self::config( "define( 'DB_NAME', 'MARKER_NAME' );\ndefine( 'DB_USER', 'MARKER_USER' );\ndefine( 'DB_PASSWORD', 'MARKER_PASSWORD' );\ndefine( 'DB_HOST', 'MARKER_HOST' );\n\$table_prefix = 'MARKER_';" ) ) );
		$this->assertSame( 'MARKER_PASSWORD', $this->load()['password'] );
	}
}
