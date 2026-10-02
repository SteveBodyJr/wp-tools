=== Beaver Press ===
Contributors: digitalbeaver
Tags: safari, tourism, multilingual, translation, multilingual seo
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.6.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual SEO and AI translation built for safari and tourism websites: real language addresses, server-rendered translations, a visual editor.

== Description ==

Beaver Press makes safari and tourism websites multilingual: safari operators, tour companies, travel agencies, lodges, camps, hotels and destination companies. Travellers find each language in search, read pages written for them, and book in their language. Built in Tanzania, with East African tourism in mind; it follows your own site wherever you operate.

* **Safari & Tourism translation profile**: the AI is told it translates a safari and tourism business, writes natural, persuasive tourism language for international travellers, uses the established words for game drives, tented camps, walking safaris and the like, keeps lodge, camp, package and park names, and never invents prices, durations or sightings. Your own instructions come last and win.
* **SEO-friendly language addresses**: your language at the root (example.com/safaris/), the others under their code (example.com/fr/safaris/, /de/, /it/, /es/, /sw/...); optional translated slugs (/fr/safaris-en-tanzanie/) and translated prefixes; old addresses answer 301.
* **Server-rendered translated pages**: search engines and travellers get the translated HTML directly, no JavaScript needed.
* **AI-assisted translation, stored once**: Claude, ChatGPT, DeepSeek, Gemini, DeepL or any OpenAI-compatible endpoint. Each text is translated once per language and stored (translation memory: "Book now" is translated once and used everywhere). Visitors never cause AI requests.
* **Visual editor and manual translations**: click any text, attribute or image alt on the page and correct it. Automatic translation never overwrites your corrections.
* **Changed texts**: when you edit a sentence that has a hand-made translation, the translation is kept on the new sentence and marked outdated (Keep, Retranslate with AI, Edit).
* **Multilingual SEO**: a self canonical per language; hreflang plus x-default; translated title, meta description, Open Graph, image alt text and structured-data text; language sitemaps with complete, indexable pages only; pages not fully translated yet are shown in your language with noindex and a canonical to the original.
* **Works with Yoast SEO, Rank Math and SEOPress** (tested): one canonical, one hreflang set, language sitemaps added to their sitemap index, their noindex choices respected.
* **Gutenberg and Elementor** (tested), language switcher (shortcode, block, menu, floating), provider/model provenance for new translations, backup, export (JSON, CSV), import with dry run, restore, and a debug view for administrators.

Languages are the ones you add in Beaver Press; for safari travellers English, French, German, Spanish, Italian and Swahili are common choices.

Designed & built by Digital Beaver - https://digitalbeavertz.com/

== Installation ==

1. Install and activate Beaver Press (Plugins -> Add New -> Upload). It includes its translation engine.
2. Settings -> Beaver Press -> Languages: add your languages.
3. In the engine settings (Languages -> Automatic Translation), pick a provider, paste its API key, press Test connection and save.
4. Settings -> Beaver Press -> Instructions: the Safari & Tourism profile is active; add a sentence about your own business if you like (your instructions win).
5. Settings -> Beaver Press: press Translate site.

A site that used the separate TranslatePress plugin: deactivate it after installing Beaver Press. Languages, translations and settings stay as they are (same tables and options).

== Frequently Asked Questions ==

= Is each text paid for more than once? =

No. A text is translated once per language and stored; later visits read the stored translation. A later run only sends texts that are new or changed.

= Can visitors cause costs? =

Not with the visitor guard on (the default): only the site owner's actions send text to the provider.

= Will automatic translation overwrite my corrections? =

No. It only fills texts without a translation. A changed source sentence keeps your translation, marked outdated, until you decide.

== Theme requirements ==

* Page and post content must be printed with the_content() (page.php, singular.php or index.php with the loop). A theme without that shows plain pages empty in every language.
* Text that changes by date or number should keep the value apart from the fixed words in the theme markup (e.g. This month: <span>October</span>), or every new value becomes a new text to translate.
* Text written by a theme's JavaScript is not translated unless the theme passes it through translated strings.

== Known limitations ==

* Changed-text detection covers texts linked to a post; other changed texts get a new translation (the old one stays stored).
* WooCommerce compatibility: not tested / not part of this release scope.
* Google Search Console inspection has not been done (needs a public site).
* LibreTranslate is not available.
* Translations made before 2.6.0 have no recorded provider and are shown as "legacy (provider unknown)".
* A page never visited in a language is not yet known to be complete or noindex; the daily top-up and the progress check visit pages.

== Credits and licence ==

Beaver Press is free software under the GNU General Public License v2 or later; the licence text is in LICENSE.

