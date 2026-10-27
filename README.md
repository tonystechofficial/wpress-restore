# WPress Restore

A WordPress plugin that restores All-in-One WP Migration backups (`.wpress` files) of any size, for free.

Put the backup in `wp-content/ai1wm-backups/` by FTP or SFTP (or send it with the built-in uploader), open **Tools → WPress Restore**, click **Restore**. The work runs a few seconds per request, so no upload size limit and no PHP time limit applies.

## Install

1. Build the zip: `python bin/build.py` → `dist/wpress-restore.zip`. Or take the zip handed to you.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, activate.

Requirements: WordPress 5.6+, PHP 7.4+ (64-bit for backups of 2 GB or more), a single site (not a network), plugin installs allowed (no `DISALLOW_FILE_MODS`, since a restore writes plugins and themes), and WordPress's standard database connection (not a HyperDB, LudicrousDB or SQLite drop-in).

## Restore a backup

1. Upload the `.wpress` file into `wp-content/ai1wm-backups/`. With FTP, use binary mode. The uploader on the page works too: it sends the file in pieces under the host's upload limit, and an interrupted upload resumes when you pick the same file again.
2. **Tools → WPress Restore → Restore**. The plugin reads the backup and checks its checksum, then shows what it contains: source address, versions, file count, database size, plugins, theme, plus warnings (newer WordPress or PHP than this server, disk space).
3. Tick the box and click **Restore now**. For a password-protected backup, type its password first.
4. At the end you are logged out: log in with a username and password **from the backup**. The page offers to delete the backup file. Do it once you no longer need the file: it holds the whole database.

## What a restore does

| Phase | What happens | Site changed? |
|---|---|---|
| Reading, checking | Walks every header, verifies the archive CRC32 | No |
| Unpacking the database | Decrypts and decompresses `database.sql` into the working folder | No |
| Importing the database | Imports into tables with a temporary prefix (`wprxxxxxx_`), rewriting addresses and paths | No |
| Restoring files | Writes plugins, themes, uploads and other wp-content files, each moved into place in one rename | Files only |
| Switching | Adjusts the restored options, then one `RENAME TABLE` swaps every table at once; must-use plugins are written last | Yes |

A backup whose database cannot be imported, or lacks the tables WordPress needs, stops before any file is written, so the site keeps running as it was. Every phase saves where it stopped: **Retry** resumes after an error, and closing the tab only pauses the restore. The switch and the moves that follow it happen in a single request, because the switch ends your session.

During a restore, a must-use plugin (`wpress-restore-guard.php`) keeps other plugins and the theme from loading in the restore's own requests. Their files are being replaced, and half-written code must not break the requests that finish writing it. The guard removes itself at the end.

Like All-in-One WP Migration's import, the restore:

- keeps this site's address and upload settings, and moves every spelling of the old address (http, https, scheme-less, www, URL-encoded, JSON-escaped, email domains) and the old server paths, keeping serialized data valid;
- reactivates the backup's theme and plugins (ai1wm records them in `package.json`), and keeps All-in-One WP Migration itself active if the site runs it;
- turns off plugins that hide the login page, and plugins that force HTTPS when the site has no HTTPS (the result screen lists them);
- leaves out caches and sessions (transients, WooCommerce sessions), drop-ins tied to the old server (`object-cache.php`, `advanced-cache.php`, `db.php`…), hosting companies' must-use plugins, and regenerated CSS;
- keeps files that exist only on this site.

It differs where that makes restores safer:

- **The database is switched in one step** instead of table by table into the live site.
- **Collations come from the server's own list.** On MariaDB 10.6, All-in-One WP Migration 7.112 reports success but drops the tables a MySQL 8 site created with `utf8mb4_0900_ai_ci` (measured: 12 tables restored out of 15). This plugin maps them to `utf8mb4_unicode_520_ci` and restores all 15. The same goes for MariaDB's `uca1400` collations on MySQL.
- **Statements run only against the backup's own table names.** Anything else is refused and logged, including tables built to copy or reach other tables or servers (`CREATE … SELECT`, MERGE, FEDERATED). A backup still carries PHP code, so restore only backups you trust.
- **Table names are compared the way the server does.** On servers that fold names to lowercase (Windows and macOS, `lower_case_table_names`), `wp_Foo` and `wp_foo` are the same table.
- **Table creation errors stop the restore** (before the switch) instead of being ignored. Rows the server refuses are counted, logged, and shown.

