# Beaver App Bar

Gives a site the bottom tab bar of a phone app. A row of icons fixed to the
bottom of the screen on mobile, so the things people came for are one tap away
instead of a scroll back to the header.

**Any theme, no external request, and nothing at all when it is off.**

---

## Install

1. Copy the `beaver-appbar` folder into `wp-content/plugins/` (or upload the zip
   under **Plugins → Add New → Upload**).
2. Activate it. Nothing changes on the front end yet — it ships switched off
   with a starter bar already filled in.
3. Go to **Appearance → App Bar**. The plugin's row on the Plugins screen has a
   shortcut to the same place.
4. Set the items, pick the accent colour, then tick **Show it on the site**.

## What can go in it

Up to five items. More than that and the labels start colliding on a 360px
phone, which is why five is the ceiling rather than a suggestion.

| Type | What the tab does |
|---|---|
| **A page, post or section** | Goes to a path (`/contact/`), a homepage section (`/#services`), an anchor on the page you are on (`#reviews`), or a full address. External addresses open in a new tab. |
| **Menu sheet** | Slides a panel up with the site's menu in it. |
| **Search sheet** | Slides a panel up with the site's search form in it. |
| **WhatsApp** | Opens a chat with the number saved in the settings. |
| **Phone call** | `tel:` link. |
| **Email** | `mailto:` link. |
| **Back to top** | Scrolls the page up. |

An item whose detail is missing — a WhatsApp tab with no number saved, a link
with nothing in it — is **skipped rather than shown broken**. One item can be
marked as the **main action**, which fills its icon with the accent colour.

The menu sheet uses the menu you choose. With none chosen it follows whatever
the theme has in its main menu position, and falls back to the site's pages, so
the sheet never opens on an empty panel.

## Settings

| Setting | Notes |
|---|---|
| **Show it on the site** | Off means off. See below. |
| **Show on** | Phones (≤600px), phones and tablets (≤1000px), or every device. 1000px is where most themes swap their burger for a full menu, which is where a bottom bar stops earning its space. |
| **Style** | Edge to edge frosted glass, or a floating rounded bar with a margin. |
| **Light or dark** | Follows the visitor's device, or pinned to one. Pin it if the site itself does not change with the device, or the bar will be the only thing on the page that does. |
| **Accent colour** | The active tab, the main action and the focus ring. |
| **Show the word under each icon** | Off gives an icons-only bar. The words stay available to screen readers. |
| **Hide while scrolling down** | Off by default: a real app's tab bar stays put. |

## Off means off

No stylesheet, no script and no markup are sent unless the bar is going to be
shown. A site with it switched off is byte for byte what it would be without the
plugin installed — not something hidden with CSS.

## It makes room for itself

The page gains exactly the space the bar occupies and gives it back above the
width the bar stops at, so nothing is ever hidden behind it and nothing is left
floating in space on a desktop. The height travels as one custom property that
drops to `0px` in the same breakpoint that hides the bar, so the two cannot fall
out of step.

Where the theme has a footer with a background of its own, the script moves that
space onto the footer, so a **dark footer runs all the way to the bottom edge**
behind the bar instead of ending in a strip of page background. Where it does
not, the body keeps the padding and the result is the same — the enhancement is
never load-bearing.

## Details that matter on a phone

* Clears the iPhone home indicator (`env( safe-area-inset-bottom )`).
* **Steps out of the way while a form field has focus**, so it never sits on top
  of the field being typed into behind the on-screen keyboard. This is the thing
  most bottom bars get wrong.
* On a one-page site, the lit tab follows the section on screen and hands back
  to the current page at the top.
* Every tab is a full-height touch target, well over the 44px minimum.
* The sheet is a real dialog: focus is trapped inside it, Escape closes it, and
  focus returns to the tab that opened it.
* `aria-current="page"` on the tab you are on, and hidden labels stay in the
  accessibility tree.
* Every animation is dropped under `prefers-reduced-motion`.

## Any theme

Nothing here reads a theme function, a page builder, or a post type. The bar is
drawn on `wp_footer` from its own markup and its own inline SVG icons, and takes
its colour from the accent setting rather than from anything the theme happens
to expose.

Every selector is prefixed and every property is set rather than inherited, so a
theme's own `ul`, `button` or `svg` rules cannot leak in and nothing here leaks
out. The one thing the plugin puts on the page outside its own markup is the
bottom spacing, written as `html body` so it wins on specificity whichever
stylesheet the site loads second.

## Hooks

| Hook | Purpose |
|---|---|
| `beaver_appbar_show` | Return false to hide the bar on particular requests — a template, a checkout, logged-out visitors. |

## Performance

* One autoloaded option, so no extra query.
* Nothing enqueued when the bar is off.
* No icon font, no CDN, no outbound request. Nothing about a visitor leaves the
  site.
* One passive scroll listener, batched into a frame, that only touches a class
  when the state actually changes.
* Every piece of the script exits immediately if the thing it manages is not on
  the page.

## Requirements

WordPress 5.8+, PHP 7.4+.

---

Digital Beaver · GPL-2.0-or-later
