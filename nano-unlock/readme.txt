=== Nano Unlock ===
Contributors: nano133
Tags: paywall, micropayments, nano, xno, pay per post
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell part of a post for a few cents in Nano (XNO). Readers pay your address directly; your site checks the payment with a Nano node.

== Description ==

Stage: Alpha. What this means: https://nano133.com/stages

Wrap the paid part of a post in the "Nano Unlock (paid part)" block, or in `[nano_unlock price="0.05"] … [/nano_unlock]`. Readers see the price and an Unlock button, pay by QR code or "Open in wallet", and the part appears about two seconds after the payment confirms.

The checkout is short: the QR code, one line ("Pay $0.05 · 0.1512 XNO"), one "Open in wallet" key and one status line. The address and the amount, each with a Copy button, are under "Details".

* The money goes straight to your Nano address. The plugin never holds a key or funds.
* No account, no card, no fee: a 1¢ sale pays you 1¢.
* The paid part is rendered on the server only after payment. It is never hidden with CSS, and it is stored apart from the post, so feeds, search, revisions and a deactivated plugin never show it.
* Your site verifies each payment itself with a Nano node (the default is node.nano133.com; any node works, and a second node can be required to agree).
* Each checkout asks for an exact, short amount: at most four decimals, so a wallet that rounds to four decimals still sends it exactly.
* On your site, one payment unlocks one item, once. The buyer keeps access on that browser through a signed cookie.
* Use your Nano address for this one site only. A new account in your wallet app is enough (see the FAQ).

= Credits =

The checkout's QR code is drawn by QR Code Generator for JavaScript (qrcode-generator 2.0.4, assets/vendor/qrcode.js), Copyright (c) 2009 Kazuhiko Arase, under the MIT license: https://github.com/kazuhikoarase/qrcode-generator. "QR Code" is a registered trademark of DENSO WAVE INCORPORATED.

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin, search for "Nano Unlock", click Install Now, then Activate. Or upload the plugin's .zip with Upload Plugin on the same page.
2. Go to Settings > Nano Unlock.
3. Paste your Nano address from your wallet (any Nano wallet). Readers pay this address directly. Never paste a seed or a private key: the plugin needs only the public address. Use an address that no other site, sub-site, shop or demo uses: make a new account in your wallet app for this site.
4. Set a default price in US dollars (5 cents to start). Readers pay the same value in XNO at the current rate.
5. Keep the default node, or set your own Nano node's RPC URL. Optional: set a second node, so that a payment counts only when both nodes confirm it.
6. In a post, add the "Nano Unlock (paid part)" block and put the paid content inside it, or wrap it in `[nano_unlock price="0.05"] … [/nano_unlock]`.

To check it before you sell, open the post in a private window, unlock it with a small payment from your own wallet, and find the sale under "Recent sales" on the settings page.

== Frequently Asked Questions ==

= What if a reader sends a rounded amount? =

Each checkout asks for an exact amount with at most four decimals, for example 0.1512 XNO: the price in XNO, rounded up to 0.0001 XNO. While another open checkout already holds that amount, the next one asks for 0.0001 XNO more, and so on: at most a tenth of the price more, and never more than 0.0099 XNO. When all of those amounts are held, the reader is asked to try again in a few minutes. That is how a payment is matched to its checkout, with no memo and no key on your site.

So a wallet that shows or rounds amounts to four decimals still sends the exact amount. Some wallets cut amounts to four decimals: before version 0.2.0, amounts had more digits, and Cake Wallet cut one, so that payment did not match. The QR code and "Open in wallet" fill the amount in, and under "Details" it has a Copy button. A payment of any other amount does not match and unlocks nothing; you see it in your wallet and can refund it.

Which wallets were checked: version 0.2.0 was tested with a mock node and with one real payment of 0.1468 XNO from a wallet app on 2026-10-19. Cake Wallet itself was not tested with the plugin yet.

= What does Cancel do? =

Cancel ends the checkout. Its amount stays reserved for 3 more minutes, then the next reader can get it. If the payment has already arrived, Cancel unlocks the part instead; if it is still confirming, the checkout stays open. A payment sent after Cancel no longer counts for that checkout; you see it in your wallet and can refund it. A reader who just leaves the page cancels nothing: the checkout's amount stays reserved, and its payment counts, for the 15 minutes of the checkout and one hour more.

