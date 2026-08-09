# Beaver AI Chat

A drop-in AI chat assistant for any WordPress site. It answers visitor questions
using your own published content, captures leads, and hands off to your team.

Nothing in this plugin is tied to a particular business, theme or post type, and
**no API key ships with it** — every site supplies its own.

---

## Install

1. Copy the `beaver-ai-chat` folder into `wp-content/plugins/`
   (or zip that folder and upload it under **Plugins → Add New → Upload**).
2. Activate it.
3. Go to **AI Chat → Settings**.

## Set it up in five minutes

**Connection tab**

1. Pick a provider and paste that provider's API key.
2. Leave **Model** blank to use the recommended default, or type your own.
3. Click **Send a test message**. You should see `✓ Connected`.
4. Tick **Show the assistant on the site** and save.

**Assistant tab** — name it, set the greeting and the suggested questions, and
describe your business in a sentence or two. That description does most of the
work in making the assistant sound like you.

**Knowledge tab** — tick the post types it should read (Pages, Products,
Services, whatever your site uses). If your items store a price in a custom
field, add that field name under **Price fields**.

**Leads tab** — set the email address that should be alerted, plus your phone,
WhatsApp and any links the assistant may share.

**Appearance tab** — set your colours, which side it sits on, and which pages it
appears on. **Chat window corners** shapes the chat window only — the window, its
buttons and the message box (choose *Square* for a completely un-rounded window).
The round chat button always stays a circle, and the small prompt bubble beside it
stays rounded. **Message bubbles** shapes the messages inside the window and stays
rounded by default; set it to *Match the chat window* if you want those squared
off too.

## Keeping the key out of the database

Add this to `wp-config.php` and the settings field is bypassed entirely:

```php
define( 'BAC_API_KEY', 'your-key-here' );
```

The key is then never written to the database and never appears in a settings
export. Useful when the site is in version control or shared with a client.

## Coming from the older theme "AI Trip Concierge"

Some sites run an earlier chat that lived inside the theme (`inc/ai-concierge.php`)
and stored its settings in the `att_concierge_settings` option.

**Tools tab → Import from the older AI Trip Concierge.** It shows you what it
found — provider, whether a key is present, the assistant's name — then carries
the API key, provider, model, persona, greeting and accent colour across in one
click. Your old settings are never deleted, so you can go back.

After importing, open the **Knowledge** tab and tick the post types the
assistant should read (your tours, services or products). Then remove the old
chat, or both will show at once:

```php
// wp-content/themes/your-child-theme/functions.php
// require get_stylesheet_directory() . '/inc/ai-concierge.php';
```

The plugin warns you in the admin if it detects both running together.

## Moving the setup to another site — credentials stay behind

**Tools tab → Download settings file.** The export contains every setting
*except* the credentials — the API key, the Slack webhook, the Telegram and
WhatsApp tokens, the outgoing webhook URL and its signing secret. Import it on
the next site, fill in that site's own keys, and you are done. Importing can
never overwrite a credential either, so pasting a colleague's config file cannot
silently repoint your alerts at their Slack.

## Providers

| Provider | Default model | Where to get a key |
|---|---|---|
| Claude (Anthropic) | `claude-opus-5` | console.anthropic.com |
| ChatGPT (OpenAI) | `gpt-4o-mini` | platform.openai.com/api-keys |
| DeepSeek | `deepseek-chat` | platform.deepseek.com |
| Gemini (Google) | `gemini-2.0-flash` | aistudio.google.com/apikey |
| Groq | `llama-3.3-70b-versatile` | console.groq.com/keys |
| OpenRouter | `openai/gpt-4o-mini` | openrouter.ai/keys |
| Custom | whatever you enter | your own endpoint |

**Custom** works with any endpoint that speaks the OpenAI `chat/completions`
format, including self-hosted models.

### A note on Claude settings

Current Claude models reject `temperature`, so that field is hidden and ignored
for Claude — steer the voice with the Tone and Extra instructions fields instead.

**Reasoning** defaults to *Off*, which is right for a chat widget: replies come
back faster and cost less. Reasoning shares the same token budget as the reply,
so if you switch it to *Adaptive* the plugin raises the ceiling automatically to
stop answers truncating. If a model rejects the setting, choose *Provider
default*.

## Where the chat appears

On a phone the chat opens **full screen** — the whole display, no page showing
behind it, and the chat button hides while it is open. Tablets and desktops keep
the floating panel. Change this under **Appearance → On mobile**.

By default it floats in the corner of every page. You can restrict it to
specific pages, exclude pages, limit it to desktop or mobile, or show it only to
logged-in users.

To place it inline in a page instead of floating:

```
[beaver_ai_chat height="520"]
```

