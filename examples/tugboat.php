#!/usr/bin/env php
<?php

/**
 * Diffy Compare Script
 *
 * Finds (or creates) a Diffy project for the base URL, takes screenshots
 * of both environments, creates a diff, then prints screenshot and diff URLs
 * grouped by breakpoint and per-page statistics.
 *
 * Usage:
 *   php tugboat.php <base_url> <compare_url> <pages>
 *   php tugboat.php --base-url=https://example.com \
 *                   --compare-url=https://staging.example.com \
 *                   --pages=/,/about-us,/blog
 */

// ─── Helpers ──────────────────────────────────────────────────────────────────

function out(string $msg = ''): void
{
    echo $msg . PHP_EOL;
}

function err(string $msg): void
{
    fwrite(STDERR, $msg . PHP_EOL);
}

function fail(string $msg, int $code = 1): never
{
    err('ERROR: ' . $msg);
    exit($code);
}

function diffyCapture(string $args): string
{
    global $diffyBin, $debug;
    $cmd = escapeshellcmd($diffyBin) . ' ' . $args;
    if ($debug) {
        out('[debug] ' . $cmd);
    }
    $output = [];
    exec($cmd . ' 2>&1', $output);
    return trim(implode("\n", $output));
}

/**
 * Strip basic-auth credentials from a parsed URL and return a clean URL string.
 */
function buildCleanUrl(array $parts): string
{
    $url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $url .= ':' . $parts['port'];
    }
    if (!empty($parts['path'])) {
        $url .= $parts['path'];
    }
    return $url;
}

/**
 * Obtain (and cache) a Diffy Bearer token from the CLI config file.
 */
function diffyApiToken(): ?string
{
    static $token = null;
    if ($token !== null) {
        return $token ?: null;
    }
    $configFile = (getenv('DIFFYCLI_CONFIG') ?: getenv('HOME') . '/.diffy-cli') . '/diffy-cli.yaml';
    if (!file_exists($configFile)) {
        return $token = '';
    }
    $apiKey = null;
    foreach (file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^key:\s*(.+)$/', trim($line), $m)) {
            $apiKey = trim($m[1], '"\'');
            break;
        }
    }
    if (!$apiKey) {
        return $token = '';
    }
    $ch = curl_init('https://app.diffy.website/api/auth/key');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode(['key' => $apiKey]),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $token = ($data['token'] ?? '');
}

/**
 * Make an authenticated GET request to the Diffy API.
 */
