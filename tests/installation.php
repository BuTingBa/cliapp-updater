<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\Files;
use BuTing\CliAppUpdater\Installer;

(static function (string $directory): void {
    $root = Files::directory($directory . '/optimized');
    $work = Files::directory($root . '/.updater');
    $archive = $directory . '/optimized.zip';
    $contents = ['same.php' => 'same', 'change.php' => 'old', 'obsolete.php' => 'remove'];
    $oldFiles = [];
    foreach ($contents as $path => $content) {
        Files::write($root . '/' . $path, $content, 0644);
        $oldFiles[$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mode' => 0644];
    }
    touch($root . '/same.php', 946684800);
    Files::writeJson($work . '/state.json', ['version_code' => 1, 'installed_files' => $oldFiles]);
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    foreach (['same.php' => 'same', 'change.php' => 'new', 'new.php' => 'new', '.env' => 'protected'] as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->setExternalAttributesName('new.php', ZipArchive::OPSYS_UNIX, 0100755 << 16);
    $zip->close();
    $installer = new Installer($root, $work);
    $result = $installer->install($archive, [], ['version_code' => 2]);
    $transaction = Files::json($work . '/transaction.json');
    expect($result['updated_files'] === 2 && $result['unchanged_files'] === 1 && $result['deleted_files'] === 1, 'Installation reports changed, identical and deleted files');
    expect(!isset($transaction['entries']['same.php']) && !file_exists($result['backup_dir'] . '/files/same.php'), 'Unchanged files are excluded from backup and recovery entries');
    clearstatcache();
    expect(filemtime($root . '/same.php') === 946684800 && !file_exists($root . '/.env'), 'Unchanged timestamps and protected files survive native extraction');
    expect(count(Files::json($work . '/state.json')['installed_files']) === 3, 'Manifest still includes unchanged files');
    if (PHP_OS_FAMILY !== 'Windows') {
        expect((fileperms($root . '/new.php') & 0777) === 0755, 'Native ZIP permissions are explicitly applied to new files');
    }
    $installer->rollback();
    expect(file_get_contents($root . '/change.php') === 'old' && is_file($root . '/obsolete.php') && !is_file($root . '/new.php'), 'Changed-only rollback restores replacements, deletions and additions');
    expect(filemtime($root . '/same.php') === 946684800, 'Rollback never rewrites skipped files');
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($contents as $path => $content) {
        $zip->addFromString($path, $content);
    }
    $zip->close();
    $called = false;
    $result = $installer->install($archive, [], ['version_code' => 2], ['after_apply' => static function () use (&$called): void { $called = true; }]);
    expect($result['updated_files'] === 0 && $result['unchanged_files'] === 3 && $called, 'Identical full package still runs hooks and advances version');
    expect(Files::json($work . '/transaction.json')['entries'] === [], 'Identical package has no file recovery entries');
    $installer->rollback();
    expect(Files::json($work . '/state.json')['version_code'] === 1, 'Empty file transaction can roll back version state');
    // Corrupt a stored entry without altering its central-directory CRC.
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('change.php', 'CRC_TEST_PAYLOAD');
    $zip->setCompressionName('change.php', ZipArchive::CM_STORE);
    $zip->close();
    $bytes = file_get_contents($archive);
    Files::write($archive, str_replace('CRC_TEST_PAYLOAD', 'CRC_TEST_CORRUPT', $bytes));
    rejects(static function () use ($installer, $archive): void { $installer->install($archive, [], ['version_code' => 2]); }, 'Native extraction rejects corrupted entry data');
    expect(file_get_contents($root . '/change.php') === 'old', 'Corrupt payload never reaches application files');
    // Inspect the staging directory directly: path preflight must fail before any extraction.
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('first.php', 'must not extract');
    $zip->addFromString('blocked/child.php', 'must not extract');
    $zip->close();
    Files::write($root . '/blocked', 'directory conflict');
    $stage = Files::directory($work . '/preflight-stage');
    $backup = Files::directory($work . '/preflight-backup');
    $extract = new ReflectionMethod(Installer::class, 'extract');
    $extract->setAccessible(true);
    $delta = null;
    $error = rejects(static function () use ($extract, $installer, $archive, $stage, $backup, &$delta): void {
        $extract->invokeArgs($installer, [$archive, $stage, &$delta, [], $backup]);
    }, 'Existing target parent conflict fails before extraction');
    expect(strpos($error->getMessage(), '/blocked') !== false && count(scandir($stage)) === 2, 'Preflight reports concrete target and leaves staging empty');

    // Full manifest: compare before extraction, including same-size local modifications.
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $targetFiles = [];
    foreach (['same.php' => 'same', 'change.php' => 'new', 'new.php' => 'new'] as $path => $content) {
        $zip->addFromString($path, $content);
        $targetFiles[$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mode' => 0644];
    }
    $zip->close();
    $inspect = static function () use ($work): void {
        $stages = array_filter(glob($work . '/stage-*'), 'is_dir');
        expect(count($stages) === 1, 'One private staging directory per install');
        $stage = reset($stages);
        expect(!file_exists($stage . '/same.php') && is_file($stage . '/change.php') && is_file($stage . '/new.php'),
            'Manifest match skips extraction; same-size changes are staged');
    };
    $result = $installer->install($archive, [], ['version_code' => 2], ['before_apply' => $inspect], null, $targetFiles);
    expect($result['extracted_files'] === 2 && $result['unchanged_files'] === 1 && $result['deleted_files'] === 1,
        'Selective extraction retains full manifest and obsolete cleanup');
    expect(isset($result['timings']['local_compare'], $result['timings']['extract_verify'], $result['timings']['commit_cleanup']),
        'Installation reports separate phase durations');
    $installer->rollback();
    expect(file_get_contents($root . '/change.php') === 'old' && is_file($root . '/obsolete.php') && !is_file($root . '/new.php'),
        'Moved staging files remain fully recoverable');
    rejects(static function () use ($installer, $archive, $inspect, $targetFiles): void {
        $installer->install($archive, [], ['version_code' => 2], ['before_apply' => $inspect,
            'after_apply' => static function (): bool { return false; }], null, $targetFiles);
    }, 'Selective installation automatically recovers after moved-file failure');
    expect(file_get_contents($root . '/change.php') === 'old' && is_file($root . '/obsolete.php'), 'Automatic rollback restores selective transaction');
    foreach (['missing', 'extra', 'size', 'mode', 'hash'] as $fault) {
        $bad = $targetFiles;
        if ($fault === 'missing') { unset($bad['new.php']); }
        if ($fault === 'extra') { $bad['absent.php'] = $bad['new.php']; }
        if ($fault === 'size') { $bad['new.php']['size']++; }
        if ($fault === 'mode') { $bad['new.php']['mode'] = 0755; }
        if ($fault === 'hash') { $bad['new.php']['sha256'] = str_repeat('0', 64); }
        rejects(static function () use ($installer, $archive, $bad): void {
            $installer->install($archive, [], ['version_code' => 2], [], null, $bad);
        }, 'Reject inconsistent full manifest: ' . $fault);
        expect(file_get_contents($root . '/change.php') === 'old', 'Invalid manifest never changes application content');
    }
    Files::write($root . '/change.php', 'new');
    Files::write($root . '/new.php', 'new');
    $result = $installer->install($archive, [], ['version_code' => 2], [], null, $targetFiles);
    expect($result['extracted_files'] === 0 && $result['unchanged_files'] === 3 && $result['updated_files'] === 0,
        'Identical manifest produces zero extracted or rewritten files');
    expect(count(Files::json($work . '/state.json')['installed_files']) === 3, 'Skipped payloads still establish complete baseline');
    $source = $work . '/verified-source';
    $destination = $root . '/move-target';
    Files::write($source, 'verified');
    Files::write($destination, 'previous');
    Files::install($source, $destination, 0644);
    expect(!file_exists($source) && file_get_contents($destination) === 'verified', 'Same-device installation moves instead of copying staged contents');
})($directory);
