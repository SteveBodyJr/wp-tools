# Beaver PWA

Makes a WordPress site installable as an app. It generates the three things a
browser looks for before it offers to install a site: a **web app manifest**, a
**service worker**, and a complete set of **app icons**. All three are produced
by WordPress on request, so nothing is written to the site root, nothing is
compiled, and no third party service is contacted.

---

## Install

1. Copy the `beaver-pwa` folder into `wp-content/plugins/` (or upload the zip
   under **Plugins → Add New → Upload**).
2. Activate it.
3. Go to **Beaver PWA → Dashboard** and read the Readiness panel. The plugin's
   row on the Plugins screen has a shortcut to the settings.

The site must be on **HTTPS**. Browsers refuse to register a service worker on
an insecure origin, with `localhost` the one exception for development.

## Nothing to configure to get started

Name, short name and description fall back to the site title and tagline; the
icon falls back to the site icon. Every field on the settings screen exists to
override a default, not to fill in a blank.

The one thing a site genuinely needs is an icon. Without a site icon (or a
dedicated app icon chosen in the settings) the manifest has no icons and no
browser will offer to install anything. The dashboard flags this as blocking and
an admin notice points at it.

## Icons are rendered, not requested

Every size the manifest advertises is produced from one source image with the GD
library PHP already ships with, so the set is always complete and correctly
sized rather than depending on whichever intermediate sizes happen to exist in
the media library.

That includes a **maskable** icon. Android crops home screen icons to a circle,
so the artwork is inset to the middle 80 per cent on the splash background
colour and nothing important is cut off. A non-square source is contained, never
cropped. Change the source image and the whole set is rebuilt.

Files land in `uploads/beaver-pwa/` stamped with a signature, and stale ones are
removed on each rebuild.

## The caching, in one table

| Request | Strategy | Why |
|---|---|---|
| Pages | Network first, cached copy kept only as the offline fallback | Nobody reads yesterday's article out of a cache |
| CSS, JS, fonts | Cache first, refreshed in the background | Instant repeat loads, current within one visit |
| Images | Cache first, hard entry limit | A large media library must not fill a phone |

Never cached, on top of anything you add: wp-admin, wp-login, wp-cron, the REST
API, XML-RPC, feeds, sitemaps, previews, the customiser, the Elementor editor,
and WooCommerce cart, checkout and account pages (resolved by page ID, not
guessed from paths).

Nor is anything WordPress marks `private` or `no-store`, which is what keeps a
signed-in visitor's pages out of the cache **without** having to switch the app
off for them.

## It removes itself when it should

A service worker outlives the page that installed it. That is how sites end up
frozen in visitors' browsers months after a plugin was switched off, serving a
copy nobody can update.

This one checks in with the site each time it starts. If the plugin has been
disabled or deleted, that request is refused and the worker clears its caches
and unregisters instead of serving a stale site for ever. A **failed** request
proves nothing, since the device may simply be offline, so only a definite
answer from the server counts.

## Cache invalidation is automatic

The worker's cache names carry a signature built from the settings, the plugin
version and a bump counter. Saving the settings screen changes the signature,
which changes the worker byte for byte, which is exactly what makes every
browser fetch the new one and drop the old caches. There is a manual
**Clear visitor caches** button for when something looks stale on its own.

## The install prompt only appears when it will work

The card stays hidden until the browser fires its own installable event, so it
never advertises something that cannot happen, and it never appears once the app
is installed. Position, delay, message, button label and how long a dismissal is
remembered are all configurable.

Safari on iOS has no install button for any site, so there the card shows the
share menu instructions instead. `[beaver_pwa_install]` puts a button anywhere
in your content, hidden on the same terms.

## Endpoints

Four, all generated, none written to disk:

| Endpoint | Clean URL | Fallback |
|---|---|---|
| Manifest | `/beaver-pwa-manifest.json` | `/?beaver_pwa=manifest` |
| Service worker | `/beaver-pwa-sw.js` | `/?beaver_pwa=sw` |
| Offline page | `/beaver-pwa-offline` | `/?beaver_pwa=offline` |
| Heartbeat | `/beaver-pwa-alive` | `/?beaver_pwa=alive` |

A service worker may only control the directory it is served from and below, so
both styles resolve to the site root, which on a subdirectory install means that
subdirectory. The query string style needs no permalinks and gives the worker
exactly the same scope. Switch styles under **Advanced** if a server rule
swallows the clean URL.

## Readiness checks the live URLs

The dashboard does not read the settings and tell you what they say. It requests
the real manifest, worker and offline page over HTTP and inspects the status,
the content type and the `Service-Worker-Allowed` header. A rewrite rule that
was never flushed, a security plugin blocking a path, or a proxy rewriting a
header are exactly the failures that settings cannot reveal.

## WP-CLI

```sh
wp beaver-pwa status              # readiness table, exits with a warning if blocked
wp beaver-pwa flush               # invalidate every visitor's cached copy
wp beaver-pwa icons               # rebuild the icon set
wp beaver-pwa manifest            # print the manifest as browsers receive it
```

## Hooks

| Hook | Purpose |
|---|---|
| `beaver_pwa_manifest` | Filter the manifest members immediately before encoding. |
| `beaver_pwa_cache_exclusions` | Filter the list of URL fragments the worker must never cache. |
| `beaver_pwa_is_active` | Return false to suppress the manifest link and worker on a given request. |

## What it does not do

* **No push notifications.** They need a server, keys and a subscription store,
  and a site that has not earned them will simply be blocked. Out of scope on
  purpose.
* **No background sync, no offline forms.** A submission that silently queues
  and fires later is worse than an honest failure for most sites.
* **It phones nobody.** Nothing leaves the site, and there is no API key.

## Performance

The manifest and worker are generated only when requested, which for a visitor
is once each per install. On a normal page the plugin prints a link tag and a
few meta tags, reads one autoloaded option for the icons, and enqueues one small
script and one stylesheet. Nothing runs in the admin outside its own screens.

## Requirements

WordPress 5.8+, PHP 7.4+, GD (bundled with PHP; without it the icons fall back
to the site icon sizes WordPress already made). HTTPS in production.

---

Digital Beaver · GPL-2.0-or-later
