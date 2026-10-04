// The WordPress.org screenshots (.wordpress-org/screenshot-N.png), from wp-env. The settings keep
// the real default node, so the pages look as on a real site (no test-mode notice), but the
// helper e2e/mu-plugins/nano-unlock-shots.php sends those node calls to the MOCK node
// (e2e/mock-node.php): no real network, no real money, a fixed rate of $1.00 per XNO.
//
//   npm run start && node e2e/wporg-shots.mjs
//
// The payee is the nano133 Unlock wallet, a real address with a key (the same as blueprint.json),
// never a keyless test address: a reader may scan the QR code in a published screenshot, and a
// payment there must reach a wallet that someone holds. The site's own settings are put back at
// the end, however it ends.
import { execFileSync, spawn } from "node:child_process";
import { copyFileSync, mkdirSync, readdirSync, rmSync } from "node:fs";
import { homedir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "@playwright/test";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const OUT = join(ROOT, ".wordpress-org");
const SITE = "http://localhost:8888";
const MOCK = "http://localhost:8787";
const PAYEE = "nano_3khpd7q3dzrbcae18bmpidbcrj5mn1n1jnkidup7acocia1b966y88czr5if";
const READER = execFileSync("php", [join(ROOT, "e2e/address.php"), "reader"]).toString().trim();
mkdirSync(OUT, { recursive: true });
// The helper goes into this checkout's wp-env site (as e2e/sync.sh copies the plugin), and out again at the end.
const ENV = join(process.env.WP_ENV_HOME ?? join(homedir(), ".wp-env"));
const SITE_DIR = readdirSync(ENV).find((d) => d.includes(`${ROOT.split("/").pop()}-`));
if (!SITE_DIR) throw new Error("wp-env is not installed here; run npm run start first");
const HELPER = join(ENV, SITE_DIR, "WordPress/wp-content/mu-plugins/nano-unlock-shots.php");
mkdirSync(dirname(HELPER), { recursive: true });
copyFileSync(join(ROOT, "e2e/mu-plugins/nano-unlock-shots.php"), HELPER);

const wp = (...args) => execFileSync("npx", ["wp-env", "run", "cli", "--", "wp", ...args], { cwd: ROOT, stdio: ["ignore", "pipe", "ignore"] }).toString().trim();
const mock = async (path, body) => (await fetch(MOCK + path, { method: body ? "POST" : "GET", body: body ? JSON.stringify(body) : undefined })).json();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

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

const title = wp("option", "get", "blogname");
let saved = null;
try {
  saved = wp("option", "get", "nano_unlock_settings", "--format=json");
} catch {
  saved = null;
}
const posts = [];
process.on("exit", () => {
  try {
    for (const id of posts) wp("post", "delete", id, "--force");
    wp("option", "delete", "nano_unlock_shots");
    wp("option", "update", "blogname", title);
    rmSync(HELPER, { force: true });
    if (saved) wp("option", "update", "nano_unlock_settings", saved, "--format=json");
    else wp("option", "delete", "nano_unlock_settings");
    wp("db", "query", "DELETE FROM wp_nano_unlock_checkouts");
    wp("transient", "delete", "--all");
    console.log("restored the site's own settings");
  } catch (e) {
    console.log(`COULD NOT RESTORE THE SETTINGS: ${e.message}`);
  }
});
process.on("SIGINT", () => process.exit(130));

wp("option", "update", "nano_unlock_settings", JSON.stringify({ address: PAYEE, usd: "0.05", node: "https://node.nano133.com/rpc", node2: "" }), "--format=json");
wp("option", "update", "nano_unlock_shots", "1");
wp("option", "update", "blogname", "Bread Notes");
wp("db", "query", "DELETE FROM wp_nano_unlock_checkouts");
wp("transient", "delete", "--all");

const article = `<!-- wp:paragraph -->
<p>Sourdough needs three things: flour, water and time. The starter does the rest, if you feed it on a schedule it can count on. Here is the routine that took my loaves from flat to tall.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Day one: mix 50 g of whole-wheat flour with 50 g of water, cover it loosely, and leave it somewhere warm.</p>
<!-- /wp:paragraph -->

<!-- wp:nano-unlock/paywall {"price":"0.05"} -->
<!-- wp:heading {"level":3} -->
<h3>The full schedule</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul><li>Days two to five: discard half, then feed 50 g of flour and 50 g of water every 24 hours.</li><li>Day six: feed twice, 12 hours apart. It should double within six hours.</li><li>Day seven: bake. Use it at its peak, when the top is domed and full of bubbles.</li></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>The one rule that matters: same flour, same water, same hour. A starter likes a routine more than a recipe.</p>
<!-- /wp:paragraph -->
<!-- /wp:nano-unlock/paywall -->`;
const id = wp("post", "create", "--post_type=post", "--post_status=publish", "--post_author=1", "--post_title=A sourdough starter in seven days", `--post_content=${article}`, "--porcelain");
posts.push(id);
const url = `${SITE}/?p=${id}`;

const browser = await chromium.launch();
try {
  // 1. A reader sees the free part, the price and the Unlock button.
  const reader = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const page = await reader.newPage();
  await page.goto(url);
  const box = page.locator(".nano-unlock--locked");
  await box.scrollIntoViewIfNeeded();
  await page.evaluate(() => window.scrollBy(0, -260));
  await page.screenshot({ path: join(OUT, "screenshot-1.png") });

  // 2. The checkout: QR code, the exact amount with Copy, Open in wallet.
  await box.locator(".nano-unlock__button").click();
  await page.locator(".nano-unlock__pay").waitFor();
  const uri = await page.locator(".nano-unlock__open").getAttribute("href");
  const m = /^nano:(nano_[a-z0-9]+)\?amount=(\d+)$/.exec(uri ?? "");
  if (await page.locator(".nano-unlock__test").count()) throw new Error("the page shows the test-mode notice");
  if (!m || m[1] !== PAYEE) throw new Error(`the checkout's payee is not the Unlock wallet: ${uri}`);
  await page.waitForTimeout(700);
  await page.locator(".nano-unlock").first().screenshot({ path: join(OUT, "screenshot-2.png") });

  // 3. Paid and confirmed (on the mock node): the part appears.
  const pay = await mock("/__pay", { to: PAYEE, amount: m[2], from: READER, confirmed: false });
  await mock("/__confirm", { hash: pay.hash });
  await page.waitForURL(/nano_unlocked=/, { timeout: 15000 });
  await page.locator(".nano-unlock--paid").waitFor();
  // The page reloads scrolled to the part: crop by the document's own coordinates.
  const paid = await page.locator(".nano-unlock--paid").evaluate((e) => {
    const r = e.getBoundingClientRect();
    return { y: r.top + window.scrollY, h: r.height };
  });
  const heading = await page.locator("h1").first().evaluate((e) => e.getBoundingClientRect().top + window.scrollY);
  const top = Math.max(0, heading - 40);
  await page.screenshot({ path: join(OUT, "screenshot-3.png"), fullPage: true, clip: { x: 0, y: top, width: 1200, height: paid.y + paid.h + 60 - top } });
  await reader.close();

  // 4. The block in the editor, with its price.
  const admin = await browser.newContext({ viewport: { width: 1400, height: 900 } });
  const ap = await admin.newPage();
  await ap.goto(`${SITE}/wp-login.php`);
  await ap.fill("#user_login", "admin");
  await ap.fill("#user_pass", "password");
  await ap.click("#wp-submit");
  await ap.waitForURL(/wp-admin/);
  await ap.goto(`${SITE}/wp-admin/post.php?post=${id}&action=edit`);
  const canvas = ap.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator(".wp-block-nano-unlock-paywall").waitFor({ timeout: 60000 });
  await ap.keyboard.press("Escape");
  const guide = ap.locator(".components-modal__screen-overlay button[aria-label='Close']");
  if (await guide.count()) await guide.first().click();
  await canvas.locator(".wp-block-nano-unlock-paywall").click({ position: { x: 20, y: 12 } });
  await ap.waitForTimeout(1000);
  await ap.screenshot({ path: join(OUT, "screenshot-4.png") });

  // 5. The settings page, with the sale.
  await ap.goto(`${SITE}/wp-admin/options-general.php?page=nano-unlock`);
  await ap.screenshot({ path: join(OUT, "screenshot-5.png"), fullPage: true });
  await admin.close();
  console.log(`screenshots in ${OUT}`);
} finally {
  await browser.close();
}
// The mock node's process keeps the event loop alive: end here (the exit handler restores the site).
process.exit(0);
