#!/usr/bin/env php
<?php
declare(strict_types=1);

$specStrings = [
    'vus_max',
    'iterations.count',
    'iterations.rate/sec',
    'http_req_duration.avg',
    'http_req_duration.p(95)',
    'http_req_duration.max',
    'http_req_duration.med',
    'http_req_duration{expected_response:true}.avg',
    'http_req_duration{expected_response:true}.p(95)',
    'http_req_duration{expected_response:true}.max',
    'http_req_duration{expected_response:true}.med',
    'http_req_waiting.avg',
    'http_req_waiting.p(95)',
    'http_req_waiting.max',
    'http_req_waiting.med',
    'http_req_failed',
    'http_reqs.count',
    'http_reqs.rate/sec',
    'x_using_apcu',
    'x_using_relay',
    'wp_hits.avg',
    'wp_hits.p(95)',
    'wp_hits.max',
    'wp_hits.med',
    'wp_hit_ratio.avg',
    'wp_hit_ratio.p(95)',
    'wp_hit_ratio.max',
    'wp_hit_ratio.med',
    'wp_ms_total.avg',
    'wp_ms_total.p(95)',
    'wp_ms_total.max',
    'wp_ms_total.med',
    'wp_sql_queries.avg',
    'wp_sql_queries.p(95)',
    'wp_sql_queries.max',
    'wp_sql_queries.med',
];

