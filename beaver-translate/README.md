# Beaver Translate

AI-drafted translations for posts and pages, reviewed by a human before
anything goes live, each published at its own address —
`example.com/fr/post-slug/` — with the site's default language left exactly
where it already was.

---

## Install

1. Copy the `beaver-translate` folder into `wp-content/plugins/` (or upload
   the zip under **Plugins → Add New → Upload**).
2. Activate it.
3. Go to **Translate → Settings**, add your languages, and enter an API key.
4. Open a post, use the **Translations** panel to generate a draft — or from
   **Translate → Dashboard**, click **Generate remaining** to work through
   everything untranslated in a language at once.
5. Go to **Translate → Review queue** to approve or reject what comes back.
6. Drop `[beaver_translate_switcher]` into a template or post to link between
   a piece of content's published languages.

## The Dashboard

**Translate → Dashboard** is the landing page: one row per configured
language, showing how many eligible posts are published, awaiting review,
failed, or never attempted, against every post type Settings has marked
translatable. **Generate remaining** queues everything not started (or
previously failed) and works through it one post at a time — deliberately
sequential, never parallel, since two AI calls landing on the same post at
once is a race this plugin does not yet have a lock against (see the open
items below).

## Why post-duplication, not string-swapping

Each language is its own real WordPress post, linked to the one it came
from via a dedicated `wp_beaver_translate_links` table — the same model
Polylang and WPML use, and the reason this plugs cleanly into
[beaver-seo](../beaver-seo/README.md)'s per-post SEO fields later: a
translation just *is* a post, with its own meta box, its own SEO title, its
own everything. TranslatePress's alternative — one post, strings swapped in
at render time — is a fundamentally different architecture that a plugin
like this would have to be built around from the start, not bolted onto.

## Why nothing publishes itself

`Beaver_Translate_Translator::translate()` never sets a post's status to
`publish`. That line does not exist in the class. A draft is created (or
updated, on regenerate) with `post_status = draft` and a link row marked
`needs_review`; only `Beaver_Translate_Metabox::ajax_approve()` and
`Beaver_Translate_CLI::approve()` — both requiring `publish_posts` — ever
change that. The same shape as
[beaver-alt-text](../beaver-alt-text/README.md): AI proposes, a person
decides.

## How content survives the round trip

Post content is HTML, and on a block-editor site, HTML wrapped in Gutenberg
block comments (`<!-- wp:paragraph -->`). The system prompt sent for content
fields is explicit: preserve every tag, attribute and block comment exactly,
translate only the text between them. This is a prompt-level instruction,
not a guarantee — which is exactly why nothing publishes without a human
opening the draft in the normal block editor first, where a mangled block
shows up as visibly broken rather than shipping to a visitor.

## Taxonomies and custom fields

Every taxonomy term and custom field a source post has is copied onto its
translation — an accommodation's Level, a package's ACF fields, a gallery of
attachment IDs — so nothing a template relies on renders blank. That copy is
verbatim by default: correct for a dropdown value or an image reference,
wrong for genuine prose living in a custom field, like a safari package's
itinerary day descriptions or FAQ answers. Turning on **Settings → What gets
translated → Custom fields** additionally sends whichever fields actually
read as prose through translation — one extra request per post, covering
every candidate field at once, not one request per field. Fields it isn't
confident about (short single-word values, anything that looks like an ID,
a URL, or a reference) are left exactly as copied.

## How the URL routing works

WordPress's rewrite matching is one flat pass over a single list of
regexes — there is no "strip a prefix, then re-match what's left." The only
complete way to add a language segment in front of every permalink shape a
site has, including whatever a theme or another plugin has registered, is
`Beaver_Translate_Router::prefix_rules()` taking WordPress's own,
already-correctly-built rule list (on the `rewrite_rules_array` filter, the
last one WordPress applies before caching) and duplicating every entry with
`(fr|de|…)/` prepended — the same approach Polylang and WPML both use.
Reimplementing permalink-structure regex generation from scratch here would
mean re-solving a problem `WP_Rewrite` has already solved, worse.

