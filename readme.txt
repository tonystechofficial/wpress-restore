=== WPress Restore ===
Tags: backup, restore, migration, wpress, all-in-one-wp-migration
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates and restores All-in-One WP Migration backups (.wpress) of any size.

== Description ==

Put a .wpress backup in wp-content/ai1wm-backups (by FTP or SFTP, or with the built-in uploader, which sends the file in small pieces), open Tools → WPress Restore, and click Restore. Or click Create backup to save this site into a .wpress file.

* No size limit: the work is done a few seconds at a time, so neither upload limits nor PHP time limits apply.
* Reads the format of All-in-One WP Migration 6 and 7, including password-protected and compressed (gzip, bzip2) backups.
* Writes backups in that same format, which this plugin and All-in-One WP Migration both restore: the database, media, plugins and themes, optionally compressed or encrypted with a password.
* Checks the backup's checksum before changing anything, so a file damaged in transfer is caught first.
* Imports the database beside the live one, under a temporary prefix, then switches the site to it in one step. A backup whose database cannot be imported leaves the site running as it was.
* Moves addresses and server paths to this site in every spelling sites store them (http, https, www, JSON, URL-encoded), keeping serialized data valid.
* Fits MySQL 8 backups onto MariaDB and the reverse (collations, storage engines, table options).
* While it restores, its own requests load no other plugin and no theme, so half-replaced plugin files cannot break the restore.

What it does not do: back up or restore a WordPress network (multisite).

== Frequently Asked Questions ==

= What happens to my login? =

The database is replaced by the backup's: at the end, log in with a username and password from the backup.

= Which plugins are turned off after the restore? =

Like All-in-One WP Migration: plugins that hide the login page, and plugins that force HTTPS when this site does not use HTTPS. The result screen lists them.

= What goes into a backup? =

The database, and everything in wp-content: media, plugins, themes, must-use plugins and the rest. Left out, as All-in-One WP Migration leaves them out: other backups, this plugin and All-in-One WP Migration themselves (each restore brings its own), and caches and sessions stored in the database. Media, post revisions, spam comments and the cache folder can be left out too.

= Where are backups kept? =

In wp-content/ai1wm-backups, the folder All-in-One WP Migration uses (or the AI1WM_BACKUPS_PATH constant). A backup contains the whole database: download it, keep a copy elsewhere, and delete it from the server once you no longer need it there.

== Changelog ==

= 1.1.0 =
* Creates backups that this plugin and All-in-One WP Migration both restore, a few seconds at a time: options to leave out media, post revisions, spam comments and the cache folder; gzip or bzip2 compression; encryption with a password.
* Download links for the backups in the list.

= 1.0.0 =
* First release.
