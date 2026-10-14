<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\Files;
use BuTing\CliAppUpdater\ClientIdentity;
use BuTing\CliAppUpdater\OfflineLicense;
use BuTing\CliAppUpdater\Updater;

require dirname(__DIR__) . '/autoload.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function expect(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function rejects(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        expect(true, $message);
        return $error;
    }
    throw new RuntimeException('Unexpected acceptance: ' . $message);
}
function package(array $files, ?callable $configure = null): void
{
    global $directory;
    $zip = new ZipArchive();
    $zip->open($directory . '/package.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $path => $content) {
        $zip->addFromString($path, $content);
    }
    if ($configure) {
        $configure($zip);
    }
    $zip->close();
    clearstatcache();
    scenario();
}
function scenario(string $mode = '', array $overrides = []): void
{
    global $directory;
    Files::writeJson($directory . '/scenario.json', ['mode' => $mode, 'release' => array_replace_recursive([
        'update' => true, 'status_code' => 200, 'version' => '1.0.0', 'version_code' => 1,
        'changelog' => 'fixture', 'published_at' => '2026-10-01 12:00:00', 'license_value' => 'fixture-license',
        'package' => [
            'package_type' => 1, 'platform' => 'php', 'file_name' => 'app.zip',
            'file_size' => filesize($directory . '/package.zip'), 'sha256' => hash_file('sha256', $directory . '/package.zip'),
            'file_url' => '/api/' . rawurlencode('app.%2F+\\测试') . '?action=download&package_id=1&ticket=' . str_repeat('a', 64),
            'file_full_url' => 'https://untrusted.invalid/never-used', 'requirements' => 'PHP >= 7.4',
            'config' => '{"command":"must not execute"}',
        ],
    ], $overrides)]);
}

