=== Beaver AI Chat ===
Contributors: digitalbeaver
Tags: ai, chat, chatbot, live chat, lead generation
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A customisable AI chat assistant that answers visitor questions from your own site content, captures leads, and hands off to your team.

== Description ==

Beaver AI Chat adds a polished chat assistant to any WordPress site. It reads
the content you already publish, so it answers with your real pages, products
and prices rather than generic filler.

Bring your own API key. Nothing is bundled, nothing is proxied through a third
party, and the key is stored on your server and never sent to the browser.

**Works with**

* Claude (Anthropic)
* ChatGPT (OpenAI)
* DeepSeek
* Gemini (Google)
* Groq
* OpenRouter
* Any OpenAI-compatible endpoint, including self-hosted models

**What you can change**

* Assistant name, avatar, greeting, suggested questions and small print
* Tone of voice, reply length, and rules such as no emojis or stay on topic
* Which post types and taxonomies it reads, and which custom fields hold prices
* Extra knowledge it should always have, and fully custom instructions
* Colours, corner style, message bubble shape, button size, side of the screen
* Light, dark, or follow the visitor's device
* Full screen or floating panel on mobile
* Which pages and which devices it appears on
* Rate limits, history depth, message length and request timeout

**Conversations**

Every chat is saved from the visitor's first message and updated on every reply,
so you can read a conversation while it is still happening. Active chats sort to
the top with a "Live now" marker. Each record keeps the transcript, whatever
contact details were given, and an optional AI-written summary. Prefer only
qualified leads? Switch it to save only once an email is given.

**Email alerts that say something**

The alert leads with what the visitor actually asked for, in one line, followed
by whatever contact details they gave and a link straight to the chat. Reply to
the email and you are replying to the visitor.

By default it waits for the chat to go quiet. A conversation keeps changing
while someone is typing, so an email sent the moment a lead appears describes a
chat that had barely started. Waiting a few minutes means one complete email
with a real summary instead of a stream of fragments. Each new message pushes
the alert back; it goes out once the visitor stops.

* Hear about everyone, or only visitors who left contact details
* Send straight away, once the chat settles, or as one roundup every few hours
  or once a day
* Ignore chats under a couple of messages, so "hi" never reaches your inbox
* Optionally include the whole transcript, and a signed expiring link that opens
  the conversation without a WordPress login
* Callback requests always jump the queue and send immediately
* Send yourself a test alert before you rely on any of it

**A queue, not a list**

Every conversation carries a status — new, working on it, done — with the person
who picked it up, so two people never ring the same visitor and nobody rings the
next one. Change it from the list without a page reload, filter by status, by
whether they left details, or by what is yours, and see how many are waiting on
a count beside the menu. A conversation you marked done that starts moving again
goes back to new, because a visitor who came back needs answering again.

**The questions your site does not answer**

The assistant says plainly when it is unsure rather than inventing an answer.
Every time it does, that question is recorded, grouped with the same question
asked differently, and counted. The result is a content plan written by real
demand: what people actually wanted and did not get. Type an answer on the
report and the assistant uses it from then on.

**It costs what it costs, and no more**

Every provider call is metered from the token counts the provider itself
reports, shown per day, per model and per conversation, and priced. Set a
monthly ceiling and the assistant stops at it and points visitors at your phone
and email instead, so a script hammering the chat overnight cannot run up a
bill. Tokens are exact; the money is an estimate you can override with your own
rates.

**Sends less to say more**

Your knowledge is sent with every single message, so on a site of any size it is
the largest part of the bill. Instead of shipping the whole site every time, the
question is matched against it and only the entries that answer it are sent in
full, with a short list of every title alongside so nothing is ever denied. On a
mid-sized site that is roughly 80% less knowledge per message, and a sharper
answer.

**Alerts where the team already is**

Slack, WhatsApp, Telegram, or a signed JSON webhook into a CRM, Zapier, Make or
your own endpoint — the same alert, cut to one line, alongside the email.

