// End-to-end test of Nano Unlock in wp-env, against a MOCK node (no real network, no real money).
//
//   npx wp-env start && node e2e/run.mjs [--shots <dir>]
//
// It starts the mock node (e2e/mock-node.php) on port 8787, points the plugin at it
// (http://host.docker.internal:8787 from inside the container), creates two sample posts
// (a shortcode and a block), and walks through the reader's flows with Playwright.

import { execFileSync, spawn } from "node:child_process";
import { mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "@playwright/test";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const SITE = "http://localhost:8888";
const MOCK = "http://localhost:8787";
const shotsArg = process.argv.indexOf("--shots");
const SHOTS = shotsArg > 0 ? process.argv[shotsArg + 1] : null;
if (SHOTS) mkdirSync(SHOTS, { recursive: true });

const addr = (label) => execFileSync("php", [join(ROOT, "e2e/address.php"), label]).toString().trim();
const SITE_ADDR = addr("site");
const BUYER = addr("buyer");
const BUYER2 = addr("buyer 2");

const wp = (...args) => execFileSync("npx", ["wp-env", "run", "cli", "--", "wp", ...args], { cwd: ROOT, stdio: ["ignore", "pipe", "ignore"] }).toString().trim();
const mock = async (path, body) => (await fetch(MOCK + path, { method: body ? "POST" : "GET", body: body ? JSON.stringify(body) : undefined })).json();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// A page's HTML. Apache closes idle keep-alive connections while wp-cli runs, so one retry.
const page$ = async (url) => {
  try {
    return await (await fetch(url)).text();
  } catch {
    return (await fetch(url)).text();
  }
};

let failures = 0;
const check = (ok, msg) => {
  console.log(`${ok ? "PASS" : "FAIL"} ${msg}`);
  if (!ok) failures++;
};

// ---- The mock node ------------------------------------------------------------------------------
const node = spawn("php", ["-S", "0.0.0.0:8787", join(ROOT, "e2e/mock-node.php")], { stdio: "ignore" });
process.on("exit", () => node.kill());
for (let i = 0; i < 50; i++) {
  try {
    await mock("/__reset", {});
    break;
  } catch {
    await sleep(100);
  }
}

// ---- The site -------------------------------------------------------------------------------------
console.log("setting up the site…");
// The site's own settings are saved here and put back when the test ends, however it ends:
// the test's address has no key, so the dev site must never be left pointing at it.
let saved = null;
try {
  saved = wp("option", "get", "nano_unlock_settings", "--format=json");
} catch {
  saved = null;
}
process.on("exit", () => {
  try {
    wp("plugin", "activate", "nano-unlock");
    wp("option", "delete", "nano_unlock_e2e");
    if (saved) wp("option", "update", "nano_unlock_settings", saved, "--format=json");
    else wp("option", "delete", "nano_unlock_settings");
    wp("transient", "delete", "--all");
    console.log("restored the site's own settings");
  } catch (e) {
    console.log(`COULD NOT RESTORE THE SETTINGS: ${e.message}`);
  }
});
process.on("SIGINT", () => process.exit(130));
wp("option", "update", "nano_unlock_settings", JSON.stringify({ address: SITE_ADDR, usd: "0.05", node: "http://host.docker.internal:8787", node2: "" }), "--format=json");
// Lets checkouts start against the mock node, and fixes the rate at $0.50 (e2e/mu-plugins).
wp("option", "update", "nano_unlock_e2e", "1");
wp("db", "query", "DELETE FROM wp_nano_unlock_checkouts");
wp("transient", "delete", "--all");
for (const id of wp("post", "list", "--post_type=post", "--meta_key=_nano_unlock_e2e", "--format=ids").split(/\s+/).filter(Boolean)) wp("post", "delete", id, "--force");

const contentA = `<!-- wp:paragraph -->
<p>Nano sends value between two accounts in under a second, with no fee. This first part is free for everyone.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[nano_unlock price="0.01"]<p>Here is the paid part. Every Nano account has its own chain of blocks, so a payment needs no miner and no global queue: the sender publishes one block, the network's representatives vote on it, and it is confirmed. That is why a one-cent sale can pay the writer the full cent.</p>[/nano_unlock]
<!-- /wp:shortcode -->`;
const contentB = `<!-- wp:paragraph -->
<p>A block paywall: the part below is a Gutenberg block, rendered on the server.</p>
<!-- /wp:paragraph -->

<!-- wp:nano-unlock/paywall {"price":"0.05"} -->
<!-- wp:paragraph -->
<p>The block's paid part: a photo set, a recipe, or the end of a story.</p>
<!-- /wp:paragraph -->
<!-- /wp:nano-unlock/paywall -->`;
const SECRET_A = "a one-cent sale can pay the writer the full cent";
const SECRET_B = "the end of a story";
const postA = wp("post", "create", "--post_type=post", "--post_status=publish", "--post_author=1", "--post_title=How Nano confirms in under a second", `--post_content=${contentA}`, "--meta_input={\"_nano_unlock_e2e\":\"1\"}", "--porcelain");
const postB = wp("post", "create", "--post_type=post", "--post_status=publish", "--post_author=1", "--post_title=A block paywall", `--post_content=${contentB}`, "--meta_input={\"_nano_unlock_e2e\":\"1\"}", "--porcelain");
const urlA = `${SITE}/?p=${postA}`;
const urlB = `${SITE}/?p=${postB}`;

// ---- 1. The server never sends the paid part before payment ------------------------------------------
console.log("\n— the locked page");
const htmlA = await (await fetch(urlA)).text();
check(!htmlA.includes(SECRET_A), "the paid part is not in the HTML");
check(htmlA.includes("data-nano-unlock-offer="), "the page carries a signed offer");
check(!htmlA.includes("nano_unlock_secret") && !/[0-9a-f]{64}/.test(htmlA.replace(/data-nano-unlock-offer="[^"]*"/, "")), "no key in the HTML");
const htmlB = await (await fetch(urlB)).text();
check(!htmlB.includes(SECRET_B) && htmlB.includes("data-nano-unlock-offer="), "the block's paid part is not in the HTML either");
const feed = await (await fetch(`${SITE}/?feed=rss2`)).text();
check(!feed.includes(SECRET_A) && !feed.includes(SECRET_B), "the RSS feed doesn't carry the paid parts");
const rest = await (await fetch(`${SITE}/?rest_route=/wp/v2/posts/${postA}`)).text();
check(!rest.includes(SECRET_A), "the core REST API doesn't carry the paid part");

// ---- 2. A reader pays (from the receivable list) ------------------------------------------------------
console.log("\n— a reader pays");
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1100, height: 900 } });
const page = await ctx.newPage();
await page.goto(urlA);
const box = page.locator(".nano-unlock--locked");
if (SHOTS) await page.screenshot({ path: join(SHOTS, "2-locked-post.png") });
check((await box.locator("p").count()) === 2 && (await box.locator(".nano-unlock__button").textContent()) === "Unlock for $0.01", "the locked box: one line, the test stamp and the key \"Unlock for $0.01\"");
// The checkout's own answer (the card shows no wallet link in test mode, so the amount is read here).
const checkoutA = page.waitForResponse((r) => r.url().includes("nano-unlock/v1/checkout"));
await box.locator(".nano-unlock__button").click();
const resA = await (await checkoutA).json();
await page.locator(".nano-unlock__card").waitFor();
const m = /^nano:(nano_[a-z0-9]+)\?amount=(\d+)$/.exec(resA.uri ?? "");
check(!!m && m[1] === SITE_ADDR && m[2] === resA.amount, `the checkout asks for a payment to the site's address (${resA.uri?.slice(0, 40)}…)`);
const amountA = resA.amount ?? "";
check(amountA === "2" + "0".repeat(28) && resA.xno === "0.02", `$0.01 at $0.50/XNO is exactly Ӿ0.02, with no tail (${resA.xno})`);
const line = (await box.locator(".nano-unlock__line [aria-hidden='true']").innerText()).trim();
const lineSr = (await box.locator(".nano-unlock__line .nano-unlock__sr").textContent()).trim();
check(line === "Pay $0.01 · Ӿ0.02" && lineSr === "Pay $0.01: 0.02 XNO", `the card's one line: "${line}" (read out as "${lineSr}")`);
// Test mode: stamped "do not pay", the QR code holds no payment, and the wallet and copy keys are off.
check((await box.locator(".nano-unlock__card--test .nano-unlock__stamp").textContent())?.includes("do not pay"), "test mode: the QR code is stamped \"do not pay\"");
check((await box.locator("a.nano-unlock__open, a.nano-unlock__qr, a[href^='nano:']").count()) === 0 && (await box.locator("button.nano-unlock__open[disabled]").count()) === 1, "test mode: no nano: link anywhere, and \"Open in wallet\" is off");
check((await box.locator(".nano-unlock__copy:not([disabled])").count()) === 0, "test mode: the copy keys are off");
// Status: one polite live region. Details: a real button, closed at first.
const said = box.locator(".nano-unlock__status [role='status'][aria-live='polite']");
check((await said.count()) === 1 && (await said.textContent()) === "Waiting for your payment", "the status line is a polite live region: \"Waiting for your payment\"");
const more = box.locator("button.nano-unlock__more");
check((await more.getAttribute("aria-expanded")) === "false" && !(await box.locator(".nano-unlock__details").isVisible()), "Details is a button, closed at first (aria-expanded=false)");
check(!(await box.locator(".nano-unlock__card").innerText()).includes(SITE_ADDR), "the face of the card shows no address");
await more.focus();
await page.keyboard.press("Enter");
const details = (await box.locator(".nano-unlock__details").innerText()).replace(/\s+/g, " ");
check((await more.getAttribute("aria-expanded")) === "true" && details.includes(SITE_ADDR) && details.includes("Ӿ0.02") && /Expires in \d+:\d\d/.test(details), "Enter opens Details: the address, the amount, the expiry");
await page.keyboard.press("Enter");
check((await more.getAttribute("aria-expanded")) === "false", "Enter closes it again");
await page.waitForTimeout(600);
if (SHOTS) await box.screenshot({ path: join(SHOTS, "3-checkout.png") });

