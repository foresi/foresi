<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/*
 * Gathers what bin/perf measured in var/perf/ into var/perf/results.json and a Markdown table,
 * var/perf/summary.md, which also goes to the job summary of GitHub Actions. With
 * --previous=FILE it compares with an earlier results.json and marks every number that moved
 * by more than 20 %: a warning, never a failure, because the runners of GitHub vary by more
 * than the changes worth catching (docs/performance.md).
 *
 *     php tests/Perf/report.php --size=large [--previous=var/perf/previous/results.json]
 */

const DRIFT = 0.20;
const IMPORT_BUDGET_ROWS_PER_SECOND = 150;

// The JSON files are written by k6, the seed and the perf tests; these read them without
// trusting their shape: a missing or odd value is null, and the row is left out or blank.

/**
 * @return array<array-key, mixed>|null
 */
function json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/**
 * The value at the path of keys, or null.
 */
function at(mixed $data, string|int ...$path): mixed
{
    foreach ($path as $key) {
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return null;
        }
        $data = $data[$key];
    }

    return $data;
}

function number(mixed $data, string|int ...$path): ?float
{
    $value = at($data, ...$path);

    return is_int($value) || is_float($value) ? (float) $value : null;
}

function text(mixed $data, string|int ...$path): string
{
    $value = at($data, ...$path);

    return is_string($value) ? $value : '';
}

/**
 * @return list<mixed>
 */
function items(mixed $data, string|int ...$path): array
{
    $value = at($data, ...$path);

    return is_array($value) ? array_values($value) : [];
}

function ms(?float $value): string
{
    return null === $value ? '' : sprintf('%.0f ms', $value);
}

// " (+35 % ⚠)" when a number moved by more than DRIFT since the previous run.
function drift(?float $now, ?float $before): string
{
    if (null === $now || null === $before || $before <= 0.0) {
        return '';
    }
    $change = ($now - $before) / $before;

    return sprintf(' (%+.0f %%%s)', $change * 100, abs($change) > DRIFT ? ' ⚠' : '');
}

$options = getopt('', ['size:', 'previous:']);
$root = dirname(__DIR__, 2);
$dir = $root.'/var/perf';
$size = $options['size'] ?? 'small';
$sha = getenv('GITHUB_SHA');

$results = [
    'size' => is_string($size) ? $size : 'small',
    'measuredAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'commit' => is_string($sha) && '' !== $sha ? $sha : trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null')),
    'machine' => php_uname('s').' '.php_uname('m').', PHP '.PHP_VERSION,
    'seed' => json_file($dir.'/seed.json'),
    'pages' => json_file($dir.'/pages-one.json'),
    'tenPeople' => json_file($dir.'/pages-ten.json'),
    'pdf' => json_file($dir.'/pdf.json'),
    'queries' => json_file($dir.'/queries.json'),
    'import' => null,
];

// The import test appends one line a run: "<platform> rows N, create: read 1.8 s, check 6.0 s (1679 rows/s), run 355.2 s (28 rows/s), …".
$importLog = $root.'/var/import-throughput.txt';
if (is_file($importLog)) {
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($importLog)), static fn (string $line): bool => '' !== $line));
    $line = [] === $lines ? '' : $lines[count($lines) - 1];
    $passes = [];
    preg_match_all('~(create|update): read ([\d.]+) s, check ([\d.]+) s \((\d+) rows/s\), run ([\d.]+) s \((\d+) rows/s\)~', $line, $found, PREG_SET_ORDER);
    foreach ($found as $pass) {
        $passes[$pass[1]] = ['readS' => (float) $pass[2], 'checkS' => (float) $pass[3], 'checkRowsPerSecond' => (int) $pass[4], 'runS' => (float) $pass[5], 'runRowsPerSecond' => (int) $pass[6]];
    }
    if (1 === preg_match('~undo of the update: ([\d.]+) s \((\d+) rows/s\)~', $line, $undo)) {
        $passes['undo'] = ['readS' => null, 'checkS' => null, 'checkRowsPerSecond' => null, 'runS' => (float) $undo[1], 'runRowsPerSecond' => (int) $undo[2]];
    }
    $rows = 1 === preg_match('~rows (\d+)~', $line, $count) ? (int) $count[1] : 0;
    $results['import'] = ['line' => $line, 'rows' => $rows, 'budgetRowsPerSecond' => IMPORT_BUDGET_ROWS_PER_SECOND, 'passes' => $passes];
}

$previousFile = $options['previous'] ?? null;
$previous = is_string($previousFile) ? json_file($previousFile) : null;
$previousPage = static function (string $page) use ($previous): mixed {
    foreach (items($previous, 'pages', 'pages') as $row) {
        if (text($row, 'page') === $page) {
            return $row;
        }
    }

    return null;
};

$md = [sprintf('## Performance baseline (%s data set)', $results['size']), '', sprintf('Commit %s, %s, %s.', $results['commit'], $results['machine'], $results['measuredAt']), ''];

