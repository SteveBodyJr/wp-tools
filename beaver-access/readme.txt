=== Beaver Access ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: login, temporary access, support, security, users
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Temporary login links. Give a developer time-limited access at the role you choose, without ever sharing a password.

== Description ==

"Can you send me an admin login?" is how most support requests start, and it is the worst part of the process. Credentials get typed into chat apps, reused elsewhere, written down, and never changed afterwards. The client cannot see what was done with them, and nobody remembers to revoke them.

Beaver Access replaces that with a link. You choose the role, how long it lasts, and how many times it can be used. Send it, they click it, they are in. It expires on its own — and one click revokes it, which signs out anyone using it at that moment.

**No password is ever shared, and none is created.** A role-based link signs in as its own throwaway account, so every action in the site's history is attributed to a visibly temporary user rather than to the owner. When the link dies, so does the account.

**Built to be careful**

* **The link is shown once and never stored.** Only a hash of it is kept, so a leaked database backup contains nothing anyone can log in with.
* **Selector and verifier**, the same split design WordPress uses for password resets: one indexed lookup instead of comparing every row, and no timing signal.
* **Every failure looks identical.** "Expired", "revoked" and "never existed" are one message, because telling them apart tells a guesser which guesses were close.
* **Rate limited** — ten failures from an address and it stops answering for fifteen minutes.
* **HTTPS required by default.** A link carries its own key in the address; over plain HTTP anything on the path can read it and reuse it.
* **No privilege escalation.** You can only issue links for roles you could already assign yourself, decided by WordPress's own rules.
* **Redirects immediately** after signing in, so the token never lingers in browser history, bookmarks or a referrer header.
* **Everything is logged** — created, used, denied and revoked, with address, browser and time.

**It does not slow the site down**

The only front-end hook reads one query variable and returns instantly when it is not there. No options are loaded, no queries are made, and nothing is enqueued for visitors. A site nobody is signing into cannot tell the plugin is installed.

**Options on each link**

* Any role you are allowed to grant — or sign in as a specific existing user.
* Expiry from fifteen minutes to seven days.
* One use, or several.
* Optionally locked to the first address that opens it, so a forwarded link is useless elsewhere.
* Email the site owner whenever a link is used, so access is never invisible to them.

== Installation ==

1. Upload the `beaver-access` folder to `/wp-content/plugins/` and activate it.
2. Go to **Users → Access Links** — or click **Access Links** in the plugin's row on the Plugins screen.
3. Choose a role and a lifetime, create the link, and copy it. It is shown once.

There is no separate settings page. The two settings — require HTTPS, and email the administrator whenever a link is used — are at the bottom of that same screen, and the **Settings** link in the plugins row jumps to them.

WP-CLI: `wp beaver-access create --role=editor --minutes=60`, `wp beaver-access list`, `wp beaver-access revoke --all`.

== Frequently Asked Questions ==

= What happens when a link expires? =

It stops working, and the temporary account it created is deleted on the next cleanup run. Anything that account authored is reassigned to whoever issued the link, so nothing is lost with it.

= Can I revoke a link somebody is using right now? =

Yes. Revoking destroys their session as well as the link — the next page they load is the login screen.

= Is it safe on a live client site? =

That is what it is for. Links expire, are single-use by default, cannot grant more than you could grant yourself, and every use is logged and optionally emailed to the site owner. Deactivating the plugin revokes everything, so nothing is left behind.

= Why not just sign in as the client's own account? =

You can — there is an option for it — but avoid it where you can. Actions taken that way are indistinguishable from the client's own, which is bad for them and bad for you. A temporary account keeps the history honest.

= What if the site is not using HTTPS? =

Links are refused, and the settings screen says so. A token in a URL is readable by anything between the browser and the server on plain HTTP. You can turn the requirement off for a local or staging site, but do not turn it off on a live one.

== Changelog ==

= 1.0.2 =
* **Expires after** now takes a custom length. Choose **Custom length** and type any number of minutes, hours or days.
* Or choose **Until a specific time** and pick the moment it should stop working, read on the site's own clock, which is shown beside the field.
* A length outside the allowed range is reported rather than quietly clamped. Ask for 90 days and you are told the limit is 30, instead of being handed a 30 day link you believe is 90.
* The 5 minute and 30 day limits are now a single pair of constants instead of the same two numbers written out in three files.

= 1.0.1 =
* Added **Access Links** and **Settings** shortcuts to the plugin's row on the Plugins screen. The screen lives under Users, which is the right home for it but not somewhere anyone looks after activating a plugin.
* Settings are a section of that screen rather than a page of their own, so the Settings shortcut now jumps straight to the heading.
* The plugin now loads its own translations, so a language pack in `/languages` is actually used.
* Fixed: saving the settings form reset the default role, lifetime and use count, because the form does not post those three and they fell back to the shipped defaults rather than to what was stored.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.2 =
Adds a custom expiry length and an exact stop time. Links already issued are unaffected.

= 1.0.1 =
Makes the plugin findable after activation. No change to links already issued.

= 1.0.0 =
Initial release.