// The payment lands but isn't confirmed yet: the page must wait.
const pay = await mock("/__pay", { to: SITE_ADDR, amount: amountA, from: BUYER, confirmed: false });
await page.waitForFunction(() => /confirming/i.test(document.querySelector(".nano-unlock__status")?.textContent ?? ""), null, { timeout: 10000 }).then(
  () => check(true, "an unconfirmed payment shows as 'confirming', nothing unlocks"),
  () => check(false, "an unconfirmed payment shows as 'confirming'"),
);
check(!(await page.content()).includes(SECRET_A), "still locked while unconfirmed");
const t0 = Date.now();
await mock("/__confirm", { hash: pay.hash });
await page.waitForURL(/nano_unlocked=/, { timeout: 10000 });
await page.locator(".nano-unlock--paid").waitFor();
check((await page.content()).includes(SECRET_A), `confirmed: the page reloads with the paid part (${Date.now() - t0} ms after confirmation)`);
check((await page.locator(".nano-unlock__note").textContent())?.includes(BUYER.slice(0, 7)), "the note names the buyer's short address");
if (SHOTS) {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: join(SHOTS, "4-unlocked-post.png") });
}
await page.goto(urlA);
check((await page.content()).includes(SECRET_A), "a later visit on this browser is still unlocked (receipt cookie)");
const homeWithReceipt = await (await ctx.request.get(`${SITE}/`)).text();
const feedWithReceipt = await (await ctx.request.get(`${SITE}/?feed=rss2`)).text();
check(!homeWithReceipt.includes(SECRET_A) && !feedWithReceipt.includes(SECRET_A), "with the receipt, the home page and the feed (both cacheable) still hold no paid part");