When the shortcode is used on a page, the floating button is suppressed there.

## How it works

```
Browser ──POST──▶ /wp-json/beaver-ai-chat/v1/chat ──▶ AI provider
                  (your server, holds the key)
```

The browser only ever talks to your own site. The API key is never enqueued and
never appears in page source.

Each request is checked against a daily rotating widget token and a per-IP rate
limit, and the endpoint always answers `200` with a friendly message so a
visitor never sees a raw error.

## Conversations

Every chat is saved under **AI Chat → Conversations** from the visitor's *first
message*, and updated on every reply — so you can read a conversation while it is
still happening. Active chats sort to the top with a **Live now** marker, and the
conversation screen refreshes itself as new messages arrive.

Each record holds the full transcript, whatever contact details the visitor gave,
and (optionally) an AI-written name and summary generated in the background so
the visitor never waits for it.

If you only want qualified leads in the list, set **Leads → What to save** to
*Only once the visitor gives an email address*.

## Working the queue

A conversation is not just a record, it is a job somebody has to do. Each one
carries a **status** — *new*, *working on it*, *done* — and, once someone takes
it, an **owner**. Change it from the list itself (it saves without a reload, so
you keep your place), or in bulk. Above the list: counts per status, plus a
filter for *left contact details*, *no contact details*, *asked for a callback*
and *with me*. The number waiting sits beside **AI Chat** in the menu.

A conversation you marked *done* that starts moving again goes **back to new** —
a visitor who came back needs answering again. It deliberately keeps its owner:
pushing something back to new by hand means "somebody else take this", a visitor
returning does not, and the person who dealt with them last is the right person
to see it.

Hook `bac_lead_status` to push changes into a CRM, `bac_lead_reopened` to react
when a closed conversation comes back to life.

## Answer gaps

