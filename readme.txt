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

Only its own automatic update, and only while a restore is in progress. WP Checkpoint uses the `auto_update_plugin` filter for itself alone: while a restore is queued, running or paused, or has failed and its work files are still kept (up to 7 days, so that it can be retried), it answers "do not update"; at any other time it passes WordPress's decision through unchanged. A restore puts the running copy of WP Checkpoint into the restored site, and an update in the middle would leave the plugin a different version partway through the restore. If WP Checkpoint cannot read its own job table, it cannot rule out a restore in progress, so it holds the update then too, and says so on its admin page. Other plugins, themes and WordPress itself are never affected, and a manual update is not blocked.

== Changelog ==

= 0.1.0 =
* Initial release.