// ---- 3. Sharing and forging ---------------------------------------------------------------------------
console.log("\n— sharing and forging");
const cookies = await ctx.cookies(SITE);
const receipt = cookies.find((c) => c.name === "nano_unlock_receipts");
check(!!receipt && receipt.httpOnly, "the receipt is an httpOnly cookie");
const friend = await browser.newContext();
const fp = await friend.newPage();
await fp.goto(urlA);
check(!(await fp.content()).includes(SECRET_A), "a shared link shows the paywall to a friend");
await friend.addCookies([{ ...receipt, value: receipt.value.slice(0, -2) + (receipt.value.endsWith("AA") ? "BB" : "AA") }]);
await fp.goto(urlA);
check(!(await fp.content()).includes(SECRET_A), "a forged receipt unlocks nothing");
// The buyer's receipts cookie names post A's item only, so post B stays locked.
await friend.clearCookies();
await friend.addCookies([receipt]);
await fp.goto(urlB);
check(!(await fp.content()).includes(SECRET_B), "a receipt for one post doesn't unlock another");
await friend.close();

// ---- 3b. Cancel in the browser ---------------------------------------------------------------------------
console.log("\n— cancel");
const cx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
const cp = await cx.newPage();
await cp.goto(urlB);
const checkoutC = cp.waitForResponse((r) => r.url().includes("nano-unlock/v1/checkout"));
await cp.locator(".nano-unlock__button").click();
const cId = (await (await checkoutC).json()).id;
await cp.locator(".nano-unlock__card").waitFor();
check(await cp.evaluate(() => document.activeElement?.classList.contains("nano-unlock__line")), "the card takes the keyboard focus from the key it replaces (its one line)");
const cancelled = cp.waitForResponse((r) => r.url().includes("nano-unlock/v1/cancel"));
await cp.locator(".nano-unlock__cancel").click();
await cancelled;
await cp.locator(".nano-unlock__button").waitFor();
const cRow = wp("db", "query", `SELECT status, IFNULL(amount_lock, 'free') FROM wp_nano_unlock_checkouts WHERE id='${cId}'`, "--skip-column-names");
check(!(await cp.locator(".nano-unlock__card").count()) && (await cp.evaluate(() => document.activeElement?.classList.contains("nano-unlock__button"))), "Cancel closes the card, and the focus goes back to the Unlock key");
check(/^cancelled\s+free$/.test(cRow), `the cancelled checkout's amount is free at once (${cRow.replace(/\s+/, " ")})`);
await cx.close();

