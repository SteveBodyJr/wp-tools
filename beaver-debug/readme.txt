=== Beaver Debug ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: debug, error log, diagnostics, javascript errors, site health
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Records what actually goes wrong on a site you cannot SSH into, and turns it into a report you can paste into a message.

== Description ==

Most WordPress sites record nothing when they break. `WP_DEBUG` is off, there is no `debug.log`, and on shared hosting there is no shell to go looking with. So a bug report arrives as "the site is broken" and the developer starts guessing.

Beaver Debug is the missing half of that conversation. It records failures with enough context to act on, and hands you one block of text to send.

**Works on any site**

Nothing here depends on a particular theme, page builder or plugin. It hooks PHP's own error handling and the browser's, so it works the same on a site you inherited as on one you built. Errors are attributed to the plugin or theme they came from, by path — which is usually the whole diagnosis on a site whose code you have never read.

**What it captures**

* **Fatal errors**, with the request that caused them, the plugin responsible, the AJAX action, the logged-in user, and peak memory against the limit. Memory is held back deliberately so that a crash caused by exhausting memory still has room to describe itself.
* **JavaScript errors from real visitors** — a broken slider, a script fighting jQuery, a checkout button that silently does nothing. None of this reaches PHP, so none of it appears in any server log. Failed scripts, stylesheets and images are caught too, as are unhandled promise rejections.
* **Failed outbound requests** — a plugin that cannot reach its API usually fails quietly rather than fatally. These are the events that explain "it just stopped working".
* **Database errors**, which rarely stop the page: the feature that needed the data simply does nothing.
* **Slow pages**, over a threshold you set, with the URL attached. Turns "the site feels sluggish" into something you can open.

**Built to be safe on a live site**

Nothing is ever shown to visitors. The log lives in a protected folder with an unguessable name, not a predictable `debug.log` anyone can fetch. Query strings are stripped from recorded URLs, so an API key in a request never reaches the log. Repeats are grouped rather than written thousands of times, browser reports are capped per page and per hour, and old events are pruned on a schedule.

**Share a report**

One screen produces a plain-text summary — environment, active plugins with versions, theme, and recent failures — ready to paste into an email or a chat. It exists to end the round trip where a developer asks for a log the client cannot reach.

== Installation ==

1. Upload the `beaver-debug` folder to `/wp-content/plugins/` and activate it.
2. That is the whole setup. Capture starts immediately with sensible defaults.
3. Optional, and worth doing: copy `mu-loader/beaver-debug-loader.php` into `wp-content/mu-plugins/`. Must-use plugins load first, so this catches errors thrown while other plugins are still loading — the ones a normal plugin is not yet running to see.

Read what was captured under **Tools → Beaver Debug**.

== Frequently Asked Questions ==

= Is it safe to leave running on a live site? =

Yes, and that is the intended use. It never prints anything to visitors, it writes to a protected folder with a random name, it groups repeats instead of flooding, and it prunes itself. Leave it on so the log already exists when something breaks.

= Does it slow the site down? =

Recording only happens when something goes wrong. On a healthy request the handlers are registered and never fire. The browser listener is a few lines of inline JavaScript with no dependencies.

= Will API keys end up in the log? =

Query strings are stripped from outbound URLs before anything is written, so a key passed that way is not recorded. Keys sent in headers are never touched. As with any log, read a report before sending it on.

= Why do I need this if WordPress emails me about fatal errors? =

WordPress emails the admin when it catches a fatal, with no context beyond the file and line. This records the request, the plugin, the user, the memory position, and a backtrace — and keeps the history, so you can see whether something happens once or a hundred times a day.

= It says my uploads folder is not writable. =

Then there is nowhere to put the log. Fix the permissions on `wp-content/uploads` and it will start recording.

= Does it work with a page builder or a theme I did not write? =

That is the case it is built for. Errors are attributed by file path and script URL, so "plugin: revslider" or "theme: Divi" appears next to the failure without the plugin knowing anything about either.

== Changelog ==

= 1.1.0 =
* Alerts. The first time a new fatal appears it is emailed, and optionally posted to a webhook, with the same detail the admin screen shows. Throttled to once per distinct problem per day.
* A standalone reader. A fatal on every request takes wp-admin with it, so the log becomes unreadable exactly when it matters. viewer.php reads it directly, without loading WordPress, from a secret address. Token-protected, rate limited, and it refuses to serve at all if the rate limit cannot be written.
* Change correlation. Plugin and theme updates, activations and theme switches are recorded into the same timeline as the failures, so "it broke after the update" stops being a guess.
* Fleet digest. Each site can post a daily summary to one endpoint, so a fleet is checked from one page rather than one login at a time.
* Upgrade readiness. WordPress deprecations are captured and grouped by the plugin or theme responsible — what will break on the next PHP or WordPress release, before the host upgrades for you.
* Slow pages now report the query count, and the three slowest queries when SAVEQUERIES is enabled.

= 1.0.1 =
* Fixed a fatal error on activation. The uninstall callback was an anonymous function, and register_uninstall_hook() stores its callback in the database — a closure cannot be serialized, so activation died before the plugin ever ran. Uninstall is now handled by uninstall.php, which needs no stored callback.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds alerting, a reader that works when the site is down, change correlation and a fleet digest.

= 1.0.1 =
Required — 1.0.0 could not be activated at all.

= 1.0.0 =
Initial release.