$seed = $results['seed'];
if (null !== $seed) {
    $counts = at($seed, 'counts');
    $counted = [];
    foreach (is_array($counts) ? $counts : [] as $type => $count) {
        if (is_int($count)) {
            $counted[] = sprintf('%s %s', number_format($count), str_replace('_', ' ', (string) $type));
        }
    }
    $md[] = sprintf('Seeded in %.0f s: %s.', number($seed, 'total_seconds') ?? 0.0, implode(', ', $counted));
    $md[] = '';
}

$pages = $results['pages'];
if (null !== $pages) {
    $md[] = sprintf('### Pages, one person (budget: p95 ≤ %.0f ms)', number($pages, 'budgetP95Ms') ?? 0.0);
    $md[] = '';
    $md[] = '| Page | median | p95 | max | |';
    $md[] = '|---|---:|---:|---:|---|';
    foreach (items($pages, 'pages') as $row) {
        $p95 = number($row, 'p(95)');
        $md[] = sprintf('| %s | %s | %s%s | %s | %s |', text($row, 'page'), ms(number($row, 'med')), ms($p95), drift($p95, number($previousPage(text($row, 'page')), 'p(95)')), ms(number($row, 'max')), true === at($row, 'ok') ? '' : '**over budget**');
    }
    $md[] = '';
}

$ten = $results['tenPeople'];
if (null !== $ten) {
    $budget = number($ten, 'budgetP95Ms') ?? 0.0;
    $p95 = number($ten, 'p(95)');
    $md[] = sprintf('### Ten people at once (budget: p95 ≤ %.0f ms)', $budget);
    $md[] = '';
    $md[] = sprintf('Median %s, p95 %s%s, max %s, %.1f requests a second%s.', ms(number($ten, 'med')), ms($p95), drift($p95, number($previous, 'tenPeople', 'p(95)')), ms(number($ten, 'max')), number($ten, 'requestsPerSecond') ?? 0.0, ($p95 ?? 0.0) > $budget ? ' — **over budget**' : '');
    $md[] = '';
}

$pdf = $results['pdf'];
if (null !== $pdf) {
    $md[] = sprintf('### Invoice PDF (budget: 100 lines ≤ %.0f ms)', number($pdf, 'budgetMs') ?? 0.0);
    $md[] = '';
    $md[] = '| Lines | median | min to max | size | peak memory |';
    $md[] = '|---:|---:|---:|---:|---:|';
    foreach (items($pdf, 'renders') as $i => $render) {
        $median = number($render, 'medianMs');
        $md[] = sprintf('| %.0f | %s%s | %.0f to %s | %.0f KB | %.0f MB |', number($render, 'lines') ?? 0.0, ms($median), drift($median, number($previous, 'pdf', 'renders', $i, 'medianMs')), number($render, 'minMs') ?? 0.0, ms(number($render, 'maxMs')), number($render, 'kilobytes') ?? 0.0, number($render, 'peakMemoryMb') ?? 0.0);
    }
    $md[] = '';
}

$import = $results['import'];
if (null !== $import) {
    $md[] = sprintf('### Import of %s contacts (budget: run and undo ≥ %d rows/s)', number_format($import['rows']), IMPORT_BUDGET_ROWS_PER_SECOND);
    $md[] = '';
    $md[] = '| Pass | read | check | run | |';
    $md[] = '|---|---:|---:|---:|---|';
    foreach ($import['passes'] as $name => $pass) {
        $md[] = sprintf(
            '| %s | %s | %s | %.1f s, %d rows/s%s | %s |',
            $name,
            null === $pass['readS'] ? '' : sprintf('%.1f s', $pass['readS']),
            null === $pass['checkS'] ? '' : sprintf('%.1f s, %d rows/s', $pass['checkS'], $pass['checkRowsPerSecond']),
            $pass['runS'],
            $pass['runRowsPerSecond'],
            drift((float) $pass['runRowsPerSecond'], number($previous, 'import', 'passes', $name, 'runRowsPerSecond')),
            $pass['runRowsPerSecond'] < IMPORT_BUDGET_ROWS_PER_SECOND ? '**under budget**' : '',
        );
    }
    if (!isset($import['passes']['undo'])) {
        $md[] = '';
        $md[] = 'The test stops at the first pass under budget, so the later passes were not timed.';
    }
    $md[] = '';
}

$queries = $results['queries'];
if (null !== $queries) {
    $md[] = '### Queries a page (checked on every pull request)';
    $md[] = '';
    $md[] = sprintf('| Page | tiny data set | ×%.0f data | budget |', number($queries, 'scale') ?? 10.0);
    $md[] = '|---|---:|---:|---:|';
    foreach (items($queries, 'pages') as $row) {
        $md[] = sprintf('| %s | %.0f | %.0f | %.0f |', text($row, 'page'), number($row, 'small') ?? 0.0, number($row, 'scaled') ?? 0.0, number($row, 'budget') ?? 0.0);
    }
    $md[] = '';
}

$markdown = implode("\n", $md)."\n";
file_put_contents($dir.'/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
file_put_contents($dir.'/summary.md', $markdown);
$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && '' !== $summary) {
    file_put_contents($summary, $markdown, FILE_APPEND);
}
fwrite(STDOUT, $markdown);