// ---- 4. The owner's wallet already received it (history path), on the block post -----------------------
console.log("\n— the wallet received the payment before the check (history)");
await page.goto(urlB);
const checkoutB = page.waitForResponse((r) => r.url().includes("nano-unlock/v1/checkout"));
await page.locator(".nano-unlock__button").click();
const amountB = (await (await checkoutB).json()).amount ?? "";
await page.locator(".nano-unlock__card").waitFor();
check(amountB === "1" + "0".repeat(29), `$0.05 at $0.50/XNO is exactly Ӿ0.1 (${amountB})`);
const payB = await mock("/__pay", { to: SITE_ADDR, amount: amountB, from: BUYER2 });
await mock("/__receive", { hash: payB.hash });
await page.waitForURL(/nano_unlocked=/, { timeout: 10000 });
check((await page.content()).includes(SECRET_B), "a payment found in the history unlocks the block");

// ---- 5. The REST API's own rules ------------------------------------------------------------------------
console.log("\n— the REST rules");
const fresh = await (await fetch(urlA)).text();
const nonce = /"nonce":"([0-9a-f]+)"/.exec(fresh)?.[1];
const offer = /data-nano-unlock-offer="([^"]+)"/.exec(fresh)?.[1];
const api = async (path, body, { withNonce = true, cookie = "", ip = "" } = {}) => {
  const r = await fetch(`${SITE}/?rest_route=/nano-unlock/v1/${path}`, {
    method: "POST",
    headers: { "content-type": "application/json", ...(withNonce ? { "x-wp-nonce": nonce } : {}), ...(cookie ? { cookie } : {}), ...(ip ? { "x-nano-unlock-test-ip": ip } : {}) },
    body: JSON.stringify(body),
  });
  return { status: r.status, data: await r.json(), setCookie: r.headers.getSetCookie() };
};
const STEP = (raw, k) => String(BigInt(raw) + BigInt(k) * 10n ** 26n);
check((await api("checkout", { offer }, { withNonce: false })).status === 403, "no nonce: 403");
const tampered = offer.replace(/^([A-Za-z0-9_-]{10})./, (s, a) => a + (s.endsWith("A") ? "B" : "A"));
check((await api("checkout", { offer: tampered })).status === 400, "a changed offer (another price or post): 400");
const c1 = await api("checkout", { offer });
const starterCookie = c1.setCookie.find((c) => c.startsWith("nano_unlock_starter="))?.split(";")[0];
check(!!starterCookie, "the checkout sets this browser's starter cookie");
// Post A's amount was freed when it was paid: the next reader gets it again, and the payment that paid
// post A (still in the node's history, with the same amount) must not pay this one.
check(c1.status === 200 && c1.data.amount === amountA, `a paid amount is free again for the next checkout (${c1.data.xno})`);
const c1claim = await api("claim", { id: c1.data.id }, { cookie: starterCookie });
check(c1claim.status === 200 && c1claim.data.paid === false, "the payment that paid the last checkout of that amount does not pay this one");
const c2 = await api("checkout", { offer }, { cookie: starterCookie });
check(c2.status === 200 && c2.data.amount === STEP(amountA, 1) && c2.data.xno === "0.0201", `while c1 holds Ӿ0.02, the next checkout gets one step more (${c2.data.xno})`);