$directory = Files::directory(dirname(__DIR__) . '/.test-' . bin2hex(random_bytes(5)));
$process = null;
$httpProcess = null;
$failure = null;
try {
    $root = Files::directory($directory . '/app');
    Files::write($root . '/index.php', 'old-code');
    Files::write($root . '/.env', 'local-secret');
    Files::write($root . '/public/uploads/customer.txt', 'customer-data');
    Files::write($root . '/config/local.php', 'local-config');
    package(['index.php' => 'new-code', 'nested/new.php' => 'new-file', '.env' => 'replace-forbidden',
        'public/uploads/customer.txt' => 'replace-forbidden', 'config/local.php' => 'replace-forbidden']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $environment = getenv();
    $environment['UPDATER_TEST_DIRECTORY'] = $directory;
    $process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/router.php'], [
        0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a'],
    ], $pipes, __DIR__, $environment, ['bypass_shell' => true]);
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
    expect($ready, 'Fixture HTTP server starts');
    $config = [
        'base_url' => 'http://' . $address, 'product_code' => 'app.%2F+\\测试', 'product_key' => 'fixture-key',
        'upgrade_token' => 'fixture-token', 'project_dir' => $root, 'work_dir' => $directory . '/state',
        'domain' => 'Example.COM.', 'client_name' => 'Fixture instance', 'preserve' => ['config/local.php'],
        'machine_id' => 'fixture-machine-a',
        'http' => ['allow_http' => true],
    ];
    rejects(static function () use ($config): void {
        new Updater(array_replace($config, ['http' => []]));
    }, 'HTTP requires explicit opt-in');
    rejects(static function () use ($config, $root): void {
        new Updater(array_replace($config, ['work_dir' => $root . '/unsafe']));
    }, 'Work directory cannot be inside updated project');
    $updater = new Updater($config);
    expect($updater->check()['update'], 'check initializes the installation without a preceding status call');
    $lastRequest = file_get_contents($directory . '/request.json');
    $clientKey = $updater->getClientKey();
    expect(file_get_contents($directory . '/request.json') === $lastRequest, 'getClientKey performs no HTTP request');
    $initial = $updater->status();
    expect(strlen($initial['client_key']) === 32 && $initial['version_code'] === 0, 'Persistent identity generated');
    expect($clientKey === $initial['client_key'] && $initial['client_key_source'] === 'machine', 'Getter exposes the installation key and source');
    expect(!isset($initial['machine_tag']) && strpos(file_get_contents($config['work_dir'] . '/state.json'), 'fixture-machine-a') === false, 'Raw machine identifier never stored in state or exposed by status');
    $fresh = new Updater(array_replace($config, ['work_dir' => $directory . '/recreated-state']));
    expect($fresh->getClientKey() === $clientKey, 'Same machine/product/project recreates the same key without copying state');
    $otherMachine = new Updater(array_replace($config, ['work_dir' => $directory . '/other-machine', 'machine_id' => 'fixture-machine-b']));
    expect($otherMachine->getClientKey() !== $clientKey, 'A different server generates a different key');
    $otherRoot = Files::directory($directory . '/second-site');
    $otherSite = new Updater(array_replace($config, ['work_dir' => $directory . '/second-state', 'project_dir' => $otherRoot]));
    expect($otherSite->getClientKey() !== $clientKey, 'Two sites on one server have independent keys');
    $otherProduct = new Updater(array_replace($config, ['work_dir' => $directory . '/second-product', 'product_code' => 'another-product']));
    expect($otherProduct->getClientKey() !== $clientKey, 'Products have separate machine-derived keys');
    $copiedWork = Files::directory($directory . '/copied-state');
    Files::copy($config['work_dir'] . '/state.json', $copiedWork . '/state.json');
    rejects(static function () use ($config, $copiedWork): void {
        (new Updater(array_replace($config, ['work_dir' => $copiedWork, 'machine_id' => 'fixture-machine-b'])))->getClientKey();
    }, 'Copying state to another identifiable server cannot silently reuse the key');
    $randomConfig = array_replace($config, ['work_dir' => $directory . '/random-state', 'machine_id' => '']);
    $randomKey = (new Updater($randomConfig))->getClientKey();
    expect((new Updater($randomConfig))->getClientKey() === $randomKey, 'Unavailable OS identity falls back to a persisted random key');
    expect((new Updater(array_replace($randomConfig, ['machine_id' => 'now-readable'])))->getClientKey() === $randomKey, 'Later OS detection does not rotate existing random licenses');
    $explicit = new Updater(array_replace($config, ['work_dir' => $directory . '/explicit-state', 'client_key' => 'chosen_client_001']));
    expect($explicit->getClientKey() === 'chosen_client_001', 'Explicit client key still takes precedence');
    $legacyWork = Files::directory($directory . '/legacy-state');
    $legacyState = Files::json($config['work_dir'] . '/state.json');
    unset($legacyState['machine_tag'], $legacyState['client_key_source']);
    $legacyState['client_key'] = 'existing_license_001';
    $legacyState['version_code'] = 17;
    Files::writeJson($legacyWork . '/state.json', $legacyState);
    $legacy = new Updater(array_replace($config, ['work_dir' => $legacyWork]));
    expect($legacy->getClientKey() === 'existing_license_001' && $legacy->status()['version_code'] === 17, 'Old installations retain their approved key and installed version');
    $detected = ClientIdentity::detect();
    expect($detected === null || (is_string($detected) && $detected !== ''), 'Native OS reader returns an identifier or an explicit fallback');
    expect((new Updater($config))->status()['client_key'] === $initial['client_key'], 'Identity survives reconstruction');
    expect($updater->report()['registered'] === true, 'Report works');
    $request = Files::json($directory . '/request.json');
    expect($request['body']['domain'] === 'example.com' && $request['method'] === 'POST', 'Report normalizes domain and sends JSON');
    expect($updater->authorize()['authorized'] === true, 'Authorize works');
    $release = $updater->check();
    $request = Files::json($directory . '/request.json');
    expect($request['path'] === '/api/' . rawurlencode($config['product_code']), 'Product code encoded exactly once');
    expect($request['query']['action'] === 'check' && $request['query']['platform'] === 'php' && $request['query']['os_info']['php'] === PHP_VERSION, 'Canonical target and PHP environment');
    expect($request['product_key'] === 'fixture-key' && $request['upgrade_token'] === 'fixture-token'
        && $request['client_key'] === $initial['client_key'], 'Credential headers follow platform contract');
    $notes = $updater->changelog();
    $request = Files::json($directory . '/request.json');
    expect($notes['direction'] === 'after' && $notes['current_version_code'] === 0, 'Changelog defaults to installed version and after');
    expect($request['query']['action'] === 'changelog' && $request['product_key'] === 'fixture-key', 'Changelog uses shared API and identity headers');
    expect(!isset($notes['list'][0]['package'], $notes['list'][0]['license_value']), 'Changelog contains no download credentials');
    expect($updater->changelog('before', 2, 5)['page'] === 2, 'Changelog pagination and direction forwarded');
    rejects(static function () use ($updater): void { $updater->changelog('unknown'); }, 'Invalid direction rejected');
    rejects(static function () use ($updater): void { $updater->changelog('after', 0); }, 'Invalid page rejected');
    rejects(static function () use ($updater): void { $updater->changelog('after', 1, 101); }, 'Invalid size rejected');
    $download = $updater->download($release);
    expect(hash_file('sha256', $download) === $release['package']['sha256'], 'Real HTTP streaming download verified');
    $request = Files::json($directory . '/request.json');
    expect($request['product_key'] === '' && $request['upgrade_token'] === '', 'Download does not send API credentials');
    expect($updater->status()['version_code'] === 0, 'Download does not advance installed version');
    unlink($download);
    $events = [];
    $hooks = [];
    foreach (['before_apply', 'after_apply', 'after_commit'] as $name) {
        $hooks[$name] = static function (array $data) use (&$events, $name): void {
            expect(!isset($data['license_value'], $data['package']), 'Hooks receive no credentials or download ticket');
            $events[] = $name;
        };
    }
    $installed = $updater->update($hooks);
    expect($events === ['before_apply', 'after_apply', 'after_commit'], 'Hooks execute in deployment order');
    expect(file_get_contents($root . '/index.php') === 'new-code' && is_file($root . '/nested/new.php'), 'Update replaces code and adds files');
    expect(file_get_contents($root . '/.env') === 'local-secret'
        && file_get_contents($root . '/public/uploads/customer.txt') === 'customer-data'
        && file_get_contents($root . '/config/local.php') === 'local-config', 'Default and configured persistent paths preserved');
    expect($updater->status()['version_code'] === 1 && is_file($installed['backup_dir'] . '/manifest.json'), 'Commit records version and backup');
    expect($updater->update() === null, 'Same resident object rereads state and sees no update');
    $updater->rollback();
    expect(file_get_contents($root . '/index.php') === 'old-code' && !file_exists($root . '/nested'), 'Rollback restores old code and removes new files/directories');
    expect($updater->status()['version_code'] === 0, 'Rollback restores previous version');
    $updater->rollback();
    expect($updater->status()['transaction_status'] === 'rolled_back', 'Repeated rollback is idempotent');
    rejects(static function () use ($updater): void {
        $updater->update(['after_apply' => static function (): void {
            throw new RuntimeException('health check failed');
        }]);
    }, 'Hook failure propagates');
    expect(file_get_contents($root . '/index.php') === 'old-code' && $updater->status()['version_code'] === 0, 'Hook failure automatically restores files and version');
    rejects(static function () use ($updater): void {
        $updater->update(['after_commit' => static function (): bool {
            return false;
        }]);
    }, 'Post-commit failure reported');
    expect($updater->status()['version_code'] === 1 && file_get_contents($root . '/index.php') === 'new-code', 'Reload failure retains committed version');
    $updater->rollback();
    $updater->update();
    $transactionPath = $config['work_dir'] . '/transaction.json';
    $transaction = Files::json($transactionPath);
    $transaction['status'] = 'applying';
    Files::writeJson($transactionPath, $transaction);
    rejects(static function () use ($updater): void {
        $updater->update();
    }, 'Interrupted transaction blocks new installation');
    $updater->rollback();
    expect($updater->status()['version_code'] === 0, 'Interrupted transaction is recoverable');
    $lock = fopen($config['work_dir'] . '/update.lock', 'c');
    flock($lock, LOCK_EX);
    rejects(static function () use ($updater): void {
        $updater->check();
    }, 'Concurrent updater cannot acquire lock');
    flock($lock, LOCK_UN);
    fclose($lock);
    foreach (['forbidden', 'invalid_json', 'business_error', 'redirect', 'download_redirect', 'expired'] as $mode) {
        scenario($mode);
        $error = rejects(static function () use ($updater): void {
            $updater->update();
        }, 'Reject response: ' . $mode);
        expect(strpos($error->getMessage(), 'fixture-secret-value') === false, 'Server error cannot leak secret response');
        expect(file_get_contents($root . '/index.php') === 'old-code', 'Failed request leaves project unchanged');
    }
    foreach ([['sha256' => str_repeat('0', 64)], ['file_size' => 1], ['file_size' => 999999],
        ['file_url' => 'https://untrusted.invalid/file'], ['platform' => 'linux-amd64'], ['file_name' => 'app.tar.gz']] as $badPackage) {
        scenario('', ['package' => $badPackage]);
        rejects(static function () use ($updater): void {
            $updater->update();
        }, 'Invalid package metadata rejected');
    }
    foreach (['../escape.php', '/absolute.php', 'C:/windows.php', '..\\escape.php', 'nested/../escape.php',
        'trailing./file', 'nul.txt', 'file:stream', 'folder//file', './file'] as $badPath) {
        package([$badPath => 'unsafe']);
        rejects(static function () use ($updater): void {
            $updater->update();
        }, 'ZIP path rejected: ' . $badPath);
        expect(file_get_contents($root . '/index.php') === 'old-code', 'Unsafe archive cannot alter application');
    }
    package(['Index.php' => 'a', 'index.php' => 'b']);
    rejects(static function () use ($updater): void {
        $updater->update();
    }, 'Case-colliding ZIP entries rejected');
    package(['Folder/a.php' => 'a', 'folder/b.php' => 'b']);
    rejects(static function () use ($updater): void {
        $updater->update();
    }, 'Case-colliding implicit ZIP directories rejected');
    package(['parent' => 'a', 'parent/child' => 'b']);
    rejects(static function () use ($updater): void {
        $updater->update();
    }, 'ZIP file-directory conflict rejected');
    package(['link' => '/outside'], static function (ZipArchive $zip): void {
        $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    });
    rejects(static function () use ($updater): void {
        $updater->update();
    }, 'ZIP symlink rejected');
    package(['index.php' => str_repeat('x', 2000)]);
    rejects(static function () use ($config): void {
        (new Updater($config + ['archive_limits' => ['max_expanded_bytes' => 1000]]))->update();
    }, 'ZIP expansion limit enforced');
    package(['index.php' => 'new-code', 'new.php' => 'new']);
    rejects(static function () use ($config): void {
        (new Updater($config + ['archive_limits' => ['max_files' => 1]]))->update();
    }, 'ZIP entry limit enforced');
    $updater->update();
    $transaction = Files::json($transactionPath);
    $backupFile = $config['work_dir'] . '/backups/' . $transaction['id'] . '/files/index.php';
    Files::write($backupFile, 'damaged');
    rejects(static function () use ($updater): void {
        $updater->rollback();
    }, 'Damaged backup rejected before restoring any files');
    expect(file_get_contents($root . '/index.php') === 'new-code', 'Damaged recovery does not touch live code');
    Files::write($backupFile, 'old-code');
    $updater->rollback();
    rejects(static function () use ($config): void {
        (new Updater(array_replace($config, ['product_code' => 'another-product'])))->status();
    }, 'State cannot be reused by another product');
    $cliConfig = $directory . '/cli.php';
    Files::write($cliConfig, '<?php return ' . var_export($config, true) . ';');
    $cli = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/cli-app-updater', 'check', $cliConfig],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cliPipes);
    fclose($cliPipes[0]);
    $cliOutput = stream_get_contents($cliPipes[1]);
    $cliError = stream_get_contents($cliPipes[2]);
    fclose($cliPipes[1]);
    fclose($cliPipes[2]);
    expect(proc_close($cli) === 0 && $cliError === '', 'Actual CLI check succeeds');
    expect(json_decode($cliOutput, true)['update'] === true && strpos($cliOutput, 'fixture-license') === false
        && strpos($cliOutput, '/download/') === false, 'CLI prints useful metadata without credentials');
    Files::write($cliConfig, '<?php $config = ' . var_export($config, true)
        . '; $config["hooks"]["after_apply"] = static function () { exit(7); }; return $config;');
    $crash = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/cli-app-updater', 'update', $cliConfig],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/crash.log', 'a'], 2 => ['file', $directory . '/crash.log', 'a']], $crashPipes);
    fclose($crashPipes[0]);
    expect(proc_close($crash) === 7, 'Installer process exits before version commit without running finally');
    expect(file_get_contents($root . '/index.php') === 'new-code' && $updater->status()['version_code'] === 0
        && $updater->status()['transaction_status'] === 'applying', 'Real interruption leaves durable recovery plan and old version');
    rejects(static function () use ($updater): void {
        $updater->update();
    }, 'Actual interrupted transaction blocks another deployment');
    $updater->rollback();
    expect(file_get_contents($root . '/index.php') === 'old-code' && !is_file($root . '/new.php'), 'Actual interrupted process files recovered');

    // Run the shipped examples unchanged, with a private fixture configuration beside them.
    $exampleRoot = Files::directory($directory . '/example-runner');
    Files::directory($exampleRoot . '/examples');
    Files::write($exampleRoot . '/autoload.php', '<?php require ' . var_export(dirname(__DIR__) . '/autoload.php', true) . ';');
    foreach (['updater.php', 'demo.php'] as $example) {
        Files::copy(dirname(__DIR__) . '/examples/' . $example, $exampleRoot . '/examples/' . $example);
    }
    Files::write($exampleRoot . '/examples/config.php', '<?php return ' . var_export($config, true) . ';');
    foreach ([['updater.php'], ['demo.php', 'client-key'], ['demo.php', 'update'], ['demo.php', 'rollback']] as $example) {
        $arguments = [PHP_BINARY, $exampleRoot . '/examples/' . $example[0]];
        if (isset($example[1])) {
            $arguments[] = $example[1];
        }
        $demo = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/demo.log', 'a']], $demoPipes);
        fclose($demoPipes[0]);
        $result = json_decode(stream_get_contents($demoPipes[1]), true);
        fclose($demoPipes[1]);
        expect(proc_close($demo) === 0 && is_array($result), 'Shipped example executes: ' . implode(' ', $example));
        if (($example[1] ?? '') === 'client-key') {
            expect($result['client_key'] === $clientKey, 'Full demo exposes the persistent installation key');
        }
    }
    expect(file_get_contents($root . '/index.php') === 'old-code', 'Demo installation and rollback restore original program');

    // A real HTTP request installs through the shipped handler with command execution disabled.
    Files::write($directory . '/http-config.php', '<?php return ' . var_export($config, true) . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $httpAddress = stream_socket_get_name($socket, false);
    fclose($socket);
    $disabled = 'exec,shell_exec,proc_open,popen,system,passthru,pcntl_exec,fastcgi_finish_request';
    $httpProcess = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $disabled,
        '-S', $httpAddress, __DIR__ . '/http-router.php'], [
        0 => ['pipe', 'r'], 1 => ['file', $directory . '/http.log', 'a'], 2 => ['file', $directory . '/http.log', 'a'],
    ], $httpPipes, __DIR__, $environment, ['bypass_shell' => true]);
    fclose($httpPipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $httpAddress, $errno, $error, 0.05);
        if ($connection) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    expect($ready, 'Application HTTP server starts with process functions disabled');
    $call = static function (string $action, int $expectedStatus = 200) use ($httpAddress): array {
        $curl = curl_init('http://' . $httpAddress . '/' . $action);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        try {
            $body = curl_exec($curl);
            expect($body !== false && curl_getinfo($curl, CURLINFO_HTTP_CODE) === $expectedStatus, 'HTTP action: ' . $action);
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            curl_close($curl);
        }
    };
    $response = $call('check');
    expect($response['sapi'] === 'cli-server' && $response['disabled'] === $disabled, 'Installation runs in a non-CLI SAPI without spawning permission');
    expect($response['data']['update'] && !isset($response['data']['package'], $response['data']['license_value']), 'HTTP check exposes only release summary');
    expect($call('client-key')['data']['client_key'] === $clientKey, 'Web and CLI share the same installation');
    $lock = fopen($config['work_dir'] . '/update.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        expect(strpos($call('update', 500)['error'], 'Another updater') !== false, 'HTTP update honors cross-process lock');
        expect(strpos($call('status', 500)['error'], 'Another updater') !== false, 'Status cannot poll a locked installation');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $response = $call('update')['data'];
    expect($response['updated'] && !isset($response['release']['backup_dir'])
        && file_get_contents($root . '/index.php') === 'new-code', 'HTTP update installs files and hides backup path');
    expect($call('status')['data']['version_code'] === 1, 'HTTP installation commits the version');
    expect($call('update')['data']['updated'] === false, 'Repeated HTTP upgrade reports no update');
    expect($call('rollback')['data']['rolled_back'] && file_get_contents($root . '/index.php') === 'old-code', 'HTTP rollback restores files');
    $call('fail-update', 500);
    expect(file_get_contents($root . '/index.php') === 'old-code'
        && $call('status')['data']['version_code'] === 0, 'Failed HTTP health hook restores files and version');
    $call('unsupported', 500);

    if (function_exists('sodium_crypto_sign_keypair')) {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $secret = sodium_crypto_sign_secretkey($pair);
        $payload = json_encode([
            'schema' => 'apps.update-license.v1', 'key_id' => hash('sha256', $public),
            'license_id' => '1', 'product_code' => $config['product_code'], 'issued_at' => '2025-01-01T00:00:00+08:00',
            'updates_until' => '2025-02-01T00:00:00+08:00',
            'constraints' => ['client_key' => $initial['client_key'], 'domain' => 'example.com'],
        ]);
        $envelope = ['algorithm' => 'Ed25519', 'key_id' => hash('sha256', $public), 'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secret))];
        $document = json_encode($envelope);
        $license = OfflineLicense::verify($document, base64_encode($public), $config['product_code'], $initial['client_key'], 'Example.COM.', '2025-01-31T23:59:59+08:00');
        expect($license['license_id'] === '1', 'Expired update cutoff still allows an eligible historical release');
        foreach ([['date' => '2025-02-02T00:00:00+08:00'], ['key' => base64_encode(random_bytes(32))],
            ['client' => 'wrong_client'], ['domain' => 'wrong.example.com'], ['product' => 'wrong-product'],
            ['document' => json_encode(array_replace($envelope, ['payload' => base64_encode($payload . ' ')]))]] as $bad) {
            rejects(static function () use ($bad, $document, $public, $config, $initial): void {
                OfflineLicense::verify($bad['document'] ?? $document, $bad['key'] ?? base64_encode($public),
                    $bad['product'] ?? $config['product_code'], $bad['client'] ?? $initial['client_key'],
                    $bad['domain'] ?? 'example.com', $bad['date'] ?? '2025-01-31T23:59:59+08:00');
            }, 'Offline signature, identity, binding or entitlement failure');
        }
    } else {
        rejects(static function (): void {
            OfflineLicense::verify('{}', '', '', '');
        }, 'Offline verification fails closed without sodium');
        echo "NOTE: sodium unavailable; signed-license tests skipped.\n";
    }
    expect((glob($config['work_dir'] . '/downloads/*.part') ?: []) === [], 'Failed download parts cleaned');
    expect((glob($config['work_dir'] . '/stage-*') ?: []) === [], 'All extraction staging directories cleaned');
} catch (Throwable $error) {
    $failure = $error;
    fwrite(STDERR, 'FAIL: ' . $error . "\n");
} finally {
    if (is_resource($httpProcess)) {
        proc_terminate($httpProcess);
        proc_close($httpProcess);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    Files::removeTree($directory);
}
echo ($failure ? 'FAIL' : 'PASS') . ': ' . $checks . ' assertions on PHP ' . PHP_VERSION . "; temporary files cleaned.\n";
exit($failure ? 1 : 0);