function usage(string $script): void
{
    $message = <<<TXT
Usage: php {$script} <input.ndjson> [output.csv]

Reads k6 NDJSON export (e.g. produced via --out json=...) and writes a two-column CSV
containing the metrics defined in the script.
TXT;
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if ($argc < 2) {
    usage($argv[0]);
}

$inputPath = $argv[1];
$outputPath = $argv[2] ?? null;

if (!is_readable($inputPath)) {
    fwrite(STDERR, "Input file '{$inputPath}' is not readable." . PHP_EOL);
    exit(1);
}

/**
 * @return array{metric:string,filter:array<string,string>|null,stat:?string}
 */
function parseSpec(string $spec): array
{
    if (!preg_match('/^([^{.]+(?:\{[^}]+\})?)(?:\\.(.+))?$/', $spec, $matches)) {
        throw new InvalidArgumentException("Unable to parse spec '{$spec}'");
    }

    $metricWithFilter = $matches[1];
    $stat = $matches[2] ?? null;

    if (!preg_match('/^([^{}]+)(?:\{([^}]+)\})?$/', $metricWithFilter, $metricMatches)) {
        throw new InvalidArgumentException("Unable to parse metric portion '{$metricWithFilter}'");
    }

    $metric = $metricMatches[1];
    $filterString = $metricMatches[2] ?? null;
    $filter = $filterString !== null ? parseFilter($filterString) : null;

    return [
        'metric' => $metric,
        'filter' => $filter,
        'stat' => $stat,
    ];
}

/**
 * @return array<string,string>
 */
function parseFilter(string $filterString): array
{
    $filter = [];
    foreach (explode(',', $filterString) as $pair) {
        [$key, $value] = array_map('trim', explode(':', $pair, 2));
        if ($key === '' || $value === null) {
            throw new InvalidArgumentException("Invalid filter condition '{$pair}'");
        }
        $filter[$key] = $value;
    }

    ksort($filter);
    return $filter;
}

/**
 * @param array<string,string>|null $filter
 */
function datasetKey(?array $filter): string
{
    if ($filter === null) {
        return '__all__';
    }
    $parts = [];
    foreach ($filter as $key => $value) {
        $parts[] = "{$key}={$value}";
    }
    return implode(',', $parts);
}

/**
 * @param array<string,string> $filter
 * @param array<string,string> $tags
 */
function matchesFilter(array $filter, array $tags): bool
{
    foreach ($filter as $key => $value) {
        if (!array_key_exists($key, $tags) || (string)$tags[$key] !== $value) {
            return false;
        }
    }
    return true;
}

function initDataset(bool $storeValues): array
{
    return [
        'sum' => 0.0,
        'count' => 0,
        'max' => null,
        'values' => $storeValues ? [] : null,
        'store_values' => $storeValues,
        'last' => null,
    ];
}

/**
 * @param array{sum:float,count:int,max:?float,values:?array,last:?float,store_values:bool} $dataset
 */
function addValueToDataset(array &$dataset, float $value): void
{
    $dataset['sum'] += $value;
    $dataset['count']++;
    $dataset['last'] = $value;
    if ($dataset['max'] === null || $value > $dataset['max']) {
        $dataset['max'] = $value;
    }
    if ($dataset['store_values']) {
        $dataset['values'][] = $value;
    }
}

/**
 * @param list<float> $values
 */
function computePercentile(array $values, float $percentile): ?float
{
    $count = count($values);
    if ($count === 0) {
        return null;
    }

    sort($values);
    $rank = ($percentile / 100) * ($count - 1);
    $lowerIndex = (int)floor($rank);
    $upperIndex = (int)ceil($rank);
    if ($lowerIndex === $upperIndex) {
        return $values[$lowerIndex];
    }
    $weight = $rank - $lowerIndex;
    return $values[$lowerIndex] * (1 - $weight) + $values[$upperIndex] * $weight;
}

/**
 * @param array{sum:float,count:int,max:?float,values:?array,last:?float,store_values:bool} $dataset
 */
function computeStat(array $dataset, ?string $stat, float $globalDuration, ?float $metricDuration, bool $isRateMetric = false): ?float
{
    switch ($stat) {
        case null:
            if ($isRateMetric) {
                return $dataset['count'] === 0 ? 0.0 : $dataset['sum'] / $dataset['count'];
            }
            return $dataset['last'];
        case 'avg':
            return $dataset['count'] === 0 ? null : $dataset['sum'] / $dataset['count'];
        case 'max':
            return $dataset['max'];
        case 'med':
            return $dataset['store_values'] && $dataset['values'] !== null
                ? computePercentile($dataset['values'], 50.0)
                : null;
        case 'p(95)':
            return $dataset['store_values'] && $dataset['values'] !== null
                ? computePercentile($dataset['values'], 95.0)
                : null;
        case 'count':
            return $dataset['sum'];
        case 'rate/sec':
            $duration = $metricDuration ?? $globalDuration;
            if ($duration <= 0) {
                return null;
            }
            return $dataset['sum'] / $duration;
        default:
            throw new InvalidArgumentException("Unknown stat '{$stat}'");
    }
}

function parseTimeToFloat(?string $isoTime): ?float
{
    if ($isoTime === null) {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($isoTime);
        return (float)$dt->format('U.u');
    } catch (Exception $e) {
        return null;
    }
}

function formatNumber(?float $value): string
{
    if ($value === null || is_nan($value) || is_infinite($value)) {
        return '';
    }

    $formatted = sprintf('%.6f', $value);
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    if ($formatted === '-0') {
        $formatted = '0';
    }

    return $formatted;
}

$specs = [];
$requirements = [];

foreach ($specStrings as $specString) {
    $parsed = parseSpec($specString);
    $specs[$specString] = $parsed;

    $metric = $parsed['metric'];
    $filter = $parsed['filter'];
    $stat = $parsed['stat'];
    $datasetKey = datasetKey($filter);

    if (!isset($requirements[$metric])) {
        $requirements[$metric] = [
            'datasets' => [],
            'is_rate_metric' => false,
        ];
    }

    if (!isset($requirements[$metric]['datasets'][$datasetKey])) {
        $requirements[$metric]['datasets'][$datasetKey] = [
            'filter' => $filter,
            'store_values' => false,
        ];
    }

    if ($stat === null && $metric === 'http_req_failed') {
        $requirements[$metric]['is_rate_metric'] = true;
    }

    if (in_array($stat, ['med', 'p(95)'], true)) {
        $requirements[$metric]['datasets'][$datasetKey]['store_values'] = true;
    }
}

$handle = fopen($inputPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open input file '{$inputPath}'." . PHP_EOL);
    exit(1);
}

$collectors = [];
foreach ($requirements as $metricName => $info) {
    $collectors[$metricName] = [
        'datasets' => [],
        'min_time' => null,
        'max_time' => null,
        'is_rate_metric' => $info['is_rate_metric'],
    ];
    foreach ($info['datasets'] as $key => $datasetInfo) {
        $collectors[$metricName]['datasets'][$key] = initDataset($datasetInfo['store_values']);
    }
}

$globalStart = null;
$globalEnd = null;

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $entry = json_decode($line, true);
    if (!is_array($entry) || !isset($entry['metric'], $entry['type'], $entry['data'])) {
        continue;
    }
    if ($entry['type'] !== 'Point') {
        continue;
    }

    $metricName = $entry['metric'];
    if (!isset($collectors[$metricName])) {
        continue;
    }

    $data = $entry['data'];
    if (!is_array($data) || !isset($data['value'])) {
        continue;
    }
    $value = (float)$data['value'];

    $time = parseTimeToFloat($data['time'] ?? null);
    if ($time !== null) {
        if ($globalStart === null || $time < $globalStart) {
            $globalStart = $time;
        }
        if ($globalEnd === null || $time > $globalEnd) {
            $globalEnd = $time;
        }

        if ($collectors[$metricName]['min_time'] === null || $time < $collectors[$metricName]['min_time']) {
            $collectors[$metricName]['min_time'] = $time;
        }
        if ($collectors[$metricName]['max_time'] === null || $time > $collectors[$metricName]['max_time']) {
            $collectors[$metricName]['max_time'] = $time;
        }
    }

    $tags = $data['tags'] ?? [];
    if (!is_array($tags)) {
        $tags = [];
    }

    foreach ($requirements[$metricName]['datasets'] as $datasetKey => $datasetInfo) {
        $filter = $datasetInfo['filter'];
        if ($filter !== null && !matchesFilter($filter, $tags)) {
            continue;
        }
        addValueToDataset($collectors[$metricName]['datasets'][$datasetKey], $value);
    }
}
fclose($handle);

