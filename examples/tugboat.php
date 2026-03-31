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

function out(?string $msg = ''): void
{
    static $silent = false;
    if ($msg === null) { $silent = true; return; }
    if (!$silent) { echo $msg . PHP_EOL; }
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

function diffyCapture(string $args, ?string $initBin = null, bool $initDebug = false): string
{
    static $bin   = 'diffy';
    static $debug = false;
    if ($initBin !== null) {
        $bin   = $initBin;
        $debug = $initDebug;
        return '';
    }
    $cmd = escapeshellcmd($bin) . ' ' . $args;
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


// ─── Bootstrap ────────────────────────────────────────────────────────────────

$scriptDir = dirname(__FILE__);

// Prefer the local binary when running from inside the repo
$diffyBin = file_exists($scriptDir . '/../diffy')
    ? realpath($scriptDir . '/../diffy')
    : 'diffy';

// ─── Parse arguments ──────────────────────────────────────────────────────────

$opts = getopt('', ['base-url:', 'compare-url:', 'pages:', 'debug', 'json'], $restIndex);
$positional = array_values(array_slice($argv, $restIndex));

$baseUrl    = $opts['base-url']    ?? $positional[0] ?? null;
$compareUrl = $opts['compare-url'] ?? $positional[1] ?? null;
$pagesArg   = $opts['pages']       ?? $positional[2] ?? null;
$debug      = isset($opts['debug']);
$jsonOutput = isset($opts['json']);

diffyCapture('', $diffyBin, $debug);
if ($jsonOutput) {
    out(null);
}

if (!$baseUrl || !$compareUrl || !$pagesArg) {
    err('Usage: php tugboat.php [--debug] [--json] <base_url> <compare_url> <pages>');
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

$comparisonStart = microtime(true);

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

$jsonOut = diffyCapture("diff:get-result $diffId --format=json");
if (empty($jsonOut)) {
    fail("No results returned for diff $diffId.");
}

$diffJson = json_decode($jsonOut, true);
if (!$diffJson) {
    fail('Could not parse diff results. Raw output: ' . substr($jsonOut, 0, 200));
}



// ─── Parse results ────────────────────────────────────────────────────────────

$diffName      = $diffJson['name'] ?? '';
$totalTests    = 0;
$totalFailures = 0;

// byBreakpoint[label][] = { page, diff_url, changes, changed, screenshot_url1, screenshot_url2 }
// byPage[pageUrl]       = { failures, tests, results[bp] }
$byBreakpoint = [];
$byPage       = [];

$snapshot1 = $diffJson['snapshot1'] ?? [];
$snapshot2 = $diffJson['snapshot2'] ?? [];

foreach ($diffJson['diffs'] as $pageUrl => $breakpoints) {
    $entry = ['tests' => 0, 'failures' => 0, 'results' => []];

    foreach ($breakpoints as $bpNum => $item) {
        $changed = !empty($item['idiff']['areas']);
        $bp      = 'Device size: ' . $bpNum;

        $s3DiffUrl      = $item['idiff']['full']      ?? null;
        $screenshotUrl1 = $snapshot1[$pageUrl][$bpNum]['full'] ?? null;
        $screenshotUrl2 = $snapshot2[$pageUrl][$bpNum]['full'] ?? null;

        $entry['tests']++;
        if ($changed) {
            $entry['failures']++;
        }

        $entry['results'][$bp] = [
            'diff_url'        => $s3DiffUrl,
            'changed'         => $changed,
            'screenshot_url1' => $screenshotUrl1,
            'screenshot_url2' => $screenshotUrl2,
        ];

        $byBreakpoint[$bp][] = [
            'page'            => $pageUrl,
            'diff_url'        => $s3DiffUrl,
            'changed'         => $changed,
            'screenshot_url1' => $screenshotUrl1,
            'screenshot_url2' => $screenshotUrl2,
        ];
    }

    $totalTests    += $entry['tests'];
    $totalFailures += $entry['failures'];
    $byPage[$pageUrl] = $entry;
}

// ─── JSON output ──────────────────────────────────────────────────────────────

if ($jsonOutput) {
    $output = [];
    foreach ($byPage as $pageUrl => $data) {
        foreach ($data['results'] as $bp => $res) {
            $output[] = [
                'page'        => $pageUrl,
                'breakpoint'  => (int) preg_replace('/[^0-9]/', '', $bp),
                'screenshot1' => $res['screenshot_url1'],
                'screenshot2' => $res['screenshot_url2'],
                'diff'        => $res['diff_url'],
                'changed'     => $res['changed'] ? 1 : 0,
            ];
        }
    }
    echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
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

// ── 1. Results by page ────────────────────────────────────────────────────────

out('RESULTS');
out($hr);

foreach ($byPage as $pageUrl => $data) {
    out();
    out('  Page: ' . $pageUrl);
    foreach ($data['results'] as $bp => $res) {
        out('    ' . $bp);
        out('      Screenshot 1: ' . $res['screenshot_url1']);
        out('      Screenshot 2: ' . $res['screenshot_url2']);
        out('      Diff:         ' . $res['diff_url']);
        out('      Changed:      ' . ($res['changed'] ? '1' : '0'));
    }
}
out();

// ── 2. Overall summary ────────────────────────────────────────────────────────

$pagesChanged = count(array_filter($byPage, fn ($p) => $p['failures'] > 0));
$totalPages   = count($byPage);

out(str_repeat('═', 68));
out('  SUMMARY');
out(sprintf('  Pages compared:      %d', $totalPages));
out(sprintf('  Pages with changes:  %d / %d', $pagesChanged, $totalPages));
out(sprintf('  Breakpoints checked: %d', $totalTests));
out(sprintf('  Checks with changes: %d / %d', $totalFailures, $totalTests));
if ($debug) {
    out(sprintf('  Total time:          %.1fs', microtime(true) - $comparisonStart));
}
out(str_repeat('═', 68));
