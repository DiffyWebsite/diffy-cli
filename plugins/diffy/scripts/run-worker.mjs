#!/usr/bin/env node
/**
 * run-worker.mjs — capture a running app locally with the Diffy screenshot-worker CONTAINER and
 * upload it to Diffy, returning the Diffy screenshot ID.
 *
 * Local capture runs inside Diffy's published Docker image
 * (`diffywebsite2/screenshot-worker`) — the SAME runtime (Chromium build, fonts, Node, Playwright)
 * Diffy uses in production — so a local capture renders identically for every user. The image is
 * runtime-only; the worker JS (`diffy-screenshots.js` + `lib/`) is cloned into a cache dir and
 * bind-mounted at /diffy-worker, with its node_modules installed *inside* the container so native
 * deps (sharp, iltorb) match the container arch.
 *
 * The orchestrator `diffy-screenshots.js` fetches the project's pages, breakpoints, and advanced
 * settings from Diffy, re-bases each page onto --url, captures every page x breakpoint with the
 * container's Chromium, uploads the set to Diffy, and prints the screenshot set URL. So there is no
 * local upload.json to build and no `diffy screenshot:create-uploaded` call.
 *
 * Progress is streamed to stderr; the resolved screenshot ID is printed alone on stdout, so
 * capture it with $(...).
 *
 * Usage:
 *   node run-worker.mjs --provision                                  # one-time setup (pull image + clone code + install)
 *   node run-worker.mjs --check                                      # validate setup only
 *   node run-worker.mjs --project-id=12345 --url=http://localhost:3000 [--name="label"]
 *
 * Because capture runs in a container, a local dev server URL (localhost / 127.0.0.1) is rewritten
 * to host.docker.internal so the container can reach your host.
 *
 * Worker CODE location is resolved from (first hit wins):
 *   --worker-dir=<path> | $DIFFY_WORKER_DIR | ./diffy-worker | ../diffy-worker | <cache>/diffy-worker
 * Overridable knobs (mainly for testing):
 *   $DIFFY_WORKER_IMAGE (default diffywebsite2/screenshot-worker:0.0.5)
 *   $DIFFY_WORKER_REPO  (default https://github.com/DiffyWebsite/diffy-worker.git)
 *   $DIFFY_WORKER_REF   (default main)
 *   $DIFFY_MAX_WORKERS  (capture concurrency inside the container; passed through if set)
 * The API key is read from $DIFFY_API_KEY, else $DIFFYCLI_CONFIG, else ~/.diffy-cli/diffy-cli.yaml.
 */

import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
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

// ---- config ----------------------------------------------------------------
const CACHE_ROOT = process.env.XDG_CACHE_HOME || path.join(os.homedir(), '.cache');
const MANAGED_DIR = path.join(CACHE_ROOT, 'diffy', 'diffy-worker');
const IMAGE = process.env.DIFFY_WORKER_IMAGE || 'diffywebsite2/screenshot-worker:0.0.5';
const WORKER_REPO = process.env.DIFFY_WORKER_REPO || 'https://github.com/DiffyWebsite/diffy-worker.git';
const WORKER_REF = process.env.DIFFY_WORKER_REF || 'main';

// ---- resolve the worker code checkout --------------------------------------
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

// ---- docker helpers --------------------------------------------------------
function commandAvailable(cmd) {
  return spawnSync(cmd, ['--version'], { stdio: 'ignore' }).status === 0;
}

function dockerDaemonUp() {
  return spawnSync('docker', ['info'], { stdio: 'ignore' }).status === 0;
}

function imagePresent() {
  return spawnSync('docker', ['image', 'inspect', IMAGE], { stdio: 'ignore' }).status === 0;
}

function sh(cmd, cmdArgs, opts = {}) {
  console.error(`  $ ${cmd} ${cmdArgs.join(' ')}${opts.cwd ? `   (in ${opts.cwd})` : ''}`);
  const r = spawnSync(cmd, cmdArgs, { stdio: 'inherit', ...opts });
  if (r.error) throw new Error(`${cmd} failed to start: ${r.error.message}`);
  if (r.status !== 0) throw new Error(`${cmd} exited with status ${r.status}`);
}