**AI Chat → Answer gaps** lists what visitors asked that the assistant could not
answer. Each entry is grouped with the same question asked differently (matched
on its significant words, so *"do you have wifi"* and *"is there wifi in the
rooms"* are one row), counted, dated, and linked back to the conversations it
came from.

Type an answer on the row and press **Teach it this**: the question and answer
are appended to the assistant's team notes — the block it treats as
authoritative — so the gap closes for the next person who asks. Or dismiss a
question that is not worth answering.

The report is filled in by the same background extraction that writes each
conversation's summary, so it costs nothing extra — and it needs **Leads → Use
the AI to name each lead and write a short summary** switched on.

## Knowledge: matched, not dumped

Knowledge is sent with **every single message**, so on a site of any size it is
the largest line on the bill and it grows as you publish.

The site is indexed once and cached. For each message the visitor's words are
scored against that index — a hit in a title counts for far more than one in the
body — and only the top entries are written out in full. A one-line **catalogue
of every remaining title** goes along too, so the assistant always knows the full
range of what exists and can offer to fetch details rather than denying
something you offer.

| Setting | What it does |
|---|---|
| **How much to send → Only what answers the question** | Default. Match and send the top entries plus the catalogue. |
| **How much to send → Everything, every time** | The pre-1.6 behaviour: the whole digest, clipped to the budget. |
| **Send at most N items in full** | The ceiling on expanded entries (default 12). The character budget still applies on top. |

Two deliberate fallbacks keep it safe: a question that scores **nothing** (a
bare "hi", or a language the index is not written in) falls back to the whole
digest, and the character budget is still the hard cap. Measured on a real
37-page site, a targeted question sends **81% less** knowledge than the old
behaviour.

Filters: `bac_knowledge_index` (adjust the indexed site), `bac_knowledge_digest`
(the finished text), `bac_stopwords` (words ignored when matching).

## What it costs

Every provider call is metered from the token counts the provider itself
reports — Anthropic's `usage`, OpenAI's `prompt_tokens`, Gemini's
`usageMetadata`, including reasoning tokens and cached reads. Nothing is
recorded when a provider reports nothing, so the totals never contain invented
zeroes.

**Connection → What it is costing** shows this month's spend, calls and tokens
over 14 days, a bar per day, and a per-model breakdown. Each conversation shows
its own running cost on its edit screen.

**Stop at $N a month** is the safety net. When the ceiling is reached the
assistant stops calling the provider entirely and tells visitors to contact the
team directly — never mentioning limits or money to them — and the team is
emailed once. It resets on the first of the month.

Tokens are exact. **Money is an estimate**: rates come from a built-in table
that a filter (`bac_model_prices`) or the per-site **Your own rates** fields can
override, which is what you want for a negotiated price, a self-hosted endpoint,
or a model this plugin has never heard of.

## Alerts beyond email

Configured under **Leads → Where else to send alerts**, each optional, each
working *alongside* the email rather than instead of it. All four get a one-line
version of the alert: who, what they want, and a link.

| Channel | What it needs |
|---|---|
| **Slack** | An incoming webhook URL |
| **WhatsApp** | Meta Cloud API: recipient numbers, phone number ID, access token, and an approved template name |
| **Telegram** | A bot token and a chat ID (group IDs start with a minus) |
| **Anywhere else** | A URL, and optionally a signing secret |

**WhatsApp needs a template** because WhatsApp does not let a business open a
conversation with free text: outside a 24-hour window opened by the recipient,
only an approved template is delivered. The plugin sends one with the alert as
its single body variable (flattened, since template variables cannot contain
line breaks). Plain text is offered for teams who keep a window open.

The generic webhook sends the whole record as JSON, and with a secret set signs
the body as `X-BAC-Signature: sha256=…` so the receiver can prove it came from
your site. Filter the body with `bac_webhook_payload`.

Inside a visitor's request these are fired without waiting for a reply, so they
never sit between someone and their answer; in cron and in the admin they wait,
so failures can be reported. **Send a test alert** exercises every channel and
tells you which ones worked.

## Email alerts

The alert answers the only question the reader has — *what does this person
want?* — before anything else: the summary in a callout at the top, the
visitor's own opening question quoted underneath, then contact details, message
count, the page they were on, and a button through to the conversation. The
visitor's address becomes the **Reply-To**, so replying in the inbox answers the
person rather than the website.

### When it sends

A conversation is written on the first message and keeps changing for as long as
the visitor keeps typing, so *when* matters more than the template.

| Setting | Behaviour |
|---|---|
| **Once the chat goes quiet** (default) | Each message pushes the alert back. It fires after N quiet minutes, filling in the AI summary first if it is still missing. One complete email per conversation. |
| **Straight away** | Sent during the turn that qualifies, with whatever is known at that moment. No model call, so the visitor's reply is never delayed. |
| **Roundup** | Nothing per conversation. One email every 1/4/12 hours, or daily at an hour you choose, grouped into *left their details* and *questions only*. Chats still in progress are held over to the next one. |

A visitor pressing **Ask the team to contact me** ignores all of that and sends
immediately, even if the conversation was already reported once.

### What it reports on

**Tell me about** decides whether an alert needs contact details (default) or
covers every real conversation. The second is how you learn what people ask when
they never leave their details, which is most of them. **Ignore chats under N
visitor messages** keeps drive-by "hi" out of the inbox without keeping it out
of the Conversations list.

Every conversation carries a **Team alert** line on its edit screen: emailed and
when, waiting for the chat to settle, held for the next roundup, not qualifying
yet, or the mailer failed.

### Links without a login

**Links in the email → Add a link that opens the conversation without a
WordPress login** adds a signed, expiring URL for people who action leads on a
phone and have no account here. The signature is `wp_hash()` over the
conversation ID and expiry, so it is bound to both and to this site's salts.
It exposes nothing the email did not already carry. Off by default; unticking it
revokes every link already sent.

### Nothing arrives

**Leads → Send a test alert** mails the most recent conversation, laid out as a
real alert, and reports back what WordPress said. It separates an alert problem
from a mail problem — the usual answer being that the site needs an SMTP plugin.

Timing depends on WP-Cron, so a site with no traffic runs late. A real system
cron calling `wp-cron.php` fixes that.

## Performance

The widget is built not to cost you page speed:

- The script is **deferred**, so it never blocks parsing.
- The stylesheet is fetched **without blocking the first paint**, with a
  `<noscript>` fallback.
- A ~500 byte inline shell paints the button correctly in the meantime, so
  nothing shifts when the full stylesheet lands.
- Classes are **autoloaded**, so a normal page view never reads the provider,
  knowledge builder or admin code from disk.
- Settings are read **once per request**, and the knowledge digest is cached.
- Nothing is enqueued at all on pages where the chat is not shown.

If your optimisation plugin already handles async assets, switch this off with
`add_filter( 'bac_async_assets', '__return_false' );`.

## When the chat stops answering

Visitors only ever see a friendly *"I hit a brief snag"* message — never a raw
error. To find out what actually happened, open **AI Chat → Connection**. The
**Status** panel shows your provider's own error, in its own words, along with
what to do about it, and clears itself as soon as a message succeeds.

It also reports the things you would otherwise have to dig for: where the key
comes from, the exact model and endpoint being called, and whether the server is
allowed to make outbound requests at all.

**Send a test message** sends a deliberately tiny prompt. It is the fastest way
to tell a *connection* problem from a *size* problem: if the test succeeds but
real chats fail, the connection is fine and the request is simply taking too
long.

| What the Status panel says | What it means |
|---|---|
| *Insufficient Balance*, *exceeded your quota* | The account behind the key is out of credit. Top it up and the chat resumes on its own. |
| *Invalid API key*, *unauthorized* | The key was rejected, or belongs to a different provider than the one selected. |
| *model does not exist* | Clear the **Model** field to fall back to the recommended default. |
| Timed out, **bytes received** | The provider began replying and was too slow. Raise **Request timeout** to 90s+, or shorten replies / the knowledge budget. Not a firewall. |
| Timed out, **0 bytes** · *could not resolve* · *refused* | Your server genuinely could not reach the provider. Ask your host about outbound requests. |

A timeout that received *some* bytes is not a blocked connection — the request
got through and the provider started answering. Only a timeout with nothing
received, or a DNS/refused error, points at a firewall.

## Your API key cannot be changed by accident

Once a key is saved, the field is **disabled** until you press **Change key**.
That is deliberate: browsers and password managers autofill password inputs
whatever `autocomplete` says, and a disabled field is neither autofilled nor
submitted. Without it, saving any unrelated setting could silently overwrite a
working key with a stored password.

Revealing the field clears it, and anything that appears without a keystroke is
discarded. Leaving it blank always keeps the current key.

## Hooks

| Hook | Type | Use it to |
|---|---|---|
| `bac_settings` | filter | Adjust settings at runtime |
| `bac_sanitize_settings` | filter | Adjust settings on save |
| `bac_system_prompt` | filter | Rewrite the finished system prompt |
| `bac_knowledge_parts` | filter | Add or remove knowledge chunks |
| `bac_knowledge_index` | filter | Change what the site index contains |
| `bac_knowledge_digest` | filter | Change the finished digest for one message |
| `bac_stopwords` | filter | Words ignored when matching a question |
| `bac_model_prices` | filter | Your own price table for spend estimates |
| `bac_webhook_payload` | filter | Change the JSON sent to the outgoing webhook |
| `bac_request_body` | filter | Change the body sent to the provider |
| `bac_widget_config` | filter | Change what the browser receives (no secrets) |
| `bac_should_show` | filter | Decide per-request whether the chat renders |
| `bac_currency_symbol` | filter | Set the currency used in the knowledge digest |
| `bac_lead_email` | filter | Change or suppress the notification email |
| `bac_digest_email` | filter | Change or suppress the roundup |
| `bac_should_notify` | filter | Decide per conversation whether it is worth an email |
| `bac_async_assets` | filter | Turn off non-blocking asset loading |
| `bac_import_map` | filter | Carry extra fields across when importing an older chat |
| `bac_lead_created` | action | Push a new lead into your CRM |
| `bac_contact_requested` | action | React when a visitor asks for a callback |
| `bac_lead_status` | action | React when a conversation changes status |
| `bac_lead_reopened` | action | React when a closed conversation comes back |
| `bac_channels_sent` | action | React after Slack / WhatsApp / Telegram / webhook |

`bac_lead_email` receives a fourth argument since 1.5.0 — `lead`, `handoff` or
`test` — so a CRM can treat a callback request differently from a routine alert.

Example — only email about visitors who mentioned a budget:

```php
add_filter( 'bac_should_notify', function ( $ok, $lead_id ) {
    $post = get_post( $lead_id );
    return $ok && (bool) preg_match( '/budget|\$|price/i', $post->post_content );
}, 10, 2 );
```

Example — file every callback request into an existing enquiry system:

```php
add_action( 'bac_contact_requested', function ( $lead_id ) {
    my_crm_create_enquiry( array(
        'name'    => get_the_title( $lead_id ),
        'email'   => get_post_meta( $lead_id, '_bac_email', true ),
        'phone'   => get_post_meta( $lead_id, '_bac_phone', true ),
        'summary' => get_post_meta( $lead_id, '_bac_summary', true ),
    ) );
} );
```

## Privacy

Conversation text is sent from your server to the provider you selected, so that
provider's terms apply to it. Nothing is sent anywhere else. Transcripts stay in
your own database.

Alert emails carry the summary, the contact details the visitor gave, and — if
you switch it on — the transcript, so they travel wherever your mail does. The
optional no-login link is readable by anyone holding it until it expires. Both
are off or minimal by default; decide deliberately before turning them on for a
site handling anything sensitive.

Deleting the plugin removes its settings and all stored leads. To keep them, add
`define( 'BAC_KEEP_DATA_ON_UNINSTALL', true );` to `wp-config.php` first.

## Requirements

WordPress 5.8+, PHP 7.4+, and outbound HTTPS from your server.

---

Digital Beaver · GPL-2.0-or-later
