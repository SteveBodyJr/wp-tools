# Beaver Shutter

Takes a site dark, and brings it back on command. A reversible shutter over the
front end — the site vanishes behind a holding page, and one click reopens it.
Nothing is deleted, and **wp-admin is never touched**, so you keep full control
of the site the whole time it is down.

---

## Install

1. Copy the `beaver-shutter` folder into `wp-content/plugins/` (or upload the
   zip under **Plugins → Add New → Upload**).
2. Activate it. The site stays open — closing it is always a deliberate act.
3. Go to **Tools → Shutter**. The plugin's row on the Plugins screen has a
   shortcut to the same place.

## The three states

| State | What visitors see | What you see |
|---|---|---|
| **Open** | The site, exactly as normal | Everything normal |
| **Closed to visitors** | The holding page | The real site (you are logged in) |
| **Dark** | The holding page | The holding page on the front end — but full wp-admin |

"Closed to visitors" is the soft option: a launch you are staging, or a quiet
close where you still want to click around the live site yourself. "Dark" is
the real blackout — the front end is gone for everyone.

Either way, **your wp-admin keeps working**. This is a shutter over the shop
window, not a new lock on your own door.

## It cannot lock you out

A maintenance mode you can get trapped behind is worse than none. Three
independent switches reopen the site, and the plugin never closes any of them:

1. **The Shutter screen** — the shutter only ever closes the front end, so
   `Tools → Shutter` always loads.
2. **WP-CLI** — `wp beaver-shutter off`.
3. **wp-config.php** — `define( 'BEAVER_SHUTTER_OFF', true );` forces the site
   open regardless of what the database says. This is the switch that works when
   nothing else does.

While the site is closed, a **⚠ Site is closed** marker sits in your toolbar on
every admin page, so a site is never left dark by accident.

## Closing returns 503, not 404 or 200

The holding page answers **503 Service Unavailable with a `Retry-After`
header** — the "temporarily down, come back later" that every search engine
understands. A 200 holding page tells Google the site *is now* that page, and a
404 tells it the pages are gone; 503 keeps the site's place in the index while
it is closed. The page is also marked `noindex` for good measure.

## What it does not do

* **It does not touch wp-admin, the login screen, WP-CLI, cron, or the REST
  API.** Only normal front-end page requests are intercepted, on the
  `template_redirect` hook.
* **It does not delete or change anything.** The holding page is drawn in the
  response and nowhere else, so deactivating or deleting the plugin removes it
  in one step — and deactivating reopens the site on the way out.
* **It has no hidden access and phones nobody.** Nothing leaves the site.

If what you actually need is to deny **admin** access too — for a client who has
not paid, say — that belongs at the layer you control: suspend the site at your
**hosting/server** level. Locking an owner out of their own WordPress from
inside it is the move that turns a billing dispute into an access-law problem,
so this plugin deliberately stops at the shop window.

## WP-CLI

```sh
wp beaver-shutter status
wp beaver-shutter close                 # dark
wp beaver-shutter close --level=visitors
wp beaver-shutter off                   # reopen
```

## Hooks

| Hook | Purpose |
|---|---|
| `beaver_shutter_bypass` | Return true to let a specific front-end request through while the site is otherwise closed. |

## Performance

The level is a single autoloaded option, so it costs no extra query. When the
site is open, the one front-end callback returns immediately and nothing is
enqueued for visitors.

## Requirements

WordPress 5.8+, PHP 7.4+.

---

Digital Beaver · GPL-2.0-or-later