A `/fr/` request that resolves to something which is not actually that
language's translation — the source post, or a translation into a different
language that happens to share a slug — 404s rather than silently rendering
the wrong content; the same check redirects a translation visited at its own
bare, unprefixed slug to its correct address, so it is only ever reachable
one way.

A language-prefixed archive, the posts-page front page, or a feed has no
single post for `verify_post_language()` to check — it's a list the query
itself builds, not a resolved singular object — so `Beaver_Translate_Router::
filter_query_by_language()` restricts the underlying query instead, on
`pre_get_posts`: a `/fr/` archive only ever lists posts actually translated
into French. Requesting the site's default language leaves every query
untouched, exactly as visiting the plain URLs always has.

## Taxonomy term names

A term — a category, a tag, `tour_category`, whatever custom taxonomy a
site has — stays exactly one row, one slug, one URL, unlike a post: nothing
about a term needs to differ per language except the one string a visitor
reads, and every post tagged with it should stay tagged with the same term
regardless of which language draft is looking at it. `Beaver_Translate_
Terms` writes a translated *display name* into termmeta per language, and
swaps it in on the one low-level `get_term` filter nearly every template
tag funnels through — `get_the_terms()`, `the_terms()`, archive titles, and
(on a site also running beaver-seo) its JSON-LD structured data all pick
this up from one place, with no rewrite rule and no new URL involved:

```sh
wp beaver-translate terms --language=fr
wp beaver-translate terms --language=fr --taxonomy=tour_category
```

Admin screens always show the real, canonical name — this only ever swaps
what a visitor reads on the front end.

## What "regenerate" does

Clicking Generate again on a language that already has an unpublished draft
updates that same post in place rather than creating a second one — the
link table keeps one row per (source post, language) pair, and reuses
whatever post is already attached to it.

Regenerating an **already-published** translation works differently, on
purpose: the new content is written to a separate, not-yet-visible post
(tracked as `pending_post_id`, distinct from the row's `translated_post_id`
— the live one) rather than into the live post itself. Writing straight
into the live post would mean setting it back to `draft` the instant
Regenerate is clicked, taking a published page offline for however long
review takes — a real bug an earlier version of this plugin actually had.
The live URL keeps serving its current content, unchanged, until a reviewer
approves the pending version — at which point its content is copied into the
live post (so the URL never changes) and the temporary pending post is
discarded. Rejecting a pending regeneration discards only that pending post;
the live translation is never touched by a rejection either.

## WP-CLI

```sh
wp beaver-translate generate 42 --language=fr
wp beaver-translate generate --all --language=fr --post-type=post
wp beaver-translate queue
wp beaver-translate approve --id=7
wp beaver-translate terms --language=fr
```

## Where the API key lives

In the database by default. Set `BEAVER_TRANSLATE_API_KEY` in
`wp-config.php` to keep it out of the database entirely — that constant
always wins, is never written back to the database, and is never rendered
into the settings form.

## Deactivating and uninstalling

Both flush the rewrite rules, so the language-prefixed URLs cleanly stop
resolving rather than leaving a stale, half-registered rule set behind.
Languages, settings, the link table and every draft or published translation
are otherwise untouched — reactivating brings the URLs straight back.

## What's not built yet

No automatic redirect by browser language — silently redirecting a visitor
who typed an English URL on purpose is its own kind of wrong, so this isn't
planned as this plugin's own initiative rather than an oversight.

## Before this touches a real site

Rewrite-rule work is exactly the kind of change that looks right on paper
and breaks on an actual permalink structure — there's no live WordPress
install here to verify against, only careful reading. Before this goes near
a client site: a staging install, a non-default permalink structure (date-
based, or a custom post type), and a full click-through of a post, a page,
an archive, and the front page, in and out of a language prefix.

## Requirements

WordPress 5.8+, PHP 7.4+, and an API key for whichever provider you choose.

---

Digital Beaver · GPL-2.0-or-later
