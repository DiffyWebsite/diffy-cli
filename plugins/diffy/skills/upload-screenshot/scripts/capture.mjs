#!/usr/bin/env node
/**
 * capture.mjs — headless screenshot capture for the diffy plugin (used by the `upload-screenshot`
 * and `visual-diff` skills).
 *
 * Renders a running UI at a set of breakpoint widths and writes:
 *   - one full-page PNG per (page × breakpoint)  ->  <out>/<slug>-<width>.png
 *   - <out>/upload.json                          ->  ready for `diffy screenshot:create-uploaded`
 *
 * The upload.json uses index-aligned arrays (urls / breakpoints / files) so a single page can
 * appear at multiple breakpoints, exactly as Diffy's Screenshot::createUpload expects.
 *
 * Before each full-page capture the script "stabilizes" the page: it scrolls top->bottom to trigger
 * lazy-loaded content and on-scroll reveal animations (images with loading="lazy" /
 * IntersectionObserver / hydrated islands), waits for network to go idle, then waits for web fonts
 * and image decode, plus a short reveal-settle. This is the real fix for "content didn't load fully"
 * — a bigger --delay alone never helps because lazy loading is scroll-triggered, not time-triggered.
 *
 * Usage:
 *   node capture.mjs --url=http://localhost:3000 --pages=/,/about \
 *                    --breakpoints=375,1280,1920 --out=<output-dir> \
 *                    --name="baseline-2026-07-06" \
 *                    [--delay=500] [--wait-selector="#app"] [--height=900] \
 *                    [--settle=15000] [--no-scroll]
 */

