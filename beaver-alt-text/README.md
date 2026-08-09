# Beaver Alt Text

Writes alt text for images that have none, using a vision model. Reviewed
before it is published, and never over the top of a person's work.

Most media libraries are full of images with no alt text. Screen reader users
get nothing from them, and search engines get nothing either.

---

## Install

1. Copy the `beaver-alt-text` folder into `wp-content/plugins/` (or upload the
   zip under **Plugins → Add New → Upload**).
2. Activate it.
3. Add an API key — best in `wp-config.php`, so it never sits in the database:

   ```php
   define( 'BEAVER_ALT_API_KEY', 'sk-ant-...' );
   ```

   Otherwise use **Alt Text → Settings**. On a site that also runs Beaver AI
   Chat, that plugin's key is used automatically when no other is set.
4. Fill in **About this site** — one sentence about what the business does
   noticeably improves the descriptions.
5. Go to **Alt Text → Dashboard** and press Start.

## Three rules the plugin will not break

1. **Alt text written by a person is never overwritten.** The plugin stores a
   fingerprint of what it wrote; if that no longer matches, someone edited it by
   hand and the image is skipped. Checked when building the queue, and again at
   the moment of publication in case a colleague edited it while the suggestion
   was waiting.
2. **Nothing is published until you approve it.** Suggestions land in a review
   screen where you can edit them first. Automatic publishing exists but is off
   by default, and can be limited to high-confidence results.
3. **Decorative images get empty alt text, not invented text.** Spacers,
   dividers and pure ornament are supposed to carry an empty alt attribute.
   Describing one makes the page worse for a screen reader user, so the model is
   asked to identify them and the plugin writes an empty value on purpose.

## Accuracy over confidence

Every suggestion carries a confidence level. The model is told to prefer a plain
true description over a specific guess, and to mark itself down whenever it
names a species, a place or a person it cannot verify from the image alone.
Low-confidence rows are flagged in the review screen with the reason attached —
those are the ones worth your eyes.

## Providers

| Provider | Notes |
|---|---|
| **Claude** (default) | Strongest at saying "I am not sure" rather than guessing, which is what you want when a wrong description gets read aloud. |
| **OpenAI** | Capable and cheap for captioning. |
| **OpenRouter** | One key, many models. Any vision-capable model slug. |
| **Groq** | Very fast and very cheap. Weaker at admitting uncertainty — keep review on. |
| **Custom** | Any OpenAI-compatible gateway that accepts `image_url` content. |
| **DeepSeek** | **Cannot be used.** It reads images in its own chat app, but its API accepts text only, so it cannot describe a picture it is never sent. Selecting it is refused before any request is made. |

## What a run costs

Images are downscaled before sending — a description does not need a large
image — and the reply is one sentence. At the default 768px, roughly:

| Images | Tokens | Cost |
|---|---|---|
| 245 | ~189K | ~$1.24 |

The dashboard shows an estimate and asks before it starts. Prices are yours to
enter under **Settings**, because published rates change and a stale number
printed as fact is worse than no number.

## Working through a big library

Bulk runs resume after an interruption. The runner shares one time budget across
each request, checks it before starting another image rather than after, and
bounds each API call by the time actually left. A request that dies anyway is
attributed to the image that caused it, which is then skipped rather than
retried forever.

Rate limits and server errors are retried with backoff, honouring the server's
`retry-after` and never sleeping past the time the request has left.

## Reviewing

**Alt Text → Review** shows the image, the suggestion, its confidence, the
model's stated reason for any doubt, and an editable field. What you approve is
what gets written.

**Approve everything at this confidence** handles the bulk. It stops at medium
on purpose — low-confidence rows should be read.

## Language

Alt text follows the site language unless you set one. A screen reader announces
alt text in the page's language, so an English description on a Swahili page is
read aloud with the wrong pronunciation.

## WP-CLI

```sh
wp beaver-alt generate --dry-run --limit=10
wp beaver-alt generate --limit=25 --apply
wp beaver-alt approve --min-confidence=medium
wp beaver-alt status
```

## Filters

| Filter | Purpose |
|---|---|
| `beaver_alt_providers` | Add or change the providers offered. |
| `beaver_alt_system_prompt` | Change the instructions sent with every image. |
| `beaver_alt_max_upload_bytes` | Largest image sent without resizing. Default 2MB. |
| `beaver_alt_time_budget` | Seconds a request may spend on generation. |

## Privacy

Images, and optionally a short excerpt of the page they appear on, are sent to
the provider you configure. Turn off page context under **Settings** if that
content is sensitive, and disclose the processing to clients as you would any
other external service.

Uninstalling removes the plugin's settings, counters and unreviewed suggestions.
Approved alt text stays — once approved it belongs to the site.

## Requirements

WordPress 5.8+, PHP 7.4+, outbound HTTPS, and an API key.

---

Digital Beaver · GPL-2.0-or-later
