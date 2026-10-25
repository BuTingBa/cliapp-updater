<?php

declare(strict_types=1);

// Included by run.php: reuse its real HTTP fixture and assertion helpers.
use BuTing\CliAppUpdater\Files;
use BuTing\CliAppUpdater\Updater;

function releaseFixture(int $id, array $contents, ?array $base = null, ?callable $mutate = null): array
{
    global $directory;
    package($contents);
    $files = [];
    foreach ($contents as $path => $content) {
        $files[$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mode' => 0644];
    }
    $manifest = ['schema' => 'apps.manifest.v1', 'files' => $files, 'file_count' => count($files),
        'expanded_size' => array_sum(array_column($files, 'size'))];
    Files::writeJson($directory . '/manifest.json', $manifest);
    $release = Files::json($directory . '/scenario.json')['release'];
    $release['version_code'] = $id;
    $release['version'] = '1.' . $id . '.0';
    $release['package']['id'] = $id;
    $release['package']['manifest_state'] = 'ready';
    $release['package']['manifest_sha256'] = hash_file('sha256', $directory . '/manifest.json');
    $release['package']['file_url'] = str_replace('package_id=1&', 'package_id=' . $id . '&', $release['package']['file_url']);
    $release['package']['manifest_url'] = str_replace('action=download', 'action=manifest', $release['package']['file_url']);
    if ($base !== null) {
        $changed = array_filter($files, static function ($file, $path) use ($base): bool {
            return ($base['files'][$path] ?? null) !== $file;
        }, ARRAY_FILTER_USE_BOTH);
        $document = ['schema' => 'apps.delta.v1', 'base_package_id' => $base['release']['package']['id'],
            'target_package_id' => $id, 'base_sha256' => $base['release']['package']['sha256'],
            'target_sha256' => $release['package']['sha256'], 'target_manifest_sha256' => $release['package']['manifest_sha256'],
            'files' => $changed, 'deleted' => array_values(array_diff(array_keys($base['files']), array_keys($files)))];
        $zip = new ZipArchive();
        $zip->open($directory . '/delta.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($changed as $path => $file) {
            $zip->addFromString('files/' . $path, $contents[$path]);
        }
        if ($mutate) {
            $mutate($document, $zip);
        }
        $zip->addFromString('update.json', \BuTing\CliAppUpdater\Json::encode($document, JSON_UNESCAPED_UNICODE));
        $zip->close();
        clearstatcache();
        $release['delta_package'] = array_replace($release['package'], [
            'id' => $id + 1000, 'package_type' => 2, 'base_package_id' => $base['release']['package']['id'], 'target_package_id' => $id,
            'file_size' => filesize($directory . '/delta.zip'), 'sha256' => hash_file('sha256', $directory . '/delta.zip'),
            'file_url' => str_replace('package_id=' . $id . '&', 'package_id=' . ($id + 1000) . '&', $release['package']['file_url']),
        ]);
    }
    Files::writeJson($directory . '/scenario.json', ['release' => $release]);
    return ['release' => $release, 'files' => $files];
}

$deltaRoot = Files::directory($directory . '/delta-app');
$deltaConfig = array_replace($config, ['project_dir' => $deltaRoot, 'work_dir' => $directory . '/delta-state']);
$deltaUpdater = new Updater($deltaConfig);
Files::write($deltaRoot . '/.env', 'customer configuration');
Files::write($deltaRoot . '/private-user.txt', 'customer data');
$baseContents = ['index.php' => 'base', 'unchanged.bin' => random_bytes(16384), 'obsolete.php' => 'old',
    'edited.php' => 'original', '中文目录/说明.txt' => '中文第一版', '123' => 'numeric filename', '.env' => 'never install'];
$base = releaseFixture(10, $baseContents);
$deltaUpdater->update();
expect($deltaUpdater->status()['package_id'] === 10 && !isset($deltaUpdater->status()['installed_files']), 'Full install records a private file baseline and public package identity');
expect(file_get_contents($deltaRoot . '/中文目录/说明.txt') === '中文第一版', 'UTF-8 filenames and content survive installation');
$targetContents = ['index.php' => 'target', 'unchanged.bin' => $baseContents['unchanged.bin'],
    'new.php' => 'new file', '中文目录/说明.txt' => '中文第二版'];
$target = releaseFixture(20, $targetContents, $base);
$withDeltaConfig = Files::json($directory . '/scenario.json');
$withDeltaConfig['release']['delta_package']['config'] = '{"delta_container":"not application config"}';
Files::writeJson($directory . '/scenario.json', $withDeltaConfig);
expect($deltaUpdater->manifest($deltaUpdater->check())['files'] === $target['files'], 'Controlled manifest endpoint returns verified file details');
$request = Files::json($directory . '/request.json');
expect($request['query']['action'] === 'manifest' && $request['product_key'] === '', 'Manifest request uses its ticket without sending product secrets');
$result = $deltaUpdater->update();
expect($result['package_type'] === 2 && file_get_contents($deltaRoot . '/index.php') === 'target', 'Exact baseline selects and installs the smaller delta');
expect($result['config'] === Updater::packageConfig($target['release']), 'Delta installation exposes target full-package configuration');
expect(!is_file($deltaRoot . '/obsolete.php') && !is_file($deltaRoot . '/edited.php'), 'Delta removes only obsolete tracked files');
expect(!is_file($deltaRoot . '/123'), 'Numeric filenames from PHP-generated deletion metadata are supported');
expect(file_get_contents($deltaRoot . '/private-user.txt') === 'customer data'
    && file_get_contents($deltaRoot . '/.env') === 'customer configuration', 'Delta never deletes customer or protected files');
expect(file_get_contents($deltaRoot . '/中文目录/说明.txt') === '中文第二版'
    && !file_exists($deltaRoot . '/update.json') && !file_exists($deltaRoot . '/files'), 'Delta wrapper stays out of project and UTF-8 survives');
expect($deltaUpdater->status()['package_id'] === 20, 'Delta commits target full-package identity');
$deltaUpdater->rollback();
expect($deltaUpdater->status()['package_id'] === 10 && is_file($deltaRoot . '/obsolete.php')
    && !is_file($deltaRoot . '/new.php'), 'Rollback restores deleted files, baseline and original version');
rejects(static function () use ($deltaUpdater): void {
    $deltaUpdater->update(['after_apply' => static function (): bool { return false; }]);
}, 'Delta hook failure propagates');
expect($deltaUpdater->status()['package_id'] === 10 && file_get_contents($deltaRoot . '/obsolete.php') === 'old', 'Failed delta automatically restores deletion and identity');

$deltaCliConfig = $directory . '/delta-crash.php';
Files::write($deltaCliConfig, '<?php $config = ' . var_export($deltaConfig, true)
    . '; $config["hooks"]["after_apply"] = static function () { exit(7); }; return $config;');
$crash = testProcess([PHP_BINARY, dirname(__DIR__) . '/bin/cli-app-updater', 'update', $deltaCliConfig],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/delta-crash.log', 'a'], 2 => ['file', $directory . '/delta-crash.log', 'a']], $crashPipes);
fclose($crashPipes[0]);
expect(proc_close($crash) === 7 && !is_file($deltaRoot . '/obsolete.php'), 'Delta process can terminate after deletion but before state commit');
expect($deltaUpdater->status()['transaction_status'] === 'applying' && $deltaUpdater->status()['package_id'] === 10,
    'Interrupted delta retains durable recovery plan and old baseline');
rejects(static function () use ($deltaUpdater): void { $deltaUpdater->update(); }, 'Interrupted delta blocks subsequent deployment');
$deltaUpdater->rollback();
expect(file_get_contents($deltaRoot . '/obsolete.php') === 'old' && is_file($deltaRoot . '/123')
    && $deltaUpdater->status()['package_id'] === 10, 'Interrupted delta recovery restores deleted files and baseline');

Files::write($deltaRoot . '/edited.php', 'local edits');
$result = $deltaUpdater->update();
expect($result['package_type'] === 1 && $result['retained_files'] === ['edited.php'], 'Local drift falls back to full package and reports modified obsolete file');
expect(file_get_contents($deltaRoot . '/edited.php') === 'local edits' && !is_file($deltaRoot . '/obsolete.php'), 'Full update safely cleans known obsolete files only');
$deltaUpdater->rollback();
Files::write($deltaRoot . '/edited.php', 'original');
$result = (new Updater($deltaConfig + ['prefer_delta' => false]))->update();
expect($result['package_type'] === 1, 'Caller can explicitly disable delta selection');
$deltaUpdater->rollback();

releaseFixture(20, $targetContents, $base, static function (array &$document): void {
    $document['base_sha256'] = str_repeat('0', 64);
});
expect($deltaUpdater->update()['package_type'] === 1, 'Different delta baseline hash falls back to the verified full package');
$deltaUpdater->rollback();

foreach (['target_hash', 'manifest_hash', 'traversal', 'missing', 'extra', 'file_hash', 'duplicate_delete'] as $fault) {
    releaseFixture(20, $targetContents, $base, static function (array &$document, ZipArchive $zip) use ($fault): void {
        if ($fault === 'target_hash') { $document['target_sha256'] = str_repeat('0', 64); }
        if ($fault === 'manifest_hash') { $document['target_manifest_sha256'] = str_repeat('0', 64); }
        if ($fault === 'traversal') { $document['deleted'][] = '../outside'; }
        if ($fault === 'missing') { $zip->deleteName('files/new.php'); }
        if ($fault === 'extra') { $zip->addFromString('unexpected.php', 'bad'); }
        if ($fault === 'file_hash') { $document['files']['new.php']['sha256'] = str_repeat('0', 64); }
        if ($fault === 'duplicate_delete') { $document['deleted'][] = 'obsolete.php'; }
    });
    rejects(static function () use ($deltaUpdater): void { $deltaUpdater->update(); }, 'Reject malformed delta: ' . $fault);
    expect(file_get_contents($deltaRoot . '/index.php') === 'base' && is_file($deltaRoot . '/obsolete.php'), 'Malformed delta leaves baseline untouched');
}
releaseFixture(20, $targetContents, $base, static function (array &$document): void {
    $document['deleted'][] = 'private-user.txt';
});
$deltaUpdater->update();
expect(file_get_contents($deltaRoot . '/private-user.txt') === 'customer data', 'Even explicit remote deletion cannot remove an untracked file');
$deltaUpdater->rollback();

$onlyDelete = $baseContents;
unset($onlyDelete['obsolete.php']);
releaseFixture(20, $onlyDelete, $base);
expect($deltaUpdater->update()['package_type'] === 2 && !is_file($deltaRoot . '/obsolete.php'), 'Deletion-only delta installs without payload files');
$deltaUpdater->rollback();

// A hashed but incomplete delta must still produce the complete target manifest.
releaseFixture(20, $targetContents, $base, static function (array &$document, ZipArchive $zip): void {
    unset($document['files']['new.php']);
    $zip->deleteName('files/new.php');
});
rejects(static function () use ($deltaUpdater): void { $deltaUpdater->update(); }, 'Missing target file rejected against target manifest');
releaseFixture(20, $targetContents, $base, static function (array &$document): void { $document['deleted'] = []; });
rejects(static function () use ($deltaUpdater): void { $deltaUpdater->update(); }, 'Missing deletion rejected against target manifest');
releaseFixture(20, $targetContents, $base);
$scenario = Files::json($directory . '/scenario.json');
$scenario['release']['delta_package']['base_package_id'] = 999;
Files::writeJson($directory . '/scenario.json', $scenario);
expect($deltaUpdater->update()['package_type'] === 1, 'Wrong baseline ID falls back to full package');
$deltaUpdater->rollback();
releaseFixture(20, $targetContents, $base);
Files::write($directory . '/http-config.php', '<?php return ' . var_export($deltaConfig, true) . ';');
expect($call('manifest')['data']['files'] === $target['files'], 'HTTP example exposes verified manifest');
expect($call('update')['data']['release']['package_type'] === 2, 'Non-CLI HTTP request installs a delta');
$call('rollback');
expect($deltaUpdater->status()['package_id'] === 10 && is_file($deltaRoot . '/obsolete.php'), 'HTTP delta rollback restores deletion and baseline');
releaseFixture(20, $targetContents, $base);
$badManifest = Files::json($directory . '/manifest.json');
$badManifest['files']['unchanged.bin']['sha256'] = str_repeat('0', 64);
Files::writeJson($directory . '/manifest.json', $badManifest);
rejects(static function () use ($deltaUpdater): void { $deltaUpdater->update(); }, 'Changed remote manifest rejected by raw-byte hash');
expect($deltaUpdater->status()['package_id'] === 10, 'Rejected manifest does not commit version');

// Legacy states are valid but cannot claim a baseline merely from a version number.
releaseFixture(20, $targetContents, $base);
$legacyState = Files::json($deltaConfig['work_dir'] . '/state.json');
unset($legacyState['installed_files']);
Files::writeJson($deltaConfig['work_dir'] . '/state.json', $legacyState);
expect($deltaUpdater->update()['package_type'] === 1 && is_file($deltaRoot . '/obsolete.php'), 'Legacy state uses full update without guessing deletion ownership');
$deltaUpdater->rollback();
expect((glob($deltaConfig['work_dir'] . '/stage-*') ?: []) === [], 'Delta staging cleaned after success and rejection');
