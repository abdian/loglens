<?php

/**
 * Reproducible LogLens benchmark suite.
 *
 *   php benchmarks/run.php --size=1G        # generate + benchmark a 1 GB file
 *   php benchmarks/run.php --entries=500000 # or by entry count
 *   php benchmarks/run.php --keep           # keep the generated fixture
 *
 * Measures: index build throughput, index-free cold-open (tail-first) latency,
 * warm substring-search latency, and peak memory. Compare against opcodesio by
 * pointing both at the same generated fixture.
 */

require __DIR__ . '/../vendor/autoload.php';

use LogLens\Fingerprint\FingerprintEngine;
use LogLens\Indexing\Indexer;
use LogLens\Indexing\IndexManager;
use LogLens\Indexing\TailReader;
use LogLens\Parsing\ParserManager;
use LogLens\Search\SearchEngine;
use LogLens\Sources\LocalFileSource;
use LogLens\Support\ByteSize;

$opts = getopt('', ['size::', 'entries::', 'keep', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php benchmarks/run.php [--size=1G|--entries=N] [--keep]\n");
    exit(0);
}

$work = sys_get_temp_dir() . '/loglens_bench_' . getmypid();
$logDir = $work . '/logs';
$idxDir = $work . '/index';
@mkdir($logDir, 0775, true);
@mkdir($idxDir, 0775, true);
$logFile = $logDir . '/laravel.log';

function human(float $ms): string
{
    return $ms < 1 ? round($ms * 1000, 1) . ' µs' : round($ms, 1) . ' ms';
}

// ---- generate fixture ------------------------------------------------------

$targetBytes = isset($opts['size']) ? ByteSize::parse($opts['size']) : null;
$targetEntries = isset($opts['entries']) ? (int) $opts['entries'] : ($targetBytes ? null : 200000);

fwrite(STDOUT, "Generating fixture…\n");
$t0 = microtime(true);
$fh = fopen($logFile, 'wb');
$base = gmmktime(0, 0, 0, 1, 1, 2026);
$i = 0;
$messages = [
    'Processing order %d for user %d',
    'Cache hit for key user:%d:profile',
    'payment failed for order %d retrying',
    'Mail queued to user %d',
    'Query executed in %d ms',
];
while (true) {
    $ts = gmdate('Y-m-d H:i:s', $base + $i);
    $level = $i % 50 === 0 ? 'ERROR' : ($i % 11 === 0 ? 'WARNING' : 'INFO');
    $msg = sprintf($messages[$i % count($messages)], $i, $i % 1000);
    fwrite($fh, "[$ts] production.$level: $msg\n");
    $i++;
    if ($targetEntries !== null && $i >= $targetEntries) {
        break;
    }
    if ($targetBytes !== null && $i % 1000 === 0 && ftell($fh) >= $targetBytes) {
        break;
    }
}
fclose($fh);
$size = filesize($logFile);
$genMs = (microtime(true) - $t0) * 1000;
fwrite(STDOUT, sprintf("  %s entries, %s in %s\n\n", number_format($i), ByteSize::format($size), human($genMs)));

// ---- wire services ---------------------------------------------------------

$source = new LocalFileSource([$logDir], ['*.log'], []);
$fileId = $source->list()[0]->id;
$parsers = new ParserManager(['vendor_markers' => ['/vendor/']]);
$manager = new IndexManager(['directory' => $idxDir, 'size_budget' => '10G']);
$indexer = new Indexer($source, $parsers, new FingerprintEngine([]), $manager, ['batch_size' => 5000]);
$tail = new TailReader($source, $parsers);
$search = new SearchEngine($source, $manager, $parsers, []);

// ---- cold open (index-free, tail-first) ------------------------------------

$t0 = microtime(true);
$page = $tail->newestPage($fileId, 100);
$coldMs = (microtime(true) - $t0) * 1000;
fwrite(STDOUT, sprintf("Cold open (index-free tail, 100 newest): %s  [%d entries]\n", human($coldMs), count($page['entries'])));

// ---- index build -----------------------------------------------------------

$t0 = microtime(true);
$status = $indexer->index($fileId);
$buildSec = microtime(true) - $t0;
$rate = $status['entries'] / max(0.001, $buildSec);
fwrite(STDOUT, sprintf(
    "Index build: %s entries in %.2fs = %s entries/s  (%s, %s)\n",
    number_format($status['entries']),
    $buildSec,
    number_format($rate),
    $status['driver'],
    ByteSize::format(directorySize($idxDir))
));

// ---- warm search -----------------------------------------------------------

foreach (['payment', 'order 4', 'level:error payment', '/user:\d+:profile/'] as $q) {
    $t0 = microtime(true);
    $res = $search->search($fileId, $q, [], null, 100);
    $ms = (microtime(true) - $t0) * 1000;
    fwrite(STDOUT, sprintf("Warm search %-22s : %8s  [%d hits, tier=%s]\n", '"' . $q . '"', human($ms), count($res['entries']), $res['tier']));
}

fwrite(STDOUT, sprintf("\nPeak memory: %s\n", ByteSize::format(memory_get_peak_usage(true))));

// ---- cleanup ---------------------------------------------------------------

if (! isset($opts['keep'])) {
    array_map('unlink', glob($idxDir . '/*') ?: []);
    @unlink($logFile);
    @rmdir($idxDir);
    @rmdir($logDir);
    @rmdir($work);
} else {
    fwrite(STDOUT, "\nFixture kept at: $logFile\n");
}

function directorySize(string $dir): int
{
    $total = 0;
    foreach (glob($dir . '/*') ?: [] as $f) {
        $total += is_file($f) ? filesize($f) : 0;
    }

    return $total;
}
