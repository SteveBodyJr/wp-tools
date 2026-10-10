# Beaver Shield

Reduces what a site tells the outside world about itself, then reads the site
back over HTTP and shows you what is still getting out.

**Tools → Beaver Shield.** WordPress 5.8+, PHP 7.4+. No API key, no outbound
request except to the site's own URLs.

---

## Say this to a client before installing it

Three things are impossible, and being straight about them is the reason this
plugin can be trusted about the rest.

* **View source cannot be blocked.** The browser already has the page.
  `view-source:`, curl, Reader mode and every HTTP client read it regardless.
  The anti-devtools scripts sold for this are trivially bypassed, hurt
  Lighthouse, and are flagged as hostile by some scanners.
* **Text cannot be made uncopyable.** It has to arrive to be read.
* **WordPress cannot be hidden.** `/wp-json`, the feed, `wp-block-*` class names
  and the comment form all say so.

What *is* possible is removing the version detail, which is what a scanner turns
into a list of known vulnerabilities without sending a single attack. That, plus
closing enumeration and the file editor, is where the real value is.

## The rules it plays by

* **Everyone gets the same HTML.** No user agent sniffing, no crawler variant.
  Cloaking is a genuine ranking risk and no obscurity is worth it.
* **Nothing that can break a site ships on.** Activation turns on only the
  strippers and the four free headers. XML-RPC, CSP, the login URL and the copy
  deterrent all ship off, each with the cost written on its own row.
* **It cannot lock anyone out.** `define( 'BEAVER_SHIELD_OFF', true );` in
  wp-config.php suspends everything, `wp beaver-shield login reset` clears the
  slug, and renaming the folder deactivates it.
* **Server writes are inside one marked block.** Same function core uses,
  backed up first, removed on deactivate. Nothing on nginx.

## SEO: what was checked, against what Google actually publishes

Every claim below was checked against Google Search Central rather than assumed,
and where the docs contradicted an assumption, the plugin changed.

| Concern | What Google says | Where that leaves the plugin |
|---|---|---|
| Cloaking | "Presenting different content to users and search engines with the intent to manipulate search rankings and mislead users." | No user agent is ever inspected to decide output. The scan fetches the home page twice, once as Googlebot, and compares. |
| Blocked resources | "Google Search won't render JavaScript from blocked files or on blocked pages," and Googlebot must not be blocked from resources that affect the meaning of the page. | The scan fetches every same-host stylesheet and script from the home page as Googlebot, with a referrer, and fails if any does not return 200. |
| `rel=prev`/`rel=next` | No longer an indexing signal. | Removed with the other discovery links. No cost. |
| 403 and 404 | Treated the same as each other; an indexed URL that starts returning either is dropped from the index. | Only ever applied to readme files, licence files and `wp-login.php` when it has been moved. Never to a page or a resource. |
| Soft 404s | A 2xx response whose content says the page is missing. | A hidden `wp-login.php` returns a real 404 status with the theme's 404 template, verified. |
| robots.txt location | "Crawlers don't check for robots.txt files in subdirectories." | The scan now checks the host root, not the install root, so a subdirectory install is reported rather than silently passing. |
| `noindex` | Requires the URL to be crawlable, since Google has to fetch it to see the header. | `llms.txt` returns 200 with `X-Robots-Tag: noindex`, which is the correct combination. |
| Google-Extended | "Does not impact a site's inclusion in Google Search nor is it used as a ranking signal." | Listed separately from Googlebot on the crawler tab so the two can never be confused. Googlebot and Bingbot are never touched. |

Three things this found that were wrong, and are now fixed:

* **`?author=` on plain permalinks.** With no permalink structure, `?author=1`
  is not an enumeration trick, it is the only URL an author archive has.
  Blocking it would 404 a real page, and Google drops a URL from the index once
  it starts answering 404. That part of the block now applies only when pretty
  permalinks are in use, and the admin screen says so when they are not.
* **Hotlink protection did nothing.** Written as a mod_rewrite rule at the site
  root, it never fired, because `wp-content/uploads` has its own `.htaccess`
  with `RewriteEngine On`, and a directory that switches rewriting on stops
  inheriting the parent's rewrite rules. It is now a `SetEnvIf` rule written
  into both files. Verified: Googlebot-Image with no referrer gets 200, the
  renderer with this site as referrer gets 200, a competitor gets 403.
* **The REST users route** can be re-opened with the
  `beaver_shield_hide_rest_users` filter, for a headless front end that reads it
  anonymously and would otherwise render with a hole in it for Googlebot.

**The one thing the scan cannot check for you** is an enforced Content Security
Policy. A policy is applied inside the browser, not at the server, so fetching
files over HTTP sees nothing wrong while Google's renderer is being blocked.
Report-only mode and the browser console are the only way to know, which is why
that is where the setting starts.

## Tabs

| Tab | What lives there |
|---|---|
| Exposure | The scan. Fetches the site anonymously and reports what arrived. |
| Fingerprint | Generator, asset versions, discovery links, headers, emoji, readme files. |
| Hardening | Enumeration, file editor, login errors, PHP in uploads, listings, XML-RPC, hotlinking. |
| Headers | The four free ones, HSTS, and a report-only CSP. |
| Login URL | Moving wp-login.php, with the recovery box on the same screen. |
| AI & crawlers | Per-bot robots.txt directives and the generated llms.txt. |
| Content | The copy deterrent, off by default, and what actually works instead. |
| Server rules | .htaccess status, the block's contents, or the nginx equivalent. |

