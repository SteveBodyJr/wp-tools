=== Beaver FileManager ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: file manager, file editor, code editor, ftp, backup
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Browse, search, upload, download, compress and edit every file on your site from wp-admin — with syntax checking, automatic backups and a restorable trash.

== Description ==

Beaver FileManager puts a real file manager inside WordPress. Not the crippled theme editor — the whole site: `wp-config.php`, `.htaccess`, plugin folders, uploads, log files, anything inside the root you choose.

It is built for the moment FTP is not an option: a client's host has no SFTP, you are on a phone, or you just need to fix one line in a template before a meeting.

**Browsing**

* Two-pane layout — a folder tree on the left, a sortable listing on the right.
* Breadcrumb navigation, plus one-click shortcuts to wp-content, the active theme, plugins, mu-plugins and uploads.
* Sort by name, size or date. Multi-select with click, Ctrl-click and Shift-click.
* File size, octal permissions, the `-rw-r--r--` string, owner, group, MIME type, MD5 checksum and image dimensions.
* Recursive search by file name, or by what is *inside* files, with matching line numbers shown under each result.

**Editing**

* Syntax highlighting for PHP, JavaScript, JSON, CSS, SCSS, HTML, XML, Markdown, YAML, SQL, shell and ini files, using the CodeMirror build that ships with WordPress — no external assets are loaded.
* **PHP files are parsed before they are written.** A file that would white-screen your site is refused, with the line number, and the cursor jumps there. You can still override it deliberately.
* Every save copies the previous version aside first. Open the History panel to read an old version, load it into the editor, or restore it outright.
* If the file changed on the server after you opened it, the save stops and asks instead of quietly discarding somebody else's work.
* Writes go to a temporary file that is then renamed into place, carrying the original permissions across, so an interrupted save cannot leave half a file behind.
* Ctrl/Cmd + S saves, Esc closes.

**Managing**

* Create files and folders, rename, copy, cut, paste, and change permissions — recursively if you want.
* Drag files onto the listing to upload them, with a progress bar. Or use the Upload button.
* Download any file. Select several items, or a whole folder, and it is zipped for you first.
* Create and extract zip archives in place, and look inside an archive without extracting it.
* Delete through a restorable trash, or permanently when you mean it.
* Preview images, video, audio and PDFs without leaving the page.

**Staying out of trouble**

* Everything is jailed to one root folder that you choose: the WordPress root, wp-content, uploads only, or any absolute path. `..` is stripped before the filesystem is touched, and every resolved path is re-checked — including symlinks that try to point outside.
* Read-only mode allows browsing, searching and downloading, and refuses every write at the endpoint, not just in the interface.
* `DISALLOW_FILE_EDIT` is honoured. If your wp-config.php sets it, the manager stays read-only until you explicitly opt out in Settings.
* Every request is capability-checked and nonce-checked. Downloads and previews are signed links, never public URLs.
* Nothing is ever served back with a type the browser will execute in the admin origin, and SVG previews are sandboxed.
* Backups and the trash live in an unguessable folder inside uploads, protected with `.htaccess`, `web.config` and an index file.
* An activity log records who changed which file, when, and from where.

**No front end, no phoning home**

The plugin registers nothing on the front end and makes no external requests. It uses only PHP functions and the CodeMirror build already bundled with WordPress.

== Installation ==

1. Upload the `beaver-filemanager` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload.
2. Activate it.
3. Open **Beaver Files** in the admin menu.
4. Check **Beaver Files → Settings** and pick the root folder you want to be able to reach.

== Frequently Asked Questions ==

= Who can use it? =

Administrators only — `manage_options`. On multisite that means super administrators, since `manage_options` is what the network grants. To hand it to a different role, filter it:

`add_filter( 'beaver_fm_capability', function () { return 'my_custom_cap'; } );`

= My wp-config.php has DISALLOW_FILE_EDIT. Why can't I edit anything? =

That is deliberate. The constant exists to say "nobody edits files through the browser on this site", and a file manager that ignored it would make the setting meaningless. Beaver Files → Settings has a checkbox to opt out if you set that constant yourself and still want a way in.

= Can I lock it down further? =

Yes. Set the root to **Uploads only** so nothing outside the media folder is reachable, or switch on **Read-only mode** to allow browsing and downloading but no changes at all. Both are enforced server-side.

= Where do backups go, and how many are kept? =

Into an unguessable folder inside your uploads directory, ten versions per file by default (configurable, one to a hundred). Beaver Files → Activity Log shows how much space backups and trash are using.

= Something went wrong after I saved a file. =

Open the file again and use the History panel — every previous version is there with a timestamp and who saved it. If you deleted something instead, open the Trash and restore it.

= Deleting the plugin — what happens to my backups? =

Deleting the plugin removes its settings, log, stored versions and trash. To keep the stored versions and trash even through a delete, add to wp-config.php:

`define( 'BFM_KEEP_DATA_ON_UNINSTALL', true );`

= Can I compress a whole folder? =

Yes, if the server has PHP's ZipArchive extension, which nearly all do. Select the folder and press Compress. Extract works either way — there is a fallback for servers without it.

= Does it work on Windows / XAMPP? =

Yes. Paths are normalized and compared case-insensitively on Windows.

== Screenshots ==

1. The file manager: folder tree, sortable listing, and the toolbar.
2. The editor, with syntax highlighting and the version history panel open.
3. Searching inside file contents, with matching lines under each result.
4. Settings — root folder, read-only mode, backups and limits.

== Credits ==

Designed and built by [Digital Beaver](https://digitalbeavertz.com/) — WordPress design, development and hosting.

== Changelog ==

= 1.1.0 =
* Added the Digital Beaver maker's mark to the file manager, activity log and settings screens, and to the admin footer on those screens.

= 1.0.1 =
* Fixed the upload dropzone staying on screen permanently, which put a dashed border and a backdrop blur over the file listing and made names hard to read.
* On a read-only site, the editor's Save button and the trash's Restore and Delete buttons are now hidden instead of failing when pressed.

= 1.0.0 =
* First release.
* Sandboxed browsing with a configurable root and symlink-aware path checks.
* Code editor with WordPress's bundled CodeMirror, PHP syntax checking before write, conflict detection and automatic versioned backups.
* Create, rename, copy, move, chmod, upload, download, zip and unzip.
* Recursive name and content search with line numbers.
* Restorable trash, activity log, read-only mode and `DISALLOW_FILE_EDIT` support.

== Upgrade Notice ==

= 1.1.0 =
Adds Digital Beaver branding to the plugin screens.

= 1.0.1 =
Fixes an overlay that sat on top of the file listing and made file names hard to read.

= 1.0.0 =
First release.