// One payment unlocks one checkout, once: a second checkout that asks for the SAME amount (forced here,
// the plugin never gives two open checkouts one amount) never sees a used payment.
wp("db", "query", `INSERT INTO wp_nano_unlock_checkouts (id,item,post_id,usd,amount,amount_lock,address,starter,status,created_at,expires_at) VALUES ('${"f".repeat(24)}','post:${postA}:0',${postA},0.01,'${amountA}',NULL,'${SITE_ADDR}','${"0".repeat(64)}','waiting',UNIX_TIMESTAMP()-60,UNIX_TIMESTAMP()+900)`);
const replay = await api("claim", { id: "f".repeat(24) });
check(replay.status === 200 && replay.data.paid === false, `a used payment can't pay a second checkout (${replay.status} paid=${replay.data.paid})`);

// Another browser (no starter cookie) asking about a paid checkout gets no receipt.
const paidId = wp("db", "query", `SELECT id FROM wp_nano_unlock_checkouts WHERE status='paid' AND amount='${amountA}'`, "--skip-column-names");
const other = await api("claim", { id: paidId });
check(other.status === 403 && other.data.code === "nano_unlock_not_starter" && !other.setCookie.some((c) => c.startsWith("nano_unlock_receipts=")), "another browser gets 403, not 'paid', and no receipt");

// Cancel: only the starting browser; the amount is free at once; the checkout is over.
check((await api("cancel", { id: c1.data.id })).status === 403, "another browser can't cancel a checkout");
const cancel1 = await api("cancel", { id: c1.data.id }, { cookie: starterCookie });
check(cancel1.status === 200 && cancel1.data.cancelled === true, "the starting browser cancels it");
const gone = await api("claim", { id: c1.data.id }, { cookie: starterCookie });
check(gone.status === 200 && gone.data.gone === true, "a cancelled checkout can't be paid any more");
const c3 = await api("checkout", { offer }, { cookie: starterCookie });
check(c3.status === 200 && c3.data.amount === amountA, "the cancelled amount goes to the next checkout at once (the lowest free step)");
// Cancel after the payment arrived: it unlocks instead.
await mock("/__pay", { to: SITE_ADDR, amount: c3.data.amount, from: BUYER2 });
const cancel3 = await api("cancel", { id: c3.data.id }, { cookie: starterCookie });
check(cancel3.status === 200 && cancel3.data.paid === true && cancel3.setCookie.some((c) => c.startsWith("nano_unlock_receipts=")), "Cancel after the payment arrived unlocks instead (and sends the receipt)");
await api("cancel", { id: c2.data.id }, { cookie: starterCookie });

