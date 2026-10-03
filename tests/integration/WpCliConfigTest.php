<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The wp-config.php the restore takes as this site's (LinkedTargets::config_location()) as a real WP-CLI process finds
 * it: the swap is driven by WP-CLI only, and WP-CLI evaluates wp-config.php instead of including it, so the included
 * files never show it there. Each case runs `wp eval` in a child process on a layout in a sandbox (WordPress is not
 * loaded: only the lookup runs).
 */
final class WpCliConfigTest extends TestCase {

	/** @var string */
	private $dir = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'wp-cli-config' );
		$this->dir = rtrim( str_replace( '\\', '/', (string) realpath( $this->dir ) ), '/' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	/**
	 * Run the lookup in WP-CLI for a WordPress directory; what it found, and whether the process included any
	 * wp-config.php (the way the lookup before M1 looked for it).
	 *
	 * @return array{0: string, 1: string}
	 */
	private function in_wp_cli( string $abspath, string $config_path = '' ): array {
		$plugin = dirname( __DIR__, 2 );
		$code   = 'require ' . var_export( $plugin . '/vendor/autoload.php', true ) . '; '
			. 'echo \\WPCheckpoint\\Restore\\LinkedTargets::config_location( ABSPATH ), "|", '
			. 'count( preg_grep( "#/wp-config\\.php$#", get_included_files() ) );';
		$env    = '' === $config_path ? '' : 'WP_CONFIG_PATH=' . escapeshellarg( $config_path ) . ' ';
		$output = array();
		$status = 0;
		exec( 'cd ' . escapeshellarg( sys_get_temp_dir() ) . ' && ' . $env . 'wp --allow-root --skip-wordpress --path=' . escapeshellarg( $abspath ) . ' eval ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$parts = explode( '|', (string) end( $output ) );
		$this->assertCount( 2, $parts, implode( "\n", $output ) );
		return array( $parts[0], $parts[1] );
	}

	public function test_wp_cli_finds_the_wp_config_of_a_bedrock_site_above_its_wordpress_directory(): void {
		mkdir( $this->dir . '/web/wp', 0755, true );
		file_put_contents( $this->dir . '/web/wp-config.php', '<?php' );
		file_put_contents( $this->dir . '/web/wp/wp-load.php', '<?php' );
		file_put_contents( $this->dir . '/web/wp/wp-settings.php', '<?php' );
		list( $found, $included ) = $this->in_wp_cli( $this->dir . '/web/wp' );
		$this->assertSame( $this->dir . '/web/wp-config.php', $found );
		$this->assertSame( '0', $included, 'the control: WP-CLI included no wp-config.php (found otherwise than by the included files)' );
	}

	public function test_wp_cli_finds_the_wp_config_it_was_told_of_and_the_standard_one_otherwise(): void {
		mkdir( $this->dir . '/site', 0755, true );
		mkdir( $this->dir . '/elsewhere', 0755, true );
		file_put_contents( $this->dir . '/site/wp-config.php', '<?php' );
		file_put_contents( $this->dir . '/site/wp-load.php', '<?php' );
		file_put_contents( $this->dir . '/elsewhere/wp-config.php', '<?php' );
		list( $standard ) = $this->in_wp_cli( $this->dir . '/site' );
		$this->assertSame( $this->dir . '/site/wp-config.php', $standard, 'the control: the one in the WordPress directory' );
		// WP_CONFIG_PATH: only WP-CLI's own lookup knows of it (WordPress's rule would find the standard one).
		list( $told ) = $this->in_wp_cli( $this->dir . '/site', $this->dir . '/elsewhere/wp-config.php' );
		$this->assertSame( $this->dir . '/elsewhere/wp-config.php', $told );
	}
}
