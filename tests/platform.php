<?php

declare(strict_types=1);

/**
 * Optional monorepo integration against the configured real MySQL and ThinkPHP platform.
 * Run with PHP 8.4: php tests/platform.php --allow-test-data
 * Starts a loopback server; creates only its own tagged fixtures, deletes them in finally.
 * No DDL, no existing business-data updates, no email/SMS. Requires server/vendor and server/.env.
 */
use app\service\{License, LicenseCertificate, Package, PackageBuild, Product, Release};
use BuTing\CliAppUpdater\{Files, OfflineLicense, Updater};
use think\facade\Db;

if (!in_array('--allow-test-data', $argv, true)) {
    fwrite(STDERR, "Requires --allow-test-data; uses the platform's configured database.\n");
    exit(2);
}
$server = dirname(__DIR__, 3) . '/server';
require $server . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';
(new think\App($server))->initialize();

$tag = 'updater_' . bin2hex(random_bytes(6));
$directory = Files::directory(dirname(__DIR__) . '/.test-' . $tag);
$uid = $productId = 0;
$process = null;
$failure = null;
$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

/** Run only this fixture's tasks, never consume another developer's pending queue. */
function buildFixture(string $key, string $work): void
{
    \app\support\DatabaseLock::run('package-worker', static function () use ($key, $work): void {
        $file = Db::name('stored_file')->where('file_url', $key)->find();
        foreach (Db::name('package_task')->where('file_id', $file['id'])->where('state', 'pending')->order('id')->select() as $task) {
            $directory = Files::directory($work . '/' . $task['id']);
            try {
                $method = new ReflectionMethod(PackageBuild::class, $task['kind'] === 'manifest' ? 'analyze' : 'buildDelta');
                $arguments = $task['kind'] === 'manifest' ? [$file, $directory] : [$task, $file, $directory];
                $method->invokeArgs(new PackageBuild(), $arguments);
                Db::name('package_task')->where('id', $task['id'])->update(['state' => 'succeeded']);
                $file = Db::name('stored_file')->find($file['id']);
            } finally {
                Files::removeTree($directory);
            }
        }
    });
}
try {
    foreach (['product' => ['secret_key'], 'release_package' => ['sha256', 'requirements'], 'upgrade_license' => ['binding_client', 'verification_mode']] as $table => $columns) {
        verify(!array_diff($columns, array_column(Db::query('SHOW COLUMNS FROM ' . $table), 'Field')), 'Current live schema supports updater protocol');
    }
    $uid = (int) Db::name('user')->insertGetId([
        'email' => $tag . '@example.invalid', 'nickname' => $tag,
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'is_root' => 0,
    ]);
    $productId = (new Product())->save($uid, ['name' => $tag, 'code' => $tag . '.%2F+\\', 'license_mode' => 1, 'public_display' => 0])['id'];
    $product = (new Product())->owned($uid, $productId);
    $archive = $directory . '/fixture.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('index.php', '<?php echo "new-release";');
    $zip->addFromString('.env', 'must-not-replace');
    $unchanged = random_bytes(16384);
    $zip->addFromString('assets/unchanged.bin', $unchanged);
    $zip->addFromString('obsolete.php', 'obsolete');
    $zip->addFromString('123', 'numeric filename');
    $zip->addFromString('中文/说明.txt', '原始中文');
    $zip->close();
    $upload = (new Package())->upload($uid, new think\file\UploadedFile($archive, 'fixture.zip', 'application/zip', null, true));
    buildFixture($upload['file_url'], $directory . '/tasks');
    (new Release())->save($uid, [
        'product_id' => $productId, 'version' => '1.0.0', 'version_code' => 1, 'state' => 1, 'force_update' => 1,
        'packages' => [$upload + ['platform' => 'php', 'requirements' => 'PHP >= 7.4']],
    ]);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $server . '/public', $server . '/public/router.php'], [
        0 => ['pipe', 'r'], 1 => ['file', $directory . '/http.log', 'a'], 2 => ['file', $directory . '/http.log', 'a'],
    ], $pipes, $server);
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($connection) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    verify($ready, 'Real local platform starts');
    $root = Files::directory($directory . '/app');
    Files::write($root . '/index.php', 'old');
    Files::write($root . '/.env', 'local');
    $config = [
        'base_url' => 'http://' . $address, 'http' => ['allow_http' => true],
        'product_code' => $product['code'], 'product_key' => $product['secret_key'],
        'project_dir' => $root, 'domain' => 'example.com',
    ];
    $updater = new Updater($config);
    $localKey = $updater->getClientKey();
    $report = $updater->report();
    verify($report['registered'], 'Updater registers a real installation');
    verify(Db::name('client')->where('id', $report['client_id'])->value('client_key') === $localKey, 'Displayed key matches actual platform registration');
    try {
        $updater->check();
        throw new LogicException('Unlicensed instance was accepted');
    } catch (RuntimeException $error) {
        verify($error->getCode() === 403, 'Registration does not grant entitlement');
    }
    $license = (new License())->save($uid, ['product_id' => $productId, 'client_id' => $report['client_id'],
        'bindings' => ['instance', 'domain'], 'domain' => 'example.com', 'verification_mode' => 'offline']);
    $config['upgrade_token'] = $license['license_value'];
    $updater = new Updater($config);
    verify($updater->authorize()['authorized'], 'Online license validates through real API');
    $release = $updater->check();
    verify($release['installed'] && $updater->status()['version_code'] === 1, 'Real forced check installs immediately');
    $updater->rollback();
    verify($release['force_update'] === true && Updater::summary($release)['force_update'] === true, 'Real forced-update flag reaches client summary');
    verify($release['update'] && $release['package']['platform'] === 'php', 'Real API response matches package contract');
    $notes = $updater->changelog();
    verify($notes['total'] === 1 && $notes['list'][0]['version'] === '1.0.0' && $notes['current_version_code'] === 0, 'Real changelog matches installed version');
    verify($updater->changelog('before')['list'] === [], 'Real earlier history excludes later releases');
    verify(!isset($notes['list'][0]['package']), 'Real changelog issues no ticket');
    $file = $updater->download($release);
    verify(hash_file('sha256', $file) === $upload['sha256'], 'Real ticket delivers original package bytes');
    unlink($file);
    $certificate = (new LicenseCertificate())->export($uid, $license['id']);
    $issued = OfflineLicense::verify($certificate['document'], $certificate['public_key'], $product['code'],
        $updater->status()['client_key'], 'example.com');
    verify($issued['license_id'] === (string) $license['id'], 'Actual platform certificate verifies in independent library');
    $result = $updater->update();
    verify($result['version_code'] === 1 && file_get_contents($root . '/index.php') === '<?php echo "new-release";', 'Actual platform package installed');
    verify(file_get_contents($root . '/.env') === 'local', 'Local configuration protected during real update');
    verify($updater->check()['update'] === false, 'Real platform observes committed version');
    $updater->rollback();
    verify(file_get_contents($root . '/index.php') === 'old' && $updater->status()['version_code'] === 0, 'Real update file recovery succeeds');
    $cliConfig = $directory . '/cli.php';
    Files::write($cliConfig, '<?php return ' . var_export($config, true) . ';');
    foreach (['client-key', 'changelog', 'update', 'rollback'] as $command) {
        $client = proc_open([getenv('UPDATER_TEST_PHP') ?: PHP_BINARY, dirname(__DIR__) . '/bin/cli-app-updater', $command, $cliConfig],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $clientPipes);
        fclose($clientPipes[0]);
        $output = stream_get_contents($clientPipes[1]);
        $errors = stream_get_contents($clientPipes[2]);
        fclose($clientPipes[1]);
        fclose($clientPipes[2]);
        verify(proc_close($client) === 0 && $errors === '' && is_array(json_decode($output, true)), 'Real-platform CLI ' . $command . ' succeeds');
    }
    verify(file_get_contents($root . '/index.php') === 'old' && $updater->status()['version_code'] === 0, 'CLI deployment and recovery restore original installation');
    $updater->update();
    $baseId = $updater->status()['package_id'];
    $nextArchive = $directory . '/next.zip';
    $zip = new ZipArchive();
    $zip->open($nextArchive, ZipArchive::CREATE);
    $zip->addFromString('index.php', 'next-release');
    $zip->addFromString('assets/unchanged.bin', $unchanged);
    $zip->addFromString('中文/说明.txt', '新版中文');
    $zip->addFromString('new.php', 'new');
    $zip->close();
    $nextUpload = (new Package())->upload($uid, new think\file\UploadedFile($nextArchive, 'next.zip', 'application/zip', null, true));
    buildFixture($nextUpload['file_url'], $directory . '/tasks');
    (new Release())->save($uid, ['product_id' => $productId, 'version' => '1.1.0', 'version_code' => 2, 'state' => 1, 'force_update' => 1,
        'delta_enabled' => 1, 'base_package_id' => $baseId, 'packages' => [$nextUpload + ['platform' => 'php']]]);
    buildFixture($nextUpload['file_url'], $directory . '/tasks');
    $next = $updater->check();
    verify($next['delta_package']['base_package_id'] === $baseId, 'Real central API offers exact-base delta');
    verify(isset($updater->manifest($next)['files']['中文/说明.txt']), 'Real manifest hash and UTF-8 filenames verify');
    $result = $next['installation'];
    verify($next['installed'] && $result['package_type'] === 2 && file_get_contents($root . '/index.php') === 'next-release', 'Real central-generated delta installs');
    verify(!is_file($root . '/obsolete.php') && file_get_contents($root . '/.env') === 'local', 'Real delta deletion protects local configuration');
    verify(!is_file($root . '/123'), 'Real central numeric filename deletion is compatible');
    verify(file_get_contents($root . '/中文/说明.txt') === '新版中文', 'Real delta preserves UTF-8 content and filenames');
    $updater->rollback();
    verify($updater->status()['package_id'] === $baseId && is_file($root . '/obsolete.php'), 'Real delta rollback restores baseline and deleted files');
    $deltaCli = proc_open([getenv('UPDATER_TEST_PHP') ?: PHP_BINARY, dirname(__DIR__) . '/bin/cli-app-updater', 'update', $cliConfig],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $deltaPipes);
    fclose($deltaPipes[0]);
    $deltaResult = json_decode(stream_get_contents($deltaPipes[1]), true);
    $deltaErrors = stream_get_contents($deltaPipes[2]);
    fclose($deltaPipes[1]);
    fclose($deltaPipes[2]);
    verify(proc_close($deltaCli) === 0 && $deltaErrors === '' && $deltaResult['package_type'] === 2, 'Real central delta installs through configured PHP CLI');
    $updater->rollback();
    Db::name('upgrade_license')->where('id', $license['id'])->update(['state' => 0]);
    try {
        $updater->download($release);
        throw new LogicException('Revoked ticket was accepted');
    } catch (RuntimeException $error) {
        verify($error->getCode() === 403, 'Download revalidates revoked entitlement');
    }
    verify((bool) OfflineLicense::verify($certificate['document'], $certificate['public_key'], $product['code'],
        $updater->status()['client_key'], 'example.com'), 'Existing offline certificate remains a signed snapshot');
    verify(Db::name('upgrade_log')->where('product_id', $productId)->where('action', 'download')->count() >= 2, 'Real download audit chain records requests');
} catch (Throwable $error) {
    $failure = $error;
    // Never print DB exception traces or request credentials.
    fwrite(STDERR, 'FAIL: ' . get_class($error) . ': ' . $error->getMessage() . ' (' . basename($error->getFile()) . ':' . $error->getLine() . ")\n");
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($uid) {
        $packageIds = $productId ? Db::name('release_package')->alias('k')->join('release_version r', 'k.release_id=r.id')
            ->where('r.product_id', $productId)->column('k.id') : [];
        foreach (glob(runtime_path() . 'private_state/*.json') ?: [] as $path) {
            $data = json_decode(file_get_contents($path), true);
            if (isset($data['package_id']) && in_array((int) $data['package_id'], array_map('intval', $packageIds), true)) {
                unlink($path);
            }
        }
        Db::transaction(static function () use ($uid, $productId): void {
            $fileIds = Db::name('stored_file')->where('user_id', $uid)->column('id');
            if ($fileIds) {
                Db::name('package_task')->whereIn('file_id', $fileIds)->delete();
            }
            $releaseIds = $productId ? Db::name('release_version')->where('product_id', $productId)->column('id') : [];
            if ($releaseIds) {
                Db::name('release_package')->whereIn('release_id', $releaseIds)->delete();
            }
            foreach (['upgrade_log', 'upgrade_license', 'release_version'] as $table) {
                Db::name($table)->where('product_id', $productId)->delete();
            }
            Db::name('product')->where('user_id', $uid)->delete();
            foreach (['stored_file', 'client', 'config'] as $table) {
                Db::name($table)->where('user_id', $uid)->delete();
            }
            Db::name('traffic_download')->where('user_id', $uid)->delete();
            Db::name('traffic_month')->where('user_id', $uid)->delete();
            Db::name('user')->where('id', $uid)->delete();
        });
        Files::removeTree($server . '/upload/packages/' . $uid);
        verify(Db::name('user')->where('id', $uid)->count() === 0, 'Temporary developer and related records cleaned');
    }
    Files::removeTree($directory);
}
echo ($failure ? 'FAIL' : 'PASS') . ': ' . $checks . " real-platform assertions; fixtures cleaned.\n";
exit($failure ? 1 : 0);