function diffyApiRequest(string $endpoint): ?array
{
    $token = diffyApiToken();
    if (!$token) {
        return null;
    }
    $ch = curl_init('https://app.diffy.website/api/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER    => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $data ?: null;
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$scriptDir = dirname(__FILE__);

// Prefer the local binary when running from inside the repo
$diffyBin = file_exists($scriptDir . '/../diffy')
    ? realpath($scriptDir . '/../diffy')
    : 'diffy';

// ─── Parse arguments ──────────────────────────────────────────────────────────

$opts = getopt('', ['base-url:', 'compare-url:', 'pages:', 'debug'], $restIndex);
$positional = array_values(array_slice($argv, $restIndex));

$baseUrl    = $opts['base-url']    ?? $positional[0] ?? null;
$compareUrl = $opts['compare-url'] ?? $positional[1] ?? null;
$pagesArg   = $opts['pages']       ?? $positional[2] ?? null;
$debug      = isset($opts['debug']);

if (!$baseUrl || !$compareUrl || !$pagesArg) {
    err('Usage: php tugboat.php [--debug] <base_url> <compare_url> <pages>');
    err('       pages: comma-separated paths, e.g. /,/about-us,/blog');
    exit(1);
}

// Parse basic-auth credentials from both URLs.
// Clean URLs (no credentials) are used for project lookup/creation/display.
// Credentials are passed as separate --envUser/--envPass arguments to screenshot:create.
$baseParts    = parse_url(rtrim($baseUrl, '/'));
$compareParts = parse_url(rtrim($compareUrl, '/'));
$baseClean    = buildCleanUrl($baseParts);
$compareClean = buildCleanUrl($compareParts);
$baseUser     = isset($baseParts['user'])    ? urldecode($baseParts['user'])    : null;
$basePass     = isset($baseParts['pass'])    ? urldecode($baseParts['pass'])    : null;
$compareUser  = isset($compareParts['user']) ? urldecode($compareParts['user']) : null;
$comparePass  = isset($compareParts['pass']) ? urldecode($compareParts['pass']) : null;

// Build pages array
$pages = array_values(array_filter(array_map('trim', explode(',', $pagesArg))));

if (empty($pages)) {
    fail('No pages provided.');
}

// ─── Find or create project ───────────────────────────────────────────────────

out("Looking for project with name: $baseClean");

$listJson    = diffyCapture('project:list');
$projectList = json_decode($listJson, true);

$projectId = null;
if (!empty($projectList['projects'])) {
    foreach ($projectList['projects'] as $project) {
        if ($project['name'] === $baseClean) {
            $projectId = (int) $project['id'];
            out("Found existing project (ID: $projectId).");
            break;
        }
    }
}

if (!$projectId) {
    out("No project found. Creating project '$baseClean'...");

    // Build YAML using single-quoted scalars to safely handle special characters.
    $q = fn (string $v): string => "'" . str_replace("'", "''", $v) . "'";

    $yaml  = "basic:\n";
    $yaml .= "    name: " . $q($baseClean) . "\n";
    $yaml .= "    environments:\n";
    $yaml .= "        production: " . $q($baseClean) . "\n";
    $yaml .= "    breakpoints:\n";
    foreach ([320, 1024, 1920] as $bp) {
        $yaml .= "        - $bp\n";
    }
    $yaml .= "    pages:\n";
    foreach ($pages as $page) {
        $yaml .= "        - " . $q($page) . "\n";
    }

    $tmpFile = '/tmp/diffy_' . uniqid() . '.yaml';
    file_put_contents($tmpFile, $yaml);

    if ($debug) {
        out('[debug] project config YAML: ' . $tmpFile);
    }

    $createOut = diffyCapture('project:create ' . escapeshellarg($tmpFile));
    if (!$debug) {
        unlink($tmpFile);
    }

    // Expected output: "[12345] Project Name created."
    if (preg_match('/\[(\d+)\]/', $createOut, $m)) {
        $projectId = (int) $m[1];
        out("Project created (ID: $projectId).");
    } else {
        fail('Failed to create project. Output: ' . $createOut);
    }
}
out();

// ─── Take screenshots and create diff ─────────────────────────────────────────

out('Starting comparison:');
out('  Base URL:    ' . $baseClean);
out('  Compare URL: ' . $compareClean);
out('  Pages:       ' . implode(', ', $pages));
out();

// Step 1: base screenshots
out('Triggering base screenshots...');
$screenshotArgs1  = sprintf('screenshot:create %d custom --envUrl=%s', $projectId, escapeshellarg($baseClean));
if ($baseUser !== null) {
    $screenshotArgs1 .= ' --envUser=' . escapeshellarg($baseUser);
}
if ($basePass !== null) {
    $screenshotArgs1 .= ' --envPass=' . escapeshellarg($basePass);
}
$screenshotOut1 = diffyCapture($screenshotArgs1);
if (!preg_match('/^(\d+)$/m', $screenshotOut1, $m)) {
    fail('Could not determine base screenshot ID. Output: ' . $screenshotOut1);
}
$screenshotId1 = (int) $m[1];
out("Base screenshots started (ID: $screenshotId1).");
out();

// Step 2: compare screenshots
out('Triggering compare screenshots...');
$screenshotArgs2  = sprintf('screenshot:create %d custom --envUrl=%s', $projectId, escapeshellarg($compareClean));
if ($compareUser !== null) {
    $screenshotArgs2 .= ' --envUser=' . escapeshellarg($compareUser);
}
if ($comparePass !== null) {
    $screenshotArgs2 .= ' --envPass=' . escapeshellarg($comparePass);
}
$screenshotOut2 = diffyCapture($screenshotArgs2);
if (!preg_match('/^(\d+)$/m', $screenshotOut2, $m)) {
    fail('Could not determine compare screenshot ID. Output: ' . $screenshotOut2);
}
$screenshotId2 = (int) $m[1];
out("Compare screenshots started (ID: $screenshotId2).");
out();

// Step 3: create diff (waits for screenshots to complete automatically)
out('Creating diff from screenshots (waiting for completion)...');
$diffOut = diffyCapture(sprintf(
    'diff:create %d %d %d --wait',
    $projectId,
    $screenshotId1,
    $screenshotId2
));
if (!preg_match('/^(\d+)$/m', $diffOut, $m)) {
    fail('Could not determine diff ID. Output: ' . $diffOut);
}
$diffId = (int) $m[1];
out("Diff completed (ID: $diffId).");
out();

// ─── Fetch results ────────────────────────────────────────────────────────────

$xmlOut = diffyCapture("diff:get-result $diffId --format=junit-xml");
if (empty($xmlOut)) {
    fail("No results returned for diff $diffId.");
}

$xml = @simplexml_load_string($xmlOut);
if (!$xml) {
    fail('Could not parse diff results. Raw output: ' . substr($xmlOut, 0, 200));
}

// Fetch full snapshot and diff data for actual S3 image URLs.
$s3Base   = 'https://s3.amazonaws.com/diffy-files/';
$snap1Api = diffyApiRequest('snapshots/' . $screenshotId1);
$snap2Api = diffyApiRequest('snapshots/' . $screenshotId2);
$diffApi  = diffyApiRequest('diffs/' . $diffId);

// ─── Parse results ────────────────────────────────────────────────────────────

$totalTests    = (int) $xml['tests'];
$totalFailures = (int) $xml['failures'];
$diffName      = (string) $xml['name'];

// byBreakpoint[label][] = { page, diff_url, changes, changed }
// byPage[pageUrl]       = { failures, tests, results[bp] }
$byBreakpoint = [];
$byPage       = [];

foreach ($xml->testsuite as $suite) {
    $pageUrl = (string) $suite['name'];
    $entry   = [
        'tests'    => (int) $suite['tests'],
        'failures' => (int) $suite['failures'],
        'results'  => [],
    ];

    foreach ($suite->testcase as $testcase) {
        $bp      = (string) $testcase['name']; // "Device size: 320"
        $diffUrl = (string) $testcase['file'];
        $pct     = 0.0;
        $changed = isset($testcase->failure);

        if ($changed && preg_match('/^([\d.]+)%/', (string) $testcase->failure, $pm)) {
            $pct = (float) $pm[1];
        }

        // Breakpoint number as stored in the API (e.g. "320" from "Device size: 320").
        $bpNum = preg_replace('/[^0-9]/', '', $bp);

        // S3 screenshot URLs from the snapshot API ("full" property).
        $s3Snap1 = $snap1Api['pages'][$pageUrl][$bpNum]['full'] ?? null;
        $s3Snap2 = $snap2Api['pages'][$pageUrl][$bpNum]['full'] ?? null;
        $s3Diff  = $diffApi['diffs'][$pageUrl][$bpNum]['full']  ?? null;

        $screenshotUrl1 = $s3Snap1 ? $s3Base . $s3Snap1 : null;
        $screenshotUrl2 = $s3Snap2 ? $s3Base . $s3Snap2 : null;
        $s3DiffUrl      = $s3Diff  ? $s3Base . $s3Diff  : $diffUrl;

        $entry['results'][$bp] = [
            'diff_url'        => $s3DiffUrl,
            'changes'         => $pct,
            'changed'         => $changed,
            'screenshot_url1' => $screenshotUrl1,
            'screenshot_url2' => $screenshotUrl2,
        ];

        $byBreakpoint[$bp][] = [
            'page'            => $pageUrl,
            'diff_url'        => $s3DiffUrl,
            'changes'         => $pct,
            'changed'         => $changed,
            'screenshot_url1' => $screenshotUrl1,
            'screenshot_url2' => $screenshotUrl2,
        ];
    }

    $byPage[$pageUrl] = $entry;
}

// ─── Print results ────────────────────────────────────────────────────────────

$hr = str_repeat('─', 68);

out(str_repeat('═', 68));
out('  DIFF RESULTS');
out('  Name:          ' . $diffName);
out('  Diff ID:       ' . $diffId);
out('  Screenshot 1:  ' . $screenshotId1 . '  (' . $baseClean . ')');
out('  Screenshot 2:  ' . $screenshotId2 . '  (' . $compareClean . ')');
out(str_repeat('═', 68));
out();

// ── 1. Screenshot URLs grouped by breakpoint ──────────────────────────────────

out('SCREENSHOT URLS BY BREAKPOINT');
out($hr);

foreach ($byBreakpoint as $breakpoint => $entries) {
    out();
    out('  ' . $breakpoint);
    foreach ($entries as $row) {
        out('    Page: ' . $row['page']);
        out('      Base:    ' . $row['screenshot_url1']);
        out('      Compare: ' . $row['screenshot_url2']);
    }
}
out();

// ── 2. Diff URLs grouped by breakpoint ────────────────────────────────────────

out('DIFF URLS BY BREAKPOINT');
out($hr);

foreach ($byBreakpoint as $breakpoint => $entries) {
    out();
    out('  ' . $breakpoint);
    foreach ($entries as $row) {
        $marker = $row['changed']
            ? sprintf('[%5.1f%% changed]', $row['changes'])
            : '[  no changes]';
        out(sprintf('    %s  %s', $marker, $row['diff_url']));
        out(sprintf('    %s  Page: %s', str_repeat(' ', 15), $row['page']));
    }
}
out();

// ── 3. Statistics per page ────────────────────────────────────────────────────

out('STATISTICS BY PAGE');
out($hr);
out();

foreach ($byPage as $pageUrl => $data) {
    $changed = $data['failures'];
    $total   = $data['tests'];
    $label   = $changed > 0
        ? sprintf('CHANGES FOUND  (%d of %d breakpoints affected)', $changed, $total)
        : 'No changes';

    out('  Page: ' . $pageUrl);
    out('  ' . $label);

    foreach ($data['results'] as $bp => $res) {
        $stat = $res['changed']
            ? sprintf('%.1f%% changed', $res['changes'])
            : 'OK';
        out(sprintf('    %-22s %s', $bp . ':', $stat));
    }
    out();
}

// ── 4. Overall summary ────────────────────────────────────────────────────────

$pagesChanged = count(array_filter($byPage, fn ($p) => $p['failures'] > 0));
$totalPages   = count($byPage);
$overallPct   = $totalTests > 0 ? round($totalFailures / $totalTests * 100, 1) : 0;

out(str_repeat('═', 68));
out('  SUMMARY');
out(sprintf('  Pages compared:          %d', $totalPages));
out(sprintf('  Pages with changes:      %d / %d', $pagesChanged, $totalPages));
out(sprintf('  Breakpoints checked:     %d', count($byBreakpoint)));
out(sprintf('  Checks with changes:     %d / %d', $totalFailures, $totalTests));
out(sprintf('  Overall change rate:     %s%%', $overallPct));
out(str_repeat('═', 68));
