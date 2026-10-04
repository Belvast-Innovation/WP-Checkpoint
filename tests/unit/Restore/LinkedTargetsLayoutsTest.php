<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Files\Links;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Tests\Fixtures\Restore\JudgeChild;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Whether a restore asks about a content group's directory (LinkedTargets::judge()), on real trees whose every
 * directory carries its true owner, set when the tree is built: this site's, another installation's, or not to be
 * told (shared media with no WordPress in it, this site's only when the trusted deployment root holds it). The
 * layouts: standard; standard reached through a deployment's current link; Bedrock (the WordPress directory beside
 * the content directory, wp-config.php above both); Bedrock through current; and one whose wp-config.php is in the
 * home directory. Each has another installation beside it and a staging installation inside the site's own
 * directory. Each directory is named directly, through a link, with "..", with doubled slashes, and through current.
 *
 * Invariants (from the owners, not from the rule):
 * - O1 a directory of another installation, or one whose owner cannot be told, is asked about, however its path is
 *   spelt;
 * - O2 in the standard, Bedrock and both current layouts, with and without open_basedir (a child process under
 *   the restriction, as hosts set it), no directory of this site is asked about;
 * - other directories of this site that are asked about are allowed (the safe side), counted.
 *
 * Also: a hardened layout (wp-config.php one level above, beside another site and a directory it shares), a Trellis
 * deployment (its shared uploads the site's by fact: asked about, allowed and counted), a staging site inside
 * wp-content, and an installation in its own directory (a front controller, the core beside it).
 *
 * Reverse validation: without the check for another installation's root, without the exclusion of a home
 * directory as the directory of wp-config.php, with the earlier rule (a link on the path), without the directory of
 * wp-config.php as a zone, without the check of what that zone holds, and without the front controller rule, the
 * invariants break.
 */
final class LinkedTargetsLayoutsTest extends TestCase {

	/** The layouts in which no directory of this site may be asked about. */
	const NAMED = array( 'standard', 'standard+current', 'bedrock', 'bedrock+current' );

	const SITE  = 'site';
	const OTHER = 'other';
	const NONE  = 'unknown-owner';

	/** @var string */
	private $dir = '';

	/** @var int */
	private $links = 0;

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'owner-layouts' );
		if ( ! @symlink( $this->dir, $this->dir . '/probe-link' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a probe.
			$this->markTestSkipped( 'This system does not let the test make symbolic links (Windows without the privilege); the rule is the same there.' );
		}
		Sandbox::remove( $this->dir . '/probe-link' );
		$this->dir = rtrim( Paths::normalize( (string) realpath( $this->dir ) ), '/' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	private static function mk( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
	}

	private static function wp( string $dir, array $files ): void {
		self::mk( $dir );
		foreach ( $files as $file ) {
			file_put_contents( $dir . '/' . $file, '<?php' );
		}
	}

	/**
	 * One layout under its own base: its WordPress directory as named, the directory of its wp-config.php, the home
	 * check, and every target with its owner.
	 *
	 * @return array{base: string, abspath: string, config: string, is_home: callable, targets: array<int, array{0: string, 1: string, 2: bool}>, current: array{0: string, 1: string}|null}
	 */
	private function layout( string $name, bool $trusted ): array {
		$base    = $this->dir . '/' . str_replace( '+', '-', $name ) . ( $trusted ? '-t' : '' );
		$current = null;
		$targets = array();
		switch ( $name ) {
			case 'standard':
			case 'standard+current':
				$site = 'standard' === $name ? "{$base}/www/site" : "{$base}/www/app/releases/3";
				self::wp( $site, array( 'wp-load.php', 'wp-config.php' ) );
				foreach ( array( 'uploads', 'plugins', 'themes', 'uploads/2026' ) as $dir ) {
					self::mk( "{$site}/wp-content/{$dir}" );
					$targets[] = array( "{$site}/wp-content/{$dir}", self::SITE, true );
				}
				$targets[] = array( "{$site}/wp-content/missing", self::SITE, false );
				self::wp( "{$site}/staging", array( 'wp-config.php' ) );
				self::mk( "{$site}/staging/wp-content/uploads" );
				$targets[] = array( "{$site}/staging/wp-content/uploads", self::OTHER, true );
				// wp-content's own index.php, and a staging site inside wp-content: wp-content stays this site's.
				file_put_contents( "{$site}/wp-content/index.php", "<?php\n// Silence is golden.\n" );
				self::wp( "{$site}/wp-content/staging2", array( 'wp-load.php' ) );
				self::mk( "{$site}/wp-content/staging2/wp-content/uploads" );
				$targets[] = array( "{$site}/wp-content/staging2/wp-content/uploads", self::OTHER, true );
				// Another installation in its own directory inside this one: a front controller, the core beside it.
				self::mk( "{$site}/blog/wp" );
				file_put_contents( "{$site}/blog/index.php", "<?php\nrequire __DIR__ . '/wp/wp-blog-header.php';\n" );
				file_put_contents( "{$site}/blog/wp/wp-load.php", '<?php' );
				self::mk( "{$site}/blog/wp-content/uploads" );
				$targets[] = array( "{$site}/blog/wp-content/uploads", self::OTHER, true );
				$abspath = $site;
				$config  = $site;
				if ( 'standard+current' === $name ) {
					symlink( $site, "{$base}/www/app/current" );
					$abspath = "{$base}/www/app/current";
					$current = array( $site, "{$base}/www/app/current" );
				}
				break;
			case 'bedrock':
			case 'bedrock+current':
				$root = 'bedrock' === $name ? "{$base}/srv/bed" : "{$base}/srv/bed/releases/9";
				self::wp( "{$root}/web", array( 'wp-config.php' ) );
				self::wp( "{$root}/web/wp", array( 'wp-load.php' ) );
				file_put_contents( "{$root}/web/index.php", "<?php\nrequire __DIR__ . '/wp/wp-blog-header.php';\n" );
				foreach ( array( 'uploads', 'plugins', 'themes', 'mu-plugins' ) as $dir ) {
					self::mk( "{$root}/web/app/{$dir}" );
					$targets[] = array( "{$root}/web/app/{$dir}", self::SITE, true );
				}
				$targets[] = array( "{$root}/web/app/missing", self::SITE, false );
				self::wp( "{$root}/web/app/staging", array( 'wp-load.php' ) );
				self::mk( "{$root}/web/app/staging/uploads" );
				$targets[] = array( "{$root}/web/app/staging/uploads", self::OTHER, true );
				$abspath = "{$root}/web/wp";
				$config  = "{$root}/web";
				if ( 'bedrock+current' === $name ) {
					symlink( $root, "{$base}/srv/bed/current" );
					$abspath = "{$base}/srv/bed/current/web/wp";
					$current = array( $root, "{$base}/srv/bed/current" );
				}
				break;
			case 'hardened':
				// wp-config.php one level above the WordPress directory, in a directory that also holds another site and
				// a directory that site shares (its UPLOADS): the zone does not stand, the shared one is asked about.
				$www = "{$base}/var/www";
				self::wp( "{$www}/html", array( 'wp-load.php' ) );
				self::wp( $www, array( 'wp-config.php' ) );
				foreach ( array( 'uploads', 'plugins' ) as $dir ) {
					self::mk( "{$www}/html/wp-content/{$dir}" );
					$targets[] = array( "{$www}/html/wp-content/{$dir}", self::SITE, true );
				}
				self::wp( "{$www}/other", array( 'wp-load.php', 'wp-config.php' ) );
				self::mk( "{$www}/other/wp-content/uploads" );
				$targets[] = array( "{$www}/other/wp-content/uploads", self::OTHER, true );
				self::mk( "{$www}/shared-media/uploads" );
				$targets[] = array( "{$www}/shared-media/uploads", self::NONE, true );
				$abspath = "{$www}/html";
				$config  = $www;
				break;
			case 'trellis':
				// A deployment whose uploads are a link from the release into the deployment's shared directory: the
				// site's by fact, outside every zone the rule knows (the trusted root not set). Asked about: allowed, counted.
				$root = "{$base}/srv/tr";
				self::wp( "{$root}/releases/4/web", array( 'wp-config.php' ) );
				self::wp( "{$root}/releases/4/web/wp", array( 'wp-load.php' ) );
				self::mk( "{$root}/releases/4/web/app/plugins" );
				self::mk( "{$root}/shared/uploads" );
				symlink( "{$root}/shared/uploads", "{$root}/releases/4/web/app/uploads" );
				symlink( "{$root}/releases/4", "{$root}/current" );
				$targets[] = array( "{$root}/releases/4/web/app/plugins", self::SITE, true );
				$targets[] = array( "{$root}/shared/uploads", self::SITE, true );
				$abspath   = "{$root}/current/web/wp";
				$config    = "{$root}/releases/4/web";
				$current   = array( "{$root}/releases/4", "{$root}/current" );
				break;
			default: // home-config: wp-config.php one level above the WordPress directory, in the home directory.
				$site = "{$base}/home/u/public_html";
				self::wp( $site, array( 'wp-load.php' ) );
				self::wp( "{$base}/home/u", array( 'wp-config.php' ) );
				self::mk( "{$site}/wp-content/uploads" );
				self::mk( "{$base}/home/u/media" );
				$targets[] = array( "{$site}/wp-content/uploads", self::SITE, true );
				$targets[] = array( "{$base}/home/u/media", self::NONE, true ); // In the home directory, not the site.
				$abspath = $site;
				$config  = "{$base}/home/u";
		}
		self::wp( "{$base}/srv/other", array( 'wp-load.php', 'wp-config.php' ) );
		self::mk( "{$base}/srv/other/wp-content/uploads" );
		$targets[] = array( "{$base}/srv/other/wp-content/uploads", self::OTHER, true );
		self::mk( "{$base}/srv/shared/uploads" );
		$targets[] = array( "{$base}/srv/shared/uploads", $trusted ? self::SITE : self::NONE, true );
		$home = "{$base}/home/u";
		return array(
			'base'    => $base,
			'abspath' => $abspath,
			'config'  => $config,
			'is_home' => static function ( string $dir ) use ( $home ): bool {
				return $dir === $home;
			},
			'targets' => $targets,
			'current' => $current,
			'trusted' => $trusted ? "{$base}/srv/shared" : '',
		);
	}

	/**
	 * Every spelling of a target: as it is, through a link (outside every zone), with "..", with doubled slashes,
	 * through the deployment's current link.
	 *
	 * @return string[]
	 */
	private function spellings( string $base, string $target, bool $exists, $current ): array {
		$out = array( $target, str_replace( '/', '//', $target ) . '/' );
		$out[] = dirname( $target ) . '/../' . basename( dirname( $target ) ) . '/' . basename( $target );
		if ( $exists ) {
			self::mk( "{$base}/links" );
			$link = "{$base}/links/l" . ( ++$this->links );
			symlink( $target, $link );
			$out[] = $link;
		}
		if ( null !== $current && 0 === strpos( $target, $current[0] . '/' ) ) {
			$out[] = $current[1] . substr( $target, strlen( $current[0] ) );
		}
		return $out;
	}

	/**
	 * The cases of all layouts.
	 *
	 * @param callable|null $zones_of function( array $layout ): string[], the zones (the rule's by default).
	 * @return array<int, array{layout: string, given: string, owner: string, zones: string[], base: string}>
	 */
	private function cases( $zones_of = null ): array {
		$cases = array();
		foreach ( array( false, true ) as $trusted ) {
			foreach ( array_merge( self::NAMED, array( 'home-config', 'trellis', 'hardened' ) ) as $name ) {
				$layout = $this->layout( $name, $trusted );
				$zones  = null !== $zones_of ? call_user_func( $zones_of, $layout ) : self::zones( $layout );
				foreach ( $layout['targets'] as $target ) {
					foreach ( $this->spellings( $layout['base'], $target[0], $target[2], $layout['current'] ) as $given ) {
						$cases[] = array(
							'layout' => $name . ( $trusted ? ' (trusted root)' : '' ),
							'named'  => in_array( $name, self::NAMED, true ),
							'given'  => $given,
							'target' => $target[0],
							'owner'  => $target[1],
							'zones'  => $zones,
							'base'   => $layout['base'],
						);
					}
				}
			}
		}
		return $cases;
	}

	/**
	 * The zones the preflight gives the rule: the WordPress directory and the trusted root, resolved; the directory
	 * of wp-config.php unless it is a home directory or a file system's root.
	 *
	 * @return string[]
	 */
	private static function zones( array $layout, bool $structure = true ): array {
		if ( $structure ) {
			// The restore's own composition (the preflight calls the same function).
			return LinkedTargets::zones( $layout['abspath'], $layout['trusted'], $layout['config'], $layout['is_home'] );
		}
		// For the reverse validation: the same without the check of what the zone holds.
		$abspath = rtrim( Paths::normalize( (string) realpath( $layout['abspath'] ) ), '/' );
		return array(
			$abspath,
			'' === $layout['trusted'] ? '' : rtrim( Paths::normalize( (string) realpath( $layout['trusted'] ) ), '/' ),
			LinkedTargets::config_zone( rtrim( Paths::normalize( (string) realpath( $layout['config'] ) ), '/' ), $layout['is_home'] ),
		);
	}

	/**
	 * The violations of a judge over the cases, and the directories of this site it asked about that are allowed.
	 *
	 * @param array<int, array<string, mixed>> $cases   Cases.
	 * @param array<int, bool>                 $asked   Whether each was asked about.
	 * @return array{violations: string[], allowed: string[], seen: array<string, int>}
	 */
	private static function check( array $cases, array $asked ): array {
		$violations = array();
		$allowed    = array();
		$seen       = array();
		foreach ( $cases as $i => $case ) {
			$seen[ $case['owner'] ] = ( $seen[ $case['owner'] ] ?? 0 ) + 1;
			if ( self::SITE !== $case['owner'] && ! $asked[ $i ] ) {
				$violations[] = sprintf( 'O1 %s: %s (%s) not asked about', $case['layout'], $case['given'], $case['owner'] );
			} elseif ( self::SITE === $case['owner'] && $asked[ $i ] ) {
				if ( $case['named'] ) {
					$violations[] = sprintf( 'O2 %s: %s (this site\'s) asked about', $case['layout'], $case['given'] );
				} else {
					$allowed[] = $case['layout'] . ': ' . $case['target'] . ' as ' . $case['given'];
				}
			}
		}
		return array(
			'violations' => $violations,
			'allowed'    => $allowed,
			'seen'       => $seen,
		);
	}

	private static function asked_by_rule( array $case ): bool {
		clearstatcache( true );
		return LinkedTargets::SITE !== LinkedTargets::judge( $case['given'], $case['zones'] )['verdict'];
	}

	public function test_no_directory_of_another_installation_goes_unasked_and_none_of_this_sites_is_asked_about(): void {
		$cases  = $this->cases();
		$result = self::check( $cases, array_map( array( self::class, 'asked_by_rule' ), $cases ) );
		$this->assertSame( array(), array_slice( $result['violations'], 0, 10 ), count( $result['violations'] ) . ' violations' );
		// The control: every owner occurred, and every spelling.
		foreach ( array( self::SITE, self::OTHER, self::NONE ) as $owner ) {
			$this->assertGreaterThan( 0, $result['seen'][ $owner ] ?? 0, 'the control: ' . $owner );
		}
		$this->assertGreaterThan( 0, $this->links, 'the control: spelt through links' );
		// Directories of this site asked about outside the named layouts: allowed (the safe side), and known: only the
		// Trellis deployment's shared uploads, which no zone holds unless the trusted root is set. Anything else here
		// is a new kind and is looked at.
		$this->assertNotSame( array(), $result['allowed'], 'the control: the Trellis shared uploads are asked about' );
		foreach ( $result['allowed'] as $case ) {
			$this->assertStringStartsWith( 'trellis', $case, 'only the known kind of question about this site\'s directories' );
			$this->assertStringContainsString( '/srv/tr/shared/uploads as ', $case );
		}
		fwrite( STDERR, sprintf( "\nAllowed questions about this site's directories: %d (Trellis shared uploads)\n", count( $result['allowed'] ) ) ); // phpcs:ignore -- reported.
	}

	public function test_under_open_basedir_too(): void {
		$this->assertTrue( function_exists( 'exec' ), 'the test runs the rule in a child process' );
		$all   = $this->cases();
		$repo  = dirname( __DIR__, 3 );
		$total = 0;
		foreach ( self::NAMED as $name ) {
			$cases = array_values(
				array_filter(
					$all,
					static function ( array $case ) use ( $name ): bool {
						return $case['layout'] === $name; // Without the trusted root: open_basedir holds the layout's base.
					}
				)
			);
			$this->assertNotSame( array(), $cases );
			$file = $cases[0]['base'] . '/cases.json';
			file_put_contents( $file, (string) json_encode( $cases ) );
			$verdicts = JudgeChild::verdicts( $file, $cases[0]['base'] . PATH_SEPARATOR . $repo );
			$asked = array_map(
				static function ( $verdict ): bool {
					return LinkedTargets::SITE !== $verdict;
				},
				$verdicts
			);
			$result = self::check( $cases, $asked );
			$this->assertSame( array(), array_slice( $result['violations'], 0, 10 ), $name . ' under open_basedir: ' . count( $result['violations'] ) . ' violations' );
			// The control: another installation inside the restricted area is still asked about there.
			$this->assertContains( true, array_intersect_key( $asked, array_filter( array_column( $cases, 'owner' ), static function ( $owner ): bool { return self::OTHER === $owner; } ) ), 'the control: ' . $name );
			$total += count( $cases );
		}
		$this->assertGreaterThan( 0, $total );
	}

	public function test_root_files_that_are_links_out_of_open_basedir_still_ask(): void {
		$repo = dirname( __DIR__, 3 );
		$base = $this->dir . '/obd';
		$out  = $this->dir . '/outside';
		self::mk( $out );
		file_put_contents( $out . '/wp-config.php', '<?php' );
		file_put_contents( $out . '/index.php', "<?php\nrequire __DIR__ . '/wp/wp-blog-header.php';\n" );
		self::mk( "{$base}/site/wp-content/uploads" );
		self::mk( "{$base}/site/staging/wp-content/uploads" );
		symlink( $out . '/wp-config.php', "{$base}/site/staging/wp-config.php" ); // A staging site's shared wp-config.php.
		self::mk( "{$base}/site/blog/wp" );
		self::mk( "{$base}/site/blog/wp-content/uploads" );
		file_put_contents( "{$base}/site/blog/wp/wp-load.php", '<?php' );
		symlink( $out . '/index.php', "{$base}/site/blog/index.php" ); // Its front controller, linked from outside.
		$zones = array( rtrim( Paths::normalize( (string) realpath( "{$base}/site" ) ), '/' ) );
		$cases = array(
			array(
				'given' => "{$base}/site/staging/wp-content/uploads",
				'zones' => $zones,
			),
			array(
				'given' => "{$base}/site/blog/wp-content/uploads",
				'zones' => $zones,
			),
			array(
				'given' => "{$base}/site/wp-content/uploads",
				'zones' => $zones,
			),
		);
		// The control: without the restriction, the two other installations are asked about, this site's is not.
		$plain = array();
		foreach ( $cases as $case ) {
			$plain[] = LinkedTargets::judge( $case['given'], $case['zones'] )['verdict'];
		}
		$this->assertSame( array( LinkedTargets::INSTALLATION, LinkedTargets::INSTALLATION, LinkedTargets::SITE ), $plain );
		file_put_contents( $base . '/cases.json', (string) json_encode( $cases ) );
		$verdicts = JudgeChild::verdicts( $base . '/cases.json', $base . PATH_SEPARATOR . $repo );
		$this->assertSame( LinkedTargets::INSTALLATION, $verdicts[0], 'a root file that is a link out of open_basedir is still listed' );
		$this->assertSame( LinkedTargets::UNKNOWN, $verdicts[1], 'a front controller out of reach cannot be read: not to be told' );
		$this->assertSame( LinkedTargets::SITE, $verdicts[2], 'the control: this site\'s own, under the restriction too' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function withdrawn(): array {
		return array(
			'without the check for another installation\'s root' => array( 'no-installation', 'O1' ),
			'without the home and root exclusion'                 => array( 'no-exclusion', 'O1' ),
			'the earlier rule (a link on the path)'               => array( 'old-rule', 'O1' ),
			'without the directory of wp-config.php as a zone'    => array( 'no-config-zone', 'O2' ),
			'without the check of what the zone holds'           => array( 'no-zone-check', 'O1' ),
			'without the front controller rule for a root'        => array( 'no-front-controller', 'O1' ),
		);
	}

	/**
	 * @dataProvider withdrawn
	 */
	public function test_a_withdrawn_part_breaks_an_invariant( string $variant, string $invariant ): void {
		$zones_of = null;
		if ( 'no-zone-check' === $variant ) {
			$zones_of = static function ( array $layout ): array {
				return self::zones( $layout, false );
			};
		}
		if ( 'no-config-zone' === $variant ) {
			$zones_of = static function ( array $layout ): array {
				$zones    = self::zones( $layout );
				$zones[2] = '';
				return $zones;
			};
		}
		if ( 'no-exclusion' === $variant ) {
			$zones_of = static function ( array $layout ): array {
				$zones    = self::zones( $layout );
				$zones[2] = rtrim( Paths::normalize( (string) realpath( $layout['config'] ) ), '/' ); // Taken whatever it is.
				return $zones;
			};
		}
		$cases = $this->cases( $zones_of );
		$asked = array();
		foreach ( $cases as $case ) {
			clearstatcache( true );
			if ( 'no-installation' === $variant ) {
				$resolved = LinkedTargets::resolve( $case['given'] );
				$asked[]  = '' === $resolved || '' === LinkedTargets::boundary( $resolved, $case['zones'] );
			} elseif ( 'no-front-controller' === $variant ) {
				$asked[] = self::asked_by_files_only( $case );
			} elseif ( 'old-rule' === $variant ) {
				$asked[] = self::old_rule( $case['given'], $case['zones'][0], $case['zones'][1] );
			} else {
				$asked[] = self::asked_by_rule( $case );
			}
		}
		$result = self::check( $cases, $asked );
		$this->assertNotSame( array(), preg_grep( '/\A' . $invariant . ' /', $result['violations'] ), $variant . ': ' . implode( ' | ', array_slice( $result['violations'], 0, 3 ) ) );
	}

	/**
	 * The rule with a root told by wp-load.php and wp-config.php only (no front controller), for the reverse
	 * validation.
	 */
	private static function asked_by_files_only( array $case ): bool {
		clearstatcache( true );
		$resolved = LinkedTargets::resolve( $case['given'] );
		$boundary = '' === $resolved ? '' : LinkedTargets::boundary( $resolved, $case['zones'] );
		if ( '' === $boundary ) {
			return true;
		}
		for ( $dir = $resolved; strlen( $dir ) > strlen( $boundary ); $dir = dirname( $dir ) ) {
			$entries = is_dir( $dir ) ? scandir( $dir ) : array();
			if ( array() !== array_intersect( LinkedTargets::ROOT_FILES, (array) $entries ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The rule of 291434a, for the reverse validation: asked when a link on the path leads outside.
	 */
	private static function old_rule( string $given, string $abspath_real, string $trusted_real ): bool {
		$within  = static function ( string $dir, string $path ): bool {
			return Paths::same( $dir, $path, false ) || Paths::is_prefix( $dir, $path, false );
		};
		$outside = static function ( string $target ) use ( $within, $abspath_real, $trusted_real ): bool {
			foreach ( array( $abspath_real, $trusted_real ) as $root ) {
				if ( '' !== $root && $within( $root, $target ) ) {
					return false;
				}
			}
			return true;
		};
		$path = Paths::normalize( $given );
		if ( 1 !== preg_match( '#\A(/|[A-Za-z]:/)(.*)\z#s', $path, $m ) ) {
			return true;
		}
		$cur   = rtrim( $m[1], '/' );
		$parts = array_values( array_filter( explode( '/', $m[2] ), 'strlen' ) );
		$gone  = '';
		foreach ( $parts as $i => $part ) {
			if ( '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				$cur = false === strpos( $cur, '/' ) ? $cur : (string) substr( $cur, 0, (int) strrpos( $cur, '/' ) );
				if ( '' !== $gone && ! $within( $gone, $cur ) ) {
					$gone = '';
				}
				continue;
			}
			$next = $cur . '/' . $part;
			if ( '' !== $gone ) {
				$cur = $next;
				continue;
			}
			if ( false === @lstat( $next ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a probe.
				if ( ! Paths::positively_gone( $next ) ) {
					return true;
				}
				$gone = $next;
				$cur  = $next;
				continue;
			}
			$real = Paths::real( $next );
			if ( false === $real ) {
				return true;
			}
			$real  = rtrim( Paths::normalize( (string) $real ), '/' );
			$state = Links::state( $next );
			if ( Links::LINK === $state || ( Links::UNKNOWN === $state && ! Paths::same( $real, $next, false ) ) ) {
				$last = true;
				for ( $j = $i + 1; $j < count( $parts ); $j++ ) {
					if ( '.' !== $parts[ $j ] ) {
						$last = false;
						break;
					}
				}
				$deployment = ! $last && '' !== $abspath_real && $within( $real, $abspath_real );
				if ( ! $deployment && $outside( $real ) ) {
					return true;
				}
			}
			$cur = $real;
		}
		return false;
	}
}