## The two decisions worth thinking about

**AI crawlers.** They run no JavaScript, so nothing on the Content tab touches
them, and every word is already readable. Blocking them is honour-system and
removes the site from ChatGPT, Perplexity and AI Overviews. For an agency that
is a lead channel, not a leak, so the default lets them in and publishes an
`llms.txt` that steers them to the service pages and states attribution terms.
Google-Extended is Gemini training only and is listed separately from Googlebot
on purpose.

**The copy deterrent.** Off by default. Its "attribute" mode is usually the
better setting: it lets the copy through and appends a source line with a link
back, so a quote arrives carrying attribution instead of a fight. Form fields,
code samples and anything editable stay selectable, and it never runs for a user
who can edit posts.

## WP-CLI

```sh
wp beaver-shield scan                # fetch the site and report exposure
wp beaver-shield scan --format=json  # for diffing across a fleet
wp beaver-shield status              # what is switched on
wp beaver-shield login show
wp beaver-shield login reset         # the recovery path
wp beaver-shield sync                # rewrite the .htaccess block
wp beaver-shield llms                # print llms.txt
```

## Multilingual sites

No Polylang, WPML or any other multilingual code is bundled, referenced or
required. Nothing here has a language concept at all. What it does have is three
places that measure paths, and those had to be made safe against a site where
`home_url()` moves per request, which is exactly what Polylang does when
language prefixes are on.

**`hreflang` is never touched.** The head clean-up removes a fixed list and
nothing else: RSD, wlwmanifest, shortlink, `rel=prev/next`, the oEmbed discovery
link and the REST discovery link. The `rel="alternate" hreflang="..."` tags a
multilingual plugin emits are not in that list, and neither is the canonical tag.
Both are left exactly as the other plugin wrote them, which matters because on a
multilingual site they are the two most important tags on the page.

Three fixes went in for this:

* **Asset versions.** Mapping an asset URL back to a file on disk was measured
  against `home_url()`. With an `/en` prefix in the way, no asset would resolve,
  every revision hash would come back empty, and cache busting would switch
  itself off across the whole site with no visible symptom. It now measures
  against the stored `home` option, which does not move.
* **The login slug.** `login_url()` would have handed out `/en/my-login/` while
  the request matcher only ever looked for `/my-login/`, so the login page would
  have been unreachable from any page but the default language. Both ends now
  use the stored base.
* **llms.txt** would have listed only the language that happened to be current
  when the file was first cached. The query now passes `lang => ''`, which
  Polylang reads as every language and every other site ignores.

Two filters are there for the rest: `beaver_shield_request_path` to strip a
language prefix before the login slug is matched, and `beaver_shield_llms_query`
to change what goes into the map.

None of this is tested against a live Polylang install, because there is not one
on any of our sites. It is written so that the failure mode is "no worse than
having the switch off" rather than a broken page.

## On our own sites, luotech-core already does some of this

`luotech-core` handles the generator tag, RSD and wlwmanifest, the emoji script,
`script_loader_src`, `robots_txt` and `llms.txt`. Most of that duplication is
harmless, because removing an action twice is the same as removing it once. Two
are not, and both are handled here rather than left to chance:

* **llms.txt.** Both would serve it and whichever loaded first would win. Beaver
  Shield now hooks `init` at priority 99, so an existing handler always wins and
  a considered document is never replaced by a generated one.
* **robots.txt.** Both append. Beaver Shield now skips any user agent already
  named in the output, and skips its llms.txt pointer if one is already there.

The practical advice for our fleet: leave the Fingerprint tab on anyway, since
it is idempotent, and turn **Publish llms.txt** off on any site where
luotech-core is active.

## Known interactions

* **Page caches.** Exclude a moved login path from the cache, the same way
  wp-login.php already is. Headers are sent by PHP, so a full-page cache in
  front of WordPress may serve pages without them; the scan is what catches
  that.
* **Jetpack and the WordPress mobile app** need XML-RPC. That switch is off by
  default for exactly this reason.
* **CDNs.** Hotlink protection never sees a request served from another domain.
* **Multisite.** Settings are per site.

## Files

```
beaver-shield.php          bootstrap, constants, activation
includes/class-settings.php    every switch, and the defaults argument
includes/class-fingerprint.php generator, versions, discovery, headers, emoji
includes/class-hardening.php   enumeration, file editor, login errors, XML-RPC
includes/class-headers.php     security headers and CSP
includes/class-htaccess.php    the marked block, and the nginx equivalent
includes/class-login.php       moving wp-login.php
includes/class-crawlers.php    robots.txt directives, llms.txt
includes/class-content.php     the copy deterrent
includes/class-scan.php        the HTTP self check
includes/class-admin.php       the screen
includes/class-cli.php         WP-CLI
public/                        shield.css, shield.js (under 1KB, deferred)
```

---

Digital Beaver · GPL-2.0-or-later