**When something goes wrong**

Visitors only ever see a friendly message, never a raw error. The Connection tab
shows your provider's own error and what to do about it, plus the model and
endpoint being called and whether outbound requests are allowed at all.

**Portable**

Export every setting except the API key and import it on the next site.

== Installation ==

1. Upload the `beaver-ai-chat` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to AI Chat, choose a provider, paste your API key and press Test.
4. Tick "Show the assistant on the site" and save.
5. On the Knowledge tab, tick the post types the assistant should read.

Moving from the older theme-based AI Trip Concierge? Tools -> Import carries the
key, provider and persona across in one click.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. The plugin ships without one so that every site uses, and pays for, its own
account. Keys are available directly from each provider.

= Can I keep the key out of the database? =

Yes. Add `define( 'BAC_API_KEY', 'your-key' );` to wp-config.php and it takes
priority over the settings field.

= Can visitors see my key? =

No. The browser only ever calls your own site, and your server makes the
provider request.

= Can I put the chat inside a page? =

Yes, use the shortcode `[beaver_ai_chat height="520"]`.

= Which data leaves my site? =

Only the conversation text and the knowledge digest, sent to the provider you
chose. Transcripts are stored in your own database.

= Why did my alert arrive a few minutes after the chat? =

Because it waited for the visitor to finish. A chat is still being written while
someone is typing, so an email sent immediately has no summary and often no
name. Leads -> When to send -> "Straight away" if you would rather have it the
moment it qualifies.

= Nothing arrives at all =

Press "Send a test alert" on the Leads tab. It reports what WordPress said, so
you can tell an alert problem from a mail problem. Most hosts need an SMTP
plugin before WordPress can send anything reliably.

= Who can open the "without logging in" link? =

Anyone who has it, until it expires. It is signed for one conversation with your
site's own keys, so it cannot be guessed or edited, and it shows nothing the
email did not already contain. Unticking the setting kills every link already
sent. It is off until you turn it on.

= How accurate is the spend figure? =

Tokens are exact: they come from the counts your provider reports on every call.
The money is an estimate from published rates, which change and which this
plugin cannot see your account's actual pricing for. Treat it as a close guide,
and enter your own rates on the Connection tab if you have a negotiated price or
a self-hosted endpoint.

= Will matching the question to my content make answers worse? =

It should make them better: the assistant gets the pages that answer the
question instead of everything you publish, so the answer is less diluted. A
short list of every title still goes with each message, and a question that
matches nothing at all falls back to sending everything, so it can never say you
do not offer something you do. Knowledge -> How much to send -> "Everything,
every time" restores the old behaviour.

= Why is the Answer gaps page empty? =

It is filled in by the same background call that writes conversation summaries.
Turn on "Use the AI to name each lead and write a short summary" on the Leads
tab, and give it a week of real conversations.

= Why does WhatsApp need a template? =

Because WhatsApp does not let a business start a conversation with free text.
Outside a 24 hour window opened by the person messaging you, only an approved
template is delivered, so the plugin sends one with the alert as its single
variable. Create a template with one body variable in your Meta account, wait
for approval, and put its name in the settings.

== Changelog ==

= 1.6.2 =
* Fixed: the assistant could refuse a visitor part way through a long
  conversation. The "messages per minute" limit counted into one running total
  whose expiry was pushed out by every message, so it never reset for anyone
  still talking: a steady one message every fifty seconds — well inside any
  sane limit — added up until it hit the ceiling and the chat stopped
  answering. Each minute now has a counter of its own, which is what per
  minute means.
* Fixed: the Endpoint URL and Reasoning labels pointed at controls that do not
  exist, so clicking them did nothing.

