# Beaver Press

Multilingual SEO and AI translation built for safari and tourism websites (safari operators, tour
companies, travel agencies, lodges, camps, hotels). The translation engine (language switcher, visual
editor, stored translations) ships inside it in `engine/`, unchanged and reusable; the product's
translation intelligence lives in one profile (`includes/class-bp-profiles.php`, Safari & Tourism).
By Digital Beaver.

Owner's how-to: `docs/TRANSLATION-GUIDE.md` in the Raya Safaris project. The release ZIP holds the
plugin files only (no `tests/`, `tools/` or this README); the user-facing description and limitations
are in `readme.txt`.

## Layout

| Path | Job |
|---|---|
| `beaver-press.php` | Header, constants; loads the bundled engine (unless the separate TranslatePress plugin is still active), then the classes; engine registration runs before the engine picks its translator (`plugins_loaded` 2) |
| `engine/` | TranslatePress free (GPL, from wordpress.org), **unchanged**. Update with `tools/update-engine.sh`. `TRANSLATE_PRESS` is defined as the free edition before it loads |
| `includes/class-bp-plugin.php` | Boot on `plugins_loaded` 5 (after the engine) |
| `includes/class-bp-brand.php` | No engine branding in wp-admin: text filter, output filter on its screens and the visual editor, logo/link CSS, its screens shown as the Languages tab |
| `includes/class-bp-keys.php` | Per-provider keys, libsodium with the site's salts; `BEAVER_PRESS_<PROVIDER>_API_KEY` / `BEAVER_PRESS_API_KEY` constants override |
| `includes/class-bp-providers.php` | Presets, one JSON request path per API format (Anthropic, OpenAI-compatible, Gemini), DeepL, model lists, token usage |
| `includes/class-bp-engine-settings.php` | The Beaver Press translator on Languages -> Automatic Translation: panel, saving, Test connection, Load models |
| `includes/class-bp-ai-machine-translator.php` | The translator (`TRP_Machine_Translator`): batches, checks, give-up list, usage |
| `includes/class-bp-batch.php` | Prompt (fixed technical rules + profile lines + names + owner instructions), reply schema, per-string checks (structure, then facts: numbers by value, e-mail/web addresses, shortcodes; `BP_Batch::facts()`) |
| `includes/class-bp-terms.php` | Terms per language and search words per language (Instructions tab card, AI suggestions, request lines) |
| `includes/class-bp-profiles.php` | Translation profiles: Safari & Tourism (context, style, terms, places, protections, per-language suggestions, names rule); filter `beaver_press_profiles` |
| `includes/class-bp-instructions.php` | Instructions tab: profile card, site and per-language instructions (profile suggestions until saved), names to keep, Try it, Redo |
| `includes/class-bp-glossary.php` | Names kept in every language (groups, per-name choices, own list); DeepL `<bp-keep>` |
| `includes/class-bp-guard.php` | Visitor guard (editors browsing do not translate either); signed `X-Beaver-Press-Run` requests |
| `includes/class-bp-run.php` | Translate-site run, chosen pages, page list, free progress check |
| `includes/class-bp-hosting.php` | Hosting check (site reaches itself, WP-Cron, PHP time limit), server-address fallback for requests to itself (`BEAVER_PRESS_LOOPBACK_IP`), real-cron and WP-CLI help |
| `includes/class-bp-auto.php` | Edited content translated in the background |
| `includes/class-bp-topup.php` | Daily top-up: pages with a few texts missing are finished at night |
| `includes/class-bp-usage.php` | Usage per day and run, estimate, model prices, daily limit |
| `includes/class-bp-complete.php` | Only complete pages for visitors; per-page progress records; hreflang filter |
| `includes/class-bp-cache.php` | Ready pages (all languages) for visitors; cleared on changes |
| `includes/class-bp-prefetch.php`, `class-bp-suggest.php` | Switcher prefetch; "View this page in ..." bar |
| `includes/class-bp-slugs.php` | Translated page addresses (automatic backfill when switched on, Latin-script languages only), old addresses 301 |
| `includes/class-bp-slug-bases.php` | Translated address prefixes (`/fr/circuits/`), optional, per language |
| `includes/class-bp-sitemap.php` | Language sitemaps (inside WordPress's sitemap) |
| `includes/class-bp-sitemap-standalone.php` | Language sitemaps when an SEO plugin switches WordPress's sitemaps off |
| `includes/class-bp-backup.php` | Translation backup, export, import, restore |
| `includes/class-bp-provenance.php` | Provider/model/status per translation; changed originals ("Changed texts") |
| `includes/class-bp-debug.php` | Administrator debug panel (`?bp_debug=1`) |
| `includes/class-bp-alerts.php`, `class-bp-budget.php`, `class-bp-history.php` | Provider alerts; spending limit; settings history |
| `includes/class-bp-names-ai.php` | Names to keep sorted by a written AI rule |
| `includes/class-bp-seo.php`, `class-bp-schema.php` | Titles, meta tags and JSON-LD translated |
| `includes/class-bp-forms.php` | Form messages and the visitor's confirmation email per language |
| `includes/class-bp-roles.php` | Translator role (visual editor only) |
| `includes/class-bp-transfer.php` | Settings export / import (never keys) |
| `includes/class-bp-quiet.php`, `class-bp-numbers.php`, `class-bp-languages.php` | Engine extras hidden; number format; languages limit |
| `includes/class-bp-admin.php` | Settings -> Beaver Press: Translate site (status line, run, usage), Pages (paged, per-language counts), Instructions, Settings (switches, costs and limits, forms, prefixes), Tools (backups, transfer, history), Languages; `tab_url()` (no Review tab since 2.1.0: corrections in the visual editor) |
| `includes/class-bp-cli.php` | `wp beaver-press ...` |
| `tests/` | Command-line tests (`run-all.sh`, shared `bootstrap.php`), never on a live site, never during a run |
| `tests/audit/` | SEO/URL audit, SEO-plugin and upgrade checks for disposable copies only (see `tests/README.md`) |
| `tools/update-engine.sh` | Bring in a new engine release and run the tests (source repository only, not in the release ZIP) |
| `LICENSE` | GPL-2.0 text (shipped in the ZIP) |

## How it works

**Addresses.** The original language lives at the site root (`example.com/page/`), every other
language under its code (`/fr/page/`, `/es/page/`): real WordPress addresses, built on the server,
crawlable without JavaScript. Optionally each page gets its own slug per language
(`/fr/safaris-tanzanie/`) and post-type prefixes their own word (`/fr/circuits/`); old addresses
answer 301, also after a permalink change. Languages written in other scripts (Chinese, Arabic...)
keep the original slugs. Links on a translated page stay in its language; links to other sites
are left alone.

**Storage.** The engine's tables: per language one row per original text (page texts) plus texts
from themes and plugins (gettext). The original content is never changed. One row per text per
language is the translation memory: "Book Now" is translated once and used everywhere.
Beaver Press adds `{prefix}bp_translation_meta` (provenance) and per-page records in options
(`bp_pg_*` completeness, `bp_pt_*` missing texts, `bp_ni_*` noindex).

**AI translation.** Only texts without a translation are sent (in batches, with fixed rules for tags,
placeholders, numbers, URLs and shortcodes, then the Safari & Tourism profile, the names to keep and the
site's own instructions, which come last and win); visitors and editors browsing never start a paid request (visitor
guard): only the Translate-site run, the daily top-up, edits in the visual editor and WP-CLI do.
Each result is stored once and served from the database afterwards; complete pages are also kept
ready as files. Providers: Claude, ChatGPT, DeepSeek, Gemini, DeepL, any OpenAI-compatible endpoint
(and the engine's Google). Keys are encrypted with the site's salts and never sent to the browser,
logs, debug output or export files.

**Batches that fail.** A batch that comes back cut off, unreadable or declined is split in halves and
sent again; only a text that fails on its own counts towards the give-up list (after two failures it
is kept in the original, so it stops costing money). Redo and "Retranslate with AI" try given-up
texts again. The engine's string lock is held longer than one request can take
(`trp_machine_translation_lock_timeout`, at least 270 s), so two processes never pay for the same
strings; a request's timeout grows with the batch size. An empty provider account (Anthropic "credit
balance", OpenAI `insufficient_quota`) is `bp_http_402`: the run stops and the owner is alerted.

**Hosting.** The run, the top-up, form preparation and the ready-page warm-up open the site's own pages
over HTTP (`BP_Run::fetch()` signed with `X-Beaver-Press-Run`, `BP_Run::visitor_fetch()`) and rely on
WP-Cron. `BP_Hosting` checks both: Overview -> Health -> Check hosting, `wp beaver-press hosting`, and
before a run starts (at most once a day). The check opens the page-not-found address in the original
language as the signed run (nothing is translated) and reads the guard's reply header
`X-Beaver-Press-Run: ok`; it also reads how late the oldest scheduled task is and the PHP time limit.
When the public address fails from the server, 127.0.0.1 or `SERVER_ADDR` is tried and kept for every
request the site makes to itself (curl's resolve list, so the URL, Host header and TLS name stay the
site's); `BEAVER_PRESS_LOOPBACK_IP` sets it by hand. A run does not start while the site cannot reach
itself. Health shows a real-cron line and the WP-CLI commands for hosts where WP-Cron does not run.
Redirects are followed by hand and only on the site's own host: the run header never leaves the site.

**Languages suggested.** A translation profile lists the languages to suggest (`languages`, then
`languages_more`); the Overview shows those the site does not offer yet, and never adds one by itself
(each language is a paid run). Safari & Tourism: German, French, Spanish, Italian, then Dutch, Polish,
Chinese. Swahili is supported but not suggested: international travellers do not book safaris in it.

**Manual translations** (visual editor, import as manual) are never replaced by automatic
translation: it only fills empty ones. "Redo a language" resets machine translations only (after an
automatic backup). An import replaces manual translations only when that option is ticked.

**Outdated translations.** When an original text with a manual translation is changed, the engine
sees a new text. If the old text is gone from that page and the new one is similar (70%), the manual
translation is kept on the new text, marked `outdated` with the old source, and listed under
Pages -> Changed texts: Keep, Retranslate with AI (explicit, one request) or Edit.

**Provenance.** From 2.6.0 every new or retranslated translation records provider, model, type
(page text or theme/plugin text), source hash, language, time and status (`machine_translated`,
`manually_edited`, `outdated`). Translations made earlier have no record and are shown as
"legacy (provider unknown)"; nothing is guessed or back-filled.

**Backup, export, import.** Settings -> Beaver Press -> Translations: JSON (all or one language)
or CSV (one language); import with validation, duplicate count and dry run; automatic backups before
import, restore and Redo (last 10, private folder); restore removes rows a later import added.
WP-CLI: `wp beaver-press export|import|backup|backups|restore`.

**SEO.** Complete translated pages: canonical to themselves, `html lang` of the language, translated
title, meta description, Open Graph, image alt and JSON-LD text, hreflang for every complete version
plus x-default (original language). Pages not complete yet are served in the original with
`noindex, follow`, canonical to the original and no hreflang, so a half-translated page is never
indexed. Language sitemaps list complete, indexable pages only (a page the theme or SEO plugin marks
noindex is left out). Who prints which tag: `docs/SEO-OWNERSHIP.md` in the Raya Safaris project.

**SEO plugins.** Tested with Yoast SEO, Rank Math (after its setup wizard) and SEOPress: one
canonical, one title, one description, one hreflang set; their sitemap index gains the language
sitemaps; their noindex choices are respected.

**Debug.** Administrators add `?bp_debug=1` (or use the admin-bar link): every text of the page with
source, translation, row id, status, provenance and source hash, plus checks for missing or outdated
translations, routing, canonical, hreflang, provider errors and the cache. Never shown to visitors,
never cached, never shows keys.

## Theme requirements

- Pages must print their content with `the_content()` (`page.php`, `singular.php` or `index.php` with
  the loop). A theme without that shows plain pages empty in every language; Beaver Press does not
  replace theme templates.
- Text that changes by date or number must be split from the fixed words, e.g.
  `This month: <span>October</span>` or `<span data-no-translation>82%</span> lit tonight`, or every
  new value becomes a new text and the page is incomplete until it is translated.
- Text written by theme JavaScript must go through the theme's translation helpers.

## Not covered or not tested

WooCommerce compatibility: not tested / not part of this release scope. Search Console URL inspection needs a public site and has not been
done. LibreTranslate is not implemented. Changed-text detection only covers texts linked to a post.

The server-address fallback (3.1.0) is tested only where the public address and 127.0.0.1 are the same
machine; the first live site behind Cloudflare or a host firewall is its real test. Gemini's quota
errors (`RESOURCE_EXHAUSTED`) are not told apart from rate limits, so an empty Gemini account does not
stop a run by itself. Texts given up on count as translated for page completeness, on purpose: a page is
not kept out of search for one sentence left in the original.

## Rules

- Never edit files in `engine/`; use its hooks and component instances (branding lives in
  `class-bp-brand.php`). Never use code from paid editions.
- Never delete the option `trp_machine_translation_counter` (the engine switches automatic
  translation off when it is re-created within 12 hours).
- Theme scripts that write text must use the theme's `ryT()` / `ryName()` / `ryDeep()` helpers
  (Raya: `inc/raya-i18n.php`) or that text stays in the original language.
- Tests start with `tests/bootstrap.php`, which copies settings and keys and restores them however the test ends.
  `tests/run-all.sh` counts a test as passed only when it exits 0 and prints its "N passed, M failed"
  line; from the source folder set `BP_WP_LOAD` to a test site's `wp-load.php`.
- Every request the site makes to itself goes through `BP_Run::fetch()` or `BP_Run::visitor_fetch()`,
  whose arguments pass through `BP_Hosting::args()`. Never a bare `wp_remote_get()` to the site: it would
  lose the server-address fallback and could carry the run header to another host on a redirect.
- Engine prices and model lists (`BP_Usage::prices()`, `BP_Providers::presets()`): a model without a
  price counts as $0, so the spending limit cannot stop it. Add the price with the model.

## Moving a site from the separate plugin

Install Beaver Press, deactivate the separate TranslatePress plugin (data stays: same tables
and options; it has no uninstaller), check the site, then delete the separate plugin.

## Filters

`beaver_press_glossary`, `beaver_press_name_groups`, `beaver_press_prices`,
`beaver_press_run_urls`, `beaver_press_meta_selector`, `beaver_press_can_trigger_translation`,
`beaver_press_translate_while_browsing`, `beaver_press_quiet`, `beaver_press_brand`,
`beaver_press_cache`, `beaver_press_cache_ttl`, `beaver_press_cache_original`,
`beaver_press_prefetch`, `beaver_press_suggest`, `beaver_press_complete_only`,
`beaver_press_slugs`, `beaver_press_slug_language`, `beaver_press_slug_bases`, `beaver_press_topup_enabled`, `beaver_press_sitemap`, `beaver_press_schema`,
`beaver_press_names_rule`, `beaver_press_carry_changed`, `beaver_press_similar_percent`, `beaver_press_profiles`,
`beaver_press_og_locale`, `beaver_press_meta_description_max`, `beaver_press_cache_watch_theme` (3.2.0).

## Constants (wp-config.php)

`BEAVER_PRESS_<PROVIDER>_API_KEY` (for example `BEAVER_PRESS_CLAUDE_API_KEY`) and `BEAVER_PRESS_API_KEY`
(every provider except a custom endpoint, whose address is typed in wp-admin) override the saved keys.
`BEAVER_PRESS_LOOPBACK_IP` is the server address for the site's requests to itself.

GPL-2.0-or-later. The engine in `engine/` is TranslatePress free, Copyright Cozmoslabs, GPL-2.0-or-later.
Designed & built by Digital Beaver, https://digitalbeavertz.com/
