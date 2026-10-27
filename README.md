# WPress Restore

A WordPress plugin that creates and restores All-in-One WP Migration backups (`.wpress` files) of any size, for free.

Put the backup in `wp-content/ai1wm-backups/` by FTP or SFTP (or send it with the built-in uploader), open **Tools → WPress Restore**, click **Restore**. Or click **Create backup** to save the site into a `.wpress` file that this plugin and All-in-One WP Migration both restore. The work runs a few seconds per request, so no upload size limit and no PHP time limit applies.

## Install

1. Download `wpress-restore.zip` from the [latest release](https://github.com/tonystechofficial/wpress-restore/releases/latest), or build it: `python bin/build.py` → `dist/wpress-restore.zip`.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, activate.

Requirements: WordPress 5.6+, PHP 7.4+ (64-bit for backups of 2 GB or more), a single site (not a network), plugin installs allowed (no `DISALLOW_FILE_MODS`, since a restore writes plugins and themes; the page is refused otherwise), and WordPress's standard database connection (not a HyperDB, LudicrousDB or SQLite drop-in).

## Restore a backup

1. Upload the `.wpress` file into `wp-content/ai1wm-backups/`. With FTP, use binary mode. The uploader on the page works too: it sends the file in pieces under the host's upload limit, and an interrupted upload resumes when you pick the same file again.
2. **Tools → WPress Restore → Restore**. The plugin reads the backup and checks its checksum, then shows what it contains: source address, versions, file count, database size, plugins, theme, plus warnings (newer WordPress or PHP than this server, disk space).
3. Tick the box and click **Restore now**. For a password-protected backup, type its password first.
4. At the end you are logged out: log in with a username and password **from the backup**. The page offers to delete the backup file. Do it once you no longer need the file: it holds the whole database.

## Create a backup

1. **Tools → WPress Restore → Create a backup.** Optionally leave out the media library, post revisions, spam comments or the `wp-content/cache` folder; compress with gzip (or bzip2, where PHP has the bz2 extension); type a password twice to encrypt the backup.
2. Click **Create backup**. The page lists the files, saves the database, packs both, then checks the archive. Closing the tab only pauses it: **Resume** carries on.
3. The finished backup appears in the list with a **Download** button. Download it and keep a copy away from the server: a backup kept only on the server it protects is lost with it.

## What a backup holds

| Phase | What happens |
|---|---|
| Listing the files | Lists wp-content: everything at its root, then uploads, plugins and themes, wherever they live |
| Saving the database | Writes the site's tables into `database.sql` in the working folder, a batch of rows per query |
| Packing the files | Writes `package.json`, the files, then the dump into an archive in the working folder, in 512,000-byte chunks |
| Finishing | Computes the archive's CRC32, writes the end block, moves the archive into the backups folder |

Nothing on the site changes, and a half-made backup never shows in the list: the archive is built in the working folder and moved into the backups folder once complete. Every phase saves where it stopped, mid-table or mid-file: **Retry** resumes after an error.

Like All-in-One WP Migration's export, the backup:

- holds the tables with the site's prefix, plus the tables of the plugins that ignore it (`wbk_`, `civicrm_`), and the views; table names and the names WordPress builds from the prefix (`wp_user_roles`, `wp_capabilities`…) carry the `SERVMASK_PREFIX_` placeholder, so the backup restores under any prefix;
- leaves the theme and the active plugins to `package.json`, which both importers read;
- leaves out other backups, this plugin and All-in-One WP Migration (each importer brings its own copy), and All-in-One WP Migration's own settings (secret key, credentials);
- leaves out a file that disappears or becomes unreadable between the listing and its turn, and says so in the log.

It differs where that makes backups better:

- **WordPress's own `wp_` options keep their names.** All-in-One WP Migration marks every option whose name starts with the prefix. With the prefix `wp_`, that includes core options such as `wp_attachment_pages_enabled` and `wp_notes_notify`: restored onto a site whose prefix is `wpb_`, they become `wpb_attachment_pages_enabled` and `wpb_notes_notify`, where WordPress no longer finds them (measured with All-in-One WP Migration 7.112 importing its own backup). This plugin marks only `wp_user_roles`.
- **Generated columns are written as `DEFAULT`.** The server computes them again on import. MySQL refuses any other value for them: a dump that writes their values, as All-in-One WP Migration's does, loses those rows on MySQL 8 (measured: 2 rows of 2).
- **Caches and sessions stay out** (transients, WooCommerce sessions): both importers drop them anyway.
- **Large tables are read by their key**, after the last row read, instead of by offset; rows of a megabyte or more are fetched a few at a time, so PHP's memory stays bounded.

Tested end to end:

- Compared with All-in-One WP Migration 7.112's export of the same site: the same 448 files, byte for byte; the dump's statements identical apart from the differences above.
- Restored by this plugin and by All-in-One WP Migration 7.112 (driven through its own AJAX import): plain, gzip with a password, and from an old host (PHP 7.4, WordPress 6.1, MariaDB 10.6), onto MySQL 8 and MariaDB 11.4, with the 23 checks of `dev/verify-site-b.php`.
- A 362 MB site with a 300 MB file and 34,000 rows (a keyed table with gaps, a composite key, no key with duplicate rows, 1 MB binary rows), exported in 17 steps of 0.05 s and restored identical: same row hashes, same file.
- A file removed in the middle of its packing, a cancel in the middle of the dump, a backups folder that refuses the move then a retry, a locked-down host (`disable_functions`, `open_basedir`), and the page in headless Chrome.

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

- WordPress networks (multisite).
- A backup is made while the site stays online, a few seconds at a time, as with All-in-One WP Migration: a post or an upload saved during the backup may be caught half-way. Create backups at a quiet time.
- Backups made by All-in-One WP Migration of tables with generated columns: MySQL refuses those rows, and the restore reports them as refused. Backups made by this plugin restore them.
- When the backups folder lies outside the site (a custom `AI1WM_BACKUPS_PATH`), backups have no download link: fetch them by FTP or SFTP.
- Addresses hidden in base64 by a few page builders (WPBakery raw HTML blocks, Oxygen, Avada, BeTheme, OptimizePress) are not moved when the domain changes; All-in-One WP Migration handles those. Restoring onto the same address is unaffected.
- Rows are imported one statement at a time, as the dump writes them: a database of millions of rows takes minutes.
- If the host kills a request mid-step (memory or process limits), the next step replays what that request had not yet saved. InnoDB tables roll back, so nothing is doubled. Tables kept as MyISAM cannot roll back: a few rows may then be reported as refused, or doubled in tables without a key.
- A backup from WordPress 6.8 or later restored onto an older WordPress: the backup's passwords use the bcrypt format older versions cannot check, so nobody can log in until WordPress is updated. The summary says so in red before you confirm. Update WordPress first.

## Security notes

- Only administrators who may install plugins (`manage_options` and `install_plugins`) can use the page, and every request carries a WordPress nonce.
- The password of an encrypted backup is never stored, whether the plugin restores or creates it. The job keeps only the key derived from it, which opens that backup but not the password, and erases the key when the job ends.
- The working folder `wp-content/wpress-restore/job-<random>/` holds the dump and the archive being built while a job runs. Its name cannot be guessed, and it denies web access on Apache (`.htaccess`) and IIS (`web.config`). On nginx, add:
  `location ~ ^/wp-content/wpress-restore/ { deny all; }`
- Like All-in-One WP Migration, the backups folder only blocks directory listing, so its **Download** links are plain file addresses that the web server sends: anyone who knows a backup's file name can download it. Backups this plugin creates end with 12 random characters, so their names cannot be guessed; a backup uploaded under a simple name can be. Encrypt backups with a password, download them, and delete them from the server, or deny `wp-content/ai1wm-backups/*.wpress` in the server configuration (the links then stop working: fetch backups by FTP or SFTP).

## Develop

```
bin/test.sh                       # unit tests on PHP 7.4 and 8.3 (Docker)
python bin/build.py               # dist/wpress-restore.zip
cd dev && docker compose up -d    # site A :8081 (MySQL 8) and site B :8082 (MariaDB 11.4)
```

The unit tests cover what decides things from its inputs: the archive format, decryption and decompression, entry paths, address replacement and serialized data, the SQL reader and rewriter, collations, uploads, the plugin, theme and package logic, and for backups the archive writer, the dump format, the rows and folders a backup holds, the file listing, the package and the names. `tests/support/Ai1wm.php` writes archives byte for byte as All-in-One WP Migration 7 does.

`dev/` holds the end-to-end tools: `seed-site-a.php` (content that must survive a move), `seed-export-a.php` (tables, a large file and short steps that stress a backup, and their hashes), `drive.py` (uploads, restores, creates and downloads backups through the AJAX endpoint like the page does), `ai1wm.py` (drives All-in-One WP Migration's own import and export the same way), `verify-site-b.php` (23 checks on the restored site; `ai1wm` after an import by All-in-One WP Migration), `ui-test.mjs` and `ui-export-test.mjs` (the admin page in headless Chrome, through `chrome.mjs`), `reset-site-b.sh` (`DB_B_IMAGE=mariadb:10.6` or `mysql:5.7` to vary the server), `make-bad-backups.php` and `repack.php` (damaged, hostile, old-format and bzip2 variants), `inspect.php` (reads any `.wpress` with the plugin's classes), `compare-sites.py` (checks a restored copy against the original site over public HTTP: pages, content counts, active plugins, page text, files byte for byte, leftovers of the old domain).

## License

GPL-2.0-or-later. Reads and writes the format of All-in-One WP Migration (GPL, by ServMask), which it neither includes nor is affiliated with.
