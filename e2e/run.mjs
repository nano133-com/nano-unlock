// End-to-end test of Nano Unlock in wp-env, against a MOCK node (no real network, no real money).
//
//   npx wp-env start && node e2e/run.mjs [--shots <dir>]
//
// It starts the mock node (e2e/mock-node.php) on port 8787, points the plugin at it
// (http://host.docker.internal:8787 from inside the container), creates two sample posts
// (a shortcode and a block), and walks through the reader's flows with Playwright.

import { execFileSync, spawn } from "node:child_process";
import { createHash } from "node:crypto";
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
await box.locator(".nano-unlock__button").click();
await page.locator(".nano-unlock__pay").waitFor();
const uri = await page.locator(".nano-unlock__open").getAttribute("href");
const m = /^nano:(nano_[a-z0-9]+)\?amount=(\d+)$/.exec(uri ?? "");
check(!!m && m[1] === SITE_ADDR, `the checkout asks for a payment to the site's address (${uri?.slice(0, 40)}…)`);
const amountA = m?.[2] ?? "";
check(amountA.startsWith("2") && amountA.length === 29, `$0.01 at $0.50/XNO is 0.02 XNO plus a unique tail (${amountA})`);
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

// ---- 3. Sharing and forging ---------------------------------------------------------------------------
console.log("\n— sharing and forging");
const cookies = await ctx.cookies(SITE);
const receipt = cookies.find((c) => c.name.startsWith("nano_unlock_r_"));
check(!!receipt && receipt.httpOnly, "the receipt is an httpOnly cookie");
const friend = await browser.newContext();
const fp = await friend.newPage();
await fp.goto(urlA);
check(!(await fp.content()).includes(SECRET_A), "a shared link shows the paywall to a friend");
await friend.addCookies([{ ...receipt, value: receipt.value.slice(0, -2) + (receipt.value.endsWith("AA") ? "BB" : "AA") }]);
await fp.goto(urlA);
check(!(await fp.content()).includes(SECRET_A), "a forged receipt unlocks nothing");
// Post A's receipt, sent under post B's cookie name: it names item A, so B stays locked.
const nameB = "nano_unlock_r_" + createHash("sha256").update(`post:${postB}:0`).digest("hex").slice(0, 16);
await friend.clearCookies();
await friend.addCookies([{ ...receipt, name: nameB }]);
await fp.goto(urlB);
check(!(await fp.content()).includes(SECRET_B), "a receipt for one post doesn't unlock another");
await friend.close();

// ---- 4. The owner's wallet already received it (history path), on the block post -----------------------
console.log("\n— the wallet received the payment before the check (history)");
await page.goto(urlB);
await page.locator(".nano-unlock__button").click();
await page.locator(".nano-unlock__pay").waitFor();
const amountB = /amount=(\d+)/.exec((await page.locator(".nano-unlock__open").getAttribute("href")) ?? "")?.[1] ?? "";
check(amountB.startsWith("1") && amountB.length === 30, `$0.05 at $0.50/XNO is 0.1 XNO plus a tail (${amountB})`);
const payB = await mock("/__pay", { to: SITE_ADDR, amount: amountB, from: BUYER2 });
await mock("/__receive", { hash: payB.hash });
await page.waitForURL(/nano_unlocked=/, { timeout: 10000 });
check((await page.content()).includes(SECRET_B), "a payment found in the history unlocks the block");

// ---- 5. The REST API's own rules ------------------------------------------------------------------------
console.log("\n— the REST rules");
const fresh = await (await fetch(urlA)).text();
const nonce = /"nonce":"([0-9a-f]+)"/.exec(fresh)?.[1];
const offer = /data-nano-unlock-offer="([^"]+)"/.exec(fresh)?.[1];
const api = async (path, body, { withNonce = true, cookie = "" } = {}) => {
  const r = await fetch(`${SITE}/?rest_route=/nano-unlock/v1/${path}`, {
    method: "POST",
    headers: { "content-type": "application/json", ...(withNonce ? { "x-wp-nonce": nonce } : {}), ...(cookie ? { cookie } : {}) },
    body: JSON.stringify(body),
  });
  return { status: r.status, data: await r.json(), setCookie: r.headers.getSetCookie() };
};
check((await api("checkout", { offer }, { withNonce: false })).status === 403, "no nonce: 403");
const tampered = offer.replace(/^([A-Za-z0-9_-]{10})./, (s, a) => a + (s.endsWith("A") ? "B" : "A"));
check((await api("checkout", { offer: tampered })).status === 400, "a changed offer (another price or post): 400");
const c1 = await api("checkout", { offer });
check(c1.status === 200 && c1.data.amount !== amountA, "a new checkout gets its own amount");
const starterCookie = c1.setCookie.find((c) => c.startsWith("nano_unlock_starter="))?.split(";")[0];
check(!!starterCookie, "the checkout sets this browser's starter cookie");