import { mkdir, writeFile, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

// ---- arg parsing -----------------------------------------------------------
function parseArgs(argv) {
  const args = {};
  for (const raw of argv.slice(2)) {
    const m = raw.match(/^--([^=]+)(?:=(.*))?$/);
    if (!m) continue;
    args[m[1]] = m[2] === undefined ? true : m[2];
  }
  return args;
}

const args = parseArgs(process.argv);

function required(name) {
  if (args[name] === undefined || args[name] === '' || args[name] === true) {
    console.error(`Error: --${name} is required.`);
    process.exit(2);
  }
  return String(args[name]);
}

const appUrl = required('url').replace(/\/+$/, '');
const pages = required('pages').split(',').map((s) => s.trim()).filter(Boolean);
const breakpoints = required('breakpoints')
  .split(',')
  .map((s) => parseInt(s.trim(), 10))
  .filter((n) => Number.isInteger(n) && n > 0);
const outDir = path.resolve(required('out'));
const name = args.name && args.name !== true ? String(args.name) : `snapshot-${Date.now()}`;
const delayMs = args.delay ? parseInt(String(args.delay), 10) : 0;
const waitSelector = args['wait-selector'] && args['wait-selector'] !== true ? String(args['wait-selector']) : null;
const viewportHeight = args.height ? parseInt(String(args.height), 10) : 900;
// Max time (ms) to wait for fonts and for images to finish, each bounded independently.
const settleMs = args.settle ? parseInt(String(args.settle), 10) : 15000;
// Auto-scroll top->bottom to trigger lazy content. Disable with --no-scroll for infinite-scroll pages.
const autoScroll = !args['no-scroll'];

if (pages.length === 0) {
  console.error('Error: no valid --pages provided.');
  process.exit(2);
}
if (breakpoints.length === 0) {
  console.error('Error: no valid --breakpoints provided.');
  process.exit(2);
}

// ---- playwright import (friendly error if missing) -------------------------
// This script lives in the plugin cache directory, which has no node_modules of its own.
// A bare `import('playwright')` resolves relative to THIS file, so it fails whenever Playwright
// is installed as a project dependency (the common case the skill itself recommends) rather than
// globally. Resolve it from the current working directory (the project the command was run in)
// first, then fall back to default resolution for a global/plugin-local install.
async function importChromium(specifier) {
  const mod = await import(specifier);
  return mod.chromium ?? mod.default?.chromium ?? null;
}

async function loadChromium() {
  // 1. Resolve from the project's node_modules (based on cwd), not the plugin dir.
  try {
    const requireFromCwd = createRequire(path.join(process.cwd(), 'package.json'));
    const entry = requireFromCwd.resolve('playwright');
    const found = await importChromium(pathToFileURL(entry).href);
    if (found) return found;
  } catch {
    // fall through to default resolution
  }
  // 2. Fall back to normal resolution (global install, or playwright next to this script).
  try {
    const found = await importChromium('playwright');
    if (found) return found;
  } catch {
    // handled below
  }
  return null;
}

const chromium = await loadChromium();
if (!chromium) {
  console.error(
    'Error: Playwright is not installed.\n' +
      'Install it once in this project (or globally):\n' +
      '  npm i -D playwright && npx playwright install chromium'
  );
  process.exit(3);
}

// slug: turn a URL path into a filename-safe token. "/" -> "home".
function slugify(p) {
  const cleaned = p.replace(/^\/+|\/+$/g, '');
  if (cleaned === '') return 'home';
  return cleaned.replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
}

// stabilize: force lazy content to load, then wait for network/fonts/images before a full-page capture.
async function stabilize(page, { scroll, settle }) {
  // 1. Scroll the full page height so IntersectionObserver / loading="lazy" content and
  //    on-scroll reveal animations trigger, then return to the top. We scroll to ABSOLUTE
  //    offsets (re-reading the live document height each tick so growth from lazy content is
  //    followed) and dwell briefly per step so reveals actually fire. Using scrollBy with a
  //    running counter can terminate early on a still-short page before content expands it,
  //    which leaves whole sections blank. Bounded so infinite-scroll pages can't loop forever.
  if (scroll) {
    await page.evaluate(async () => {
      await new Promise((resolve) => {
        const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
        const cap = 200000; // safety cap in px for infinite-scroll pages
        let y = 0;
        const timer = setInterval(() => {
          window.scrollTo(0, y);
          y += step;
          const bottom = Math.max(
            document.body.scrollHeight,
            document.documentElement.scrollHeight
          );
          if (y >= bottom || y >= cap) {
            clearInterval(timer);
            window.scrollTo(0, 0);
            resolve();
          }
        }, 150);
      });
    });
  }

  // 2. Let network triggered by the scroll (island hydration, lazy images) go idle.
  try {
    await page.waitForLoadState('networkidle', { timeout: settle });
  } catch {
    // best-effort: continue even if the network never fully quiets
  }

  // 3. Wait for web fonts so text isn't captured mid-swap (bounded by `settle`).
  await page.evaluate((ms) => {
    if (!document.fonts) return undefined;
    return Promise.race([
      document.fonts.ready.then(() => {}),
      new Promise((r) => setTimeout(r, ms)),
    ]);
  }, settle);

  // 4. Force every image to finish decoding, or error out (bounded by `settle`).
  await page.evaluate((ms) => {
    const pending = Array.from(document.images)
      .filter((img) => !(img.complete && img.naturalWidth > 0))
      .map((img) => img.decode().catch(() => {}));
    if (pending.length === 0) return undefined;
    return Promise.race([
      Promise.all(pending).then(() => {}),
      new Promise((r) => setTimeout(r, ms)),
    ]);
  }, settle);

  // 5. Short reveal-settle so scroll-triggered animations land, even when no --delay is given.
  await page.waitForTimeout(500);
}

// ---- capture ---------------------------------------------------------------
async function main() {
  // Fresh output dir so stale PNGs never leak into an upload set.
  if (existsSync(outDir)) await rm(outDir, { recursive: true, force: true });
  await mkdir(outDir, { recursive: true });

  const urls = [];
  const bps = [];
  const files = [];

  const browser = await chromium.launch();
  try {
    const context = await browser.newContext();
    for (const pageEntry of pages) {
      // Page entries may come from `diffy project:get` as absolute URLs
      // (e.g. https://prod.example.com/faq) or as plain paths (/faq). Either way we
      // render against the LOCAL app: take just the path+query and re-base onto --url.
      let urlPath;
      if (/^https?:\/\//i.test(pageEntry)) {
        const u = new URL(pageEntry);
        urlPath = `${u.pathname}${u.search}`;
      } else {
        urlPath = pageEntry.startsWith('/') ? pageEntry : `/${pageEntry}`;
      }
      const slug = slugify(urlPath);
      const targetUrl = `${appUrl}${urlPath}`;

      for (const width of breakpoints) {
        const page = await context.newPage();
        await page.setViewportSize({ width, height: viewportHeight });
        try {
          await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: 60000 });
        } catch (err) {
          console.error(`Warning: networkidle wait timed out for ${targetUrl} @ ${width}px — continuing.`);
        }
        if (waitSelector) {
          try {
            await page.waitForSelector(waitSelector, { timeout: 15000 });
          } catch {
            console.error(`Warning: selector "${waitSelector}" not found on ${targetUrl} @ ${width}px.`);
          }
        }
        // Trigger lazy content and wait for fonts/images before the full-page capture.
        try {
          await stabilize(page, { scroll: autoScroll, settle: settleMs });
        } catch (err) {
          console.error(`Warning: stabilize step failed for ${targetUrl} @ ${width}px — continuing.`);
        }
        // Optional final settle for animations/transitions after content has loaded.
        if (delayMs > 0) await page.waitForTimeout(delayMs);

        const filename = `${slug}-${width}.png`;
        const filepath = path.join(outDir, filename);
        await page.screenshot({ path: filepath, fullPage: true });
        await page.close();

        urls.push(urlPath);
        bps.push(width);
        files.push(filepath);
        console.error(`captured ${urlPath} @ ${width}px -> ${filename}`);
      }
    }
  } finally {
    await browser.close();
  }

  const upload = { snapshotName: name, urls, breakpoints: bps, files };
  const uploadPath = path.join(outDir, 'upload.json');
  await writeFile(uploadPath, JSON.stringify(upload, null, 2));

  // Machine-readable result on stdout (stderr carries progress logs).
  console.log(uploadPath);
}

main().catch((err) => {
  console.error(err?.stack || String(err));
  process.exit(1);
});
