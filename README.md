# Digital Beaver WP Tools

Fourteen WordPress plugins built for running client sites on ordinary shared
hosting. Each one is self-contained: no framework, no build step, no external
service, and no API key ships with any of them. Every site supplies its own.

They are written to work on the hosting most small sites actually run on. No
root access, no command line requirement, no imaging binaries beyond what PHP
already bundles, and nothing that assumes a persistent object cache.

---

## The plugins

| Plugin | Version | What it does |
|---|---|---|
| [beaver-ai-chat](beaver-ai-chat/README.md) | 1.6.2 | AI chat assistant that answers from your own content, captures leads, hands off to your team. |
| [beaver-access](beaver-access/README.md) | 1.0.2 | Temporary login links. Time-limited access at a chosen role, no password shared. |
| [beaver-alt-text](beaver-alt-text/README.md) | 1.2.0 | Writes alt text for images that have none, using a vision model. Reviewed before publishing. |
| [beaver-appbar](beaver-appbar/README.md) | 1.0.0 | Gives a site the bottom tab bar of a phone app. Any theme, no external request. |
| [beaver-chameleon](beaver-chameleon/README.md) | 1.0.0 | A daily-mutating honeypot and a human-interaction trap for the comment and login forms. Invisible to visitors, every block logged. |
| [beaver-debug](beaver-debug/README.md) | 1.1.0 | Records what goes wrong on a site you cannot SSH into, and makes it shareable. |
| [beaver-filemanager](beaver-filemanager/README.md) | 1.1.0 | A full file manager inside wp-admin, with backups and a restorable trash. |
| [beaver-image-optimizer](beaver-image-optimizer/README.md) | 1.3.6 | Converts JPEG and PNG to WebP using only what PHP already ships with. |
| [beaver-pwa](beaver-pwa/README.md) | 1.0.0 | Makes a site installable as an app: manifest, offline worker, generated icons, install prompt. |
| [beaver-seo](beaver-seo/README.md) | 1.0.0 | Titles, meta descriptions, Open Graph tags, canonical URLs, an XML sitemap and a redirect manager — generated from what WordPress already knows. |
| [beaver-sync](beaver-sync/README.md) | 1.0.0 | Brings the live site's media down to a local copy over HTTPS. Read-only on the live site. |
| [beaver-shutter](beaver-shutter/README.md) | 1.0.0 | Puts a holding page over the front end for a launch window or a migration, and takes it off again. wp-admin is never touched. |
| [beaver-translate](beaver-translate/README.md) | 1.4.0 | AI-drafted translations for posts and pages, reviewed by a human before anything goes live, each published at its own /fr/ address. |
| [beaver-updates](beaver-updates/README.md) | 1.1.0 | Puts the others into Plugins → Updates, and lists the ones a site is missing so they can be added in a click. |

Each folder has its own README covering how that plugin works and why it makes
the choices it does.

## Install

Download the zip for a plugin from [Releases](../../releases) and upload it
under **Plugins → Add New → Upload**, or unzip it into `wp-content/plugins/`.

Release tags are per plugin, in the form `beaver-pwa-1.0.0`, because the
plugins are versioned independently of each other.

**Install [beaver-updates](beaver-updates/README.md) first and the rest become
one click.** It puts everything else on this list into Plugins → Updates, and
it works with whatever is already installed: no other plugin needs re-uploading
for it to take effect.

## Where each one appears after activating

Several deliberately have no top-level menu, which makes them easy to miss.
All but Debug also put a shortcut in their own row on the **Plugins** screen,
which is the reliable way in when the menu item is not where you expect it.

| Plugin | wp-admin location |
|---|---|
| beaver-ai-chat | **AI Chat** (top level) |
| beaver-access | **Users → Access Links** |
| beaver-alt-text | **Alt Text** (top level) |
| beaver-appbar | **Appearance → App Bar** |
| beaver-chameleon | **Chameleon Shield** (top level) |
| beaver-debug | **Tools → Beaver Debug** |
| beaver-filemanager | **Beaver Files** (top level) |
| beaver-image-optimizer | **Beaver Optimizer** (top level) |
| beaver-pwa | **Beaver PWA** (top level) |
| beaver-seo | **SEO** (top level) |
| beaver-shutter | **Tools → Shutter** |
| beaver-sync | **Tools → Beaver Sync** |
| beaver-translate | **Translate** (top level) |
| beaver-updates | **Tools → Beaver Updates** |

## How they are built

Every plugin here follows the same shape, which is what makes any of them quick
to pick up:

* `plugin-name.php` holds the bootstrap, the constants and the activation and
  deactivation hooks. Nothing else.
* `includes/class-*.php` is one class per concern. No framework, no autoloader,
  no dependency to install.
* `admin/css/` and `admin/js/` are enqueued on that plugin's own screens only,
  so no other page in wp-admin pays for them.
* `uninstall.php` rather than `register_uninstall_hook()` with a closure. That
  function stores its callback in the database, and a closure cannot be
  serialized, so it is fatal at activation.
* `readme.txt` in the WordPress format, `README.md` for anyone reading the
  source.
* WP-CLI commands wherever a job might run long enough to want them.

The version appears in three places and they move together: the plugin header,
the `*_VERSION` constant, and the stable tag plus changelog in `readme.txt`. A
mismatch there means asset cache busting silently stops working.

## Requirements

WordPress 5.8 or newer and PHP 7.4 or newer, across all of them. AI Chat and
Alt Text additionally need outbound HTTPS and a key for whichever model
provider you choose.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

Built by [Digital Beaver](https://digitalbeavertz.com/), Arusha, Tanzania.
