=== Nano Unlock ===
Contributors: nano133
Tags: paywall, micropayments, nano, xno, pay per post
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell part of a post for a few cents in Nano (XNO). Readers pay your address directly; your site checks the payment with a Nano node.

== Description ==

Wrap the paid part of a post in the "Nano Unlock (paid part)" block, or in `[nano_unlock price="0.05"] … [/nano_unlock]`. Readers see the price and an Unlock button, pay by QR code or "Open in wallet", and the part appears about two seconds after the payment confirms.

* The money goes straight to your Nano address. The plugin never holds a key or funds.
* No account, no card, no fee: a 1¢ sale pays you 1¢.
* The paid part is rendered on the server only after payment. It is never hidden with CSS, and it is stored apart from the post, so feeds, search, revisions and a deactivated plugin never show it.
* Your site verifies each payment itself with a Nano node (the default is node.nano133.com; any node works, and a second node can be required to agree).
* One payment unlocks one item, once. The buyer keeps access on that browser through a signed cookie.

== Frequently Asked Questions ==

= What happens if I deactivate the plugin? =

The paid parts stay hidden. They are stored apart from the post's content, so WordPress shows only the shortcode's tag (and nothing for the block). Turn the plugin on again and everything works as before, including buyers' access.

= What happens if I delete the plugin? =

Its data is kept (paid parts, sales, settings) unless you tick "Also delete the paid parts" on the settings page first.

= Does it work with page caching? =

Yes, with a page-cache plugin that respects DONOTCACHEPAGE (most do). A paid part is shown only on its post's own page, and that page sends no-cache headers and sets DONOTCACHEPAGE. The home page, archives, search, feeds and other posts' pages show a link to the post instead of the paid part, so they can be cached.

A cache that ignores these signals (a CDN or a reverse proxy set to cache every page) could serve one buyer's unlocked page to other readers. Exclude the posts that have a paid part from such a cache, or bypass it for requests that carry the nano_unlock_receipts cookie.

== Changelog ==

= 0.1.0 =
* First version: settings, shortcode, block, checkout, verification on the site, receipts.