The translation engine in engine/ is TranslatePress - Multilingual 3.3.7 (free edition), Copyright 2017 Cozmoslabs, licensed under the GNU General Public License v2 or later, included without changes. Source: https://wordpress.org/plugins/translatepress-multilingual/ (code: https://plugins.svn.wordpress.org/translatepress-multilingual/tags/3.3.7/). Its own licence and copyright notices, and those of the libraries it bundles, remain in its files. Beaver Press is not affiliated with or endorsed by Cozmoslabs. No code from paid editions is included.

== Changelog ==

= 2.6.2 =
* Safari & Tourism translation profile: one central definition of the industry context, tourism style, terminology, places, what must never change, per-language suggestions and the names rule (shown on the Instructions tab; further profiles can be added by filter). Sites with their own saved instruction and names rule keep them.
* Language links refresh at once when a page gets or changes its translated address (ready pages included).
* Language sitemaps list only public page addresses: no query-string, preview, admin or login addresses (query strings stay allowed with plain permalinks).
* A language without complete pages gets no sitemap entry (no invalid entry in an SEO plugin's index).
* Licence text (LICENSE) included; readme rewritten (limitations, theme requirements, engine source).

= 2.6.1 =
* Language sitemaps leave out pages the theme, an SEO plugin or WordPress marks noindex (read from the page as built: robots/googlebot meta or X-Robots-Tag). Tested with no SEO plugin, Yoast SEO, Rank Math and SEOPress.
* hreflang: versions not complete yet are also left out when the address has a query string (e.g. ?utm_source=).
* Provenance records the real type (page text or theme/plugin text); translations made before 2.6.0 show as "legacy (provider unknown)", nothing is back-filled.
* Visual editor: the engine's "Extra Translation Features / Upgrade to PRO" box removed (nothing unlocked).
* Audit: flags texts that come back every day and short sentences with a month inside; upgrade/fresh-install check script.

= 2.6.0 =
* SEO and address audit script for disposable copies (tests/audit): canonical, two-way hreflang, x-default, html lang, translated title and description, links, slugs and 301s, 404s, sitemaps, mobile, permalink change, default theme, Elementor, Gutenberg.
* hreflang x-default on by default (original language). Pages not complete yet: canonical and og:url point to the original, no hreflang. Ready copies refreshed when a page becomes complete or not; records start afresh after a theme switch. Old translated addresses redirect after a permalink change.
* Image alt text translated (not counted for page completeness).
* Translations backup, export (JSON, CSV) and import with validation, dry run and duplicate count; manual translations never replaced unless chosen; automatic backups before import, restore and Redo; restore. WP-CLI: export, import, backup, backups, restore.
* Provenance per translation (provider, model, status, source hash, times). A changed original keeps its manual translation, marked outdated, listed under Changed texts with Keep, Retranslate with AI and Edit.
* Works next to Yoast SEO, Rank Math and SEOPress: language sitemaps served and added to their sitemap index when WordPress's sitemaps are off; no duplicate canonical, title, description or hreflang.
* Debug mode for administrators (?bp_debug=1, admin-bar link): every text with its translation and provenance, page checks, provider and cache state; never shows keys.

= 2.5.0 =
* Translated address prefixes: /fr/circuits/... instead of /fr/tours/..., per language and per post type or taxonomy (Address prefixes card, shown when translated addresses are on). Archives, their pages, terms, links, canonical, language links and sitemaps follow; old addresses get a 301. Nothing changes until the prefixes are saved; Draft fills the boxes from stored translations (or the engine). A prefix that is a page's address, another prefix or a language code is refused. Languages not written in Latin letters keep the original addresses.

= 2.4.0 =
* Alerts: when the provider refuses (key rejected or missing, no credit, DeepL quota used up) or the spending limit is reached, a notice shows on every wp-admin screen for administrators and one email goes to the site's admin address (switch on the Set-up card). Both clear after the next successful request.
* Prices built in for DeepSeek (deepseek-flash, deepseek-v4-pro and the old names), so usage and estimates show real cost; DeepSeek's default model is now deepseek-flash.
* Spending limit in money: stop at a set amount per day and per month (runs, the daily top-up and the AI buttons). Usage is kept for 62 days.
* Pages tab: open an incomplete page's cell to see which texts are missing; a text that comes back every day (a changing number or date) is flagged.
* Settings history: the last 10 copies of the settings, kept after each save, with Restore. API keys, translations and the language list are never in a copy.
* Tests: one shared start (tests/bootstrap.php) puts the settings back however a test ends.

= 2.3.0 =
* Names to keep: the AI sorts names with one written rule ("How to decide", built in and editable) instead of word lists. Suggest with AI tags every name keep or translate; nothing changes until the suggestions are applied and saved.
* New stays and destinations are sorted by the same rule in the daily run (only new names; switch on the card). Names the owner has saved are never changed.
* Find names I missed: proper names in the site's texts that are not on the card, offered for "Your own names".
* Search box on the card, with Tick shown / Untick shown.
* Fixed: group titles on the card could show the engine's hidden markers (#!trpst#...) after the name cache was rebuilt during a page view.

= 2.2.0 =
* Automatically translate slugs: turning it on gives every existing post, page, custom post type and category its address in each language in the background (languages in non-Latin scripts keep the original address).
* Daily top-up: pages that lost their complete state without an edit are finished at night (only the missing texts; switch on the Set-up card; wp beaver-press topup).
* The engine's usage-data opt-in is never offered and never sends anything.
* A provider with no credit (HTTP 402) stops a run, like a refused key.

= 2.1.0 =
* Languages tab inside Beaver Press (the engine's screens, with the Beaver Press header); the Review tab removed (edits in the visual editor).
* Beaver Press screens in the same design as the Languages screens.
* Faster: pages in the original language are kept ready too, ready pages are built again in the background after a change (also wp beaver-press cache-warm), and editors browsing the site no longer start paid translations.
* Fixed: language links on pages not yet complete carried an internal marker; a run could stop on another provider's error.

= 2.0.0 =
* One plugin: the translation engine is included (engine/, TranslatePress free 3.3.7, unchanged). The separate plugin is no longer needed.
* Its name, logo, website links and sales notices removed from wp-admin; its menu is Settings -> Languages.
* tools/update-engine.sh to bring in new engine releases, tested.

= 1.14.1 =
* The run no longer empties the ready pages; enter model prices for estimates; tests included.

= 1.14.0 =
* Instructions generated for every language; master instruction; names to keep chosen by group and one by one.

= 1.13.0 =
* Forms in the visitor's language: messages after sending and the visitor's confirmation email; emails to you stay in the original language.

= 1.12.0 =
* Export and import settings (never the API keys) to set up another site in a minute; also from WP-CLI.

= 1.11.0 =
* WP-CLI: wp beaver-press status, estimate, run, resume, pause, cancel, check, pages, draft-addresses, topup, cache-clear, cache-warm.

= 1.10.0 =
* Language sitemaps in WordPress's sitemap: every complete page in each language, at its translated address.

= 1.9.0 =
* Translated page addresses per language (/fr/a-propos/), drafted from translated titles, editable, old addresses 301; switch in Settings -> Beaver Press.

= 1.8.1 =
* Adding and removing languages: no limit in practice, new languages get suggested instructions and show as "not translated yet", removed languages are skipped and cleaned up.

= 1.8.0 =
* Instructions tab: your own translator instructions for the whole site and per language (suggestions included), Try it, the full prompt, Redo a language.

= 1.7.0 =
* Pages tab: every page's progress per language, translate chosen pages, free progress check; the run shows the page it is on.

= 1.6.0 =
* Visitors see a translated page only when it is complete; until then the original page with a short note, left out of hreflang and suggestions.

= 1.5.0 =
* Language suggestion bar in the visitor's own language, remembered; optional first-visit redirect (off by default, never for search engines).

= 1.4.0 =
* Fast language switching: the other language is fetched when a visitor reaches for the switcher, and fully translated pages are kept ready for visitors (emptied on any change).

= 1.3.0 =
* Translator role: the visual editor and a Review-only Translations page, nothing else; administrators keep full access.

= 1.2.0 =
* Structured data (JSON-LD) translated on translated pages: names, descriptions, headlines, FAQ questions and answers; prices, dates and links unchanged.

= 1.1.0 =
* Auto-update: published or updated content is translated in the background in every started language (only new text is sent); switch in Settings -> Beaver Press.

= 1.0.2 =
* Model dropdown filled by "Load models"; clearer names rule (spelling kept, generic words translated).

= 1.0.1 =
* Numbers on translated pages formatted as on the default-language pages (one text per sentence); estimate counts languages not visited yet.

= 1.0.0 =
* Quiet mode for TranslatePress's admin; branding; readme and icons.

= 0.9.0 =
* Page titles, meta description, Open Graph and Twitter tags translated.

= 0.8.0 =
* Review tab: filter, edit, approve, re-translate, kept-in-original list.

= 0.7.0 =
* Usage and token tracking, estimate before a run, run budget, daily limit respected.

= 0.6.0 =
* Settings -> Beaver Press with the Translate-site run.

= 0.5.0 =
* Glossary from the site's names plus an own list.

= 0.4.0 =
* Visitor guard.

= 0.3.0 =
* Batch translation with per-string checks.

= 0.2.0 =
* Engine with one switcher for Claude, ChatGPT, DeepSeek, Gemini, DeepL and custom models.

= 0.1.0 =
* Extra-languages setting, encrypted key store, provider layer.
