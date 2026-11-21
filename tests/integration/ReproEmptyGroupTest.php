<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Undo;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * REPRO ONLY, not for merging: what a restore does with a content group the backup declares while the backup's
 * files of that group are recorded under another name (a must-use plugins or plugins directory with a name of its
 * own in the content directory, or one reached through a link there). Each case prints what it sees; nothing is
 * asserted but that the case ran.
 */
final class ReproEmptyGroupTest extends SwapTestCase {

	public function tear_down(): void {
		Plugin::instance()->job_actions()->undo_gate( new Undo( Plugin::instance()->jobs() ) );
		parent::tear_down();
	}

	private function out( string $title, $what = null ): void {
		$text = null === $what ? '' : ( is_string( $what ) ? $what : (string) json_encode( $what, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$text = (string) preg_replace( '/wp-checkpoint-stage-[a-f0-9]{12}-\d+-[a-f0-9]{32}/', '<stage>', $text );
		$text = str_replace( $this->sandbox, '<sandbox>', $text );
		fwrite( STDERR, "\n--- " . $title . ( '' === $text ? '' : "\n" . $text ) . "\n" );
	}

	/**
	 * Everything under a directory, links not followed.
	 *
	 * @param string $dir  Directory.
	 * @param string $base Prefix of the names.
	 * @return string[]
	 */
	private function listing( string $dir, string $base = '' ): array {
		$out   = array();
		$names = (array) scandir( $dir );
		sort( $names );
		$any = false;
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$any  = true;
			$path = $dir . '/' . $name;
			if ( is_link( $path ) ) {
				$out[] = $base . $name . ' -> ' . readlink( $path );
			} elseif ( is_dir( $path ) ) {
				$out = array_merge( $out, $this->listing( $path, $base . $name . '/' ) );
			} else {
				$out[] = $base . $name;
			}
		}
		if ( ! $any && '' !== $base ) {
			$out[] = $base . '  (empty directory)';
		}
		return $out;
	}

	private function tree(): string {
		clearstatcache( true );
		return implode( "\n", $this->listing( $this->sandbox ) );
	}

	/**
	 * One case, start to end.
	 *
	 * @param string                $name    Its name.
	 * @param array<string, string> $backup  The backup's files.
	 * @param array<string, mixed>  $options The job's options.
	 */
	private function scenario( string $name, array $backup, array $options = array() ): void {
		$this->out( '================ ' . $name );
		$this->backup_has              = $backup;
		$this->swap_parts['mu_dir'] = $this->dirs['mu-plugins'];
		$this->register_type();
		$export = ScanRoots::resolve_dirs(
			array_merge(
				$this->dirs,
				array(
					'abspath' => $this->abspath,
					'content' => $this->dirs['other-content'],
				)
			),
			ScanRoots::GROUPS
		);
		$said   = array();
		foreach ( $export['roots'] as $root ) {
			$said[] = $root['group'] . ': ' . $root['path'] . ' => archive prefix "' . $root['prefix'] . '"' . ( '' === $root['refuse'] ? '' : ' REFUSED: ' . $root['refuse'] );
		}
		$this->out( 'an export of THIS layout would record (ScanRoots::resolve_dirs)', implode( "\n", array_merge( $said, $export['warnings'] ) ) );
		$this->out( 'the backup\'s files', array_keys( $backup ) );
		$this->out( 'tree before', $this->tree() );
		try {
			$job = $this->at_swap( $options );
		} catch ( \Throwable $e ) {
			$this->out( 'the restore did not reach the swap', $e->getMessage() );
			$this->out( 'tree now', $this->tree() );
			$this->assertTrue( true );
			return;
		}
		$work    = $this->work( $job );
		$staging = json_decode( (string) file_get_contents( RestoreFiles::path( $work, RestoreFiles::STAGING ) ), true );
		$this->out( 'staging: groups (live directories), staged, left out', array( 'groups' => $staging['groups'], 'staged' => $staging['staged'], 'left_out' => $staging['left_out'] ) );
		$lost = RestoreFiles::path( $work, RestoreFiles::UNMAPPED );
		$this->out( 'paths of the backup that belong to no group (not restored)', is_file( $lost ) ? (string) file_get_contents( $lost ) : '(no file)' );
		$units = array();
		foreach ( $this->plan_of( $job )['dirs'] as $entry ) {
			$units[] = sprintf( 'live %s <= stage %s ; old => %s ; had_live %s', $entry['live'], $entry['stage'], $entry['old'], $entry['had_live'] ? 'yes' : 'no' );
		}
		$this->out( 'the plan\'s directory units', implode( "\n", $units ) );
		$this->out( 'tree at the swap (staged, not swapped)', $this->tree() );

		$done = $this->cli_run( $job );
		$this->out( 'after the restore: status ' . $done->status . ', site_state ' . $done->site_state . ', error "' . $done->last_error . '", message "' . $done->progress_message . '"' );
		$this->out( 'tree after the restore', $this->tree() );
		if ( Job::COMPLETED !== $done->status ) {
			$this->assertTrue( true );
			return;
		}

		$actions = Plugin::instance()->job_actions();
		$actions->undo_gate(
			new Undo(
				Plugin::instance()->jobs(),
				array(
					'abspath'       => $this->abspath,
					'restore_types' => array( $this->type ),
				)
			)
		);
		$told = $actions->undo( $done->id, '' );
		$this->out( 'undo, first call: ok ' . ( $told['ok'] ? 'yes' : 'no' ) . ': ' . $told['message'] );
		if ( $told['ok'] ) {
			$claimed = $actions->undo( $done->id, $told['code'] );
			$this->out( 'undo, confirmed: ok ' . ( $claimed['ok'] ? 'yes' : 'no' ) . ': ' . $claimed['message'] );
			if ( $claimed['ok'] ) {
				$undone = $this->cli_run( Plugin::instance()->jobs()->find( $done->id ) );
				$this->out( 'after the undo: status ' . $undone->status . ', site_state ' . $undone->site_state . ', error "' . $undone->last_error . '", message "' . $undone->progress_message . '"' );
			}
		}
		$this->out( 'tree after the undo', $this->tree() );
		$this->assertTrue( true );
	}

	/**
	 * The backup's files with the entries of one group recorded under another top-level name.
	 *
	 * @param string $group Group ("mu-plugins", "plugins").
	 * @param string $as    The name in the content directory.
	 * @return array<string, string>
	 */
	private static function recorded_as( string $group, string $as ): array {
		$out = array();
		foreach ( self::backup_files() as $path => $text ) {
			$from = 'wp-content/' . $group . '/';
			$out[ 0 === strpos( $path, $from ) ? 'wp-content/' . $as . '/' . substr( $path, strlen( $from ) ) : $path ] = $text;
		}
		return $out;
	}

	public function test_0_control_the_default_layout(): void {
		$this->scenario( 'CONTROL: default layout, backup of a default layout', self::backup_files() );
	}

	public function test_1_must_use_plugins_under_a_name_of_their_own_same_site(): void {
		$content = $this->dirs['other-content'];
		rename( $content . '/mu-plugins', $content . '/my-mu' );
		$this->dirs['mu-plugins'] = $content . '/my-mu';
		$this->scenario( 'A1: WPMU_PLUGIN_DIR = wp-content/my-mu on this site; backup made on a site with the same layout', self::recorded_as( 'mu-plugins', 'my-mu' ) );
	}

	public function test_2_must_use_plugins_under_a_name_of_their_own_at_the_source_only(): void {
		$this->scenario( 'A2: this site has the default layout; backup made on a site with WPMU_PLUGIN_DIR = wp-content/my-mu', self::recorded_as( 'mu-plugins', 'my-mu' ) );
	}

	public function test_3_plugins_under_a_name_of_their_own_same_site(): void {
		$content = $this->dirs['other-content'];
		rename( $content . '/plugins', $content . '/my-plugins' );
		$this->dirs['plugins'] = $content . '/my-plugins';
		$this->scenario( 'A3: WP_PLUGIN_DIR = wp-content/my-plugins on this site; backup made on a site with the same layout', self::recorded_as( 'plugins', 'my-plugins' ) );
	}

	public function test_4_plugins_under_a_name_of_their_own_at_the_source_only(): void {
		$this->scenario( 'A4: this site has the default layout; backup made on a site with WP_PLUGIN_DIR = wp-content/my-plugins', self::recorded_as( 'plugins', 'my-plugins' ) );
	}

	private function link_the_must_use_plugins(): void {
		$content = $this->dirs['other-content'];
		mkdir( $this->sandbox . '/shared' );
		rename( $content . '/mu-plugins', $this->sandbox . '/shared/mu' );
		symlink( $this->sandbox . '/shared/mu', $content . '/mu-link' );
		$this->dirs['mu-plugins'] = $content . '/mu-link';
	}

	public function test_5_must_use_plugins_through_a_link_in_the_content_directory_no_policy(): void {
		$this->link_the_must_use_plugins();
		$this->scenario( 'B1: WPMU_PLUGIN_DIR = wp-content/mu-link, a link to <sandbox>/shared/mu; backup of the same layout; no policy', self::recorded_as( 'mu-plugins', 'mu-link' ) );
	}

	public function test_6_must_use_plugins_through_a_link_in_the_content_directory_policy_swap(): void {
		$this->link_the_must_use_plugins();
		$this->scenario( 'B2: as B1, with the policy linked_targets = swap', self::recorded_as( 'mu-plugins', 'mu-link' ), array( 'policy' => array( LinkedTargets::POLICY_KEY => LinkedTargets::SWAP ) ) );
	}

	public function test_7_must_use_plugins_through_a_link_named_mu_plugins(): void {
		$content = $this->dirs['other-content'];
		mkdir( $this->sandbox . '/shared' );
		rename( $content . '/mu-plugins', $this->sandbox . '/shared/mu' );
		symlink( $this->sandbox . '/shared/mu', $content . '/mu-plugins' );
		$this->scenario( 'B3 (control for the link): wp-content/mu-plugins itself is a link to <sandbox>/shared/mu; backup of the same layout', self::backup_files(), array( 'policy' => array( LinkedTargets::POLICY_KEY => LinkedTargets::SWAP ) ) );
	}
}
