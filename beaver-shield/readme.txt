=== Beaver Shield ===
Contributors: digitalbeaver
Tags: security, hardening, privacy, headers, llms.txt
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reduces what a site tells the outside world about itself, and shows you what is still getting out.

== Description ==

Beaver Shield removes the version and component detail a WordPress site
volunteers for free, closes the doors a normal site never uses, and sends the
response headers a modern site should be sending anyway. Then it reads the site
back over HTTP, signed out, and tells you what actually arrived.

**What it cannot do, stated up front.** This is the part most plugins in this
category are dishonest about.

* It cannot block view source. The browser has already downloaded the page.
  `view-source:`, curl, Reader mode and every HTTP client read it regardless,
  and the scripts sold to trap the developer tools only slow the site down for
  real visitors.
* It cannot make text uncopyable. Text has to arrive to be read. The deterrent
  here stops a visitor highlighting a paragraph; it does not stop a competitor,
  a scraper or an AI crawler, none of which run JavaScript.
* It cannot hide that a site runs WordPress. The REST route, the feed, the block
  class names and the comment form all say so. What it removes is the *version*
  detail, which is the part a scanner turns into a list of known holes.

What it does do is remove that detail, and it makes a measurable difference to
what a fingerprinting tool reports.

= Everyone gets the same page =

No user agent is sniffed and no output varies for a crawler. Serving search
engines something different from visitors is cloaking, and no amount of
obscurity is worth a manual action.

= You cannot be locked out =

One line in wp-config.php stands the whole plugin down, including the moved
login URL:

`define( 'BEAVER_SHIELD_OFF', true );`

WP-CLI does the same without a browser: `wp beaver-shield login reset`.

= Fingerprint =

* Removes the generator tag and the version stamped into every feed.
* Replaces the version on every script and style URL with a hash of the file.
  The parameter is kept on purpose: deleting it is the usual advice and it is
  wrong, because that is what tells a browser its cached copy is stale.
* Removes RSD, wlwmanifest, the shortlink and the REST discovery link.
* Removes X-Powered-By and X-Pingback.
* Stops the emoji polyfill loading, which is a named file on a fixed path and a
  request on every page.
* Blocks readme and licence files, including the readme.txt every plugin ships
  stating its exact version.

= Hardening =

* Blocks username enumeration: `?author=1`, the anonymous half of the REST users
  route, the oEmbed author fields and the users sitemap. Anyone signed in is
  unaffected, so the block editor keeps working.
* Switches off the admin file editor.
* Stops login errors confirming which accounts exist.
* Stops PHP executing anywhere in the uploads folder.
* Stops folders listing their contents.
* Optional: XML-RPC off, hotlink protection, a moved login URL.

= Headers =

X-Frame-Options, X-Content-Type-Options, Referrer-Policy and Permissions-Policy
are on by default and cost a visitor nothing. HSTS is off, because a browser
that has seen it refuses plain HTTP for a year.

A content security policy is off, and when switched on starts in report-only
mode, where the browser reports what the policy would have blocked and blocks
nothing. A policy that has not been fitted to the site it protects fails as a
blank section rather than an error, so it is fitted before it is enforced.

= AI crawlers =

An AI crawler fetches raw HTML. It runs no JavaScript, so the copy deterrent is
invisible to it and every word on the site is already readable. The only lever
is a robots.txt directive naming each bot, honoured by choice rather than
enforced.

The default takes the commercial side of that trade: let them read the site, and
publish an `llms.txt` telling them what the business does, which pages matter,
and the terms it expects to be quoted under. Blocking them removes the site from
ChatGPT, Perplexity and AI Overviews. The block list is there for the sites that
want it, with Google-Extended kept clearly separate from Googlebot.

= The scan =

Switches describe an intention. A caching layer, a CDN or another plugin can all
disagree with them. The scan fetches the site anonymously over HTTP and reports
what came back, which is the only part worth trusting.

== Installation ==

1. Upload the folder to `/wp-content/plugins/` and activate it.
2. Go to **Tools → Beaver Shield** and run the scan.
3. Work through the tabs. Anything marked Recommended is safe to leave on.

== Frequently Asked Questions ==

= Will this hurt SEO? =

No, and the plugin tries to prove it rather than assert it. The canonical tag,
the sitemap, the feed and the page content are untouched, and the indexable body
text is identical with the plugin on and off.

Four of the scan's checks exist only for this. It confirms the site is set to
allow indexing, that robots.txt does not restrict Googlebot or Bingbot, that a
canonical tag is present, and that the page fetched as Googlebot matches the
page fetched as anyone else, since serving crawlers something different is
cloaking. A fifth check reads every stylesheet and script out of the home page
and fetches each one as Googlebot, because Google renders a page before indexing
it and cannot render what it cannot fetch.

Two settings need thought rather than trust. An enforced Content Security Policy
applies inside the browser, including Google's renderer, and no scan can see it;
run it in report-only mode first. And on a site with plain permalinks, `?author=1`
is the author archive rather than an enumeration trick, so that part of the block
switches itself off there.

= Will this slow the site down? =

It takes work away rather than adding it. The emoji script and its DNS prefetch
come off every page, and the front end JavaScript is under 1KB, deferred, and
only loads when the copy deterrent is switched on.

= Does it edit my .htaccess? =

Only inside a single marked block, written with the same core function
WordPress uses for its own rewrite rules. The file is copied once before the
first write, everything outside the markers is left alone, and the block is
removed on deactivation. On nginx it writes nothing and shows you the config to
paste instead.

= I moved the login URL and lost it. =

Add `define( 'BEAVER_SHIELD_OFF', true );` to wp-config.php, or run
`wp beaver-shield login reset`, or rename the plugin folder over FTP.

= Does the copy deterrent stop people stealing my content? =

No, and nothing can. It stops a casual highlight and copy. What actually
protects work is being indexed first, a phrase only you use seeded in the copy
so lifting can be proved, watermarked images, and a takedown notice to their
host.

== Screenshots ==

1. The exposure scan, showing what a visitor can still read.
2. Fingerprint settings, with the case for each one.
3. The AI crawler tab and the generated llms.txt.

== Changelog ==

= 1.0.0 =
* First release.
* Fingerprint reduction: generator, asset versions, discovery links, response
  headers, emoji polyfill, readme and licence files.
* Hardening: username enumeration, admin file editor, login errors, PHP in
  uploads, directory listing, XML-RPC, hotlink protection.
* Security headers with a report-only content security policy.
* Optional custom login URL, with a wp-config escape hatch and a WP-CLI reset.
* robots.txt directives per AI crawler and a generated llms.txt.
* Optional copy deterrent, with a mode that appends attribution instead of
  blocking.
* Exposure scan over HTTP, in the admin screen and in WP-CLI, including four
  SEO checks and a render check that fetches every stylesheet and script as
  Googlebot.

== Upgrade Notice ==

= 1.0.0 =
First release.
