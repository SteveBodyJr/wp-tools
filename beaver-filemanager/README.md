# Beaver FileManager

A full file manager inside wp-admin — browse, search, upload, download, compress and edit any file on the site, with syntax checking, versioned backups and a restorable trash.

Part of the Digital Beaver WP Tools set.

---

## Layout

```
beaver-filemanager.php        Bootstrap: constants, requires, activation lifecycle
includes/
  class-settings.php          Option schema, sanitization, policy questions, Settings screen
  class-filesystem.php        The jail and every filesystem operation
  class-editor.php            Save pipeline, PHP lint, backups, trash
  class-logger.php            Activity log
  class-admin.php             Menus, the manager screen, all AJAX endpoints
admin/css/admin.css           Interface styles
admin/js/admin.js             Interface behaviour (vanilla, no jQuery)
uninstall.php                 Removes options and private storage on delete
```

## The security model

Four things are enforced, in this order, on every request that reaches an endpoint:

1. **Nonce** — `check_ajax_referer( 'beaver_fm_ajax' )`, or `wp_verify_nonce()` for the two streaming endpoints, which are browser navigations rather than fetches.
2. **Capability** — `manage_options` by default, filterable through `beaver_fm_capability`.
3. **Write policy** — read-only mode, `DISALLOW_FILE_EDIT`, and the `beaver_fm_can_write` filter. Endpoints that change something call `guard( true )`; the check lives at the endpoint, not in the interface.
4. **The jail** — `Beaver_FM_Filesystem::resolve()`.

`resolve()` is the piece worth reading. It takes an untrusted relative path and:

- rejects null bytes and normalizes separators,
- collapses `.` and `..` **before touching the filesystem**, so a traversal attempt never reaches a `stat()` call,
- joins it to the configured root,
- runs `realpath()` and confirms the result is still inside the root, which is what catches a symlink pointing out,
- refuses anything inside the plugin's own private storage,
- and, for paths that do not exist yet, applies the same check to the parent.

Everything else in the plugin goes through it. There is no second way to turn a request parameter into a path.

Streaming responses (`download`, `preview`) never send a type the browser will execute in the admin origin. Downloads are always `attachment`; inline previews are restricted to image, video, audio and PDF, sent with `X-Content-Type-Options: nosniff` and a locked-down CSP. SVG additionally gets the `sandbox` directive — it is the one inline type a browser will run script from.

## The save pipeline

`Beaver_FM_Editor::save()` runs three gates before anything is written:

1. **Conflict** — the editor sends the MD5 it opened with. If the file changed since, the save stops rather than discarding somebody else's work. The interface offers to force it.
2. **Syntax** — for PHP files, `token_get_all( $content, TOKEN_PARSE )` runs the real parser and throws `ParseError` on a broken file. The line number goes back to the editor, which jumps the cursor there. No shelling out to `php -l`, which most shared hosts disable.
3. **Backup** — the current contents are copied into the version store first.

The write itself goes to a temp file in the same directory and is renamed over the target, carrying permissions (and ownership, when running as root) across, so an interrupted write cannot leave a half-written theme file behind. `opcache_invalidate()` is called afterwards.

## Private storage

Backups and trash live in `wp-content/uploads/beaver-fm-{key}/`, where `{key}` is 12 random characters generated once and stored in the `beaver_fm_storage_key` option. The folder is protected with `.htaccess`, `web.config` and an `index.php`, and it is hidden from listings and refused by `resolve()`.

```
beaver-fm-{key}/
  backups/{md5(root|relpath)}/
    manifest.json          Which file this bucket belongs to
    {ts}-{rand}.bak        The stored contents
    {ts}-{rand}.json       Time, user, size
  trash/{ts}-{rand}/
    beaver-fm.json         Original path, name, whether it was a folder, who deleted it
    {original name}        The item itself
```

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `beaver_fm_capability` | `manage_options` | Capability required to use the manager |
| `beaver_fm_can_write` | `true` | Last word on whether writes are permitted |

## Constants

| Constant | Effect |
| --- | --- |
| `DISALLOW_FILE_EDIT` | Read-only unless the Settings opt-out is ticked |
| `BFM_KEEP_DATA_ON_UNINSTALL` | Keep backups, trash and settings when the plugin is deleted |

## AJAX endpoints

All are `wp_ajax_beaver_fm_*`. Reads: `list`, `tree`, `read`, `info`, `search`, `backups`, `backup_read`, `trash`. Writes: `save`, `create`, `rename`, `delete`, `transfer`, `chmod`, `upload`, `zip`, `unzip`, `backup_restore`, `trash_restore`, `trash_delete`, `trash_empty`. Streams: `download`, `preview`.

Every response is `wp_send_json_success` / `wp_send_json_error`; errors carry `message`, `code` and `data`, and the interface keys off `code` to offer the right recovery (`beaver_fm_conflict` → overwrite, `beaver_fm_parse_error` → jump to the line and offer to save anyway).

## Notes

- No front-end hooks, no external HTTP requests. CodeMirror comes from the copy WordPress already ships, via `wp_enqueue_code_editor()` — called once per language so each one's linter is registered.
- The interface builds DOM nodes with `textContent`, never `innerHTML`, because a file named `<img onerror=…>` is a legal file name on a screen that runs with administrator privileges.
- The accent colour comes from `--wp-admin-theme-color`, so the interface follows the user's admin colour scheme.
- Sorting, selection and the folder tree are client-side; only listings and file contents cross the wire.

## Requirements

WordPress 5.8+, PHP 7.4+. `ZipArchive` is needed to *create* archives; extraction falls back to `unzip_file()` without it.

## Credits

Designed and built by **[Digital Beaver](https://digitalbeavertz.com/)** — WordPress design, development and hosting.

The maker's mark renders through `Beaver_FM_Admin::render_credit()` at the foot of the file manager, activity log and settings screens, and `Beaver_FM_Admin::footer_text()` replaces the admin footer credit on those screens only. Both are attribution only: nothing in the plugin depends on them, and neither ever renders on the front end.
