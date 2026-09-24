<?php
/**
 * Backups tab.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin\Tabs;

use WPCheckpoint\Admin\Page;
use WPCheckpoint\Admin\DownloadHandler;
use WPCheckpoint\Admin\JobProgress;
use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Backups\BackupStore;
use WPCheckpoint\Backups\EstimateStatus;
use WPCheckpoint\Backups\ExportResults;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobConflicts;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * The backups: the jobs that are running or just ended (S3, with their
 * questions, S4), a form to make a backup (S2), the list (S1) or, before
 * the first backup, what one would hold (S1a), and one backup's details
 * (S5, ?backup={base}). Everything is rendered here; assets/admin/backups.js
 * only sends the actions (make, check, delete, estimate) and reloads, and
 * assets/admin/jobs.js keeps the job blocks moving. Text from the manifest
 * and from jobs is cleaned by the presenter; every value is escaped.
 */
final class BackupsTab implements Tab {

	const PER_PAGE = 20;

	/**
	 * Site transient holding the tables of other installations for the create form (5 minutes).
	 */
	const FOREIGN_CACHE = 'wpcheckpoint_foreign_tables';

	/**
	 * URL slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'backups';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Backups', 'wp-checkpoint' );
	}

	/**
	 * Tab content.
	 *
	 * @return void
	 */
	public function render(): void {
		$plugin    = Plugin::instance();
		$dirs      = $plugin->directories();
		$actions   = $plugin->job_actions();
		$presenter = $plugin->job_presenter();
		if ( '' === $dirs->backups() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The storage directory is not available, so no backup can be listed or made. See the Tools tab.', 'wp-checkpoint' ) . '</p></div>';
			return;
		}
		$store  = new BackupStore( $dirs->backups() );
		$active = $actions->active();
		$base   = self::query_string( 'backup' );
		if ( 1 === preg_match( PreflightStep::BASE_PATTERN, $base ) ) {
			$details = $store->details( $base, $active );
			if ( null !== $details ) { // Gone (deleted meanwhile): the list instead.
				$this->render_details( $details, $base, $active, $presenter );
				return;
			}
		}
		$highlight = $this->highlight( $dirs, $store );
		$this->render_jobs( $actions, $presenter );
		$page  = max( 1, (int) self::query_string( 'paged' ) );
		$list  = $store->page( $page, self::PER_PAGE, $active );
		$pages = max( 1, (int) ceil( $list['total'] / self::PER_PAGE ) );
		if ( $page > $pages ) {
			$page = $pages;
			$list = $store->page( $page, self::PER_PAGE, $active );
		}
		$busy = self::busy_reason( $active );
		if ( 0 === $list['total'] ) {
			if ( '' === $busy ) {
				$this->render_empty( $actions );
			}
			return; // While the first backup is made, its block above is all there is to see.
		}
		$this->render_create( $busy, false );
		$this->render_list( $list, $page, $pages, $highlight, $presenter );
	}

