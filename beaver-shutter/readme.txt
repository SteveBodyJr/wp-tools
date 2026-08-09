=== Beaver Shutter ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: maintenance mode, coming soon, holding page, 503, offline
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Takes a site dark and brings it back on command. Front end only — your wp-admin keeps working, and nothing is ever deleted.

== Description ==

A reversible shutter over the front of a site. The site vanishes behind a holding page, and one click reopens it. wp-admin is never touched, so you keep full control of the site the whole time it is down.

**Three states**

1. **Open** — the site is live and the plugin shows nothing.
2. **Closed to visitors** — the public sees the holding page; anyone logged in still sees the real site. Good for a staged launch or a soft close.
3. **Dark** — everyone sees the holding page and the front end is effectively offline. Your wp-admin still works normally.

**It cannot lock you out.** Three independent switches reopen the site, and the plugin never closes any of them: the Shutter screen (wp-admin is never closed), WP-CLI (`wp beaver-shutter off`), and a `BEAVER_SHUTTER_OFF` constant in wp-config.php that forces the site open whatever the database says. While a site is closed, a warning sits in your admin toolbar so it is never left dark by accident.

**It returns 503, not 404 or 200.** The holding page answers 503 with a Retry-After header — the "temporarily down, come back later" that search engines understand — so a closed site keeps its place in the index. The page is also marked noindex.

**What it does not do**

* It does not touch wp-admin, the login screen, WP-CLI, cron, or the REST API. Only normal front-end requests are intercepted, on the `template_redirect` hook.
* It does not delete or change anything. The holding page is drawn in the response and nowhere else, so removing the plugin removes it in one step — and deactivating reopens the site on the way out.
* It has no hidden access and phones nobody. Nothing leaves the site.

If you need to deny admin access too, do that at your hosting or server level rather than from inside WordPress — locking an owner out of their own admin is the step this plugin deliberately does not take.

== Installation ==

1. Upload the `beaver-shutter` folder to `/wp-content/plugins/` and activate it.
2. Go to **Tools → Shutter** — or click **Shutter** in the plugin's row on the Plugins screen.
3. Pick a state, edit the holding-page wording, and save.

WP-CLI: `wp beaver-shutter close`, `wp beaver-shutter close --level=visitors`, `wp beaver-shutter off`, `wp beaver-shutter status`.

== Frequently Asked Questions ==

= Does closing the site log me out of wp-admin? =

No. The shutter only intercepts normal front-end requests. wp-admin, the login screen, WP-CLI and cron are never touched, so you keep full control of the site while it is closed.

= What if I close the site and then cannot get back in? =

You can't be locked out. wp-admin stays open, `wp beaver-shutter off` reopens it from the command line, and adding `define( 'BEAVER_SHUTTER_OFF', true );` to wp-config.php forces the site open no matter what.

= Will closing the site hurt my search rankings? =

Not for a normal maintenance window. The holding page returns 503 with a Retry-After header, which tells crawlers the site is temporarily unavailable and to come back, rather than that the pages have gone.

= Can I keep one page reachable while the rest is closed? =

Yes, in code: return true from the `beaver_shutter_bypass` filter for the requests you want to let through.

= Does it slow the site down? =

No. The state is a single autoloaded option, so it costs no extra query. When the site is open, the one front-end callback returns immediately and nothing is enqueued.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