// The node is down: the claim fails closed with 503, and Cancel keeps the amount held.
const c4 = await api("checkout", { offer }, { cookie: starterCookie });
await mock("/__down", { down: true });
wp("transient", "delete", "--all");
const down = await api("claim", { id: c4.data.id }, { cookie: starterCookie });
check(down.status === 503, `node down: the claim answers 503, nothing unlocks (${down.status})`);
const downCancel = await api("cancel", { id: c4.data.id }, { cookie: starterCookie });
const c4lock = wp("db", "query", `SELECT amount_lock FROM wp_nano_unlock_checkouts WHERE id='${c4.data.id}'`, "--skip-column-names");
check(downCancel.status === 503 && c4lock === c4.data.amount, "node down: Cancel answers 503 and the amount stays held (a payment may be on its way)");
await mock("/__down", { down: false });

// Node load: many fast polls on one checkout share one scan every 3 s.
await mock("/__reset", {});
wp("transient", "delete", "--all");
const tl = Date.now();
let polls = 0;
while (Date.now() - tl < 6000) {
  await api("claim", { id: c4.data.id }, { cookie: starterCookie });
  polls++;
  await sleep(100);
}
const calls = await mock("/__calls");
check((calls.receivable ?? 0) <= 3 && (calls.account_history ?? 0) <= 3, `${polls} polls in 6 s made ${calls.receivable ?? 0} receivable + ${calls.account_history ?? 0} history calls`);
await api("cancel", { id: c4.data.id }, { cookie: starterCookie });

// One visitor holds at most 10 amounts at once; and starts at most 20 checkouts per 10 minutes.
wp("transient", "delete", "--all");
const held = [];
let attempts = 0;
let capped = null;
while (!capped && attempts < 15) {
  attempts++;
  const r = await api("checkout", { offer }, { cookie: starterCookie });
  if (r.status === 200) held.push(r.data.id);
  else capped = r;
}
check(held.length === 10 && capped?.status === 429 && /Too many checkouts are open/.test(capped.data.message), `one visitor holds at most 10 amounts at once (the #${attempts} is refused: ${capped?.data.message})`);
for (const id of held) await api("cancel", { id }, { cookie: starterCookie });
let firstRefused = 0;
while (!firstRefused && attempts < 25) {
  attempts++;
  const r = await api("checkout", { offer }, { cookie: starterCookie });
  if (r.status === 429) firstRefused = attempts;
  else await api("cancel", { id: r.data.id }, { cookie: starterCookie });
}
check(firstRefused === 21, `checkouts from one address are refused from #${firstRefused} (limit 20 per 10 minutes)`);
wp("transient", "delete", "--all");

