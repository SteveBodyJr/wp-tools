# Beaver Press tests

Command-line checks used while building Beaver Press. They run against a real WordPress with
TranslatePress and Beaver Press active. **Never run them on a live client site**: they switch
settings for a moment (and restore them), and some ask the engine for a few words through a
fake provider. They never print or send the API key, and never reset real translations.

```bash
# all tests (PHP ones load ../../../../wp-load.php; HTTP ones use BP_SITE)
PHP_BIN=/opt/lampp/bin/php BP_SITE=http://localhost/raya-safaris-wp ./run-all.sh

# one test
/opt/lampp/bin/php test-glossary-prompt.php
```

Every PHP test starts with `require __DIR__ . '/bootstrap.php';`: it loads WordPress, keeps a copy of
the settings and API keys the tests touch, and puts it back however the test ends. If a test is
killed outright, the next test restores the copy first and prints a NOTE. New tests must use it.

Environment: `BP_WP_LOAD` (path to wp-load.php), `BP_HOST`, `BP_SITE` (site URL for the HTTP
tests), `BP_TEST_SLUG` (a translated language's URL slug, default `fr`), `PHP_BIN`.

| Test | Covers |
|---|---|
| test-keys-providers.php | encrypted keys, provider requests, errors never showing keys |
| test-engine-settings.php | engine panel, sanitising, models, test connection |
| test-batches.php | batches, checks, placeholders, DeepL, Claude schema, give-up list |
| test-visitor-guard.php | only editors, cron/CLI and the signed run start paid translations |
| test-glossary-prompt.php | names kept, prompt wording |
| test-usage-estimate.php | usage log, estimate, daily limit |
| test-structured-data.php | JSON-LD translated, prices/dates/URLs unchanged |
| test-translator-role.php | Translator role and capability |
| test-instructions.php | instructions, suggestions per language, names to keep |
| test-export-import.php | settings file: no keys, {site}, round trip |
| test-names-ai.php | names card, AI rule, suggestions, new names |
| test-alerts.php | provider alerts: notice, one email per problem |
| test-budget.php | spending limit per day and month |
| test-missing-texts.php | which texts a page misses, recurring ones |
| test-history.php | settings history and restore |
| test-backup.php | translation backup, export, import rules, dry run, restore |
| test-provenance.php | provider/model/status, changed originals kept as outdated |
| test-debug-http.php | debug panel: administrators only, no keys, problems found |
| test-prefixes-http.sh | translated address prefixes, 301s, canonical |
| test-forms-email.php | visitor confirmation email per language (captured, never sent) |
| test-cache-http.sh | ready pages: bypass rules, identical output (needs a complete /fr/kilimanjaro/) |
| test-addresses-http.sh | translated addresses (Raya pages; adjust paths for another site) |

The HTTP tests use Raya Safaris pages; on another site change the paths at the top.

## Audits on a disposable copy (V1, V4)

Never on a client's live site, and not on the working site either: make a copy (files and
database, own URL), mark it with the option `beaver_press_disposable_site = yes` (the scripts
refuse to run otherwise), and block mail and provider requests there (a must-use plugin returning
early from `pre_wp_mail` and `pre_http_request` for provider hosts). The scripts change the copy
for a moment and put it back.

```bash
# SEO and address audit: status, canonical, hreflang (self, two-way, x-default), html lang,
# translated title and description, links staying in the language, translated slugs and 301s,
# 404s, language sitemaps, mobile, logged-out. --all for every page, --mutate adds a permalink
# change, the default theme, an Elementor page and a Gutenberg page (each put back).
BP_WP_LOAD=/copy/wp-load.php BP_SITE=http://localhost/copy php audit/bp-seo-audit.php --mutate --report=/tmp/audit.md

# Next to Yoast SEO, Rank Math and SEOPress (installed, inactive): one at a time, checks every
# head element for duplicates and the right owner, and that the language sitemaps are reachable.
BP_WP_LOAD=/copy/wp-load.php BP_SITE=http://localhost/copy BP_WP_CLI="php /path/wp-cli.phar" php audit/bp-seo-plugins.php --report=/tmp/seo-plugins.md
```

Both print each failed check with its URL and write a Markdown report.
