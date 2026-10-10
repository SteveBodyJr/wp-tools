=== Beaver SEO ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: seo, sitemap, open graph, structured data, redirects
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Titles, meta descriptions, social previews, a sitemap and redirects — generated from what WordPress already knows, no external service.

== Description ==

Beaver SEO prints the tags search engines and social networks actually read, and nothing it cannot back up with real data. It does not score your writing, does not invent a keyword density target, and does not phone anywhere to do it.

**What it does**

* Editable title and meta description per post, page and term, built from a template with a sensible automatic fallback when you leave it blank.
* Open Graph and Twitter Card tags for every page, so a shared link previews correctly on every network that reads either format.
* A canonical URL tag, with a per-post override for content that is also published somewhere else.
* Per-post noindex and nofollow, plus site-wide rules for search results, archives and paginated listings — added to WordPress's own `wp_robots` output, not fighting it.
* An XML sitemap at `/sitemap.xml`, split automatically once a post type passes a few thousand entries, with each page's image included.
* A robots.txt editor, for sites with no physical robots.txt file already in their root directory.
* Basic JSON-LD: a WebSite node on every page, an Organization or LocalBusiness node once you name one, an Article node on posts.
* A redirect manager for the exact moment a URL changes: 301, 302 or 307, with a hit counter.
* A notice if Yoast, Rank Math, All in One SEO or SEOPress is also active, since two SEO plugins both filling in the same tag is a site that broke itself.

**What it deliberately does not do**

No readability or focus-keyword scoring. That kind of analysis is real work to do honestly, and doing it badly — a green light for a hollow sentence, a red one for a well-written page that just does not repeat a phrase enough times — is worse than not offering an opinion at all. This plugin gets the mechanics right and leaves the writing to you.

== Installation ==

1. Upload the `beaver-seo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **SEO → Settings** and fill in the site name, title template and social defaults.
4. If another SEO plugin is active, deactivate it — the notice on the Settings screen will say so.

== Frequently Asked Questions ==

= Does this replace Yoast or Rank Math? =

For titles, meta tags, sitemaps, redirects and basic schema, yes. It does not replicate their content-scoring features, on purpose — see the description above.

= Will the sitemap slow my site down? =

No. Each sitemap page is generated on request and cached for an hour, and is capped at 1,000 URLs per file regardless of library size, so generating one is never a heavy query even on a large site.

= Can I use regular expressions in a redirect? =

No, redirects match an exact path only. That is a deliberate limit: a mistyped pattern in a redirect manager is a well known way to take an entire site offline, and nothing this plugin needs to do requires one.

= What happens to my robots.txt edits if the site already has a physical robots.txt file? =

They are ignored. A real `robots.txt` file in the site root is always served directly by the web server and WordPress is never asked. Delete that file first if you want this plugin to manage it.

= Does deactivating lose my redirects? =

No. Deactivating only removes the sitemap's rewrite rules and its cache. Redirects, settings and every post's SEO fields are untouched, and reactivating restores the sitemap immediately.

== Changelog ==

= 1.1.0 =
* Added `hreflang` tags, when beaver-translate is also active: every singular post prints `<link rel="alternate" hreflang="…">` for each of its published language versions, plus `x-default` pointing at the site's own default-language URL. Reads beaver-translate's own link table directly — nothing to configure on either plugin, and nothing prints at all when beaver-translate isn't active.

= 1.0.0 =
* First release.
