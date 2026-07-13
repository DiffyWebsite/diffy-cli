#!/usr/bin/env node
/**
 * run-worker.mjs — capture a running app locally with the diffy-worker screenshot engine and
 * upload it to Diffy, returning the Diffy screenshot ID.
 *
 * This delegates to the diffy-worker project's own local runner (`diffy-screenshots.js`), which
 * uses the SAME rendering pipeline Diffy runs in production. The worker fetches the project's
 * pages, breakpoints, and advanced settings itself, re-bases each page onto the local --url,
 * captures every page x breakpoint on the host with Playwright, uploads the set to Diffy, and
 * returns a screenshot ID. So there is no local upload.json to build and no `diffy
 * screenshot:create-uploaded` call — this one command replaces the whole capture+upload half.
 *
 * The worker is either a checkout you point at (DIFFY_WORKER_DIR), or one this script provisions
 * for you: `--provision` clones the pinned branch into a cache dir, installs its deps, and fetches
 * Playwright's Chromium. That makes local capture work for a user who has never cloned the worker.
 *
 * Progress is streamed to stderr; the resolved screenshot ID is printed alone on stdout, so
 * capture it with $(...).
 *
 * Usage:
 *   node run-worker.mjs --provision                                  # one-time setup (clone+install)
 *   node run-worker.mjs --check                                      # validate setup only
 *   node run-worker.mjs --project-id=12345 --url=http://localhost:3000 [--name="label"]
 *
 * Worker location is resolved from (first hit wins):
 *   --worker-dir=<path> | $DIFFY_WORKER_DIR | ./diffy-worker | ../diffy-worker | <cache>/diffy-worker
 * Bootstrap source (overridable, mainly for testing):
 *   $DIFFY_WORKER_REPO (default https://github.com/diffywebsite/diffy-worker.git)
 *   $DIFFY_WORKER_REF  (default local-capture)
 * The API key is read from $DIFFY_API_KEY, else $DIFFYCLI_CONFIG, else ~/.diffy-cli/diffy-cli.yaml.
 */

import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';

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
const checkMode = args.check === true || args.check === 'true';
const provisionMode = args.provision === true || args.provision === 'true';

function required(name) {
  if (args[name] === undefined || args[name] === '' || args[name] === true) {
    console.error(`Error: --${name} is required.`);
    process.exit(2);
  }
  return String(args[name]);
}

// ---- bootstrap config ------------------------------------------------------
const CACHE_ROOT = process.env.XDG_CACHE_HOME || path.join(os.homedir(), '.cache');
const MANAGED_DIR = path.join(CACHE_ROOT, 'diffy-local', 'diffy-worker');
const WORKER_REPO = process.env.DIFFY_WORKER_REPO || 'https://github.com/DiffyWebsite/diffy-worker.git';
const WORKER_REF = process.env.DIFFY_WORKER_REF || 'local-capture';

// ---- resolve the worker checkout -------------------------------------------
function resolveWorkerDir() {
  const candidates = [];
  if (args['worker-dir'] && args['worker-dir'] !== true) candidates.push(String(args['worker-dir']));
  if (process.env.DIFFY_WORKER_DIR) candidates.push(process.env.DIFFY_WORKER_DIR);
  candidates.push(path.resolve(process.cwd(), 'diffy-worker'));
  candidates.push(path.resolve(process.cwd(), '..', 'diffy-worker'));
  candidates.push(MANAGED_DIR);
  for (const c of candidates) {
    if (c && existsSync(path.join(c, 'diffy-screenshots.js'))) return path.resolve(c);
  }
  return null;
}