## Supported backups

- All-in-One WP Migration 6 and 7 formats, from any host.
- Password-protected (AES-256), gzip- and bzip2-compressed backups. bzip2 needs PHP's bz2 extension on the server; without it the plugin says so before changing anything.
- Single sites. Network (multisite) backups are refused.

Tested end to end with real All-in-One WP Migration 7.112 exports: MySQL 8 → MariaDB 11.4, MariaDB 10.6 and MySQL 5.7; MariaDB 11.4 → MySQL 8; a site over itself; encrypted with gzip; the pre-7 format; a 2.3 GB archive restored over 21 requests and uploaded in 1,222 pieces under a 2 MB upload limit, byte for byte.

## Not supported, known limits

- Creating backups: use All-in-One WP Migration for that, on the source site.
- WordPress networks (multisite).
- Addresses hidden in base64 by a few page builders (WPBakery raw HTML blocks, Oxygen, Avada, BeTheme, OptimizePress) are not moved when the domain changes; All-in-One WP Migration handles those. Restoring onto the same address is unaffected.
- Rows are imported one statement at a time, as the dump writes them: a database of millions of rows takes minutes.
- If the host kills a request mid-step (memory or process limits), the next step replays what that request had not yet saved. InnoDB tables roll back, so nothing is doubled. Tables kept as MyISAM cannot roll back: a few rows may then be reported as refused, or doubled in tables without a key.
- A backup from WordPress 6.8 or later restored onto an older WordPress: the backup's passwords use the bcrypt format older versions cannot check, so nobody can log in until WordPress is updated. The summary says so in red before you confirm. Update WordPress first.

## Security notes

- Only administrators who may install plugins (`manage_options` and `install_plugins`) can use the page, and every request carries a WordPress nonce.
- The password of an encrypted backup is never stored. The job keeps only the key derived from it, which opens that backup but not the password, and erases the key when the restore ends.
- The working folder `wp-content/wpress-restore/job-<random>/` holds the unpacked dump while a restore runs. Its name cannot be guessed, and it denies web access on Apache (`.htaccess`) and IIS (`web.config`). On nginx, add:
  `location ~ ^/wp-content/wpress-restore/ { deny all; }`
- Like All-in-One WP Migration, the backups folder only blocks directory listing: a backup can be downloaded by anyone who knows its file name. Delete backups once restored, or deny `wp-content/ai1wm-backups/*.wpress` in the server configuration.

## Develop

```
bin/test.sh                       # unit tests on PHP 7.4 and 8.3 (Docker)
python bin/build.py               # dist/wpress-restore.zip
cd dev && docker compose up -d    # site A :8081 (MySQL 8) and site B :8082 (MariaDB 11.4)
```

The unit tests cover what decides things from its inputs: the archive format, decryption and decompression, entry paths, address replacement and serialized data, the SQL reader and rewriter, collations, uploads, and the plugin, theme and package logic. `tests/support/Ai1wm.php` writes archives byte for byte as All-in-One WP Migration 7 does.

`dev/` holds the end-to-end tools: `seed-site-a.php` (content that must survive a move), `drive.py` (uploads and restores through the AJAX endpoint like the page does), `verify-site-b.php` (23 checks on the restored site), `ui-test.mjs` (the admin page in headless Chrome), `reset-site-b.sh` (`DB_B_IMAGE=mariadb:10.6` or `mysql:5.7` to vary the server), `make-bad-backups.php` and `repack.php` (damaged, hostile, old-format and bzip2 variants), `inspect.php` (reads any `.wpress` with the plugin's classes).

## License

GPL-2.0-or-later. Reads the format of All-in-One WP Migration (GPL, by ServMask), which it neither includes nor is affiliated with.
