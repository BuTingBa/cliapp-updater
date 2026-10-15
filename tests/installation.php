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
})($directory);
