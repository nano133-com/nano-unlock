# Nano Unlock for WordPress

Sell part of a post for a few cents in Nano (XNO). The reader pays **your own
Nano address** directly, and **your site** checks the payment with a Nano
node. There is no account, no card, no middleman and no service to sign up
for. The plugin never holds a key or any money.

This repository holds the plugin (`nano-unlock/`) and its development tools.
It is based on the Ӿ Unlock prototype on nano133.com, in the form where the
site verifies payments itself, in PHP, with no outside service in between.

- WordPress 6.3 or newer (tested on 6.8 and 7.1), PHP 7.4 or newer (tested on 7.4 and 8.3).
- No PHP extension beyond the WordPress defaults: amounts are handled as
  decimal strings, and the address checksum uses a small BLAKE2b in plain PHP.

## Install

1. Build the zip: `npm run zip` (or zip the `nano-unlock/` folder).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose
   `nano-unlock.zip`, then **Activate**.
3. Go to **Settings → Nano Unlock** and enter your Nano address.

## Settings

| Setting | What it does |
|---|---|
| Your Nano address | Readers pay this address. It must be a valid `nano_` address: the last 8 characters are a checksum, so a typo is refused and the old value is kept. |
| Default price (USD) | Used when a paid part names no price. $0.01 to $1000. |
| Node RPC URL | The node that proves payments. Default: `https://node.nano133.com/rpc`. Any Nano node RPC works (your own node, or a public one). |
| Second node (optional) | If set, a payment counts only when **both** nodes confirm the same send. |

**Test nodes are refused.** A node that is not a public `https` node (plain
`http`, `localhost`, a private IP, `host.docker.internal`, `*.local`, a name
without a dot) can't see real payments. With such a node, the settings page
shows a red "Test node" notice, readers get no Unlock button, and the
checkout endpoint refuses to start. Only the plugin's own end-to-end test
turns this off (filter `nano_unlock_allow_test_node`), and then every
checkout shows "TEST MODE: do not send real money".

The settings page also lists the latest sales (post, price, amount, payer and
block).

## Selling a part of a post

**Block editor:** add the **Nano Unlock (paid part)** block and put the paid
content inside it. Set the price in the block's sidebar (empty = the default
price).

**Shortcode** (classic editor, or a Shortcode block):

```
[nano_unlock price="0.05"]
The paid part: text, images, other blocks or shortcodes.
[/nano_unlock]
```

An optional `id` (block: "Item ID") names the item, for example
`[nano_unlock id="recipe" price="0.10"]`. Without it, an item is the paid
part's position in the post, so reordering paid parts changes which purchase
unlocks which part. Use an `id` if you reorder them.

The reader sees the price and an **Unlock** button. Editors of the post see
the paid part with a dashed border and a note, so they can check it.

## How a payment is verified

1. **The offer.** Next to a locked part, the page carries a signed offer: the
   post, the item, the price and the post's last-modified time, signed with
   the site's own key (HMAC-SHA256). The reader can't change the price or ask
   for another item. After the post is edited, old offers stop working and
   the reader is asked to reload.
