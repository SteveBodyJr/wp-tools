# Beaver Chameleon

A daily-mutating honeypot and a human-interaction trap for the comment and
login forms. Both are invisible to a visitor, both are logged rather than
just discarded, and the dashboard is under **Chameleon Shield** in the admin
menu.

---

## Install

1. Copy the `beaver-chameleon` folder into `wp-content/plugins/` (or upload
   the zip under **Plugins → Add New → Upload**).
2. Activate it. Both traps are live immediately — nothing to configure.
3. Go to **Chameleon Shield** in the admin menu to watch what it catches.

## How the two traps work

**The honeypot.** Every comment and login form gains one extra text field
with a name derived from the current date and the site's own `wp_salt()` — it
changes at midnight and cannot be predicted without the site's secret keys.
It is hidden with CSS on `wp_head`, never with an inline `type="hidden"` or
inline `display:none`, so a scraper that reads only the markup — never the
stylesheet — sees an ordinary-looking field rather than an obviously-hidden
one. A script that fills in every input it finds fills this one in too, and
any value at all trips it.

**The behavior trap.** Both forms also carry a hidden token field that starts
empty. A small script waits for the first `mousemove`, `touchstart`,
`keydown` or `pointerdown` and only then copies a valid one-time token into
it. A plain HTTP client never runs the script, so the field stays empty. A
headless browser that runs JavaScript but submits programmatically — how most
scripted form-fillers work — never dispatches a trusted input event either, so
it stays empty too. The handler also checks `event.isTrusted`, so a script
that fakes the trap by dispatching its own synthetic event is ignored rather
than accepted. Only an actual person moving a mouse, tapping a screen or
pressing a key ever fills it in.

A second, independent signal rides alongside the token: a signed render
timestamp, present from the moment the page loads rather than only after
interaction. A submission that arrives less than a second after the page
rendered — fast enough for a script, too fast for anyone to have read the
form, typed into it and clicked submit — is rejected even if the token itself
is valid. So is one that arrives more than an hour later, which also bounds
how long a captured token+timestamp pair stays replayable.
`beaver_chameleon_min_seconds` (default `1`) and
`beaver_chameleon_max_seconds` (default one hour) filter that window.

Either trap tripping is enough on its own: a filled honeypot, a missing or
too-fast token, both end the request immediately with a 403, before WordPress
inserts the comment or attempts the login.

## What it doesn't touch

REST API and XML-RPC requests are skipped entirely — those clients never
rendered this plugin's form fields or ran its script, and they authenticate
through their own mechanisms already. Only a standard `wp-login.php` POST and
a standard comment-form POST are ever checked.

## Dashboard

**Chameleon Shield** in the admin menu shows total bots blocked, blocks today,
a breakdown of which trap caught them, and the last ten blocks with a
timestamp and a masked IP (`203.0.113.xxx` / `2001:db8:****`) — enough to spot
a repeat network without keeping full addresses. "Reset statistics" clears all
of it and asks first.

## Storage

Three rows, none of which grow without bound:

* `beaver_chameleon_stats` — one option, three integers, not autoloaded.
* `beaver_chameleon_today_{date}` — a transient with a one-day expiry, so
  "blocks today" needs no cron to reset it.
* `beaver_chameleon_log` — one option, capped at the last ten blocks; the
  eleventh push evicts the oldest.

## Off means off

A `BEAVER_CHAMELEON_OFF` constant in `wp-config.php` suspends both traps at
once — the escape hatch for whatever is guarding the front door needs a way
to be switched off from outside it.

```php
define( 'BEAVER_CHAMELEON_OFF', true );
```

## Requirements

WordPress 5.8+, PHP 7.4+.

---

Digital Beaver · GPL-2.0-or-later
