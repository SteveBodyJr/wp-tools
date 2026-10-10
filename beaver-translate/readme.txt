=== Beaver Translate ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: translation, multilingual, ai, languages
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-drafted translations for posts and pages, reviewed by a human before anything goes live.

== Description ==

Beaver Translate generates a draft translation of a post or page using an AI provider of your choice, and stops there. It never publishes anything on its own — every draft sits in a review queue until someone with editing rights reads it and approves it.

Each language is a separate, real WordPress post, linked back to the one it was translated from. That means every other plugin on the site — SEO, caching, page builders — sees a normal post, not a special case it has to be taught about.

**What it does**

* One AI-generated draft per post per configured language, created with a click from the "Translations" panel on the post editor.
* A review queue listing everything waiting for approval across the whole site.
* Approve to publish, reject to send the draft to the trash — nothing is ever silently overwritten or silently published.
* Works with Claude, ChatGPT, DeepSeek, Gemini, Groq, OpenRouter, or any OpenAI-compatible endpoint you point it at.
* HTML and Gutenberg block markup are preserved deliberately — only the human-readable text is translated, not the structure around it.
* Each published translation gets its own address — `example.com/fr/post-slug/` — with the site's default language left exactly where it already was; nothing about an existing site's URLs changes on activation.
* A translation visited at its own bare, unprefixed slug redirects (301) to its correct prefixed address, so it is never reachable at two different URLs.
* A `[beaver_translate_switcher]` shortcode links between every language a piece of content is actually published in.
* Taxonomy term names (categories, tags, custom taxonomies) translate too, via `wp beaver-translate terms` — the term itself stays one row and one URL, only its displayed name changes per language.
* `hreflang` tags, automatically, once [beaver-seo](../beaver-seo/README.md) is also active.
* WP-CLI for bulk generation across a whole post type.

**What it deliberately does not do (yet)**

No automatic redirect by browser language. `hreflang` and taxonomy term name translation are both built — see the changelog — the remaining gap is a redirect-by-browser-language feature this plugin has never planned to add on its own initiative, since silently redirecting a visitor who typed an English URL on purpose is its own kind of wrong.

== Installation ==

1. Upload the `beaver-translate` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Translate → Settings**, add the languages you want to translate into, and enter an API key for the provider of your choice.
4. Open any post or page and use the **Translations** panel to generate a draft.
5. Go to **Translate → Review queue** to approve or reject what comes back.
6. Add `[beaver_translate_switcher]` to a template or to post content to link between a piece of content's published languages.

== Frequently Asked Questions ==

= Will a translation ever publish itself? =

No. Every draft is created with `post_status = draft` and stays there until a signed-in user with publishing rights clicks Approve, in wp-admin or via `wp beaver-translate approve`. There is no setting that changes this.

= What happens to the translation if I delete the original post? =

The translation is left alone — only the link between them is removed. Deleting an English original is not a reason to also delete a French translation that may be the only copy a French visitor ever reads.

= Does it translate images? =

No, and it does not need to — the featured image is copied across unchanged, since translating text does not change a photograph.

= Can I use my own AI provider's endpoint, not one from the list? =

Yes, choose "Custom (OpenAI-compatible)" and enter your own URL. Anything that speaks the OpenAI chat/completions shape works.

= Where does my API key live? =

In the database by default, or set `BEAVER_TRANSLATE_API_KEY` in `wp-config.php` to keep it out of the database entirely — that always takes priority and is never shown back in the settings screen.

= Will activating this change any of my existing URLs? =

No. Only translations get a language prefix; the site's default language keeps every URL it already had. This is deliberate — a plugin that changed existing, indexed URLs on activation would cost a site its search rankings, not just its links.

= What happens if I remove a language from the settings after posts were already translated into it? =

Anything already translated into that language stays published, but becomes reachable only at its plain, unprefixed address rather than through any `/xx/` prefix — it will not loop, error, or disappear.

== Changelog ==

= 1.5.0 =
* Fixed a language-prefixed archive, the posts-page front page, or a feed being able to list posts in every language at once, English included — the rewrite duplication that makes `/fr/category/wildlife/` resolve at all was never paired with anything that filtered what such a listing actually shows. Any non-singular request now restricts the main query to posts actually translated into the requested language, the same way a single post or page was already being verified.
* Added taxonomy term name translation. A term stays exactly one row, one slug, one URL — only its displayed name is swapped per language, via `wp beaver-translate terms --language=fr`, so a French archive or a French post no longer shows an English category, tag or custom taxonomy label. Every template tag that reads a term's name (`get_the_terms()`, `the_terms()`, archive titles, and — for any site also running beaver-seo — its JSON-LD structured data) picks this up automatically, since it is filtered at the one place nearly all of them funnel through.
* `hreflang` tags are built, in [beaver-seo](../beaver-seo/README.md) rather than here as planned — see that plugin's changelog. Nothing to turn on: install and activate it alongside this plugin and the tags appear.

= 1.4.0 =
* Added an opt-in "also translate custom field text" setting. copy_structured_fields (1.3.2) stopped custom fields from disappearing on a translated post, but copied every one verbatim — correct for a dropdown value or a term ID, wrong for a safari package's itinerary day descriptions, FAQ answers, and included/excluded items, which stayed in the source language even on an otherwise fully translated page. Turning this on sends every custom field that reads as prose through translation too, in one extra request per post rather than one per field.
* The site's own name (Settings > General > Site Title) is now protected by the glossary automatically — no checkbox, nothing to type. A company is never expected to translate its own name just because a page mentioning it did.

