=== Beaver Updates ===
Contributors: digitalbeaver
Plugin URI: https://github.com/SteveBodyJr/wp-tools
Author URI: https://digitalbeavertz.com/
Tags: updates, plugin updates, self hosted, maintenance
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Brings the Digital Beaver plugins into Plugins → Updates, and lists the ones this site does not have yet so they can be added in a click.

== Description ==

Plugins that do not come from wordpress.org have no way of telling a site that a new version exists. They sit at whatever version was uploaded until somebody remembers to upload another one, which across a handful of sites means nobody ever quite knows what is running where.

This plugin fixes that for the Digital Beaver set. It reads one small published manifest and hands the versions to WordPress, which then shows the updates in the ordinary place with the ordinary button.

**Install it once, and the rest light up**

The plugins already on the site do not need changing, re-releasing or re-uploading. Whatever Digital Beaver plugins are installed become updatable the moment this one is activated.

**One request per check, not one per plugin**

WordPress asks about updates once per plugin, so the obvious approach makes ten requests every time a site checks. Everything here is answered from a single cached document instead: one request per site per twelve hours, regardless of how many plugins are installed. A failure is cached too, for an hour, because without that an unreachable manifest means every admin page load retries.

**It cannot slow the site down**

Nothing runs on the front end. The manifest is never fetched on a visitor's request, only in the admin or by cron, and the request has a five second ceiling. The risk with an update channel is not the server it points at, it is somebody's wp-admin hanging while it waits.

**It cannot install something else**

A package is only ever offered if it is published where this channel publishes. A manifest that had been tampered with cannot point an install at somebody else's archive.

**Features**

* Digital Beaver plugins appear under Plugins → Updates with a working update button.
* A Tools screen listing every plugin on the channel, what is installed here, and what is available.
* Per plugin automatic updates, using the same setting as the toggle on the Plugins screen.
* Version details in the usual modal rather than an error from wordpress.org.
* Supports the `Update URI` header from WordPress 5.8 as well, for any plugin that carries one.
* WP-CLI: `wp beaver-updates status` and `wp beaver-updates check`.

== Installation ==

1. Upload the `beaver-updates` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **Tools → Beaver Updates** to see what is current and what is behind.

== Frequently Asked Questions ==

= Do I have to re-upload the other plugins first? =

No. This one works with whatever is already installed.

= What happens if the channel is unreachable? =

Nothing breaks. No update is offered, the plugins carry on working, and the failure is remembered for an hour so the site is not retrying on every page load. The Tools screen says what went wrong.

= Should I turn on automatic updates? =

For the plugins that cannot lock you out, yes. Think twice about the file manager, the access links and the debug log: if a release of one of those is broken, you want to be the one who pressed the button.

= Does it phone home with anything about my site? =

No. It fetches a static file and sends nothing but the request itself.

== Changelog ==

= 1.1.0 =
* New **Available to add** section listing every plugin on the channel that this site does not have, with what it does and what version is current.
* An **Install** button on each one, which fetches and unpacks it in place. The plugin is left inactive, with an **Activate** link beside it, so nothing switches itself on.
* A **Download zip** link on every card as well, for sites where WordPress needs credentials to write to the plugins folder, or for a person without the capability to install.
* Anything published later joins the list on its own. There is nothing to configure when a new plugin appears.
* The installed table now shows whether each plugin is active.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds a browsable list of the plugins this site does not have yet, and an install button for each.

= 1.0.0 =
First release.
