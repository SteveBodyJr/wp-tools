# Beaver Access

Temporary login links. Give a developer time-limited access at the role you
choose, without ever sharing a password.

"Can you send me an admin login?" is how most support requests start, and it is
the worst part of the process. Credentials get typed into chat apps, reused
elsewhere, written down, and never changed afterwards. The client cannot see
what was done with them, and nobody remembers to revoke them.

---

## Install

1. Copy the `beaver-access` folder into `wp-content/plugins/` (or upload the zip
   under **Plugins → Add New → Upload**).
2. Activate it.
3. Go to **Users → Access Links**. The plugin's row on the Plugins screen has
   **Access Links** and **Settings** shortcuts to the same place.

There is no top-level menu on purpose — the screen is about who can sign in, so
it belongs with the other user tools rather than adding another item to the
sidebar of every client site.

**There is no separate settings page either.** The two settings live at the
bottom of that one screen:

| Setting | Default |
|---|---|
| **Require HTTPS** — refuse links over plain HTTP | on |
| **Notify** — email the site administrator whenever a link is used | on |

Everything else is decided per link when you create it, which is the point:
lifetime and role are properties of the grant, not of the plugin.

## Making a link

Choose what it is for, the role to grant, how long it lasts, and how many times
it can be used. Create it, copy it, send it the way you would send a password.

**The link is shown once.** Only a hash of it is stored, so this screen cannot
show it to you again — and a leaked database backup contains nothing anyone can
log in with.

| Option | Notes |
|---|---|
| **Role** | Any role you could already assign yourself. Only those are listed. |
| **Existing user** | Sign in as a specific account instead. Use sparingly — actions are then indistinguishable from that person's own. |
| **Expires after** | Presets from 15 minutes to 7 days; **Custom length** for any number of minutes, hours or days; **Until a specific time** to pick the moment on the site's clock. Bounds are 5 minutes to 30 days, and asking for more says so rather than silently capping. |
| **Times it can be used** | One by default. |
| **Lock to first address** | A forwarded link then works nowhere else. Avoid for people on mobile connections, whose address changes. |

## A role link makes its own account

Rather than borrowing the client's login, a role-based link creates a throwaway
user with the role you chose. Every action in the site's history is then
attributed to a visibly temporary account, so a year later nobody is wondering
why the owner apparently edited a template at 2am.

When the link expires or is revoked, the account is deleted. Anything it
authored is reassigned to whoever issued the link, so nothing is lost with it.

## Revoking

Revoking destroys the session as well as the link — the next page the person
loads is the login screen. **Revoke every live link** does the same for all of
them at once.

Deactivating the plugin revokes everything, because a temporary account left
signed in after the plugin is switched off is access nobody can see or withdraw.

## The security model

* **Selector and verifier** — the same split design WordPress uses for password
  resets. The selector is an indexed column, so finding a link is one keyed
  lookup rather than comparing every row; the verifier is only ever stored as a
  SHA-256 hash.
* **Every failure looks identical.** "Expired", "revoked" and "never existed"
  are one message, because telling them apart tells a guesser which guesses were
  close.
* **Rate limited** — ten failures from an address and it stops answering for
  fifteen minutes.
* **HTTPS required by default.** A link carries its own key in the address; over
  plain HTTP anything on the path can read and reuse it. You can turn the
  requirement off for a local or staging site — do not turn it off on a live one.
* **No privilege escalation.** Links can only be issued for roles you could
  already assign, decided by WordPress's own `get_editable_roles()`, so the
  `editable_roles` filter that multisite and membership plugins hook is honoured.
* **Redirects immediately** after signing in, so the token does not linger in
  browser history, bookmarks, or a referrer header sent to the next site.
* **Everything is logged** — created, used, denied and revoked, with address,
  browser and time. Optionally the site owner is emailed whenever a link is used.

## It does not slow the site down

The only front-end hook reads one query variable and returns instantly when it
is absent. No options are loaded, no queries are made, and nothing is enqueued
for visitors. A site nobody is signing into cannot tell the plugin is installed.

## WP-CLI

```sh
wp beaver-access create --role=editor --minutes=60 --label="Checkout bug"
wp beaver-access list
wp beaver-access revoke 3
wp beaver-access revoke --all
```

## Hooks

| Hook | Purpose |
|---|---|
| `beaver_access_granted` | Fires after a link signs someone in. Receives the user ID and the link. |
| `beaver_access_destination` | Where a link sends someone. Defaults to wp-admin. |
| `beaver_access_client_ip` | The address a request is attributed to. Defaults to `REMOTE_ADDR` — override only if a proxy sits in front, since forwarded headers can be set by the client. |

## Privacy

Nothing leaves the site. Links, the audit log and temporary accounts all live in
your own database. Uninstalling drops the table, deletes the log, and removes
every temporary account it created.

## Requirements

WordPress 5.8+, PHP 7.4+. HTTPS strongly recommended, and required by default.

---

Digital Beaver · GPL-2.0-or-later