= 1.6.1 =
* Fixed: **Save settings did nothing.** The "Clear the usage history" button
  had its own form, and that form sat inside the settings form. HTML has no
  nested forms, so every browser closed the settings form at that point
  instead: the Assistant, Knowledge, Leads and Appearance tabs all fell
  outside it and were never submitted, and the Save settings button ended up
  belonging to no form at all, so clicking it did nothing whatsoever. Only the
  Connection tab could be saved, and only by pressing Enter in one of its
  fields. All 121 settings fields are now inside the form where they belong.
* Fixed: number fields rejected sensible values. Each one carried a step —
  max_tokens moved in 64s, the character budget in 500s — and to a browser a
  step is a validation rule, not a spinner increment, so 2000 tokens made the
  field invalid and one invalid field cancels the whole form. Any whole number
  in range is now accepted, and anything the browser does reject opens the tab
  holding it rather than failing silently on a panel you cannot see.

= 1.6.0 =
* New: conversations are a queue, not a list. Each one has a status — new,
  working on it, done — and an owner, changeable straight from the list, with
  counts beside the menu and filters for status, contact details, callbacks and
  what is yours. A conversation marked done that starts moving again reopens.
* New: an Answer gaps report. Questions the assistant could not answer are
  collected, grouped with the same question asked differently, and counted, so
  you can see what people wanted and did not get. Type the answer on the report
  and it is written into the assistant's team notes for next time.
* New: knowledge is matched to the question instead of being sent wholesale.
  Only the entries that answer what was asked are sent in full, plus a short
  list of every title so nothing is ever wrongly denied. Roughly 80% less
  knowledge per message on a mid-sized site, and a sharper answer. Knowledge ->
  How much to send -> "Everything, every time" for the old behaviour.
* New: spend tracking and a monthly ceiling. Every call is metered from the
  provider's own token counts and shown per day, per model and per conversation.
  Set a limit and the assistant stops at it and points visitors at your phone and
  email, so nothing can quietly run up a bill. The team is emailed once when it
  happens.
* New: alerts to Slack, WhatsApp, Telegram, and a signed JSON webhook for a CRM
  or an automation tool. They work alongside the email, and "Send a test alert"
  now exercises every one of them and reports which worked.
* Settings export and import now leave every credential behind, not just the API
  key, so a config file can be shared safely.

= 1.5.0 =
* Email alerts rewritten. The alert now leads with what the visitor asked for,
  quotes their opening question, lists whatever contact details they gave, and
  links straight to the conversation. Replying to it replies to the visitor.
* New: hear about every conversation, not only the ones that left contact
  details. That is how you find out what people ask when they never fill
  anything in, which is most of them.
* New: alerts wait for the chat to go quiet, so you get one complete email per
  conversation instead of one about a chat that had barely started. Existing
  sites move to this automatically with a five minute window; choose "Straight
  away" on the Leads tab for the old behaviour.
* New: a roundup option, hourly through to once a day at an hour you pick,
  grouped into visitors who left their details and visitors who only asked.
* New: ignore chats under a set number of messages, so nobody is emailed because
  someone typed "hi".
* New: optionally include the whole transcript, and an optional signed link that
  opens a conversation without a WordPress login, for people who read alerts on
  a phone and have no account here. Off by default, expires, and revocable.
* New: your own subject line, with tokens, or leave it blank and each subject
  describes what actually happened.
* New: "Send a test alert" on the Leads tab, and a line on every conversation
  saying whether the team has been told and when.
* A visitor pressing "Ask the team to contact me" always sends immediately,
  whatever the timing is set to.
* Fixed: scheduled background jobs were not fully cleared on deactivate or
  delete, because they carry the conversation ID as an argument.

= 1.4.1 =
* A timeout is no longer blamed on your firewall when it was not one. If the
  provider started replying and then took too long, the plugin now says so and
  points at the timeout, instead of sending you to your host for a problem they
  do not have.
* "Request timeout" moved to the Connection tab, next to the error that asks you
  to change it, and can now be raised to 180 seconds. New installs default to 60.

