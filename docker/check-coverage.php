<?php

declare(strict_types=1);

const EXIT_PASSED = 0;
const EXIT_REGRESSION = 1;
const EXIT_INVALID_INPUT = 2;

/**
 * @return array{covered: int, total: int}
 */
function read_clover_metrics(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException("Clover report is not readable: {$path}");
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->load($path, LIBXML_NONET);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (! $loaded) {
        $detail = isset($errors[0]) ? trim($errors[0]->message) : 'unknown XML error';
        throw new RuntimeException("Invalid Clover XML: {$detail}");
    }

    $xpath = new DOMXPath($document);
    $metrics = $xpath->query('/coverage/project/metrics');

    if ($metrics === false || $metrics->length !== 1) {
        throw new RuntimeException('Clover report must contain exactly one /coverage/project/metrics element');
    }

    $node = $metrics->item(0);

    if (! $node instanceof DOMElement) {
        throw new RuntimeException('Clover project metrics element is invalid');
    }

    $covered = read_non_negative_integer($node->getAttribute('coveredstatements'), 'coveredstatements');
    $total = read_non_negative_integer($node->getAttribute('statements'), 'statements');

    if ($total === 0) {
        throw new RuntimeException('Clover report contains zero statements');
    }

    if ($covered > $total) {
        throw new RuntimeException('Clover coveredstatements cannot exceed statements');
    }

    return [
        'covered' => $covered,
        'total' => $total,
    ];
}

/**
 * @return array{covered: int, total: int}
 */
function read_baseline(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException("Coverage baseline is not readable: {$path}");
    }

    try {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("Invalid coverage baseline JSON: {$exception->getMessage()}", 0, $exception);
    }

    if (! is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['metric'] ?? null) !== 'statements') {
        throw new RuntimeException('Coverage baseline must use schema 1 and the statements metric');
    }

    $covered = read_json_integer($data, 'covered_statements');
    $total = read_json_integer($data, 'total_statements');

    if ($total === 0) {
        throw new RuntimeException('Coverage baseline contains zero statements');
    }

    if ($covered > $total) {
        throw new RuntimeException('Baseline covered_statements cannot exceed total_statements');
    }

    return [
        'covered' => $covered,
        'total' => $total,
    ];
}

function read_non_negative_integer(string $value, string $name): int
{
    if ($value === '' || ! ctype_digit($value)) {
        throw new RuntimeException("Coverage metric {$name} must be a non-negative integer");
    }

    return (int) $value;
}

/**
 * @param array<string, mixed> $data
 */
function read_json_integer(array $data, string $name): int
{
    if (! array_key_exists($name, $data) || ! is_int($data[$name]) || $data[$name] < 0) {
        throw new RuntimeException("Coverage baseline field {$name} must be a non-negative integer");
    }

    return $data[$name];
}

function percentage(int $covered, int $total): float
{
    return round(($covered * 100) / $total, 6);
}

/**
 * @param array<string, mixed> $summary
 */
function write_json_summary(string $path, array $summary): void
{
    $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    write_report($path, $json . PHP_EOL);
}

/**
 * @param array<string, mixed> $summary
 */
function write_markdown_summary(string $path, array $summary): void
{
    if ($summary['status'] === 'error') {
        $markdown = implode(PHP_EOL, [
            '## Coverage gate',
            '',
            '**ERROR**',
            '',
            (string) $summary['error'],
            '',
        ]);
        write_report($path, $markdown);
        return;
    }

    $current = $summary['current'];
    $baseline = $summary['baseline'];
    $result = $summary['status'] === 'passed' ? 'PASS' : 'FAIL';
    $delta = sprintf('%+.2f pp', $summary['delta_percentage_points']);
    $markdown = implode(PHP_EOL, [
        '## Coverage gate',
        '',
        "**{$result}**",
        '',
        '| Metric | Covered | Total | Coverage |',
        '| --- | ---: | ---: | ---: |',
        sprintf(
            '| Current statements | %d | %d | %.2f%% |',
            $current['covered_statements'],
            $current['total_statements'],
            $current['percentage']
        ),
        sprintf(
            '| Baseline statements | %d | %d | %.2f%% |',
            $baseline['covered_statements'],
            $baseline['total_statements'],
            $baseline['percentage']
        ),
        '',
        "Change: {$delta}",
        '',
    ]);
    write_report($path, $markdown);
}

function write_report(string $path, string $contents): void
{
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create report directory: {$directory}");
    }

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Unable to write coverage report: {$path}");
    }
}

function print_result(array $summary): void
{
    $current = $summary['current'];
    $baseline = $summary['baseline'];
    $status = strtoupper((string) $summary['status']);

    printf("Coverage gate: %s\n", $status);
    printf(
        "Current statement coverage: %d/%d (%.2f%%)\n",
        $current['covered_statements'],
        $current['total_statements'],
        $current['percentage']
    );
    printf(
        "Baseline statement coverage: %d/%d (%.2f%%)\n",
        $baseline['covered_statements'],
        $baseline['total_statements'],
        $baseline['percentage']
    );
    printf("Change: %+.2f percentage points\n", $summary['delta_percentage_points']);
}

if ($argc < 3 || $argc > 5) {
    fwrite(
        STDERR,
        "Usage: php docker/check-coverage.php <clover.xml> <baseline.json> [summary.json] [summary.md]\n"
    );
    exit(EXIT_INVALID_INPUT);
}

$cloverPath = $argv[1];
$baselinePath = $argv[2];
$reportDirectory = dirname($cloverPath);
$jsonPath = $argv[3] ?? $reportDirectory . '/coverage-summary.json';
$markdownPath = $argv[4] ?? $reportDirectory . '/coverage-summary.md';

try {
    $current = read_clover_metrics($cloverPath);
    $baseline = read_baseline($baselinePath);
    $passed = $current['covered'] * $baseline['total'] >= $baseline['covered'] * $current['total'];
    $currentPercentage = percentage($current['covered'], $current['total']);
    $baselinePercentage = percentage($baseline['covered'], $baseline['total']);
    $summary = [
        'schema' => 1,
        'metric' => 'statements',
        'status' => $passed ? 'passed' : 'regression',
        'current' => [
            'covered_statements' => $current['covered'],
            'total_statements' => $current['total'],
            'percentage' => $currentPercentage,
        ],
        'baseline' => [
            'covered_statements' => $baseline['covered'],
            'total_statements' => $baseline['total'],
            'percentage' => $baselinePercentage,
        ],
        'delta_percentage_points' => round($currentPercentage - $baselinePercentage, 6),
    ];

    write_json_summary($jsonPath, $summary);
    write_markdown_summary($markdownPath, $summary);
    print_result($summary);
    exit($passed ? EXIT_PASSED : EXIT_REGRESSION);
} catch (Throwable $exception) {
    $summary = [
        'schema' => 1,
        'metric' => 'statements',
        'status' => 'error',
        'error' => $exception->getMessage(),
    ];

    try {
        write_json_summary($jsonPath, $summary);
        write_markdown_summary($markdownPath, $summary);
    } catch (Throwable $writeException) {
        fwrite(STDERR, "Coverage gate report error: {$writeException->getMessage()}\n");
    }

    fwrite(STDERR, "Coverage gate error: {$exception->getMessage()}\n");
    exit(EXIT_INVALID_INPUT);
}