// ---- 5b. Many readers at the same instant (MySQL, parallel requests) ---------------------------------
console.log("\n— many readers at once");
// Each request is its own visitor (the e2e helper takes the address from a test header). Post B costs
// $0.05: Ӿ0.1. Its paid checkout freed Ӿ0.1, so the 60 readers must hold exactly Ӿ0.1000 to Ӿ0.1059.
wp("db", "query", "DELETE FROM wp_nano_unlock_checkouts WHERE status <> 'paid'");
const offerB = /data-nano-unlock-offer="([^"]+)"/.exec(await (await fetch(urlB)).text())?.[1];
const burst = async (from, n) => Promise.all(Array.from({ length: n }, (_, i) => api("checkout", { offer: offerB }, { ip: `10.77.${Math.floor((from + i) / 250)}.${((from + i) % 250) + 1}` })));
const first = await burst(0, 60);
const got = first.filter((r) => r.status === 200).map((r) => r.data.amount);
const want = Array.from({ length: 60 }, (_, k) => STEP(amountB, k));
check(got.length === 60 && new Set(got).size === 60, `60 parallel checkouts: 60 answered, 60 different amounts (${got.length} answered, ${new Set(got).size} different)`);
check([...got].sort().join() === [...want].sort().join(), "together they hold Ӿ0.1000 … Ӿ0.1059, with no gap and no tail");
const second = await burst(60, 50);
const got2 = second.filter((r) => r.status === 200).map((r) => r.data.amount);
const busy = second.filter((r) => r.status === 503);
check(got2.length === 40 && busy.length === 10 && new Set([...got, ...got2]).size === 100, `50 more at once: 40 get the last steps, 10 are told "busy" (${got2.length} + ${busy.length})`);
check(busy.every((r) => /Try again in a moment/.test(r.data.message)), `the busy answer: "${busy[0]?.data.message}"`);
const locks = wp("db", "query", "SELECT COUNT(DISTINCT amount_lock), COUNT(*), MAX(CAST(amount_lock AS DECIMAL(40,0))) FROM wp_nano_unlock_checkouts WHERE amount_lock IS NOT NULL", "--skip-column-names").split(/\s+/);
check(locks[0] === "100" && locks[1] === "100" && locks[2] === STEP(amountB, 99), `the table holds 100 different amounts, the highest Ӿ0.1099 (${locks.join(" ")})`);
wp("db", "query", "DELETE FROM wp_nano_unlock_checkouts WHERE status <> 'paid'");
wp("transient", "delete", "--all");

// ---- 6. The settings page -------------------------------------------------------------------------------
console.log("\n— the settings page");
const admin = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
const ap = await admin.newPage();
await ap.goto(`${SITE}/wp-login.php`);
await ap.fill("#user_login", "admin");
await ap.fill("#user_pass", "password");
await ap.click("#wp-submit");
await ap.waitForURL(/wp-admin/);
await ap.goto(`${SITE}/wp-admin/options-general.php?page=nano-unlock`);
check((await ap.inputValue("#nano_unlock_address")) === SITE_ADDR, "the settings page shows the address");
check((await ap.locator("table.widefat tbody tr").count()) >= 2, "the settings page lists the sales");
if (SHOTS) await ap.screenshot({ path: join(SHOTS, "1-settings.png"), fullPage: true });
await ap.fill("#nano_unlock_address", SITE_ADDR.slice(0, -1) + (SITE_ADDR.endsWith("a") ? "b" : "a"));
await ap.click("#submit");
check((await ap.locator(".notice-error, .error").first().textContent())?.includes("not valid"), "a mistyped address (bad checksum) is refused");
check((await ap.inputValue("#nano_unlock_address")) === SITE_ADDR, "and the old address is kept");
await ap.goto(urlA);
check((await ap.content()).includes(SECRET_A) && (await ap.locator(".nano-unlock--preview").count()) === 1, "an editor sees the paid part, marked as a preview");

