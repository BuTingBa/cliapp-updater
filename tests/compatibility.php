<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\{ClientIdentity, Files, Updater};

// Exercise public update/check calls with the real loopback HTTP fixture, including old persisted state.
(static function (array $config, string $directory): void {
    $contents = ['app.php' => 'base', 'keep.bin' => random_bytes(16384), '.env' => 'remote'];
    $target = array_replace($contents, ['app.php' => 'latest']);
    foreach (['new_address', 'legacy_address', 'legacy_same', 'legacy_random', 'legacy_no_machine'] as $case) {
        $root = Files::directory($directory . '/' . $case);
        $local = array_replace($config, ['project_dir' => $root, 'work_dir' => $root . '/.updater']);
        if ($case === 'legacy_random') {
            $local['machine_id'] = '';
        }
        $updater = new Updater($local);
        Files::write($root . '/.env', 'local');
        $base = releaseFixture(10, $contents);
        $updater->update();
        $statePath = $local['work_dir'] . '/state.json';
        $state = Files::json($statePath);
        if ($case !== 'new_address') {
            unset($state['installation_scope'], $state['package_origin']);
            $state['identity'] = hash('sha256', rtrim($local['base_url'], '/') . "\n" . $local['product_code'] . "\n" . $root);
            if ($case !== 'legacy_random') {
                $state['machine_tag'] = ClientIdentity::tag($local['machine_id'], $state['identity']);
                $state['client_key'] = substr($state['machine_tag'], 0, 32);
            }
            Files::writeJson($statePath, $state);
        }
        $clientKey = $state['client_key'];
        $journal = file_get_contents($local['work_dir'] . '/transaction.json');
        if ($case !== 'legacy_same') {
            $local['base_url'] .= '/relocated-center';
        }
        if ($case === 'legacy_no_machine') {
            $local['machine_id'] = '';
        }
        $moved = new Updater($local);
        expect($moved->getClientKey() === $clientKey && $moved->status()['version_code'] === 10,
            'URL migration retains identity and installed version: ' . $case);
        expect(file_get_contents($local['work_dir'] . '/transaction.json') === $journal,
            'State adoption does not rewrite the existing recovery journal');
        expect(!isset($moved->status()['installation_scope'], $moved->status()['package_origin']), 'Internal scope and package origin remain private');
        releaseFixture(50, $target, $base);
        $moved->check();
        $request = Files::json($directory . '/request.json');
        expect((int) $request['query']['version_code'] === 10
            && (int) $request['query']['base_package_id'] === ($case === 'legacy_same' ? 10 : 0),
            'Only the original center receives the old package ID');
        $result = $moved->update();
        expect($result['package_type'] === ($case === 'legacy_same' ? 2 : 1)
            && $moved->status()['version_code'] === 50 && file_get_contents($root . '/app.php') === 'latest',
            'Cross-version update succeeds after URL migration');
        expect(file_get_contents($root . '/.env') === 'local', 'URL migration preserves local protected files');
        $moved->rollback();
        expect($moved->status()['version_code'] === 10 && $moved->getClientKey() === $clientKey
            && file_get_contents($root . '/app.php') === 'base', 'Migrated full update can roll back to the original center version');
        $moved->update();
        $nextBase = releaseFixture(50, $target);
        releaseFixture(80, array_replace($target, ['app.php' => 'next']), $nextBase);
        expect($moved->update()['package_type'] === 2, 'Successful full migration establishes a new-center delta baseline');
        $moved->rollback();
        expect($moved->status()['version_code'] === 50 && file_get_contents($root . '/app.php') === 'latest',
            'New-center delta rollback preserves migrated identity and version');
        expect($moved->getClientKey() === $clientKey, 'Migration and rollback never rotate the approved key');
    }

    $root = Files::directory($directory . '/local-edit');
    $local = array_replace($config, ['project_dir' => $root, 'work_dir' => $root . '/.updater']);
    $updater = new Updater($local);
    $base = releaseFixture(10, $contents);
    $updater->update();
    foreach (['before_update', 'same_size_edit', 'missing_file', 'during_download', 'forced_download'] as $case) {
        releaseFixture(50, $target, $base);
        $editedPath = $case === 'same_size_edit' ? 'app.php' : 'keep.bin';
        $duringDownload = in_array($case, ['during_download', 'forced_download'], true);
        if (!$duringDownload) {
            if ($case === 'missing_file') {
                unlink($root . '/keep.bin');
            } else {
                Files::write($root . '/' . $editedPath, $case === 'same_size_edit' ? 'edit' : 'locally modified file omitted from delta');
            }
        } else {
            $scenario = Files::json($directory . '/scenario.json');
            $scenario['mode'] = 'edit_during_download';
            $scenario['edit_path'] = $root . '/keep.bin';
            $scenario['release']['force_update'] = $case === 'forced_download';
            Files::writeJson($directory . '/scenario.json', $scenario);
        }
        Files::write($directory . '/downloads.jsonl', '');
        $hooksCalled = 0;
        $hooks = ['before_apply' => static function () use (&$hooksCalled): void { $hooksCalled++; }];
        $result = $case === 'forced_download' ? $updater->check($hooks)['installation'] : $updater->update($hooks);
        expect($result['package_type'] === 1 && $updater->status()['version_code'] === 50,
            'Local edit uses full package without blocking upgrade: ' . $case);
        expect(file_get_contents($root . '/keep.bin') === $contents['keep.bin'] && $hooksCalled === 1,
            'Full fallback repairs files omitted from delta and runs hooks exactly once');
        $downloads = array_map('json_decode', file($directory . '/downloads.jsonl', FILE_IGNORE_NEW_LINES));
        expect(count($downloads) === ($duringDownload ? 2 : 1) && end($downloads)->delta === false,
            'Baseline fallback downloads the full package only once');
        $updater->rollback();
        $edited = $duringDownload ? 'edited while downloading' : ($case === 'same_size_edit' ? 'edit' : 'locally modified file omitted from delta');
        $restored = $case === 'missing_file' ? !file_exists($root . '/keep.bin') : file_get_contents($root . '/' . $editedPath) === $edited;
        expect($restored && $updater->status()['version_code'] === 10,
            'Rollback restores the actual locally modified pre-update file');
        Files::write($root . '/' . $editedPath, $contents[$editedPath]);
    }
    releaseFixture(50, $target, $base);
    $scenario = Files::json($directory . '/scenario.json');
    $scenario['mode'] = 'edit_during_download';
    $scenario['edit_path'] = $root . '/keep.bin';
    $scenario['release']['package']['sha256'] = str_repeat('0', 64);
    Files::writeJson($directory . '/scenario.json', $scenario);
    rejects(static function () use ($updater): void { $updater->update(); }, 'Full fallback must still pass the package hash check');
    expect($updater->status()['version_code'] === 10 && file_get_contents($root . '/app.php') === 'base', 'Rejected full fallback changes neither application nor committed version');
    Files::write($root . '/keep.bin', $contents['keep.bin']);
    releaseFixture(50, $target, $base);
    Files::write($directory . '/downloads.jsonl', '');
    $hooksCalled = 0;
    rejects(static function () use ($updater, &$hooksCalled): void {
        $updater->update(['after_apply' => static function () use (&$hooksCalled): void {
            $hooksCalled++;
            throw new \BuTing\CliAppUpdater\DeltaUnavailable('Application hook failure must not trigger another install');
        }]);
    }, 'Post-mutation failures never trigger full-package retry');
    expect($hooksCalled === 1 && count(file($directory . '/downloads.jsonl')) === 1 && $updater->status()['version_code'] === 10,
        'Failed hook is recovered without duplicate downloads or callbacks');
    expect((glob($local['work_dir'] . '/stage-*') ?: []) === [] && (glob($local['work_dir'] . '/downloads/*') ?: []) === [],
        'Both rejected delta staging and all downloads are cleaned after fallback');
})($config, $directory);