2. **The checkout.** `POST /wp-json/nano-unlock/v1/checkout` converts the
   price to XNO at the current rate (the median of CoinGecko, Kraken and
   KuCoin, cached for five minutes; the last good rate is used for up to a
   day if all fail) and rounds it up to 0.0001 XNO (a "step"). That **clean
   amount** is the checkout's own amount while no other open checkout holds
   it; otherwise the checkout gets the next free step (0.1512 XNO, 0.1513
   XNO, …). So an amount has **at most four decimals**, and a wallet that
   rounds to four decimals still sends it exactly. The amount identifies the
   payment, so no memo is needed, and the match stays exact: no tolerance.

   **The reservation.** `amount_lock`, a unique key in the checkouts table,
   holds each checkout's amount. `Nano_Unlock_Store::create()` reads which
   of the price's steps are held, then inserts the lowest free one; when
   two readers pick the same free step at the same instant, the unique key
   refuses the second insert and that reader moves on to the next step
   (PHPUnit proves it with 24 parallel processes on one database, and the
   end-to-end test with 110 parallel requests on MariaDB). An amount is
   freed at once when its checkout is paid, and 3 minutes after a cancel
   (`CANCEL_HOLD`). An unpaid amount stays held for the checkout's 15
   minutes, the hour for late payments and 5 minutes more (`HOLD_AFTER`,
   longer than the 2-minute clock allowance below), so a late payment can
   never meet a new checkout that got its amount. The steps above a price
   add at most a tenth of it (`MAX_EXTRA_PERCENT`; a 1¢ price of 0.0304 XNO
   gets at most 30 steps more) and never more than 0.0099 XNO (`MAX_STEPS`,
   100 amounts): when all are held, the reader gets "Busy right now … Try
   again in a moment" (HTTP 503) and nothing is held. One visitor (an IP address, or an IPv6 /64) may hold at
   most 10 amounts at once, so one visitor alone can't use up a price's
   steps; the count uses a keyed hash of the address, stored only while the
   amount is held.

   The reader gets a QR code and a `nano:` link ("Open in wallet"), which
   fill in the exact amount, and one line: "Pay $0.05 · 0.1512 XNO". The
   address and the amount with Copy buttons, the expiry and one sentence of
   help are under "Details". A payment of a wrong amount unlocks nothing;
   the site owner sees it in the wallet and can refund it.
3. **The check.** The page asks `POST /wp-json/nano-unlock/v1/claim` every
   two seconds. The site asks the node for the address's receivable sends
   and its recent history (a wallet may already have received the payment),
   and looks for the exact amount (at most 50 entries each: the most the
   node.nano133.com gateway allows). A payment that already paid a checkout
   is skipped: an amount comes back for a new checkout after its sale, and
   the old payment is still in the scan. A match is only a candidate: the
   site then reads that send block with `block_info` and accepts it only
   when it is a **confirmed send** of **exactly** that amount **to your
   address**, first seen by the node **after the checkout started** (its
   `local_timestamp`, with 2 minutes for the clocks; a block without one
   never counts). With a second node, both must agree.
4. **Once, on this site.** The payment's block hash is stored with a unique
   key, so on one site one payment unlocks one checkout, once. A confirmed
   block's answer is kept for an hour, so an older payment of a repeated
   amount costs one `block_info` call, not one per poll. **One address
   serves one site only:** the node can't tell which site a payment was
   for, and two sites that sell at the same price ask for the same amount,
   so one payment can unlock a checkout on both (also two sites of one
   network, a shop on the same address, and each Playground tab of the
   demo). The settings page says so, and on a network it warns when another
   site has the same address.
5. **The receipt.** Only the browser that started the checkout (a random
   httpOnly cookie) gets the receipt. All of a browser's receipts share one
   httpOnly cookie, `nano_unlock_receipts`, signed with the site's key
   (HMAC-SHA256). Each receipt names only the item, the checkout that paid
   for it and its expiry (30 days, filter `nano_unlock_receipt_seconds`); the
   buyer's address and the payment stay in the checkout's row on the server.
   One receipt adds about 70 bytes, and the cookie keeps the newest receipts
   that fit in 2800 bytes (about 38 typical ones; the oldest are dropped), so
   many purchases never make the request too large for the server. The page
   reloads with the paid part.

   The claim answers "paid" only when the payment is recorded **and** the
   receipt cookie was sent with that answer. If the payment is recorded but
   the cookie could not be set, the answer is an error, and the checkout
   stays on the page with a "Try again" button. The open checkout is kept in
   the tab's `sessionStorage`, so a reload picks it up again; a page that
   reloads after "paid" and is still locked (the browser dropped the cookie)
   says so instead of losing the checkout. The browser that started a
   checkout can claim its receipt again for an hour after the payment.

### Where the paid parts are stored

