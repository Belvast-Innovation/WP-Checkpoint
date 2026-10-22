=== WP Checkpoint ===
Contributors: belvast
Tags: backup, migration, restore, rollback, search replace
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup, migration and safe updates. Restores are always free, every change can be undone.

== Description ==

(T103: write final description, FAQ, privacy notes and the "free forever" feature list.)

== Frequently Asked Questions ==

= Does WP Checkpoint change how plugins are updated automatically? =

Only its own automatic update, and only while a restore is in progress. WP Checkpoint uses the `auto_update_plugin` filter for itself alone: while a restore is queued, running or paused, or has failed and its work files have not been cleaned up yet (they are kept for 7 days, for a retry, and removed at WP Checkpoint's next housekeeping after that: when a job runs or its admin page is opened), it answers "do not update"; at any other time it passes WordPress's decision through unchanged. A restore puts the running copy of WP Checkpoint into the restored site, and an update in the middle would leave the plugin a different version partway through the restore. If WP Checkpoint cannot read its own job table, it cannot rule out a restore in progress, so it holds the update then too, and says so on its admin page. Other plugins, themes and WordPress itself are never affected, and a manual update is not blocked.

= Can I run WP-CLI commands while a restore is in progress? =

Do not run WP-CLI on the site while a restore swaps it in: from the moment the swap starts until it ends, its tables and directories may be half replaced. WordPress shows visitors a maintenance page then, but WP-CLI does not stop for it, so a system cron that runs `wp cron event run`, or any other command, would work on that half-replaced site. While a restore holds the site, WP Checkpoint refuses every WP-CLI command but its own (`wp wpcheckpoint …`, which finishes or puts back the restore) and `wp help`, and says why; `wp wpcheckpoint job list` shows the restore and what finishes it. It cannot stop a command run with `--skip-plugins`, or one on a site where WP Checkpoint is not active, nor what plugins loaded before it do as they load.

== Changelog ==

= 0.1.0 =
* Initial release.