= Can I use one Nano address for two sites? =

No. Give each site its own address (a new account in your wallet app is enough). The node can't tell which site a payment was for. Two sites that sell at the same price ask for the same amount (0.1512 XNO, then 0.1513 XNO, …), so one payment can unlock a checkout on both sites. The same goes for two sites of one WordPress network (the settings page warns when another site of the network has the same address), a shop that takes Nano on the same address, and a demo. Each WordPress Playground tab of the Nano Unlock demo is a separate site with the same demo address.

= Can a payment unlock the wrong checkout? =

On one site, two open checkouts never ask for the same amount (a unique key in the database holds it), and a payment counts only when it is a confirmed send of exactly that amount to your address, which your node first saw after the checkout started (with 2 minutes for the clocks). If your node can't say when it first saw a payment (a block with no time), that payment never counts: the check fails closed. A payment that already unlocked one checkout never counts again.

A payment that comes outside its own checkout's time can unlock another reader's checkout of the same amount: a second send of the same amount, a payment sent more than a minute after Cancel, or a payment sent more than an hour after its checkout expired. You still receive every payment, and you can refund the person who paid twice or too late. The same is true for a payment that someone else happens to send to your address, of exactly the amount an open checkout asks for. An address used only by this plugin, on one site, makes this as rare as possible.

The node's clock matters too: if it runs more than 2 minutes behind your server's clock, a payment in the first minutes of a checkout does not count. After a long node outage, a payment that the node catches up on may get the time of the catch-up, and can then unlock a newer checkout of the same amount.

= What does the plugin keep about readers? =

For each checkout: the item, the price, the amount, a hash of a random token in the reader's browser, and, only while its amount is reserved, a keyed hash of the reader's IP address (so that one visitor can hold at most 10 open checkouts). The rate limits count with the same keyed hash, in short-lived counters (at most 20 minutes). An IPv6 address counts by its /64. A paid checkout also keeps the payer's Nano address and the payment's block hash, which are public on the Nano network. The reader's access is a signed cookie in their own browser.

= My site is behind a CDN or a proxy. =

The limits count readers by the address that connects to your server (REMOTE_ADDR). Behind a CDN or a reverse proxy that does not pass the reader's real address, all readers who come through one proxy address count as one: after 10 open checkouts there, the next readers are told "Too many checkouts are open from your connection", for up to 80 minutes. Most hosts restore the real address. If yours does not, return it with the `nano_unlock_client_ip` filter, for example from the header your CDN sets (trust that header only when every request comes through the CDN).

= How many readers can pay at once? =

All posts at one price share its amounts: the price in XNO, and up to a tenth of it more in steps of 0.0001 XNO, at most 100 amounts. At $0.33 per XNO a 1¢ price has 31 amounts and a 5¢ price has 100; at $3.30 per XNO a 1¢ price has 4. A paid checkout gives its amount back at once and a cancelled one after 3 minutes, but a reader who just leaves holds one for 80 minutes (the checkout's 15 minutes, an hour for late payments and 5 minutes more). When all amounts of a price are held, new readers are asked to try again in a few minutes. A higher price has more amounts.

= My wallet does not receive payments at once. Does that matter? =

Open your wallet now and then so that it receives the payments. Your site looks at the 50 newest payments that wait on your address and the 50 newest receives in its history (the most the default node gives). When more than 50 sends at or above your prices wait unreceived, a new payment may not be seen until your wallet receives the others.

= What happens if I deactivate the plugin? =

The paid parts stay hidden. They are stored apart from the post's content, so WordPress shows only the shortcode's tag (and nothing for the block). Turn the plugin on again and everything works as before, including buyers' access.

= What happens if I delete the plugin? =

Its data is kept (paid parts, sales, settings) unless you tick "Also delete the paid parts" on the settings page first. On a WordPress network, deleting the plugin removes this data for the main site only; the other sites' tables and settings stay.

= Does it work with page caching? =

Yes, with a page-cache plugin that respects DONOTCACHEPAGE (most do). A paid part is shown only on its post's own page, and that page sends no-cache headers and sets DONOTCACHEPAGE. The home page, archives, search, feeds and other posts' pages show a link to the post instead of the paid part, so they can be cached.

A cache that ignores these signals (a CDN or a reverse proxy set to cache every page) could serve one buyer's unlocked page to other readers. Exclude the posts that have a paid part from such a cache, or bypass it for requests that carry the nano_unlock_receipts cookie.

== Screenshots ==

1. A post with a paid part, as a reader sees it: the free part, the price and the Unlock button.
2. The checkout: the QR code, "Pay $0.05 · 0.05 XNO", "Open in wallet" and the status line. The address and the amount, each with a Copy button, are under Details.
3. About a second after the payment confirms, the paid part appears on the reader's page.
4. The "Nano Unlock (paid part)" block in the editor, with its price in the block settings.
5. The settings page: your Nano address, the default price, the node, and the recent sales.

== External services ==

This plugin connects to the services below from your site's server. Readers' browsers never contact them through the plugin, and the plugin sends no reader data to them (no IP address, no cookie, no name).

= A Nano node (node.nano133.com by default) =

Needed to check payments: the site asks a Nano node whether your address received a confirmed payment of a checkout's exact amount. The default node is run by nano133.com; you can set any other Nano node, and an optional second node, on the settings page. The plugin then calls the node(s) you set instead.

* When: while a reader's checkout waits for a payment (the reader's page asks your site every 2 seconds; your site asks the node at most every 3 seconds), once more for each payment it finds, to confirm it (on both nodes when a second node is set), and once when a reader presses Cancel. Nothing is sent when no checkout is open.
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