// ---- resolve the API key ---------------------------------------------------
function resolveApiKey() {
  if (process.env.DIFFY_API_KEY) return process.env.DIFFY_API_KEY;
  const cfg = process.env.DIFFYCLI_CONFIG || path.join(os.homedir(), '.diffy-cli', 'diffy-cli.yaml');
  if (!existsSync(cfg)) return null;
  // The config is small YAML written by `diffy auth:login`: a `key: <value>` line.
  const m = readFileSync(cfg, 'utf8').match(/^\s*key:\s*(.+?)\s*$/m);
  if (!m) return null;
  return m[1].replace(/^['"]|['"]$/g, '');
}

// ---- provisioning ----------------------------------------------------------
function sh(cmd, cmdArgs, opts = {}) {
  console.error(`  $ ${cmd} ${cmdArgs.join(' ')}${opts.cwd ? `   (in ${opts.cwd})` : ''}`);
  const r = spawnSync(cmd, cmdArgs, { stdio: 'inherit', ...opts });
  if (r.error) throw new Error(`${cmd} failed to start: ${r.error.message}`);
  if (r.status !== 0) throw new Error(`${cmd} exited with status ${r.status}`);
}

function commandAvailable(cmd) {
  return spawnSync(cmd, ['--version'], { stdio: 'ignore' }).status === 0;
}

// Clone (or update) the worker into the cache and install it. Returns the worker dir.
function provision() {
  let dir = resolveWorkerDir();

  if (dir && dir !== MANAGED_DIR) {
    // The user manages this checkout (DIFFY_WORKER_DIR / sibling) — don't touch its git, just
    // make sure its deps + Chromium are present.
    console.error(`Using your worker checkout at ${dir} (not cloning).`);
  } else {
    if (!commandAvailable('git')) {
      throw new Error('git is required to install the worker. Install git, or set DIFFY_WORKER_DIR to a checkout you manage.');
    }
    mkdirSync(path.dirname(MANAGED_DIR), { recursive: true });
    if (existsSync(path.join(MANAGED_DIR, '.git'))) {
      console.error(`Updating diffy-worker in ${MANAGED_DIR} (ref ${WORKER_REF})...`);
      sh('git', ['-C', MANAGED_DIR, 'fetch', '--depth', '1', WORKER_REPO, WORKER_REF]);
      sh('git', ['-C', MANAGED_DIR, 'checkout', '-f', 'FETCH_HEAD']);
    } else {
      console.error(`Cloning diffy-worker into ${MANAGED_DIR} (ref ${WORKER_REF})...`);
      sh('git', ['clone', '--depth', '1', '--branch', WORKER_REF, WORKER_REPO, MANAGED_DIR]);
    }
    dir = MANAGED_DIR;
  }

  if (!existsSync(path.join(dir, 'node_modules')) || args.force) {
    // --omit=optional skips the native, unused `iltorb` so a fresh install can't fail on it.
    console.error('Installing worker dependencies (npm install --omit=optional)...');
    sh('npm', ['install', '--omit=optional'], { cwd: dir });
  }

  console.error('Ensuring Playwright Chromium is installed (one-time, ~150MB)...');
  sh('npx', ['--yes', 'playwright', 'install', 'chromium'], { cwd: dir });

  console.error(`\nWorker ready at ${dir}`);
  return dir;
}

// ---- mode: --provision -----------------------------------------------------
if (provisionMode) {
  try {
    provision();
    process.exit(0);
  } catch (e) {
    console.error(`\nProvisioning failed: ${e.message}`);
    process.exit(5);
  }
}

const workerDir = resolveWorkerDir();
const apiKey = resolveApiKey();

// ---- mode: --check ---------------------------------------------------------
if (checkMode) {
  const problems = [];

  if (!workerDir) {
    console.error('worker:            NOT INSTALLED (run `run-worker.mjs --provision`, or set DIFFY_WORKER_DIR)');
    problems.push('worker not installed');
  } else {
    console.error(`worker dir:        ${workerDir}`);
    const hasNodeModules = existsSync(path.join(workerDir, 'node_modules'));
    console.error(`worker deps:       ${hasNodeModules ? 'installed' : 'MISSING (run `run-worker.mjs --provision`)'}`);
    if (!hasNodeModules) problems.push('worker deps not installed');
  }

  console.error(`api key:           ${apiKey ? 'found' : 'MISSING (run `diffy auth:login <API_KEY>` or set DIFFY_API_KEY)'}`);
  if (!apiKey) problems.push('no API key');

  const finish = () => {
    if (problems.length) {
      console.error(`\nSetup incomplete: ${problems.join('; ')}.`);
      process.exit(1);
    }
    console.error('\nSetup OK — ready to capture.');
    process.exit(0);
  };

  if (!workerDir) {
    finish();
  } else {
    // Best-effort Chromium probe using the worker's own Playwright.
    const probe = spawn(
      'node',
      ['-e', 'const {chromium}=require("playwright");const fs=require("fs");process.exit(fs.existsSync(chromium.executablePath())?0:1)'],
      { cwd: workerDir }
    );
    probe.on('error', () => {});
    probe.on('close', (probeCode) => {
      const chromiumOk = probeCode === 0;
      console.error(`playwright chromium: ${chromiumOk ? 'installed' : 'MISSING (run `run-worker.mjs --provision`)'}`);
      if (!chromiumOk) problems.push('playwright chromium not installed');
      finish();
    });
  }
} else {
  // ---- capture + upload via the worker's local runner ----------------------
  if (!workerDir) {
    console.error(
      'Error: the diffy-worker capture engine is not installed.\n' +
        'Run one-time setup first:  node "' + path.resolve(process.argv[1]) + '" --provision\n' +
        '(or set DIFFY_WORKER_DIR to a checkout you manage).'
    );
    process.exit(3);
  }

  const projectId = required('project-id');
  const url = required('url').replace(/\/+$/, '');
  const name = args.name && args.name !== true ? String(args.name) : '';

  if (!apiKey) {
    console.error(
      'Error: no Diffy API key found.\n' +
        'Run `diffy auth:login <API_KEY>` (key from https://app.diffy.website/#/keys), or set DIFFY_API_KEY.'
    );
    process.exit(4);
  }

  const childArgs = ['diffy-screenshots.js', `--url=${url}`, `--projectId=${projectId}`];
  if (name) childArgs.push(`--screenshot-name=${name}`);

  const env = { ...process.env, DIFFY_API_KEY: apiKey, DIFFY_PROJECT_ID: String(projectId) };

  console.error(`Running diffy-worker capture from ${workerDir} against ${url} (project ${projectId})...`);

  const child = spawn('node', childArgs, { cwd: workerDir, env });

  let stdout = '';
  child.stdout.on('data', (d) => {
    const s = d.toString();
    stdout += s;
    process.stderr.write(s); // progress -> stderr so our stdout stays machine-readable
  });
  child.stderr.on('data', (d) => process.stderr.write(d));

  child.on('error', (e) => {
    console.error(`Failed to launch the worker: ${e.message}`);
    process.exit(1);
  });

  child.on('close', (code) => {
    // diffy-screenshots.js prints ".../snapshots/<id>" for the uploaded set; take the last match.
    const matches = [...stdout.matchAll(/snapshots\/(\d+)/g)];
    const id = matches.length ? matches[matches.length - 1][1] : null;

    if (code !== 0) {
      console.error(`\nWorker exited with code ${code}.`);
      process.exit(code || 1);
    }
    if (!id) {
      console.error('\nCould not determine the screenshot ID from the worker output above.');
      process.exit(1);
    }
    process.stdout.write(id + '\n'); // the one machine-readable line
  });
}
