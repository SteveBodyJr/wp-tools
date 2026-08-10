=== Beaver Sync ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: media sync, migration, staging, uploads, download media
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Brings the live site's media down to a local copy over HTTPS. No SSH, no FTP, and nothing is ever written to the live site.

== Description ==

Working on a local copy of a site whose media library lives on the server is a nuisance: every page you open is full of broken images. Beaver Sync fixes that by copying the media down, and only the part of it you are missing.

**How it works**

The live site publishes one read-only list of the files it holds. The local copy fetches that list, compares it against its own uploads folder, shows you exactly what differs, and downloads only that. Files come down over ordinary HTTPS from the addresses the live site already serves to the public, so nothing has to be tunnelled and no credentials are shared.

**What it will not do, on purpose**

* **It never writes to the live site.** There is no upload route, no delete route, no write of any kind. The only endpoint is a GET that answers with a file list. A sync tool that can push files into production is a remote code execution endpoint wearing a friendly name, and this job does not need one.
* **It never deletes locally.** A file you have that the server does not is counted, shown, and left exactly where it is.
* **It only carries media.** Anything the source offers that is not a known media extension is refused, so even a source that had been tampered with cannot put a PHP file into your uploads folder.
* **It does not touch the database.** Your live content stays the source of truth for content.

**Built for real hosting**

A library of three thousand images will not arrive inside one PHP request on shared hosting, so the work is done in small batches with a progress bar, and it resumes where it stopped. For a first pull of a large library, use WP-CLI and let it run.

**You always see it first**

Checking for differences changes nothing. You get counts, a list of what would be copied, its total size, and a list of what you have that the server does not, before anything is downloaded.

== Installation ==

The same plugin goes on both sites and does opposite jobs.

1. On the **live site**: upload, activate, go to **Tools → Beaver Sync**, choose *The live site*, save, and copy the sync key it shows you.
2. On the **local copy**: upload, activate, choose *The local copy*, paste the live site's address and that key, and save.
3. Press **Check for differences**, read the list, then **Copy these files**.

Better still, put the key in the live site's `wp-config.php` and it never touches the database at all:

`define( 'BEAVER_SYNC_KEY', 'your-long-random-key' );`

WP-CLI: `wp beaver-sync pull` for a dry run, `wp beaver-sync pull --live` to copy.

== Frequently Asked Questions ==

= Can this overwrite my live site? =

No. The live site has no write route at all. The strongest thing that can happen with a leaked key is that someone reads the list of files your site already serves publicly.

= What is the key protecting, then? =

The index, not the files. Every file listed is already downloadable by anyone who knows its address. The list is the complete inventory of everything you hold, including anything uploaded and never linked, and that is worth not handing out.

= Will it delete my local files? =

Never. Files you have that the live site does not are reported so you can see them, and left alone.

= How does it decide a file has changed? =

By size, the same way rsync's quick check does. Media is written once and not edited in place, so size is a strong signal. Modified times are deliberately ignored: the same file copied between two machines routinely arrives with a new timestamp, and treating that as a change would mean re-downloading the whole library on every run.

= It stops part way through =

It resumes. Press Continue, or run the WP-CLI command, which has no request timeout to run into.

= Does it sync themes, plugins or the database? =

No, and that is deliberate. Code belongs in an update channel where a version number records what is installed, and the database belongs to production, which is where your client writes.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