// One payment unlocks one checkout, once: a second checkout that asks for the SAME amount (forced here,
// the plugin never does that) is refused when that payment is already used.
wp("db", "query", `INSERT INTO wp_nano_unlock_checkouts (id,item,post_id,usd,amount,amount_lock,address,starter,status,created_at,expires_at) VALUES ('${"f".repeat(24)}','post:${postA}:0',${postA},0.01,'${amountA}',NULL,'${SITE_ADDR}','${"0".repeat(64)}','waiting',UNIX_TIMESTAMP()-60,UNIX_TIMESTAMP()+900)`);
const replay = await api("claim", { id: "f".repeat(24) });
check(replay.status === 409, `a used payment can't pay a second checkout (${replay.status} ${replay.data.message ?? ""})`);

// Another browser (no starter cookie) asking about a paid checkout gets no receipt.
const paidId = wp("db", "query", `SELECT id FROM wp_nano_unlock_checkouts WHERE status='paid' AND amount='${amountA}'`, "--skip-column-names");
const other = await api("claim", { id: paidId });
check(other.status === 200 && other.data.paid === true && !other.setCookie.some((c) => c.startsWith("nano_unlock_r_")), "another browser learns 'paid' but gets no receipt");

// The node is down: the claim fails closed with 503.
await mock("/__down", { down: true });
wp("transient", "delete", "--all");
const down = await api("claim", { id: c1.data.id }, { cookie: starterCookie });
check(down.status === 503, `node down: the claim answers 503, nothing unlocks (${down.status})`);
await mock("/__down", { down: false });

// Node load: many fast polls on one checkout share one scan every 3 s.
await mock("/__reset", {});
wp("transient", "delete", "--all");
const tl = Date.now();
let polls = 0;
while (Date.now() - tl < 6000) {
  await api("claim", { id: c1.data.id }, { cookie: starterCookie });
  polls++;
  await sleep(100);
}
const calls = await mock("/__calls");
check((calls.receivable ?? 0) <= 3 && (calls.account_history ?? 0) <= 3, `${polls} polls in 6 s made ${calls.receivable ?? 0} receivable + ${calls.account_history ?? 0} history calls`);

// Rate limit on checkouts: 20 per 10 minutes per address.
wp("transient", "delete", "--all");
let firstRefused = 0;
for (let i = 1; i <= 22 && !firstRefused; i++) if ((await api("checkout", { offer })).status === 429) firstRefused = i;
check(firstRefused === 21, `checkouts from one address are refused from #${firstRefused} (limit 20 per 10 minutes)`);
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
// The block in the editor: it loads, with no "unsupported block" warning and no script errors.
const errors = [];
ap.on("pageerror", (e) => errors.push(e.message));
await ap.goto(`${SITE}/wp-admin/post.php?post=${postB}&action=edit`);
const canvas = ap.frameLocator('iframe[name="editor-canvas"]');
await canvas.locator(".wp-block-nano-unlock-paywall").waitFor({ timeout: 60000 });
await ap.keyboard.press("Escape");
const guide = ap.locator(".components-modal__screen-overlay button[aria-label='Close']");
if (await guide.count()) await guide.first().click();
check((await canvas.locator(".nano-unlock-editor__label").textContent())?.includes("$0.05"), "the editor shows the block with its price");
check((await canvas.locator(".block-editor-warning").count()) === 0 && errors.length === 0, `no block warning and no script errors in the editor (${errors.join("; ")})`);
await canvas.locator(".wp-block-nano-unlock-paywall p").last().click();
await ap.waitForTimeout(800);
if (SHOTS) await ap.screenshot({ path: join(SHOTS, "5-block-editor.png") });
await admin.close();

await browser.close();
console.log(`\n${failures ? `${failures} FAILED` : "all passed"}`);
process.exit(failures ? 1 : 0);
