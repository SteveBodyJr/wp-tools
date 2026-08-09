=== Beaver Alt Text ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: accessibility, alt text, seo, images, ai
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Writes alt text for images that have none, using a vision model. Reviewed before it is published, and never over the top of a person's work.

== Description ==

Most media libraries are full of images with no alt text. Screen reader users get nothing from them, and search engines get nothing either. Beaver Alt Text looks at each image that is missing alt text, writes a one-sentence description, and holds it for you to approve.

**Three rules the plugin will not break**

1. **Alt text written by a person is never overwritten.** The plugin records a fingerprint of what it wrote. If that fingerprint no longer matches, somebody has edited the text by hand and the image is left alone — at scan time and again at the moment of publication, in case a colleague edited it while the suggestion sat in the queue.
2. **Nothing is published until you approve it.** Suggestions land in a review screen where you can edit them first. What you approve is what gets written. Automatic publishing is available but off by default, and can be limited to high-confidence results.
3. **Decorative images get empty alt text, not invented text.** Spacers, dividers, and pure ornament are supposed to carry an empty alt attribute. Describing them makes the page worse for screen reader users, so the model is asked to identify them and the plugin writes an empty value on purpose.

**Built for shared hosting**

Long jobs are the hard part on cheap hosting, so the bulk runner shares one time budget across each request, checks it before starting another image rather than after, and bounds each API call by the time actually left. A request that dies anyway is attributed to the image that caused it, which is then skipped rather than retried forever.

**Accuracy over confidence**

Every suggestion carries a confidence level. The model is told to prefer a plain true description over a specific guess, and to mark anything down when it names a species, a place, or a person it cannot verify from the image alone. Low-confidence rows are flagged in the review screen with the reason attached.

**Features**

* Finds every image with missing or empty alt text.
* Bulk runs that resume after an interruption, plus per-image generation from the media library.
* A review screen with the image, the suggestion, its confidence, and an editable field.
* Alt text column in the media library showing generated, human-written, decorative, missing, or waiting-for-review.
* Site context setting, so the model knows what your business photographs.
* Page context: the post an image is attached to can be sent as a short excerpt to improve accuracy.
* Images are downscaled before sending — a description does not need a large image, and this keeps the cost down.
* Prompt caching on the system prompt, so a run of hundreds of images pays for the instructions once.
* WP-CLI: `wp beaver-alt generate`, `wp beaver-alt approve`, `wp beaver-alt status`.
* Works with Claude, OpenAI, OpenRouter, Groq, or any OpenAI-compatible gateway that accepts image input.

== Installation ==

1. Upload the `beaver-alt-text` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Add your API key. The best place is `wp-config.php`, so the key never sits in the database:
   `define( 'BEAVER_ALT_API_KEY', 'sk-ant-...' );`
   Otherwise use **Alt Text → Settings**. On a site that also runs Beaver AI Chat, that plugin's key is used automatically when no other key is set.
4. Fill in **About this site** under Settings. One sentence about what the business does noticeably improves the descriptions.
5. Go to **Alt Text → Dashboard** and press Start.

== Frequently Asked Questions ==

= Will it overwrite alt text I have already written? =

No. The plugin stores a fingerprint of the text it writes; if the current alt text does not match that fingerprint, it was written or edited by a person and is skipped. This is checked twice — when building the queue, and again immediately before publishing.

= Why are some images given empty alt text? =

Because they are decorative. A spacer or an ornamental flourish carries no information a reader would miss, and the accessible result is an empty alt attribute. Describing it out loud is noise for someone using a screen reader. These images are shown as "Decorative" in the media library.

= How much does a run cost? =

Very little. Images are downscaled before sending, which puts a typical image at a few hundred input tokens, and the description itself is one sentence. A library of a few hundred images generally costs less than a cup of coffee. Exact cost depends on your model and provider pricing.

= Can it run without me reviewing every image? =

Yes — enable publishing without review under Settings, and optionally restrict it to high-confidence suggestions. It is off by default because alt text is read aloud to people who cannot see the image, so a confidently wrong description is worse than none.

= Does it work on shared hosting? =

Yes. The bulk runner is built for hosts with short request limits: it processes what fits in the time available, resumes where it left off, and never retries an image that killed a request.

= What happens to the alt text if I uninstall the plugin? =

It stays. Approved alt text belongs to the site, not to this plugin. Uninstalling removes the plugin's own settings, counters and unreviewed suggestions only.

= Is my content sent to a third party? =

Yes — images, and optionally a short excerpt of the page they appear on, are sent to the model provider you configure. Turn off page context under Settings if that content is sensitive, and disclose the processing to clients as you would for any other external service.

== Screenshots ==

1. Dashboard with library coverage and a bulk run in progress.
2. Review screen with the image, the suggestion, and its confidence.
3. Alt text column in the media library.
4. Settings, including site context and review behaviour.

== Changelog ==

= 1.2.0 =
* Rate limits and server errors are retried with backoff instead of failing the image permanently, honouring the server's `retry-after` and never sleeping past the time the request has left.
* A run now shows what it will cost before it starts, and asks. Prices are yours to enter rather than baked into the plugin, because published rates change.
* Bulk approve on the review screen, with paging. Low-confidence suggestions are deliberately excluded — those are the ones worth your eyes.
* Fixed the library scan being slow on large media libraries: it read two meta values per image, one query each. The meta cache is now primed in chunks.
* The review count driving the menu bubble ran on every admin page load. It is now one cached COUNT.
* Alt text follows the site language. A screen reader announces alt text in the page's language, so an English description on a Swahili page is read with the wrong pronunciation.
* Added a run lock, so two people pressing Start at once cannot describe — and bill for — the same images twice.

= 1.1.0 =
* Added provider choice: Claude, OpenAI, OpenRouter, Groq, or any OpenAI-compatible endpoint.
* DeepSeek is listed but refused, with the reason shown: its API accepts text only, so it cannot be sent an image to describe. Selecting it blocks before any request is made rather than failing per image.
* A provider that ignores the structured-output request and wraps its JSON in prose or a code fence is now recovered rather than reported as a failure.
* The settings screen explains what each provider is good for and where to get a key.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Faster scans, retries on rate limits, cost estimate before a run, and bulk approve.

= 1.1.0 =
Adds OpenAI, OpenRouter, Groq and custom endpoints alongside Claude.

= 1.0.0 =
Initial release.
