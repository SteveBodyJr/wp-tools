=== Beaver App Bar ===
Contributors: digitalbeaver
Plugin URI: https://digitalbeavertz.com/
Author URI: https://digitalbeavertz.com/
Tags: mobile menu, bottom navigation, tab bar, app bar, sticky menu
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gives a site the bottom tab bar of a phone app. Works on any theme, adds no request, and shows nothing at all when it is off.

== Description ==

A row of icons fixed to the bottom of the screen on mobile, the way a phone app works. The things people came for are one tap away instead of a scroll back to the header.

**Up to five items**, each one of:

* a page, post or section of the homepage
* a menu sheet that slides up with the site's own menu in it
* a search sheet
* WhatsApp, a phone call, or an email
* back to top

One item can be marked as the main action and is filled with the accent colour, so there is never any doubt about the thing to tap.

**It works on any theme.** Nothing in it reads a theme function, a page builder or a post type. The bar is drawn on `wp_footer` from its own markup, its own inline SVG icons and its own stylesheet, and it takes its colour from one accent setting. Every selector is prefixed, so a theme's rules cannot leak in and nothing here leaks out.

**It costs nothing when it is off.** No stylesheet, no script and no markup are sent unless the bar is going to be shown, so the site is byte for byte what it would be without the plugin. There is no icon font, no CDN and no outbound request of any kind: the icons are inline SVG and the two asset files are the plugin's own.

**It makes room for itself.** The page gains exactly the space the bar occupies, and gives it back above the width the bar stops at, so nothing is ever hidden behind it. Where a theme has a footer with a background of its own, the space goes onto the footer, so a dark footer runs all the way to the bottom edge instead of ending in a strip of page background.

**Details that matter on a phone**

* It clears the iPhone home indicator, and every tab is a full-height touch target.
* It steps out of the way while a form field has focus, so it never sits on top of the field being typed into behind the on-screen keyboard.
* Optionally it slides away as the visitor reads down the page and returns when they scroll back up.
* On a one-page site the lit tab follows the section on screen.
* Light and dark, following the visitor's device or pinned to one.
* Labels can be hidden for an icons-only bar; they stay available to screen readers.
* The sheet is a real dialog: it traps focus, closes on Escape, and returns focus to the tab that opened it.
* Every animation is dropped for anyone who asks for reduced motion.

== Installation ==

1. Upload the `beaver-appbar` folder to `/wp-content/plugins/` and activate it.
2. Go to **Appearance → App Bar** — or click **App Bar** in the plugin's row on the Plugins screen.
3. Set the items, pick the accent colour, then tick **Show it on the site**.

The plugin arrives switched off with a starter bar already filled in, so activating it changes nothing until you have looked at the settings.

== Frequently Asked Questions ==

= Will it clash with my theme's own mobile menu? =

No. It is a separate piece of navigation with its own prefixed markup and styles, and it does not touch the header. If the theme already has a bottom bar of its own, use one or the other.

= Does it slow the site down? =

No. When the bar is off, nothing is enqueued and nothing is printed. When it is on, it is one small stylesheet, one small script and inline SVG icons, with no external request. The scroll handler is passive and batched into a frame, and it only changes a class when the state actually changes.

= Can I hide it on particular pages? =

Yes, in code: return false from the `beaver_appbar_show` filter for the requests you want it gone from.

= What does "a page, post or section" accept? =

A path such as `/contact/`, a homepage section such as `/#services`, an anchor on the current page such as `#reviews`, or a full web address. External addresses open in a new tab on their own.

= Where do the WhatsApp number and phone number come from? =

From this plugin's own settings, under "Details the items use". An item whose detail has not been filled in is skipped rather than shown as a link that goes nowhere.

= Does it work with a caching plugin? =

Yes. Everything is rendered server-side into the page, so a cached page contains a complete bar.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
