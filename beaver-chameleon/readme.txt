=== Beaver Chameleon ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: anti-spam, honeypot, login security, comment spam, bot protection
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A daily-mutating honeypot and a human-interaction trap for the comment and login forms. Invisible to visitors, logged rather than discarded.

== Description ==

Two independent traps, both invisible, both checked on every comment and login submission:

**A honeypot that changes its name every day.** A conventional honeypot field keeps the same name forever, which is exactly what lets a bot script learn it once and skip it on every future submission, here or anywhere else. This one is derived from the current date and the site's own security keys, so it mutates at midnight and cannot be predicted without them. It is hidden with CSS, not with an inline `display:none` on the field itself, so a scraper reading only the markup sees an ordinary field.

**A trap for the absence of human behaviour.** Both forms carry a hidden token that starts empty and is only filled in by a small script the moment it sees a real `mousemove`, `touchstart`, `keydown` or `pointerdown` — checked with `event.isTrusted`, so a script faking the trap by dispatching its own synthetic event is ignored. A plain HTTP client never runs the script. A headless browser that submits programmatically — how most scripted form-fillers work — never fires a trusted input event either. A signed render timestamp travelling alongside the token also rejects anything submitted less than a second after the page loaded, script-fast rather than human-fast. Only an actual person interacting with the page for at least a moment ever gets through.

Either one tripping ends the request immediately with a 403, before WordPress inserts the comment or attempts the login. REST API and XML-RPC requests are never checked — those clients authenticate through their own mechanisms and never touched this plugin's forms in the first place.

**Chameleon Shield**, a dashboard under its own top-level admin menu item, shows total bots blocked, blocks today, a breakdown by trap, and the last ten blocks with a timestamp and a masked IP address.

= Storage =

One small option for the running totals, one self-expiring daily transient, and one option capped at the last ten blocked attempts. Nothing here grows without bound, and none of it is autoloaded.

= Off means off =

Define `BEAVER_CHAMELEON_OFF` as `true` in `wp-config.php` to suspend both traps without deactivating the plugin.

== Installation ==

1. Copy the `beaver-chameleon` folder into `wp-content/plugins/` (or upload the zip under **Plugins → Add New → Upload**).
2. Activate it. Both traps are live immediately.
3. Go to **Chameleon Shield** in the admin menu to watch what it catches.

== Frequently Asked Questions ==

= Will this ever block a real visitor? =

It can, in one specific case: someone with JavaScript disabled will never fill in the behavioral token, because the token is only ever set by that script. Everyone else — including a person using a screen reader or keyboard-only navigation, since `keydown` alone satisfies the trap — passes as soon as they interact with the page at all.

= Does it slow the site down? =

No externally-loaded script or stylesheet is added to the front end. The honeypot is a few bytes of inline CSS; the behavior trap is a few dozen lines of inline JavaScript with four passive, self-removing event listeners. Tailwind is loaded from a CDN only on the plugin's own admin screen, never on the front end.

= Will it interfere with a mobile app, Jetpack, or another REST client posting comments? =

No. REST API and XML-RPC requests are excluded from both checks entirely, since those clients never rendered this plugin's fields and authenticate their own way already.

= Where is the masked IP address from? =

`REMOTE_ADDR` only — the one part of a request a client cannot spoof by sending a header. The last IPv4 octet, or everything past the first two IPv6 groups, is replaced before it is ever stored.

= What happens if I uninstall the plugin? =

Both statistics options and the current daily transient are removed. Define `BEAVER_CHAMELEON_KEEP_DATA_ON_UNINSTALL` as `true` in `wp-config.php` first if you want to keep them.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
