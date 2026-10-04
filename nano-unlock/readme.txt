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

= Credits =

The checkout's QR code is drawn by QR Code Generator for JavaScript (qrcode-generator 2.0.4, assets/vendor/qrcode.js), Copyright (c) 2009 Kazuhiko Arase, under the MIT license: https://github.com/kazuhikoarase/qrcode-generator. "QR Code" is a registered trademark of DENSO WAVE INCORPORATED.

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin, search for "Nano Unlock", click Install Now, then Activate. Or upload the plugin's .zip with Upload Plugin on the same page.
2. Go to Settings > Nano Unlock.
3. Paste your Nano address from your wallet (any Nano wallet). Readers pay this address directly. Never paste a seed or a private key: the plugin needs only the public address.
4. Set a default price in US dollars (5 cents to start). Readers pay the same value in XNO at the current rate.
5. Keep the default node, or set your own Nano node's RPC URL. Optional: set a second node, so that a payment counts only when both nodes confirm it.
6. In a post, add the "Nano Unlock (paid part)" block and put the paid content inside it, or wrap it in `[nano_unlock price="0.05"] … [/nano_unlock]`.

To check it before you sell, open the post in a private window, unlock it with a small payment from your own wallet, and find the sale under "Recent sales" on the settings page.

== Frequently Asked Questions ==

= What if a reader sends a rounded amount? =

Each checkout asks for a unique amount: the price plus a tiny tail in its last digits, which is how the payment is matched. The QR code and "Open in wallet" fill it in, and the full amount has a Copy button. A rounded or retyped amount does not match and unlocks nothing; you see the payment in your wallet and can refund it.

= What happens if I deactivate the plugin? =

The paid parts stay hidden. They are stored apart from the post's content, so WordPress shows only the shortcode's tag (and nothing for the block). Turn the plugin on again and everything works as before, including buyers' access.

= What happens if I delete the plugin? =

Its data is kept (paid parts, sales, settings) unless you tick "Also delete the paid parts" on the settings page first.

= Does it work with page caching? =

Yes, with a page-cache plugin that respects DONOTCACHEPAGE (most do). A paid part is shown only on its post's own page, and that page sends no-cache headers and sets DONOTCACHEPAGE. The home page, archives, search, feeds and other posts' pages show a link to the post instead of the paid part, so they can be cached.

A cache that ignores these signals (a CDN or a reverse proxy set to cache every page) could serve one buyer's unlocked page to other readers. Exclude the posts that have a paid part from such a cache, or bypass it for requests that carry the nano_unlock_receipts cookie.

== Screenshots ==

1. A post with a paid part, as a reader sees it: the free part, the price and the Unlock button.
2. The checkout: a QR code, "Open in wallet", the exact amount and the address, each with a Copy button.
3. About a second after the payment confirms, the paid part appears on the reader's page.
4. The "Nano Unlock (paid part)" block in the editor, with its price in the block settings.
5. The settings page: your Nano address, the default price, the node, and the recent sales.

== External services ==

This plugin connects to the services below from your site's server. Readers' browsers never contact them through the plugin, and the plugin sends no reader data to them (no IP address, no cookie, no name).

= A Nano node (node.nano133.com by default) =

Needed to check payments: the site asks a Nano node whether your address received a confirmed payment of a checkout's exact amount. The default node is run by nano133.com; you can set any other Nano node, and an optional second node, on the settings page. The plugin then calls the node(s) you set instead.

* When: while a reader's checkout waits for a payment (the reader's page asks your site every 2 seconds; your site asks the node at most every 3 seconds), and once more for each payment it finds, to confirm it (on both nodes when a second node is set). Nothing is sent when no checkout is open.
* What is sent: your public Nano address and the smallest open checkout amount (the `receivable` and `account_history` calls), and a payment's block hash (the `block_info` call). The request's user agent names the plugin, its version and your site's home URL. Like any web service, the node sees your server's IP address.
* nano133.com terms: https://nano133.com/terms
* nano133.com privacy policy: https://nano133.com/privacy#others

= Price feeds: CoinGecko, Kraken and KuCoin =

Needed to turn a price in US dollars into XNO. The plugin uses the median of the feeds that answer, so one wrong feed can't move the price.

* When: when a reader starts a checkout and no rate is cached from the last five minutes. One request to each feed.
* What is sent: a plain public price request (the URLs below), with the plugin's own user agent ("NanoUnlock" and its version), which names no site. Nothing about your site, your readers or your sales. Like any web service, the feed sees your server's IP address.
* CoinGecko, https://api.coingecko.com/api/v3/simple/price?ids=nano&vs_currencies=usd. Terms: https://www.coingecko.com/en/terms, API terms: https://www.coingecko.com/en/api_terms, privacy policy: https://www.coingecko.com/en/privacy
* Kraken, https://api.kraken.com/0/public/Ticker?pair=NANOUSD. Terms: https://www.kraken.com/legal/global-terms, privacy notice: https://www.kraken.com/legal/privacy
* KuCoin, https://api.kucoin.com/api/v1/market/orderbook/level1?symbol=XNO-USDT. Terms of use: https://www.kucoin.com/legal/terms-of-use, privacy policy: https://www.kucoin.com/legal/privacy-policy

The checkout's QR code is drawn in the reader's browser by a script shipped with the plugin; it calls no service. "Open in wallet" opens a nano: link in the reader's own wallet app.

== Changelog ==

= 0.1.0 =
* First version: settings, shortcode, block, checkout, verification on the site, receipts.