$globalDuration = ($globalStart !== null && $globalEnd !== null) ? ($globalEnd - $globalStart) : 0.0;

$outputHandle = $outputPath !== null
    ? fopen($outputPath, 'wb')
    : fopen('php://stdout', 'wb');

if ($outputHandle === false) {
    fwrite(STDERR, "Unable to open output destination." . PHP_EOL);
    exit(1);
}

// Get the basename minus extension
$inputBaseName = pathinfo($inputPath, PATHINFO_FILENAME);

$header = ['metric', $inputBaseName];
fputcsv($outputHandle, $header, ',', '"', '\\');

foreach ($specStrings as $specString) {
    $spec = $specs[$specString];
    $metric = $spec['metric'];
    $filter = $spec['filter'];
    $stat = $spec['stat'];
    $datasetKey = datasetKey($filter);

    $collector = $collectors[$metric] ?? null;
    $dataset = $collector['datasets'][$datasetKey] ?? null;

    $value = null;
    if ($dataset !== null) {
        $metricDuration = null;
        if ($collector['min_time'] !== null && $collector['max_time'] !== null) {
            $metricDuration = $collector['max_time'] - $collector['min_time'];
        }
        $value = computeStat(
            $dataset,
            $stat,
            $globalDuration,
            $metricDuration,
            $collector['is_rate_metric'] ?? false
        );
    }

    fputcsv($outputHandle, [$specString, formatNumber($value)], ',', '"', '\\');
}

if ($outputPath !== null) {
    fclose($outputHandle);
}