= 1.4.0 =
* On a phone the chat now takes the whole screen instead of floating in a panel
  with the page showing behind it. The chat button hides while it is open, the
  close arrow in the header is the way out, and the page behind no longer scrolls
  under the conversation.
* Respects notches and home indicators, so the header and the message box are
  never clipped.
* Tablets and desktops keep the floating panel. Set Appearance -> On mobile to
  "Floating panel" if you prefer the old behaviour on phones too.

= 1.3.2 =
* Fixed a serious one: browsers and password managers were autofilling the API
  key box, so saving any other setting could silently overwrite a working key
  with a stored password, and the chat would start failing for no visible reason.
* The key field is now disabled until you press "Change key", so it cannot be
  autofilled and cannot be submitted by accident. Anything a password manager
  drops in without a keystroke is discarded.
* When a key is saved you now see a confirmation and its length rather than an
  input that looks empty but might not be.

= 1.3.1 =
* The corner setting now shapes the chat window only. The round chat button stays
  a circle, and the little prompt bubble beside it stays rounded, whichever style
  you pick — a square chat button reads as a stray box rather than a control.
* Message bubbles keep their own setting and stay rounded by default.

= 1.3.0 =
* The corner setting is now two settings, because squaring the chat window and
  squaring the speech bubbles are different decisions. "Chat window corners"
  shapes the window, buttons, input and chat button; "Message bubbles" shapes the
  messages inside it and stays Rounded by default.
* So a square window no longer forces square speech bubbles. If you do want
  everything squared off, set Message bubbles to "Match the chat window".

= 1.2.1 =
* New: when the chat fails, the Connection tab now shows your provider's own
  error and what to do about it. Visitors still only ever see the friendly
  "I hit a brief snag" message, but you no longer have to read a debug log to
  find out it was a spent balance, a rejected key or a blocked firewall.
* New: a Status panel showing where the key comes from, the provider, model and
  endpoint being called, and whether the server is allowed to make outbound
  requests at all.
* The warning clears itself as soon as a message succeeds.

= 1.2.0 =
* New: one-click import from the older theme-based "AI Trip Concierge". Under
  Tools it shows what it found, then carries the API key, provider and persona
  across so nothing has to be retyped. Your old settings are left untouched.
* New: a warning appears in the admin when the old concierge is still switched
  on in the theme alongside this plugin, which is what makes two chat windows
  show at once, with the one line to comment out to fix it.
* Fixed: a second copy of the plugin anywhere on the site (a duplicate folder, a
  must-use copy, a staging leftover) took the whole site down with a fatal
  "Cannot redeclare" error. It now stands down quietly instead.

= 1.1.0 =
* Conversations are now saved from the visitor's first message, so you can read
  a chat while it is still happening instead of waiting for it to end. The old
  behaviour is still available under Leads as "Only once the visitor gives an
  email address".
* The conversation screen refreshes itself while a chat is live, and the list
  shows a "Live now" marker with active chats sorted to the top.
* The team alert now waits until real contact details appear, so nobody gets an
  email every time a visitor types "hi".
* New Corners setting: Pill, Rounded, Soft or Square. Square applies everywhere,
  including the chat button and message bubbles.
* Refined the whole widget: layered shadows, a finer header, better typography,
  calmer motion and a richer dark mode.
* Performance: the script is deferred and the stylesheet no longer blocks the
  first paint, a small inline shell prevents any layout shift, classes load only
  when used, and settings are read once per request.

= 1.0.1 =
* Fixed: the chat button did nothing when clicked. WordPress prints footer
  scripts before the widget markup, so the script found no widget to attach to.
  It now waits for the page to finish loading, and the markup is printed first.
* Fixed: the "contact me" button appeared even when lead capture was switched
  off, where it could never succeed.
* Accessibility: the closed panel is no longer reachable by keyboard, the
  launcher reports its open state, focus returns to it on close, and only the
  message log is announced rather than the whole widget.

= 1.0.0 =
* First release.
