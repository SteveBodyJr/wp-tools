# Beaver SEO

Titles, meta descriptions, Open Graph and Twitter Card tags, canonical URLs,
robots control, an XML sitemap, basic structured data, and a redirect
manager — generated from what WordPress already knows. No external service,
no build step, no keyword-density scoring.

---

## Install

1. Copy the `beaver-seo` folder into `wp-content/plugins/` (or upload the zip
   under **Plugins → Add New → Upload**).
2. Activate it.
3. Go to **SEO → Settings** and fill in the site name, title template and
   social defaults.
4. If Yoast, Rank Math, All in One SEO or SEOPress is also active, deactivate
   it — the settings screen will tell you so, but running two is what causes
   a duplicate `<title>` or two canonical tags on the same page.

## What it deliberately does not do

No readability or focus-keyword score. That kind of analysis is real work to
do honestly, and doing it badly — rewarding a hollow sentence that repeats a
phrase, marking down a well-written page that does not — is worse than not
offering an opinion. This plugin gets the mechanics right: the tags a crawler
or a social network actually reads. The writing is yours.

## How each piece works

**Titles and descriptions.** A template with tokens (`%title% %sitename%
%tagline% %sep% %excerpt% %date% %author% %year%`), overridable per post and
per term. Left blank, the description falls back to the post's own excerpt,
or a trim of its content — never a guess dressed up as one.

**Open Graph and Twitter Card.** Reuses the exact title and description
`Beaver_SEO_Meta` already resolved for the page, so the tags search engines
read and the preview a social network shows can never say two different
things about the same page. The image falls back from a per-post override,
to the featured image, to a site-wide default.

**Canonical and robots.** The canonical tag is added through WordPress's own
`get_canonical_url` filter rather than replacing it, so a theme or another
plugin's canonical logic is only overridden where this plugin actually has an
opinion. Robots directives go through `wp_robots`, the same filter core uses
for "discourage search engines" — this plugin adds to that list, never
replaces it.

**Sitemap.** Served at `/sitemap.xml`, generated on request rather than
written to disk, and cached for an hour. Each post type is split into files
of at most 1,000 URLs, comfortably under the search engines' own 50,000-URL
ceiling, so a large library never risks a slow query or an oversized file.

**Structured data.** A WebSite node on every page, an Organization or
LocalBusiness node once one is named in settings, an Article node on posts.
Nothing that requires data this plugin cannot verify — no ratings, no FAQ
blocks, no product schema.

**Redirects.** An exact-path match against a dedicated `wp_beaver_seo_
redirects` table, checked before WordPress resolves the request. No regular
expressions: a mistyped pattern in a redirect table is a well known way to
take a whole site offline, and nothing here needs one.

**robots.txt.** Only takes effect when the site has no physical `robots.txt`
file in its root — a real file on disk is served directly by the web server
and WordPress is never asked.

**hreflang, with beaver-translate.** A soft dependency, checked with
`class_exists( 'Beaver_Translate_Links' )` at `plugins_loaded` — this plugin
never requires that one, and prints nothing at all when it is not active.
When it is, every singular post gets one `<link rel="alternate"
hreflang="…">` per published language version, read straight from
[beaver-translate](../beaver-translate/README.md)'s own link table, plus
`x-default` pointing at the source post's own URL. Nothing to configure on
either plugin: activate both and the tags appear. A page with only one
published language prints none — hreflang relating a page to itself is
noise, not signal.

## WP-CLI

```sh
wp beaver-seo sitemap flush
wp beaver-seo redirects list
wp beaver-seo redirects add /old-page /new-page
wp beaver-seo redirects add /old-page https://example.com/moved --status=302
wp beaver-seo redirects delete --id=4
```

## Deactivating and uninstalling

Deactivation removes the sitemap's rewrite rules and its cache only. Every
setting, every post's SEO fields, and the whole redirect table are preserved
— reactivating brings the sitemap straight back. Uninstalling removes all of
it: settings, post and term meta, and the redirects table.

## Requirements

WordPress 5.8+, PHP 7.4+. Nothing else — no outbound HTTP request is ever
made by this plugin.

---

Digital Beaver · GPL-2.0-or-later
