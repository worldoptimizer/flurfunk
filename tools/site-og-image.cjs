#!/usr/bin/env node
/*
 * Renders the website's Open Graph image (docs/assets/og.png, 1200x630).
 *
 * It takes the hero app mock from the built home page, so build and serve the
 * site first, for example:
 *
 *   jekyll build -s docs -d /tmp/flurfunk-site
 *   mkdir -p /tmp/flurfunk-serve && ln -sfn /tmp/flurfunk-site /tmp/flurfunk-serve/flurfunk
 *   (cd /tmp/flurfunk-serve && python3 -m http.server 8765)
 *   PLAYWRIGHT=tests/e2e/node_modules/playwright node tools/site-og-image.cjs http://127.0.0.1:8765/flurfunk/
 */
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT ? path.resolve(process.env.PLAYWRIGHT) : 'playwright');

const base = process.argv[2] || 'http://127.0.0.1:8765/flurfunk/';
const out = path.join(__dirname, '..', 'docs', 'assets', 'og.png');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, colorScheme: 'dark', reducedMotion: 'reduce' });
  await page.goto(base, { waitUntil: 'networkidle' });
  const app = await page.locator('.app').evaluate((el) => el.outerHTML);
  const logo = await page.locator('.brand .logo-mark').evaluate((el) => el.outerHTML);

  await page.setContent(`<!doctype html><html data-theme="dark"><head>
    <base href="${base}">
    <link rel="stylesheet" href="assets/site.css">
    <link rel="stylesheet" href="assets/home.css">
    <style>
      html, body { margin: 0; width: 1200px; height: 630px; overflow: hidden; }
      body {
        position: relative;
        background: radial-gradient(40rem 26rem at 85% 70%, rgb(255 201 60 / 9%), transparent 70%), #121415;
      }
      .og-text { position: absolute; left: 72px; top: 92px; width: 430px; }
      .og-text .logo-mark { width: 64px; height: 64px; }
      .og-text .logo-word { font-size: 46px; }
      .og-text p { margin: 34px 0 0; font-family: var(--font-serif); font-size: 34px; line-height: 1.25; color: #f3f0ea; }
      .og-text small { display: block; margin-top: 28px; color: #a7adab; font: 600 17px/1.4 var(--font-sans); letter-spacing: 0.08em; text-transform: uppercase; }
      .og-app { position: absolute; left: 560px; top: 120px; }
      .og-app .app { font-size: 12.5px; margin: 0; }
    </style></head><body>
    <div class="og-text">
      <span class="logo">${logo}<span class="logo-word">Flurfunk</span></span>
      <p>Team conversations inside WordPress. Channels, threads and tasks next to your posts.</p>
      <small>Free &middot; GPL-2.0-or-later</small>
    </div>
    <div class="og-app">${app}</div>
  </body></html>`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: out });
  await browser.close();
  console.log('wrote ' + out);
})();
