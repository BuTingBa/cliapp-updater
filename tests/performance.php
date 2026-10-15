<?php

declare(strict_types=1);

// Real filesystem benchmark, isolated from application data. Run each case in a fresh process.
// php tests/performance.php 30000 identical [path/to/autoload.php]
use BuTing\CliAppUpdater\Files;
use BuTing\CliAppUpdater\Installer;

require $argv[3] ?? dirname(__DIR__) . '/autoload.php';
$count = (int) ($argv[1] ?? 10000);
$case = $argv[2] ?? 'fresh';
if ($count < 1 || $count > 50000 || !in_array($case, ['fresh', 'identical', 'changed'], true)) {
    throw new InvalidArgumentException('Expected 1..50000 files and fresh/identical/changed');
}
$directory = Files::directory(dirname(__DIR__) . '/.test-perf-' . bin2hex(random_bytes(5)));
try {
    $root = Files::directory($directory . '/app');
    $work = Files::directory($root . '/.updater');
    $archive = $directory . '/package.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $old = [];
    for ($i = 0; $i < $count; $i++) {
        $path = sprintf('app/group%04d/file%05d.php', intdiv($i, 100), $i);
        $content = '<?php /* ' . str_repeat(hash('sha256', (string) $i), 16) . ' */';
        if ($case !== 'fresh') {
            Files::write($root . '/' . $path, $content, 0644);
            touch($root . '/' . $path, 946684800);
            $old[$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mode' => 0644];
        }
        $zip->addFromString($path, $case === 'changed' && $i % 100 === 0 ? $content . ' changed' : $content);
    }
    $zip->close();
    $state = ['version_code' => 1, 'installed_files' => $old];
    Files::writeJson($work . '/state.json', $state);
    unset($old, $state); // Fixture construction must not inflate the installer's live memory.
    $installer = new Installer($root, $work);
    $times = [];
    $started = microtime(true);
    $hooks = [];
    foreach (['before_apply', 'after_apply'] as $phase) {
        $hooks[$phase] = static function () use (&$times, $phase, $started): void {
            $times[$phase] = round(microtime(true) - $started, 3);
        };
    }
    $result = $installer->install($archive, ['version' => 'benchmark', 'version_code' => 2], ['version_code' => 2], $hooks);
    $elapsed = round(microtime(true) - $started, 3);
    $peak = memory_get_peak_usage(true);
    $transaction = Files::json($work . '/transaction.json');
    $manifest = Files::json($work . '/state.json')['installed_files'];
    if (count($manifest) !== $count) {
        throw new RuntimeException('Benchmark installed manifest is incomplete');
    }
    $sample = $root . '/app/group0000/file00000.php';
    if (hash_file('sha256', $sample) !== $manifest['app/group0000/file00000.php']['sha256']) {
        throw new RuntimeException('Benchmark installed content differs from manifest');
    }
    if (isset($result['unchanged_files'])) {
        $expected = $case === 'fresh' ? $count : ($case === 'identical' ? 0 : (int) ceil($count / 100));
        if (count($transaction['entries']) !== $expected || $result['updated_files'] !== $expected) {
            throw new RuntimeException('Benchmark wrote unexpected files');
        }
        clearstatcache();
        if ($case !== 'fresh' && $count > 1 && filemtime($root . '/app/group0000/file00001.php') !== 946684800) {
            throw new RuntimeException('Benchmark rewrote an unchanged file');
        }
    }
    $report = ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'case' => $case, 'files' => $count,
        'zip_bytes' => filesize($archive), 'seconds' => $elapsed, 'phase_seconds' => $times,
        'peak_memory_mib' => round($peak / 1048576, 1),
        'transaction_files' => count($transaction['entries']),
        'backed_up_files' => count(array_filter($transaction['entries'], static function (array $entry): bool { return $entry['existed']; })),
        'unchanged_files' => $result['unchanged_files'] ?? null];
    echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
} finally {
    Files::removeTree($directory);
}