// Rewrite a host-local URL so it is reachable from inside the container.
function containerReachableUrl(rawUrl) {
  let u;
  try {
    u = new URL(rawUrl);
  } catch {
    return rawUrl;
  }
  if (['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'].includes(u.hostname)) {
    u.hostname = 'host.docker.internal';
    return u.toString().replace(/\/+$/, '');
  }
  return rawUrl.replace(/\/+$/, '');
}

// ---- provisioning ----------------------------------------------------------
// Pull the runtime image, clone/update the worker code into the cache, and install its deps
// INSIDE the container. Returns the worker code dir.
function provision() {
  if (!commandAvailable('docker')) {
    throw new Error('Docker is required for local capture. Install Docker Desktop (https://www.docker.com/products/docker-desktop/) and start it.');
  }
  if (!dockerDaemonUp()) {
    throw new Error('The Docker daemon is not running. Start Docker Desktop (or `dockerd`) and retry.');
  }

  console.error(`Pulling screenshot-worker image ${IMAGE} (one-time, ~1.1GB)...`);
  sh('docker', ['pull', IMAGE]);

  let dir = resolveWorkerDir();
  if (dir && dir !== MANAGED_DIR) {
    // The user manages this checkout (DIFFY_WORKER_DIR / sibling) — don't touch its git.
    console.error(`Using your worker code checkout at ${dir} (not cloning).`);
  } else {
    if (!commandAvailable('git')) {
      throw new Error('git is required to fetch the worker code. Install git, or set DIFFY_WORKER_DIR to a checkout you manage.');
    }
    mkdirSync(path.dirname(MANAGED_DIR), { recursive: true });
    if (existsSync(path.join(MANAGED_DIR, '.git'))) {
      console.error(`Updating worker code in ${MANAGED_DIR} (ref ${WORKER_REF})...`);
      sh('git', ['-C', MANAGED_DIR, 'fetch', '--depth', '1', WORKER_REPO, WORKER_REF]);
      sh('git', ['-C', MANAGED_DIR, 'checkout', '-f', 'FETCH_HEAD']);
    } else {
      console.error(`Cloning worker code into ${MANAGED_DIR} (ref ${WORKER_REF})...`);
      sh('git', ['clone', '--depth', '1', '--branch', WORKER_REF, WORKER_REPO, MANAGED_DIR]);
    }
    dir = MANAGED_DIR;
  }

  // The orchestrator spawns `node ./index.js --env-file=.env ...`; Node errors if .env is absent.
  // An empty file is enough — the child inherits DIFFY_* from the container env.
  const envFile = path.join(dir, '.env');
  if (!existsSync(envFile)) writeFileSync(envFile, '');

  if (!existsSync(path.join(dir, 'node_modules')) || args.force) {
    // Install inside the container so native modules (sharp, iltorb) match its arch.
    // --omit=optional skips the native, unused `iltorb`.
    console.error('Installing worker dependencies inside the container (npm install --omit=optional)...');
    sh('docker', ['run', '--rm', '-v', `${dir}:/diffy-worker`, '-w', '/diffy-worker', IMAGE,
      'npm', 'install', '--omit=optional']);
  }

  console.error(`\nWorker ready — image ${IMAGE}, code at ${dir}`);
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

const dockerOk = commandAvailable('docker');
const daemonOk = dockerOk && dockerDaemonUp();
const workerDir = resolveWorkerDir();
const apiKey = resolveApiKey();

// ---- mode: --check ---------------------------------------------------------
if (checkMode) {
  const problems = [];

  console.error(`docker:            ${dockerOk ? 'installed' : 'MISSING (install Docker Desktop)'}`);
  if (!dockerOk) problems.push('docker not installed');
  else {
    console.error(`docker daemon:     ${daemonOk ? 'running' : 'NOT RUNNING (start Docker Desktop)'}`);
    if (!daemonOk) problems.push('docker daemon not running');
  }

  if (daemonOk) {
    const img = imagePresent();
    console.error(`worker image:      ${img ? `present (${IMAGE})` : `NOT PULLED (run \`run-worker.mjs --provision\`)`}`);
    if (!img) problems.push('worker image not pulled');
  }

  if (!workerDir) {
    console.error('worker code:       NOT INSTALLED (run `run-worker.mjs --provision`, or set DIFFY_WORKER_DIR)');
    problems.push('worker code not installed');
  } else {
    console.error(`worker code:       ${workerDir}`);
    const hasNodeModules = existsSync(path.join(workerDir, 'node_modules'));
    console.error(`worker deps:       ${hasNodeModules ? 'installed' : 'MISSING (run `run-worker.mjs --provision`)'}`);
    if (!hasNodeModules) problems.push('worker deps not installed');
  }

  console.error(`api key:           ${apiKey ? 'found' : 'MISSING (run `diffy auth:login <API_KEY>` or set DIFFY_API_KEY)'}`);
  if (!apiKey) problems.push('no API key');

  if (problems.length) {
    console.error(`\nSetup incomplete: ${problems.join('; ')}.`);
    process.exit(1);
  }
  console.error('\nSetup OK — ready to capture.');
  process.exit(0);
} else {
  // ---- capture + upload via the worker container ---------------------------
  if (!dockerOk) {
    console.error('Error: Docker is required for local capture. Install Docker Desktop and start it, then run:\n  node "' + path.resolve(process.argv[1]) + '" --provision');
    process.exit(3);
  }
  if (!daemonOk) {
    console.error('Error: the Docker daemon is not running. Start Docker Desktop (or `dockerd`) and retry.');
    process.exit(3);
  }
  if (!workerDir || !imagePresent()) {
    console.error(
      'Error: the screenshot-worker is not set up.\n' +
        'Run one-time setup first:  node "' + path.resolve(process.argv[1]) + '" --provision\n' +
        '(pulls the image + fetches the worker code; or set DIFFY_WORKER_DIR to a checkout you manage).'
    );
    process.exit(3);
  }

  const projectId = required('project-id');
  const rawUrl = required('url').replace(/\/+$/, '');
  const url = containerReachableUrl(rawUrl);
  const name = args.name && args.name !== true ? String(args.name) : '';

  if (!apiKey) {
    console.error(
      'Error: no Diffy API key found.\n' +
        'Run `diffy auth:login <API_KEY>` (key from https://app.diffy.website/#/keys), or set DIFFY_API_KEY.'
    );
    process.exit(4);
  }

  if (url !== rawUrl) {
    console.error(`Rewrote ${rawUrl} -> ${url} so the container can reach your host.`);
  }

  // Env values are passed by name (docker reads them from our env) so the key never lands in argv.
  const env = { ...process.env, DIFFY_API_KEY: apiKey, DIFFY_PROJECT_ID: String(projectId) };

  const dockerArgs = ['run', '--rm', '-e', 'DIFFY_API_KEY', '-e', 'DIFFY_PROJECT_ID'];
  if (process.env.DIFFY_MAX_WORKERS) dockerArgs.push('-e', 'DIFFY_MAX_WORKERS');
  dockerArgs.push('--add-host', 'host.docker.internal:host-gateway');
  dockerArgs.push('-v', `${workerDir}:/diffy-worker`, '-w', '/diffy-worker');
  dockerArgs.push(IMAGE, 'node', 'diffy-screenshots.js', `--url=${url}`);
  if (name) dockerArgs.push(`--screenshot-name=${name}`);

  console.error(`Running screenshot-worker container (${IMAGE}) against ${url} (project ${projectId})...`);

  const child = spawn('docker', dockerArgs, { env });

  let stdout = '';
  child.stdout.on('data', (d) => {
    const s = d.toString();
    stdout += s;
    process.stderr.write(s); // progress -> stderr so our stdout stays machine-readable
  });
  child.stderr.on('data', (d) => process.stderr.write(d));

  child.on('error', (e) => {
    console.error(`Failed to launch the worker container: ${e.message}`);
    process.exit(1);
  });

  child.on('close', (code) => {
    // diffy-screenshots.js prints ".../snapshots/<id>" for the uploaded set; take the last match.
    const matches = [...stdout.matchAll(/snapshots\/(\d+)/g)];
    const id = matches.length ? matches[matches.length - 1][1] : null;

    if (code !== 0) {
      console.error(`\nWorker container exited with code ${code}.`);
      process.exit(code || 1);
    }
    if (!id) {
      console.error('\nCould not determine the screenshot ID from the worker output above.');
      process.exit(1);
    }
    process.stdout.write(id + '\n'); // the one machine-readable line
  });
}