A paid part is never kept in the post's content. On every save, the plugin
moves each paid part into its own protected post meta entry
(`_nano_unlock_part_<id>`), and the post keeps only a self-closing block
(`<!-- wp:nano-unlock/paywall {"partId":"…"} /-->`) or a self-closing
`[nano_unlock … part="…"]`. When a post is opened for editing (the block
editor's REST request in the edit context, or the classic editor), the plugin
puts the parts back inside their blocks, so authors edit them as usual; the
next save moves them out again. Existing posts with inline paid parts are
moved on activation or update (once; running it again changes nothing, and
it doesn't change the posts' modified time).

The paid part is only ever rendered on the server, only on the post's own
page, and only for a request with a valid receipt (or from someone who can
edit the post). It is not in the HTML before payment, not in feeds, not in
excerpts, and not in the core REST API output. On the home page, archives,
search results, feeds, the REST API and another post's page, a paid part is
a link to the post's page, even for a buyer.

A checkout waits 15 minutes. A payment that arrives up to an hour after that
still counts (the amount stays reserved, and 5 minutes more).

**Cancel** (`POST /wp-json/nano-unlock/v1/cancel`, only from the browser that
started the checkout) first asks the node, without the shared scan: if the
payment is there, the checkout is paid and unlocks; if it is seen but not
confirmed, the checkout stays open; if the node does not answer, nothing is
cancelled and the amount stays held. Otherwise the checkout ends; its
amount stays held for 3 more minutes, then it is free. A payment the node
first sees within a minute after Cancel can then never count for the next
checkout that gets the amount. A reader who just leaves the page cancels
nothing.

**Coincidences.** A payment matches only an open checkout that holds exactly
its amount, sent to the site's address after that checkout started. On one
site, two open checkouts never share an amount, and a used payment is
skipped. A payment outside its own checkout's time can unlock another
reader's checkout of the same amount: a second send, a send more than a
minute after Cancel, or a send more than an hour after its checkout
expired. So can an outside payment to the same address of exactly that
4-decimal amount, and any payment on a second site that uses the same
address. The owner still receives every payment. Round amounts (0.1 XNO,
1 XNO) are the likely ones; an address used only by the plugin on one site
has none.

**The node's clock.** "After the checkout started" uses the node's
`local_timestamp` and the site's clock, with 2 minutes of allowance. A node
clock more than 2 minutes behind makes early payments not count. After a
long node outage, a payment the node catches up on may get the catch-up's
time (not tested).

**Capacity.** All posts at one price share its amounts. At $0.33 per XNO a
1¢ price has 31 and a 5¢ price 100; at $3.30 per XNO a 1¢ price has 4. A
reader who leaves without paying or cancelling holds an amount for 80
minutes, so at most amounts × 60 / 80 such readers an hour fit at one
price; after that, new readers get "busy" for up to 80 minutes.

**The scan.** The node gives at most 50 receivable sends and 50 history
entries (the `node.nano133.com` gateway's cap; `receivable` has no
`offset`). An owner whose wallet leaves more than 50 sends at or above the
prices unreceived can hide a new payment until the wallet receives.

### Wallets

Amounts have at most four decimals, because some wallets round to four.
Before 0.2.0 amounts had more digits, and Cake Wallet cut one to four
decimals, so that payment did not match. Version 0.2.0 was tested with the
mock node and with one real payment of 0.1468 XNO from a wallet app on
2026-10-19. Cake Wallet itself was not tested with the plugin yet.

### Node load

The address scan is shared by all open checkouts and cached for 3 seconds,
so a site makes about two node calls every 3 seconds while anyone is paying,
and none when nobody is. `block_info` is only called for a matching amount.

The public node `node.nano133.com` allows about 120 RPC calls a minute per
IP address. One site stays well below that. Several busy sites that share
one server IP (shared hosting) share that budget; they can set their own
node.

## Security

- Every REST call needs the page's REST nonce.
- Rate limits per visitor: 20 checkouts per 10 minutes (600 per 10 minutes
  for everyone), at most 10 open checkouts at once, and 180 payment checks
  (and cancels) per minute. A visitor is a keyed hash of the address
  (`wp_hash`), an IPv6 address counted by its /64; no counter is named
  after an unkeyed hash of an address. The count of open checkouts and the
  new checkout run under a per-visitor `GET_LOCK` (MySQL, MariaDB), so
  parallel requests can't pass the limit together; SQLite (Playground) has
  no named locks and runs without.
- A checkout request that the browser marks `Sec-Fetch-Site: cross-site`
  (a page on another site) is refused.
- The visitor address is `REMOTE_ADDR`. Forwarded headers are not trusted,
  because anyone can send them. Behind a proxy or CDN, set the real header
  with the `nano_unlock_client_ip` filter.
- The signing key is 32 random bytes made at activation, stored in the
  options table (not autoloaded), and never sent to the browser.
- Settings need `manage_options`. Everything printed is escaped. All SQL
  values go through `$wpdb->prepare()`.
- When a node does not answer, the check fails closed: nothing is unlocked,
  and the reader sees "The network check is busy".
- Caching: a paid part is shown only on its post's own page, and that page
  sends no-cache headers and sets `DONOTCACHEPAGE`. Page-cache plugins that
  respect `DONOTCACHEPAGE` (most of them) don't store it. Every other page
  shows a link instead of the paid part, so a cached copy of it holds no
  reader's paid part.
- A cache that ignores those signals (a CDN or a reverse proxy set to cache
  every page, for example) could store one buyer's unlocked page and serve
  it to others. On such a setup, exclude the posts that have a paid part, or
  bypass the cache for requests that carry the `nano_unlock_receipts`
  cookie.

### Limits to know

- **Turning the plugin off hides the paid parts.** WordPress drops the
  unknown block and prints only the shortcode's tag (for example
  `[nano_unlock price="0.05" part="…"]`), never the paid text. Turning it on
  again restores the paywall, and buyers' receipts still work.