// A test node without the test helper: a red notice, and no checkout can start.
wp("option", "delete", "nano_unlock_e2e");
await ap.goto(`${SITE}/wp-admin/options-general.php?page=nano-unlock`);
check((await ap.locator(".notice-error").first().textContent())?.includes("Test node: real payments will not be seen"), "a test node shows a red notice on the settings page");
if (SHOTS) await ap.screenshot({ path: join(SHOTS, "6-test-node-notice.png") });
const refused = await api("checkout", { offer: /data-nano-unlock-offer="([^"]+)"/.exec(await (await fetch(urlB)).text())?.[1] ?? offer });
check(refused.status === 503 || refused.status === 400, `a checkout against a test node is refused (${refused.status})`);
const lockedTest = await (await fetch(urlA)).text();
check(!lockedTest.includes("data-nano-unlock-offer=") && !lockedTest.includes("nano-unlock__button"), "readers get no Unlock button while a test node is set");
wp("option", "update", "nano_unlock_e2e", "1");
// ---- 7. The plugin turned off, then on again ----------------------------------------------------------
console.log("\n— the plugin turned off");
check(!wp("post", "get", postA, "--field=post_content").includes(SECRET_A) && !wp("post", "get", postB, "--field=post_content").includes(SECRET_B), "the posts' stored content holds no paid text");
wp("plugin", "deactivate", "nano-unlock");
const off = [await page$(urlA), await page$(urlB), await page$(`${SITE}/?feed=rss2`), await page$(`${SITE}/?rest_route=/wp/v2/posts/${postA}`), await page$(`${SITE}/?rest_route=/wp/v2/posts/${postB}`), await page$(`${SITE}/?s=writer`), await page$(`${SITE}/?s=story`)];
check(off.every((h) => !h.includes(SECRET_A) && !h.includes(SECRET_B)), "turned off: no paid text in the pages, the feed, the REST API or search");
check(off[0].includes("[nano_unlock") && !off[1].includes("nano-unlock"), "turned off: the shortcode shows only its tag, and the block shows nothing");
const offFriend = await browser.newContext();
const ofp = await offFriend.newPage();
await ofp.goto(urlA);
check(!(await ofp.content()).includes(SECRET_A), "turned off: a browser sees no paid text either");
await offFriend.close();
wp("plugin", "activate", "nano-unlock");
console.log("\n— the plugin on again");
await page.goto(urlA);
check((await page.content()).includes(SECRET_A), "on again: the buyer's receipt still unlocks the part");
const again = await browser.newContext();
const agp = await again.newPage();
await agp.goto(urlA);
check(!(await agp.content()).includes(SECRET_A) && (await agp.locator(".nano-unlock__button").count()) === 1, "on again: a new reader sees the paywall and the Unlock button");
const checkoutAgain = agp.waitForResponse((r) => r.url().includes("nano-unlock/v1/checkout"));
await agp.locator(".nano-unlock__button").click();
const amountAgain = (await (await checkoutAgain).json()).amount ?? "";
await agp.locator(".nano-unlock__card").waitFor();
await mock("/__pay", { to: SITE_ADDR, amount: amountAgain, from: BUYER2 });
await agp.waitForURL(/nano_unlocked=/, { timeout: 15000 });
check((await agp.content()).includes(SECRET_A), "on again: a new payment unlocks it");
await again.close();

// The block in the editor: it loads, with no "unsupported block" warning and no script errors.
const errors = [];
ap.on("pageerror", (e) => errors.push(e.message));
await ap.goto(`${SITE}/wp-admin/post.php?post=${postB}&action=edit`);
const canvas = ap.frameLocator('iframe[name="editor-canvas"]');
await canvas.locator(".wp-block-nano-unlock-paywall").waitFor({ timeout: 60000 });
await ap.keyboard.press("Escape");
// A new site shows the editor's welcome guide once; Escape may already have closed it.
const guide = ap.locator(".components-modal__screen-overlay button[aria-label='Close']");
if (await guide.count()) await guide.first().click({ timeout: 5000 }).catch(() => {});
check((await canvas.locator(".nano-unlock-editor__label").textContent())?.includes("$0.05"), "the editor shows the block with its price");
check((await canvas.locator(".wp-block-nano-unlock-paywall").innerText()).includes("the end of a story"), "the editor shows the stored paid part inside the block");
check((await canvas.locator(".block-editor-warning").count()) === 0 && errors.length === 0, `no block warning and no script errors in the editor (${errors.join("; ")})`);
await canvas.locator(".wp-block-nano-unlock-paywall p").last().click();
await ap.waitForTimeout(800);
if (SHOTS) await ap.screenshot({ path: join(SHOTS, "5-block-editor.png") });
await admin.close();

await browser.close();
console.log(`\n${failures ? `${failures} FAILED` : "all passed"}`);
process.exit(failures ? 1 : 0);