== Upgrade Notice ==

= 0.2.0 =
A new checkout card and short, exact amounts (at most four decimals). The update adds two columns to the plugin's table. Give each site its own Nano address: two sites with one address can both unlock for one payment.

== Changelog ==

= 0.2.0 =
* A new checkout card: the QR code, one line ("Pay $0.05 · 0.1512 XNO"), one "Open in wallet" key and one status line. The address, the amount (each with Copy), the expiry and one sentence of help are under "Details", closed at first. In Twenty Twenty-Three, "Open in wallet" is white on teal again; the keys pass WCAG AA contrast; screen readers hear each status change once; the moving dot stops with reduced motion. Prices show their cents ($0.10, not $0.1).
* Clean amounts: each checkout asks for the price rounded up to 0.0001 XNO, with no random digits at the end, so a wallet that rounds to four decimals (Cake Wallet did) pays it exactly. Open checkouts at the same price get 0.0001 XNO more each, reserved by a unique key in the database: at most a tenth of the price more and never more than 0.0099 XNO, then the reader is asked to try again in a moment. The match is still exact. A paid checkout frees its amount at once; one visitor can hold at most 10 open checkouts.
* A cancel route (POST /nano-unlock/v1/cancel): Cancel ends the checkout on the site, and its amount is free 3 minutes later. If the payment has already arrived, Cancel unlocks instead. When the site can't cancel (the node is busy), the checkout stays open in the page, and its payment still counts.
* A payment counts only when the node knows when it first saw it (after the checkout started); a block with no time never counts. A payment that already unlocked a checkout is never matched again.
* Database columns for the visitor limit and the cancel time (database version 2). The version is recorded only when both columns exist; if the update fails, the dashboard says so and the plugin tries again. Checkouts that were open during the update keep their exact amount until they end.
* Limits: one visitor can hold at most 10 open checkouts, counted under a database lock (MySQL, MariaDB); the rate limits count by a keyed hash, and an IPv6 address by its /64; a checkout can start only from the site's own pages. The burn address is refused as the payee. Each site of a network gets its own daily clean-up.
* A confirmed payment that paid nothing (a second send, a payment after Cancel) is read from the node once, not on every check.
* Test mode: the QR code holds only "TEST MODE: do not pay", stamped and blurred, and the wallet and copy keys are off.

= 0.1.1 =
* The price requests to CoinGecko, Kraken and KuCoin carry the plugin's own user agent ("NanoUnlock" and its version) instead of WordPress's default, which names the site.
* Every query on the plugin's checkouts table names the table through $wpdb->prepare() (the %i placeholder).
* The readme describes the installation, the screenshots and the external services.

= 0.1.0 =
* First version: settings, shortcode, block, checkout, verification on the site, receipts.