= 1.3.2 =
* Fixed translations missing every taxonomy term and custom field the source post had — an accommodation's Level and Location, a destination's region, any ACF field. Only title, excerpt and content were ever translated; everything else a post carries was simply never copied, so a translated page could render with entire sections of its own template blank. Every taxonomy and custom field is now carried across at generation time, and again when a regenerated translation is approved.
* The glossary no longer asks for individual names one at a time. Instead: tick a post type — Destinations, Accommodations, whatever a site has — and every published title in it is protected as a whole category, staying current automatically as content is added, with a free-text area underneath for anything else.

= 1.3.1 =
* Fixed a real hole in "nothing goes live without review": a translated post is a completely normal WordPress post, so nothing ever stopped an editor from opening it and clicking WordPress's own Publish button directly, bypassing this plugin's Approve action entirely. When that happened, the link table never found out — which meant the language switcher failed to notice the translation existed, and, more seriously, Reject in the review queue would have trashed a post that was already live. Publishing a translated post any way at all now syncs the link table to match, closing both problems at the source rather than only hiding the symptom.
* The glossary is now a checklist of safari-industry terms (place names, cultural terms, industry vocabulary) with a free-text area for anything site-specific, instead of one blank textarea.

= 1.3.0 =
* Fixed newly added languages 404ing at their own URLs. The language-prefixed rewrite rules are built from the configured language list, but nothing rebuilt them when that list changed — a language added in Settings sat completely unreachable until something unrelated triggered a flush (deactivating the plugin, resaving Permalinks). Settings now flushes the rewrite rules itself, but only when the language list or default language actually changed.
* Added a glossary: a plain list of terms — place names, property names, brand terms — that are never translated or transliterated, in any field, including beaver-seo's.
* Added optional interop with beaver-seo, off by nothing more than beaver-seo not being installed: a source post's SEO title and description are translated alongside its content, and its noindex/nofollow flags and social image are carried across as-is. Without this, a translation quietly fell back to an auto-generated SEO title instead of a deliberately written one.
* Added automatic switcher placement: a floating switcher, off by default, one settings toggle away, for any theme or Elementor site with no shortcode-friendly hook of its own to wire into. The `[beaver_translate_switcher]` shortcode still works exactly as before for anyone placing it deliberately.
* Fixed the switcher rendering for the wrong content when placed somewhere other than post content — a theme header, for instance. WordPress sets the global `$post` to the first post of the main query on any page that has one, including archives and the blog home; the switcher now only ever renders on an actual `is_singular()` view.

= 1.2.1 =
* Fixed the switcher shortcode showing translation links for the wrong content — or arbitrary content — when placed outside a single post/page, such as a theme's global header. WordPress sets the global `$post` to the first post of the main query on any request that has one, archives and the blog home included, not only single views; the shortcode now renders nothing unless the request is actually `is_singular()`.
* The switcher's own CSS no longer assumes a light background or in-content spacing — every colour is `currentColor` and the margin is gone, so it reads correctly whether it ends up inside post content or dropped into a theme's own header/footer hook.

= 1.2.0 =
* Added a Dashboard screen: per-language translation coverage (published, needs review, failed, not started) against every eligible post, page and custom post type.
* Added a "Generate remaining" bulk action per language, processed one post at a time in the browser — never in parallel, to keep the odds of two AI calls landing on the same post as low as this plugin can currently make them.
* Settings moved to its own screen (`beaver-translate-settings`); the top-level menu and its first submenu are now the Dashboard.

= 1.1.2 =
* The API key field now shows a masked, locked "saved" state once a key exists, with an explicit Change button — saving the settings screen for any other reason (adding a language, changing the timeout) can no longer accidentally resubmit or blank the stored key.

= 1.1.1 =
* Fixed Regenerate taking an already-published translation offline. Regenerating reused the live post's own ID and set it back to `draft` the instant the button was clicked, so a published page went dark for however long review took — sometimes permanently, if nobody noticed. Regenerating a live translation now writes the new content to a separate, not-yet-visible post; the live page keeps serving its current content until a reviewer explicitly approves the new version, which copies the new content into the live post and discards the temporary one.
* Fixed Reject on a rejected regeneration trashing the wrong post. It trashed `translated_post_id` unconditionally — for a regeneration of a live translation, that is the live page itself, not the draft being rejected. Reject now always discards only the pending attempt; a live translation is never touched by rejecting a regeneration of it.
* The review queue, the post editor's Translations panel, and `wp beaver-translate queue` now all link to the actual post waiting for review rather than the (possibly unrelated, already-live) post the language is otherwise attached to.

= 1.1.0 =
* Every published translation now gets its own address (`example.com/fr/post-slug/`), built by duplicating WordPress's own rewrite rules with a language prefix rather than reimplementing permalink matching from scratch.
* A translation visited at its own bare slug redirects to its correct prefixed address; a prefixed address that does not actually resolve to that language's translation 404s instead of silently showing the wrong content.
* Added the `[beaver_translate_switcher]` shortcode.
* The Translations panel now links to a published translation's live URL.

= 1.0.0 =
* First release.