	/**
	 * A sanitized query string value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function query_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * URL of this tab with query arguments.
	 *
	 * @param array<string, string|int> $args Arguments.
	 * @return string
	 */
	private static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'wp-checkpoint',
					'tab'  => 'backups',
				),
				$args
			),
			Page::base_url()
		);
	}

	/**
	 * S3: the jobs that are not finished, and the ones that failed within
	 * the retention of their work files (until dismissed). A job that
	 * completed is not shown here: its backup is in the list below, which
	 * highlights it; the plugin's own estimate is never shown.
	 *
	 * @param JobActions   $actions   Actions.
	 * @param JobPresenter $presenter Presenter.
	 * @return void
	 */
	private function render_jobs( JobActions $actions, JobPresenter $presenter ): void {
		$now   = time();
		$shown = array();
		foreach ( $actions->list_user_jobs( array( Job::QUEUED, Job::RUNNING, Job::PAUSED, Job::FAILED ), 50 ) as $job ) {
			if ( Job::FAILED !== $job->status || $now - $job->finished_at < JobRepository::WORK_RETENTION_SECONDS ) {
				$shown[] = $job;
			}
		}
		if ( array() === $shown ) {
			return;
		}
		$progress = new JobProgress( $presenter );
		echo '<section class="wpcheckpoint-jobs" aria-label="' . esc_attr__( 'Jobs', 'wp-checkpoint' ) . '">';
		foreach ( $shown as $job ) {
			$progress->render( $job );
		}
		echo '</section>';
	}

	/**
	 * The backup an export just made (?job={id}), to highlight in the list;
	 * '' when there is none or it is no longer there.
	 *
	 * @param Directories $dirs  Directories.
	 * @param BackupStore $store Store.
	 * @return string
	 */
	private function highlight( Directories $dirs, BackupStore $store ): string {
		$id = (int) self::query_string( 'job' );
		if ( $id <= 0 ) {
			return '';
		}
		$base = ExportResults::base_of( $id );
		if ( '' === $base ) {
			// Stored before the results were kept: the job's plan, while its work directory is still there.
			$job  = Plugin::instance()->jobs()->find( $id );
			$work = null === $job ? '' : QuestionText::work_dir( $job, $dirs );
			if ( '' !== $work && ExportPlan::exists( $work, ExportPlan::PLAN ) ) {
				try {
					$base = (string) ( ExportPlan::read( $work, ExportPlan::PLAN )['base'] ?? '' );
				} catch ( \RuntimeException $e ) {
					$base = '';
				}
			}
		}
		return 1 === preg_match( PreflightStep::BASE_PATTERN, $base ) && $store->exists( $base ) ? $base : '';
	}

	/**
	 * Why no backup can be started now, in words, or ''.
	 *
	 * @param Job[] $active Active jobs.
	 * @return string
	 */
	private static function busy_reason( array $active ): string {
		foreach ( $active as $job ) {
			if ( JobConflicts::RESTORE === $job->type ) {
				return __( 'A restore is running. Backups can be made again when it has finished.', 'wp-checkpoint' );
			}
		}
		foreach ( $active as $job ) {
			if ( JobConflicts::EXPORT === $job->type ) {
				return __( 'A backup is being made. You can start another one when it has finished.', 'wp-checkpoint' );
			}
		}
		return '';
	}

	/**
	 * S2: make a backup. Disabled while a backup is being made or a restore
	 * runs. Before the first backup it is part of the empty state (S1a),
	 * under what a backup would hold.
	 *
	 * @param string $busy  Why no backup can start now, or ''.
	 * @param bool   $first Whether there is no backup yet.
	 * @return void
	 */
	private function render_create( string $busy, bool $first ): void {
		$foreign = get_site_transient( self::FOREIGN_CACHE );
		if ( ! is_array( $foreign ) ) {
			try {
				$foreign = ExportJob::foreign_tables();
			} catch ( \RuntimeException $e ) {
				$foreign = array();
			}
			// Tables change rarely; the pre-flight judges again when a backup starts, this list is only an offer.
			set_site_transient( self::FOREIGN_CACHE, $foreign, 300 );
		}
		?>
		<section class="wpcheckpoint-create"<?php echo $first ? '' : ' aria-labelledby="wpcheckpoint-create-title"'; ?>>
			<?php if ( ! $first ) : ?>
				<h2 id="wpcheckpoint-create-title"><?php esc_html_e( 'Create a backup', 'wp-checkpoint' ); ?></h2>
			<?php endif; ?>
			<form id="wpcheckpoint-create-form" data-wpcheckpoint-create>
				<p>
					<button type="submit" class="button button-primary"<?php disabled( '' !== $busy ); ?>><?php echo esc_html( $first ? __( 'Create your first backup', 'wp-checkpoint' ) : __( 'Create backup', 'wp-checkpoint' ) ); ?></button>
					<span class="wpcheckpoint-form-status" data-field="form_status" role="status" aria-live="polite"><?php echo esc_html( $busy ); ?></span>
				</p>
				<details>
					<summary><?php esc_html_e( 'Options', 'wp-checkpoint' ); ?></summary>
					<fieldset>
						<legend><?php esc_html_e( 'What to back up', 'wp-checkpoint' ); ?></legend>
						<label><input type="radio" name="contents" value="all" checked> <?php esc_html_e( 'The database and the files', 'wp-checkpoint' ); ?></label><br>
						<label><input type="radio" name="contents" value="database"> <?php esc_html_e( 'The database only', 'wp-checkpoint' ); ?></label><br>
						<label><input type="radio" name="contents" value="files"> <?php esc_html_e( 'The files only', 'wp-checkpoint' ); ?></label>
					</fieldset>
					<p>
						<label for="wpcheckpoint-exclusions"><?php esc_html_e( 'Leave out files matching these patterns (one per line, relative to the site, for example wp-content/cache/*)', 'wp-checkpoint' ); ?></label><br>
						<textarea id="wpcheckpoint-exclusions" name="exclusions" rows="3" cols="60" class="large-text code"></textarea>
					</p>
					<p>
						<label for="wpcheckpoint-exclude-tables"><?php esc_html_e( 'Leave out these tables (one per line)', 'wp-checkpoint' ); ?></label><br>
						<textarea id="wpcheckpoint-exclude-tables" name="exclude_tables" rows="3" cols="60" class="large-text code"></textarea>
					</p>
					<?php if ( array() !== $foreign ) : ?>
						<fieldset>
							<legend><?php esc_html_e( 'Tables that look like another WordPress installation in this database are left out. Include them:', 'wp-checkpoint' ); ?></legend>
							<?php foreach ( $foreign as $prefix => $tables ) : ?>
								<label>
									<input type="checkbox" name="include_group" value="<?php echo esc_attr( (string) wp_json_encode( array_values( $tables ) ) ); ?>">
									<?php
									/* translators: 1: number of tables, 2: table name prefix */
									echo esc_html( sprintf( _n( '%1$d table with the prefix %2$s', '%1$d tables with the prefix %2$s', count( $tables ), 'wp-checkpoint' ), count( $tables ), $prefix ) );
									?>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
				</details>
			</form>
		</section>
		<?php
	}

	/**
	 * S1a: before the first backup, what it would hold. The database size
	 * is known now; the files are counted by the estimate job, which the
	 * script starts when no fresh count exists.
	 *
	 * @param JobActions $actions Actions.
	 * @return void
	 */
	private function render_empty( JobActions $actions ): void {
		$status = ( new EstimateStatus( $actions ) )->status( false );
		?>
		<section class="wpcheckpoint-empty" aria-labelledby="wpcheckpoint-empty-title">
			<h2 id="wpcheckpoint-empty-title"><?php esc_html_e( 'No backups yet', 'wp-checkpoint' ); ?></h2>
			<p><?php esc_html_e( 'A backup holds this site\'s database and files in ordinary zip files that can be restored with this plugin, or by hand without it.', 'wp-checkpoint' ); ?></p>
			<div class="wpcheckpoint-estimate" data-wpcheckpoint-estimate data-state="<?php echo esc_attr( $status['state'] ); ?>" data-job="<?php echo esc_attr( (string) $status['job'] ); ?>" role="status" aria-live="polite">
				<?php $this->render_estimate_lines( $status ); ?>
				<button type="button" class="button-link" data-estimate-stop hidden><?php esc_html_e( 'Stop counting', 'wp-checkpoint' ); ?></button>
			</div>
			<?php $this->render_create( '', true ); ?>
		</section>
		<?php
	}

	/**
	 * The estimate lines (also rebuilt by the script from the same data).
	 *
	 * @param array<string, mixed> $status EstimateStatus::status().
	 * @return void
	 */
	private function render_estimate_lines( array $status ): void {
		echo '<p data-field="database">';
		if ( null !== $status['database_bytes'] ) {
			/* translators: %s: size such as 12 MB */
			echo esc_html( sprintf( __( 'Database: about %s.', 'wp-checkpoint' ), size_format( (int) $status['database_bytes'], 1 ) ) );
		}
		echo '</p><p data-field="files">';
		if ( 'ready' === $status['state'] ) {
			/* translators: 1: number of files, 2: size such as 1.2 GB */
			echo esc_html( sprintf( _n( 'Files: %1$s file, %2$s.', 'Files: %1$s files, %2$s.', (int) $status['files'], 'wp-checkpoint' ), number_format_i18n( (int) $status['files'] ), size_format( (int) $status['files_bytes'], 1 ) ) );
		} elseif ( 'running' === $status['state'] || 'due' === $status['state'] ) {
			esc_html_e( 'Files: counting…', 'wp-checkpoint' );
			echo ' <span class="spinner is-active wpcheckpoint-inline-spinner" aria-hidden="true"></span>';
		}
		echo '</p><p data-field="time">';
		if ( null !== $status['seconds'] ) {
			/* translators: %s: duration such as 5 mins */
			echo esc_html( sprintf( __( 'A full backup took about %s on this site last time.', 'wp-checkpoint' ), human_time_diff( 0, (int) $status['seconds'] ) ) );
		}
		echo '</p>';
	}

	/**
	 * S1: the backups, newest export first, a page at a time.
	 *
	 * @param array{total: int, items: array<int, array<string, mixed>>} $backups   Page.
	 * @param int                                                        $page      Page number.
	 * @param int                                                        $pages     Pages.
	 * @param string                                                     $highlight Base name to highlight.
	 * @param JobPresenter                                               $presenter Presenter.
	 * @return void
	 */
	private function render_list( array $backups, int $page, int $pages, string $highlight, JobPresenter $presenter ): void {
		?>
		<section class="wpcheckpoint-list" aria-labelledby="wpcheckpoint-list-title">
			<h2 id="wpcheckpoint-list-title"><?php esc_html_e( 'Backups', 'wp-checkpoint' ); ?></h2>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php esc_html_e( 'Backups, newest first', 'wp-checkpoint' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Made', 'wp-checkpoint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'wp-checkpoint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Contents', 'wp-checkpoint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Warnings', 'wp-checkpoint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Check', 'wp-checkpoint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'wp-checkpoint' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $backups['items'] as $item ) : ?>
						<?php $base = (string) $item['base']; ?>
						<tr data-backup="<?php echo esc_attr( $base ); ?>"<?php echo $base === $highlight ? ' class="wpcheckpoint-highlight"' : ''; ?>>
							<td>
								<a href="<?php echo esc_url( self::url( array( 'backup' => $base ) ) ); ?>"><?php echo esc_html( self::made( $item ) ); ?></a>
								<?php if ( $base === $highlight ) : ?>
									<span class="wpcheckpoint-badge"><?php esc_html_e( 'New', 'wp-checkpoint' ); ?></span>
								<?php endif; ?>
								<?php $this->render_state_badges( $item, $presenter ); ?>
							</td>
							<td>
								<?php
								echo esc_html( $item['valid'] ? size_format( (int) $item['bytes'], 1 ) : '—' );
								if ( $item['valid'] && (int) $item['volumes'] > 1 ) {
									/* translators: %d: number of files */
									echo '<br><span class="description">' . esc_html( sprintf( _n( '%d file', '%d files', (int) $item['volumes'], 'wp-checkpoint' ), (int) $item['volumes'] ) ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( self::contents_text( $item ) ); ?></td>
							<td><?php echo esc_html( $item['valid'] ? number_format_i18n( (int) $item['warnings'] ) : '—' ); ?></td>
							<td><?php echo esc_html( self::verification_text( $item['verification'] ) ); ?></td>
							<td class="wpcheckpoint-actions">
								<a class="button" href="<?php echo esc_url( self::url( array( 'backup' => $base ) ) ); ?>"><?php esc_html_e( 'Details and download', 'wp-checkpoint' ); ?></a>
								<?php $this->render_backup_actions( $item ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<nav class="wpcheckpoint-pages" aria-label="<?php esc_attr_e( 'Pages of backups', 'wp-checkpoint' ); ?>">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', self::url() ),
								'format'    => '',
								'current'   => $page,
								'total'     => $pages,
								'prev_text' => __( 'Newer', 'wp-checkpoint' ),
								'next_text' => __( 'Older', 'wp-checkpoint' ),
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Badges for a backup in use or partly deleted.
	 *
	 * @param array<string, mixed> $item      Summary.
	 * @param JobPresenter         $presenter Presenter.
	 * @return void
	 */
	private function render_state_badges( array $item, JobPresenter $presenter ): void {
		if ( '' !== (string) $item['in_use'] ) {
			echo '<br><span class="wpcheckpoint-badge wpcheckpoint-badge-busy">' . esc_html( $presenter->clean( (string) $item['in_use'] ) ) . '</span>';
		}
		if ( ! empty( $item['delete_incomplete'] ) ) {
			echo '<br><span class="wpcheckpoint-badge wpcheckpoint-badge-warning">' . esc_html__( 'The last delete did not finish: delete it again.', 'wp-checkpoint' ) . '</span>';
		} elseif ( $item['valid'] && ! $item['complete'] ) {
			echo '<br><span class="wpcheckpoint-badge wpcheckpoint-badge-warning">' . esc_html__( 'Files of this backup are missing or have the wrong size.', 'wp-checkpoint' ) . '</span>';
		} elseif ( ! $item['valid'] ) {
			echo '<br><span class="wpcheckpoint-badge wpcheckpoint-badge-warning">' . esc_html__( 'Its manifest cannot be read.', 'wp-checkpoint' ) . '</span>';
		}
	}

	/**
	 * Check and delete buttons (sent by the script).
	 *
	 * @param array<string, mixed> $item Summary.
	 * @return void
	 */
	private function render_backup_actions( array $item ): void {
		$base  = (string) $item['base'];
		$busy  = '' !== (string) $item['in_use'];
		$check = $busy || ! empty( $item['delete_incomplete'] ) || ! $item['valid'];
		?>
		<button type="button" class="button" data-backup-action="verify" data-base="<?php echo esc_attr( $base ); ?>"<?php disabled( $check ); ?>><?php esc_html_e( 'Check', 'wp-checkpoint' ); ?></button>
		<button type="button" class="button button-link-delete" data-backup-action="delete" data-base="<?php echo esc_attr( $base ); ?>"<?php disabled( $busy ); ?>><?php esc_html_e( 'Delete', 'wp-checkpoint' ); ?></button>
		<?php
	}

	/**
	 * S5: one backup.
	 *
	 * @param array<string, mixed> $details   BackupStore::details().
	 * @param string               $base      Base name.
	 * @param Job[]                $active    Active jobs.
	 * @param JobPresenter         $presenter Presenter.
	 * @return void
	 */
	private function render_details( array $details, string $base, array $active, JobPresenter $presenter ): void {
		$restoring = JobConflicts::restoring( $base, $active );
		$site      = isset( $details['site'] ) && is_array( $details['site'] ) ? $details['site'] : array();
		$exported  = isset( $details['exported'] ) && is_array( $details['exported'] ) ? $details['exported'] : array();
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All backups', 'wp-checkpoint' ); ?></a></p>
		<h2><?php echo esc_html( self::made( $details ) ); ?> <code><?php echo esc_html( $base ); ?></code></h2>
		<?php $this->render_state_badges( $details, $presenter ); ?>

		<h3><?php esc_html_e( 'What it holds, and from when', 'wp-checkpoint' ); ?></h3>
		<p><?php echo esc_html( self::contents_text( $details ) ); ?></p>
		<?php if ( isset( $exported['started_at'], $exported['finished_at'] ) ) : ?>
			<p>
				<?php
				$from = self::local_time( (string) $exported['started_at'], true );
				$to   = self::local_time( (string) $exported['finished_at'], true );
				echo esc_html(
					$from === $to
						/* translators: %s: time */
						? sprintf( __( 'Database: each table as it was when its export started, at %s. Rows added to a table after that are not in it.', 'wp-checkpoint' ), $from )
						/* translators: 1: start time, 2: end time */
						: sprintf( __( 'Database: each table as it was when its export started, between %1$s and %2$s. Rows added to a table after that are not in it.', 'wp-checkpoint' ), $from, $to )
				);
				?>
			</p>
		<?php endif; ?>
		<p><?php esc_html_e( 'Files: scanned and packed while the backup was made; a file that changed while it was packed was read again.', 'wp-checkpoint' ); ?></p>
		<p class="description"><?php esc_html_e( 'The help page “What a backup contains, and when” explains what that means for tables and files that changed during the backup, and when to prefer a restore point.', 'wp-checkpoint' ); ?></p>

		<?php if ( array() !== $site ) : ?>
			<h3><?php esc_html_e( 'Made from', 'wp-checkpoint' ); ?></h3>
			<ul>
				<?php
				foreach (
					array(
						__( 'WordPress', 'wp-checkpoint' ) => $site['wp_version'] ?? '',
						__( 'PHP', 'wp-checkpoint' )       => $site['php_version'] ?? '',
						__( 'Language', 'wp-checkpoint' )  => $site['locale'] ?? '',
						__( 'Table prefix', 'wp-checkpoint' ) => $site['table_prefix'] ?? '',
						__( 'Multisite', 'wp-checkpoint' ) => ! empty( $site['multisite'] ) ? __( 'yes', 'wp-checkpoint' ) : __( 'no', 'wp-checkpoint' ),
					) as $label => $value
				) {
					echo '<li>' . esc_html( $label . ': ' . $presenter->clean( (string) $value ) ) . '</li>';
				}
				?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $details['warnings'] ) && is_array( $details['warnings'] ) ) : ?>
			<h3><?php esc_html_e( 'Warnings', 'wp-checkpoint' ); ?></h3>
			<ul class="wpcheckpoint-warnings">
				<?php foreach ( $details['warnings'] as $warning ) : ?>
					<li><?php echo esc_html( $presenter->clean( (string) $warning ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Check', 'wp-checkpoint' ); ?></h3>
		<p><?php echo esc_html( self::verification_text( $details['verification'] ) ); ?></p>
		<p>
			<?php $this->render_backup_actions( $details ); ?>
		</p>

		<h3><?php esc_html_e( 'Files', 'wp-checkpoint' ); ?></h3>
		<?php if ( '' !== $restoring ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $presenter->clean( $restoring ) ); ?></p></div>
		<?php else : ?>
			<p><?php esc_html_e( 'Download every file listed here and keep them together in one directory: the backup can only be restored with all of them.', 'wp-checkpoint' ); ?></p>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'File', 'wp-checkpoint' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'wp-checkpoint' ); ?></th>
					<th scope="col"><?php esc_html_e( 'How to check it', 'wp-checkpoint' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( self::files_of( $details ) as $file ) : ?>
					<tr>
						<td>
							<?php if ( '' === $restoring && $file['present'] ) : ?>
								<a href="<?php echo esc_url( DownloadHandler::url( 'backups/' . $file['name'] ) ); ?>"><?php echo esc_html( $file['name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $file['name'] ); ?>
							<?php endif; ?>
							<?php if ( ! $file['present'] ) : ?>
								<span class="wpcheckpoint-badge wpcheckpoint-badge-warning"><?php esc_html_e( 'missing', 'wp-checkpoint' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $file['bytes'] ) . ' ' . __( 'bytes', 'wp-checkpoint' ) ); ?></td>
						<td>
							<?php if ( null !== $file['sha256'] ) : ?>
								<?php esc_html_e( 'Check the file size and its SHA-256:', 'wp-checkpoint' ); ?> <code><?php echo esc_html( $file['sha256'] ); ?></code>
							<?php else : ?>
								<?php esc_html_e( 'Check the file size. For a full check, use Check above, or check the file block by block as the help page “Checking downloaded volumes by hand” describes.', 'wp-checkpoint' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The manifest and the volumes, as rows of the files table.
	 *
	 * @param array<string, mixed> $details Details.
	 * @return array<int, array{name: string, bytes: int, sha256: string|null, present: bool}>
	 */
	private static function files_of( array $details ): array {
		$files = array();
		if ( isset( $details['manifest_file'] ) && is_array( $details['manifest_file'] ) ) {
			$files[] = array(
				'name'    => (string) $details['manifest_file']['name'],
				'bytes'   => (int) $details['manifest_file']['bytes'],
				'sha256'  => '' === (string) $details['manifest_file']['sha256'] ? null : (string) $details['manifest_file']['sha256'],
				'present' => true,
			);
		}
		foreach ( (array) ( $details['volume_files'] ?? array() ) as $volume ) {
			$files[] = array(
				'name'    => (string) $volume['name'],
				'bytes'   => (int) $volume['bytes'],
				'sha256'  => null === $volume['sha256'] ? null : (string) $volume['sha256'],
				'present' => (bool) $volume['present'],
			);
		}
		return $files;
	}

	/**
	 * When a backup was made, in the site's time zone.
	 *
	 * @param array<string, mixed> $item Summary.
	 * @return string
	 */
	private static function made( array $item ): string {
		$created = (string) ( $item['created_at'] ?? '' );
		return '' === $created ? (string) $item['base'] : self::local_time( $created );
	}

	/**
	 * An ISO 8601 UTC time in the site's format and time zone.
	 *
	 * @param string $iso     Time.
	 * @param bool   $seconds Whether to show the seconds.
	 * @return string
	 */
	private static function local_time( string $iso, bool $seconds = false ): string {
		$time = strtotime( $iso );
		if ( false === $time ) {
			return $iso;
		}
		$format = (string) get_option( 'time_format' );
		if ( $seconds && false === strpbrk( $format, 's' ) ) {
			// The site's time format with seconds after the minutes (g:i a becomes g:i:s a).
			$with   = preg_replace( '/i/', 'i:s', $format, 1 );
			$format = is_string( $with ) && $with !== $format ? $with : $format . ':s';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . $format, $time );
	}

	/**
	 * What a backup holds, in words.
	 *
	 * @param array<string, mixed> $item Summary.
	 * @return string
	 */
	private static function contents_text( array $item ): string {
		if ( empty( $item['valid'] ) ) {
			return '—';
		}
		$contents = isset( $item['contents'] ) && is_array( $item['contents'] ) ? $item['contents'] : array();
		$database = ! empty( $contents['database'] );
		$files    = ! empty( $contents['files'] );
		if ( $database && $files ) {
			/* translators: 1: number of tables, 2: number of files */
			return sprintf( __( 'Database (%1$s tables) and files (%2$s)', 'wp-checkpoint' ), number_format_i18n( (int) $item['tables'] ), number_format_i18n( (int) $item['files'] ) );
		}
		if ( $database ) {
			/* translators: %s: number of tables */
			return sprintf( __( 'Database only (%s tables)', 'wp-checkpoint' ), number_format_i18n( (int) $item['tables'] ) );
		}
		/* translators: %s: number of files */
		return sprintf( __( 'Files only (%s)', 'wp-checkpoint' ), number_format_i18n( (int) $item['files'] ) );
	}

	/**
	 * The latest check, in words: what it found and when, never a claim
	 * about the files as they are now (the record covers the manifest, not
	 * the volumes).
	 *
	 * @param mixed $verification BackupStore::verification().
	 * @return string
	 */
	public static function verification_text( $verification ): string {
		$state  = is_array( $verification ) ? (string) ( $verification['state'] ?? 'none' ) : 'none';
		$record = is_array( $verification ) && is_array( $verification['record'] ?? null ) ? $verification['record'] : null;
		if ( 'manifest_changed' === $state ) {
			return __( 'Not checked: its manifest changed after the last check.', 'wp-checkpoint' );
		}
		if ( 'current' !== $state || null === $record ) {
			return __( 'Not checked yet.', 'wp-checkpoint' );
		}
		$depth = 'structure' === $record['depth'] ? __( 'structure', 'wp-checkpoint' ) : __( 'full', 'wp-checkpoint' );
		/* translators: 1: time of the check, 2: depth (full or structure), 3: what it found */
		return sprintf( __( 'Last check (%1$s, %2$s): %3$s', 'wp-checkpoint' ), self::local_time( (string) $record['verified_at'] ), $depth, self::outcome_text( (string) $record['outcome'] ) );
	}

	/**
	 * A check's outcome in words.
	 *
	 * @param string $outcome VerificationResult outcome.
	 * @return string
	 */
	private static function outcome_text( string $outcome ): string {
		switch ( $outcome ) {
			case VerificationResult::PASSED:
				return __( 'intact.', 'wp-checkpoint' );
			case VerificationResult::PASSED_PARTIAL:
				return __( 'intact as far as it was checked.', 'wp-checkpoint' );
			case VerificationResult::FAILED:
				return __( 'damaged.', 'wp-checkpoint' );
			case VerificationResult::INVALID:
				return __( 'could not be read.', 'wp-checkpoint' );
			case VerificationResult::CHANGED:
				return __( 'its files changed while they were checked.', 'wp-checkpoint' );
			case VerificationResult::UNREADABLE:
				return __( 'this server could not read it; that is not damage.', 'wp-checkpoint' );
		}
		return __( 'could not be checked.', 'wp-checkpoint' );
	}
}
