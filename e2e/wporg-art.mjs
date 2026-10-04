// The WordPress.org banner (772x250, 1544x500) and icon (128x128, 256x256) in .wordpress-org/,
// drawn from the plugin's own look (the Ӿ mark on Nano teal, the locked box, the Unlock button).
//
//   node e2e/wporg-art.mjs
import { mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "@playwright/test";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const OUT = join(ROOT, ".wordpress-org");
mkdirSync(OUT, { recursive: true });

const TEAL = "#1f9d8a";
const DARK = "#0d3b35";
const FONT = `-apple-system, "Segoe UI", "Helvetica Neue", Arial, sans-serif`;

// The icon: the Ӿ mark in a teal rounded square, a small padlock at its corner.
const icon = `<!doctype html><html><head><style>
  html, body { margin: 0; width: 256px; height: 256px; background: transparent; }
  .tile { position: absolute; inset: 0; border-radius: 56px; background: linear-gradient(145deg, #27b39d, ${TEAL} 55%, #157a6c); }
  .mark { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; font: 700 150px/1 ${FONT}; padding-bottom: 8px; }
  .lock { position: absolute; right: 26px; bottom: 26px; width: 64px; height: 64px; border-radius: 50%; background: ${DARK}; display: grid; place-items: center; box-shadow: 0 0 0 6px #ffffff; }
</style></head><body><div class="tile"></div><div class="mark">Ӿ</div>
<div class="lock"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
</body></html>`;

// The banner: the name and the promise on the left, a locked box with its Unlock button on the right.
const banner = `<!doctype html><html><head><style>
  html, body { margin: 0; width: 772px; height: 250px; overflow: hidden; }
  body { background: radial-gradient(120% 140% at 85% 20%, #1b8a7a 0%, ${DARK} 55%, #082724 100%); color: #fff; font-family: ${FONT}; position: relative; }
  .grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px); background-size: 26px 26px; }
  .text { position: absolute; left: 44px; top: 40px; width: 360px; }
  .name { display: flex; align-items: center; gap: 14px; font: 700 40px/1 ${FONT}; letter-spacing: -0.5px; }
  .mark { width: 48px; height: 48px; border-radius: 12px; background: ${TEAL}; display: grid; place-items: center; font: 700 30px/1 ${FONT}; }
  .tag { margin-top: 16px; font: 500 20px/1.35 ${FONT}; color: #d6f3ee; }
  .tag b { color: #7ee8d4; font-weight: 700; }
  .box { position: absolute; right: 36px; top: 36px; width: 290px; padding: 18px 20px; border-radius: 14px; background: #f4fbf9; color: #13302c; box-shadow: 0 18px 40px rgba(0,0,0,.35); }
  .box .head { display: flex; align-items: center; gap: 10px; font: 600 17px/1.2 ${FONT}; }
  .box .dot { width: 26px; height: 26px; border-radius: 50%; background: ${TEAL}; color: #fff; display: grid; place-items: center; font: 700 15px/1 ${FONT}; }
  .box p { margin: 10px 0 14px; font: 400 14px/1.4 ${FONT}; color: #35514c; }
  .box .btn { display: inline-block; padding: 10px 18px; border-radius: 999px; background: ${TEAL}; color: #fff; font: 700 15px/1 ${FONT}; }
  .box .lines i { display: block; height: 9px; border-radius: 5px; background: #d9e6e3; margin-top: 8px; }
</style></head><body><div class="grid"></div>
<div class="text"><div class="name"><span class="mark">Ӿ</span>Nano Unlock</div>
<div class="tag">Sell part of a post for <b>1¢</b>.<br/>Readers pay your wallet.<br/><b>Keep every cent.</b></div></div>
<div class="box"><div class="head"><span class="dot">Ӿ</span>The rest of this is paid</div>
<p>Pay $0.05 in Nano (XNO) to read it. No account and no card.</p><span class="btn">Unlock for $0.05</span>
<div class="lines"><i style="width:92%"></i><i style="width:74%"></i></div></div>
</body></html>`;

const browser = await chromium.launch();
try {
  for (const [html, w, h, sizes, name] of [
    [icon, 256, 256, [128, 256], "icon"],
    [banner, 772, 250, [1, 2], "banner"],
  ]) {
    for (const s of sizes) {
      const scale = name === "icon" ? s / 256 : s;
      const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: scale });
      await page.setContent(html);
      const file = name === "icon" ? `icon-${s}x${s}.png` : `banner-${w * s}x${h * s}.png`;
      await page.screenshot({ path: join(OUT, file), omitBackground: name === "icon" });
      await page.close();
      console.log(file);
    }
  }
} finally {
  await browser.close();
}
