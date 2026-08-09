# Beaver Updates

Puts the Digital Beaver plugins into **Plugins → Updates**, so they update in a
click like anything from wordpress.org. One small manifest, cached, no key and
no server of ours in the path.

---

## Install

1. Copy the `beaver-updates` folder into `wp-content/plugins/`, or upload the
   zip under **Plugins → Add New → Upload**.
2. Activate it.
3. **Tools → Beaver Updates**. The plugin's row on the Plugins screen has a
   shortcut to the same place.

This is the one plugin that has to reach a site by hand. Everything else on the
channel updates itself from then on.

## Nothing else has to change

The other plugins need no header, no new version and no re-upload. Whatever is
already installed becomes updatable the moment this is activated.

That is deliberate. The obvious route is the `Update URI` header WordPress 5.8
added, but a header only takes effect once a site already has the version that
contains it, so adopting it would have meant re-releasing every plugin and then
installing every one of them by hand: the exact job this is meant to end. This
plugin filters `site_transient_update_plugins` instead, which applies to what is
on disk right now.

The header route is supported too, for any plugin that later carries one, but
nothing depends on it.

## One request per check, not one per plugin

WordPress calls the update filters **once per plugin**. The naive version of
this plugin would make ten HTTP requests every time a site checked, and eleven
when the next plugin ships.

Everything is answered from a single cached document instead:

| | |
|---|---|
| Requests per site per check | 1, whatever the plugin count |
| Cache on success | 12 hours, matching WordPress's own check interval |
| Cache on failure | 1 hour |
| Timeout | 5 seconds |
| Front end requests | never fetched |

Caching the failure matters as much as caching the success. Without it an
unreachable manifest means every admin page load on every site retries, and a
fleet hammers an endpoint that is already unhappy.

## What it will not install

A package is only offered if its URL is published where this channel publishes.
A manifest that had been tampered with cannot point an install at somebody
else's archive.

Entries are also validated before use: anything missing a version or a package
is dropped rather than half trusted, and our data is written into the update
transient unconditionally, so a plugin on wordpress.org that ever shares one of
these slugs cannot have its version offered in place of ours.

## Automatic updates

The Tools screen has a checkbox per plugin, writing to the same
`auto_update_plugins` option as the toggle on the Plugins screen, so the two
never disagree. Only our own plugins are ever added or removed; whatever the
site has decided about anything else is left alone.

Worth turning on for the plugins that cannot lock you out. Think twice about
the file manager, the access links and the debug log: if a release of one of
those is broken, you want to be the person who pressed the button.

## WP-CLI

```sh
wp beaver-updates status     # installed against published, per plugin
wp beaver-updates check      # fetch the manifest again, ignoring the cache
wp plugin update beaver-pwa  # ordinary core command, works once this is active
```

## Filters

| Hook | Purpose |
|---|---|
| `beaver_updates_manifest_url` | Point a site at a different manifest, for a staging channel. |

## Requirements

WordPress 5.8+, PHP 7.4+, and outbound HTTPS to `raw.githubusercontent.com` and
`github.com`.

---

Digital Beaver · GPL-2.0-or-later