- **Deleting the plugin keeps its data** (the paid parts, the sales, the
  settings and the key) unless "Also delete the paid parts…" is ticked on
  the settings page.
- Paid parts have no revision history: revisions and autosaves keep only
  the free text. A save that doesn't carry a part's content (an autosave, or
  a tool that doesn't load it) keeps the stored text for that part.
- A preview of unsaved changes shows the stored paid text, not the unsaved
  edits to it (a preview of a published post comes from its autosave).
- A receipt is a cookie for one browser. Anyone who copies the cookie out of
  that browser gets the same access until it expires. There is no "restore
  on another device" in this version.
- A browser keeps about 38 receipts (fewer with long item ids). After that,
  each new purchase drops the oldest receipt, and that item locks again on
  that browser.
- A payment sent from an exchange works like any other payment, but it comes
  from the exchange's address. The checkout asks readers to pay from a
  wallet they control.
- Nobody can stop a reader from copying text they have unlocked.
- On a WordPress network, each site has its own table and daily clean-up,
  and deleting the plugin removes the data of the main site only (when its
  box is ticked); the other sites' tables and settings stay.

## Development

```
npm install
composer install
npm start          # wp-env on http://localhost:8888 (admin / password), copies the plugin in
npm run sync       # copy the plugin into wp-env again after a change
npm test           # PHPUnit: amounts and their reservation (24 parallel processes on SQLite), the table update, receipts and offers, addresses, the payment rules and the block cache, rendering, the REST routes, settings
npm run test:wp    # integration tests inside WordPress: paid-part storage, migration, amounts on MySQL and the update from 0.1.1, uninstall
npm run lint       # PHPCS with the WordPress Coding Standards and PHPCompatibilityWP
npm run e2e        # end-to-end test against a MOCK node (no real network, no real money)
npm run e2e -- --shots <folder>   # the same, with screenshots
npm run zip        # nano-unlock.zip
```

`npm run sync` exists because on some Docker Desktop setups the nested bind
mount that wp-env normally uses for a plugin folder comes up empty after a
container restarts. Copying the folder is reliable.

**The end-to-end test uses addresses that have no key** (`e2e/address.php`
hashes a label into a public key; no private key exists). Money sent to them
is lost. The test saves the site's settings first and puts them back when it
ends, and the test-node check above keeps readers from paying while it runs.

The end-to-end test starts `e2e/mock-node.php` (an in-memory ledger on port
8787), points the plugin at it through `host.docker.internal`, fixes the
XNO rate at $0.50 with a helper mu-plugin, creates two sample posts (a
shortcode and a block) and checks:

- the paid parts are not in the HTML, the feed or the core REST API;
- a checkout asks for an exact, clean amount to the site's address ($0.01 at
  $0.50 is 0.02 XNO), and the card shows "Pay $0.01 · 0.02 XNO";
- test mode: the QR code is stamped "do not pay", with no `nano:` link and
  no copy keys; the status is a polite live region; "Details" is a closed
  button that Enter opens;
