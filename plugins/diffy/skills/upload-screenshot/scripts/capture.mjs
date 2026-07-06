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
 * Usage:
 *   node capture.mjs --url=http://localhost:3000 --pages=/,/about \
 *                    --breakpoints=375,1280,1920 --out=<output-dir> \
 *                    --name="baseline-2026-07-06" [--delay=500] [--wait-selector="#app"] [--height=900]
 */

import { mkdir, writeFile, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';

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

if (pages.length === 0) {
  console.error('Error: no valid --pages provided.');
  process.exit(2);
}
if (breakpoints.length === 0) {
  console.error('Error: no valid --breakpoints provided.');
  process.exit(2);
}

// ---- playwright import (friendly error if missing) -------------------------
let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch {
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
