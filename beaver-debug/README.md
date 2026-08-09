# Beaver Debug

Records what actually goes wrong on a site you cannot SSH into, and turns it
into a report you can paste into a message.

Most WordPress sites record nothing when they break. `WP_DEBUG` is off, there is
no `debug.log`, and on shared hosting there is no shell to go looking with. So a
bug report arrives as "the site is broken" and the developer starts guessing.

Nothing here depends on a particular theme, page builder or plugin. It hooks
PHP's own error handling and the browser's, so it works the same on a site you
inherited as on one you built.

---

## Install

1. Copy the `beaver-debug` folder into `wp-content/plugins/` (or upload the zip
   under **Plugins → Add New → Upload**).
2. Activate it. That is the whole setup — capture starts immediately with
   sensible defaults.
3. Read what it caught under **Tools → Beaver Debug**.

**Worth doing as well:** copy `mu-loader/beaver-debug-loader.php` into
`wp-content/mu-plugins/`. Must-use plugins load first, so this catches errors
thrown while other plugins are still loading — the ones a normal plugin is not
yet running to see. The main file guards against loading twice, so leaving the
plugin active as well is harmless.

## What it captures

| | |
|---|---|
| **Fatal errors** | With the request, the plugin responsible, the AJAX action, the user, and peak memory against the limit. Memory is held back deliberately so a crash caused by exhausting memory still has room to describe itself. |
| **JavaScript errors** | From real visitors — a broken slider, a script fighting jQuery, a button that silently does nothing. None of this reaches PHP, so none of it is in any server log. Failed scripts, stylesheets and images are caught too, as are unhandled promise rejections. |
| **Failed API calls** | A plugin that cannot reach its API usually fails quietly rather than fatally. These are the events that explain "it just stopped working". |
| **Database errors** | A failing query rarely stops the page; the feature that needed the data simply does nothing. |
| **Slow pages** | Over a threshold you set, with the URL and query count attached. |
| **Deprecations** | Grouped by the plugin or theme responsible — what will break on the next PHP or WordPress release. |
| **Site changes** | Plugin and theme updates, activations and theme switches, on the same timeline as the failures. |

## Attribution is the point

Errors are attributed by file path and script URL, so you get the answer rather
than a stack trace to interpret:

```
plugins/woocommerce/includes/class-wc-cart.php  →  plugin: woocommerce
themes/astra/functions.php                      →  theme: astra
revslider/public/js/rs6.min.js                  →  plugin: revslider
cdn.jsdelivr.net/…/slick.min.js                 →  external: cdn.jsdelivr.net
```

On a site whose code you have never read, "which plugin" is usually the whole
diagnosis.

## Share a report

The **Share a report** tab produces one block of plain text — environment,
active plugins with versions, theme, and recent failures — ready to paste into
an email or a chat. It exists to end the round trip where a developer asks for a
log the client cannot reach.

Query strings are stripped from recorded URLs before anything is written, so an
API key passed that way does not end up in the report. Read it before sending it
on, as you would any log.

## Alerts

Under **Settings**, choose what earns an alert: nothing, fatal errors, fatal and
database errors, or everything. The first time a new problem appears it is
emailed, and optionally posted to a webhook — the payload has a `text` field,
which is what Slack and Discord render with no configuration.

Each distinct problem alerts once per day, so a fatal inside a loop cannot flood
you.

## Reading the log when the site is down

A fatal on every request takes wp-admin with it, and the normal screen stops
being reachable exactly when it matters. `viewer.php` reads the log directly,
without loading WordPress, from a secret address shown under **Settings**.

Treat that address as a password. It is token-protected, locks out after ten
wrong guesses for fifteen minutes, and refuses to serve at all if it cannot
write its own rate-limit file — no silent degradation to unprotected.

```
https://example.com/wp-content/plugins/beaver-debug/viewer.php?token=…
?severity=fatal   &limit=200
```

## Fleet digest

Point **Send summaries to** at one endpoint and each site posts a short daily
summary — counts, versions, and the messages of recent fatal errors. Eleven
sites become one page instead of eleven logins. No page content is included.

## It does not slow the site down

Recording only happens when something goes wrong. On a healthy request the
handlers are registered and never fire. The browser listener is a few lines of
inline JavaScript with no dependencies, capped at five reports per page and
sixty per hour.

## Where the log lives

`wp-content/uploads/beaver-debug-<random>/events.log`, behind an `.htaccess`
deny rule and an `index.php`. Never a guessable `debug.log` anyone can fetch.
Repeats are grouped rather than written thousands of times, and events older
than the retention setting are pruned daily.

## WP-CLI

```sh
wp beaver-debug log --severity=fatal
wp beaver-debug report > site-report.txt
wp beaver-debug clear
```

## Filters

| Filter | Purpose |
|---|---|
| `beaver_debug_health_checks` | Add or change the environment checks. |

## Requirements

WordPress 5.8+, PHP 7.4+, and a writable uploads folder.

---

Digital Beaver · GPL-2.0-or-later