- Cancel closes the card and gives the focus back to the Unlock key; the
  amount stays held for 3 minutes, then the next checkout gets it;
- an unconfirmed payment shows "confirming" and unlocks nothing;
- a confirmed payment reloads the page with the paid part (about 2 s);
- a payment already received by the wallet (history) also counts;
- a shared link, a forged receipt, and one post's receipt on another post
  all stay locked;
- no nonce → 403; a changed offer → 400; a paid amount comes back for the
  next checkout, and the payment that paid it does not pay that one; while
  it is held, the next checkout gets one step more; a used payment never
  pays a second checkout; another browser gets 403 and no receipt;
- Cancel: only the starting browser; the amount is held for 3 minutes, then
  goes to the next checkout; after the payment arrived, Cancel unlocks
  instead; with the node down, Cancel in the browser keeps the checkout and
  the payment that arrives later still unlocks;
- node down → 503 and nothing unlocks, and Cancel keeps the amount held;
- 48 fast polls in 6 s cause 2 `receivable` and 2 history calls;
- the 11th open checkout from one address → 429; the 21st checkout from one
  address in 10 minutes → 429;
- 60 parallel checkouts (60 visitors) get 0.1000 … 0.1059 XNO, no two alike;
- 24 parallel checkouts from one visitor: exactly 10 get an amount; a
  checkout marked `Sec-Fetch-Site: cross-site` → 403;
  50 more at once: 40 get the last steps, 10 are told "busy";
- the settings page refuses a mistyped address and lists sales;
- the block loads in the editor with no warnings or script errors.

## Releases

Pushing a tag `v*` (for example `v0.1.0`) runs `.github/workflows/release.yml`:
it runs PHPUnit and PHPCS, builds `nano-unlock.zip` from the `nano-unlock/`
folder only (no tests, tools or dev files), checks that the zip holds the
plugin's main file and nothing else, and attaches it to a GitHub release for
that tag. The version in the tag must match `Version:` in `nano-unlock.php`.

### WordPress.org assets

`.wordpress-org/` holds the directory page's assets (the SVN `assets/` folder):
`screenshot-1.png` … `screenshot-5.png` (captions in `readme.txt`, Screenshots),
`banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png` and
`icon-256x256.png`. To make them again:

```sh
npm run start && node e2e/wporg-shots.mjs   # the screenshots
node e2e/wporg-art.mjs                      # the banner and the icon
```

The screenshots keep the real default node in the settings, so the pages show
no test-mode notice, and a helper (`e2e/mu-plugins/nano-unlock-shots.php`, only
while the script runs) sends those node calls to the mock node: no real
network and no real money. Their payee is the nano133 Unlock wallet, an
address with a key, never a keyless test address: a reader may scan the QR
code in a published screenshot.

## WordPress Playground

`blueprint.json` sets up a demo: it installs the plugin from the latest
GitHub release (`nano-unlock.zip`), sets the nano133 demo wallet as the
payee, and creates a sample post with a $0.01 paid part. It needs a
published release (see Releases above) and a repository that Playground can
download from. Open:

```
https://playground.wordpress.net/#<the blueprint JSON, URL-encoded>
```

**The demo takes real money.** Its checkout is a real one: a visitor who
pays sends about 1 cent of real XNO to the demo wallet, and the paid part
unlocks in that Playground tab. The sample post starts with a boxed note
that says so, and that paying is optional. Each Playground tab is its own
site with the same demo address and price, so one payment can also unlock
an open checkout of the same amount in another demo tab.

Playground runs PHP in the browser, so the plugin's calls to the node and
the price feeds are browser requests there, and they need CORS. The node
gateway at `node.nano133.com`, CoinGecko and Kraken allow the Playground
origin (KuCoin does not, so there the rate comes from the other two feeds).

## License

Nano Unlock is free software under the GNU General Public License, version 2
or (at your option) any later version: see `LICENSE`.

The checkout's QR code is drawn by
[QR Code Generator for JavaScript](https://github.com/kazuhikoarase/qrcode-generator)
(qrcode-generator 2.0.4, `nano-unlock/assets/vendor/qrcode.js`), Copyright
(c) 2009 Kazuhiko Arase, under the MIT license. "QR Code" is a registered
trademark of DENSO WAVE INCORPORATED.
