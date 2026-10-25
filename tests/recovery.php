<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\{ClientIdentity, Files, Updater};

(static function (array $config, string $directory): void {
    foreach (['complete', 'rolled_back', 'applying', 'rolling_back', 'legacy', 'direct_rollback'] as $case) {
        $root = Files::directory($directory . '/recover-' . $case);
        $local = array_replace($config, ['project_dir' => $root, 'work_dir' => $root . '/.updater']);
        $updater = new Updater($local);
        $contents = ['app.php' => 'base', 'keep.bin' => random_bytes(16384)];
        $base = releaseFixture(10, $contents);
        $updater->update();
        if ($case === 'legacy') {
            $state = Files::json($local['work_dir'] . '/state.json');
            unset($state['installation_scope'], $state['package_origin']);
            $state['identity'] = hash('sha256', $local['base_url'] . "\n" . $local['product_code'] . "\n" . $root);
            $state['machine_tag'] = ClientIdentity::tag($local['machine_id'], $state['identity']);
            $state['client_key'] = substr($state['machine_tag'], 0, 32);
            Files::writeJson($local['work_dir'] . '/state.json', $state);
        }
        $key = $updater->getClientKey();
        releaseFixture(50, array_replace($contents, ['app.php' => 'latest']), $base);
        $updater->update();
        if ($case === 'rolled_back') {
            $updater->rollback();
        }
        $journalPath = $local['work_dir'] . '/transaction.json';
        $journal = Files::json($journalPath);
        if (in_array($case, ['applying', 'rolling_back', 'direct_rollback'], true)) {
            $journal['status'] = $case === 'direct_rollback' ? 'applying' : $case;
        }
        if ($case === 'legacy') {
            // A real v2.1.0 journal retains the old state format even after its state file is lost.
            unset($journal['previous_state']['installation_scope'], $journal['previous_state']['package_origin']);
            $local['base_url'] .= '/relocated-center';
            $updater = new Updater($local);
        }
        Files::writeJson($journalPath, $journal);
        $journalBytes = file_get_contents($journalPath);
        unlink($local['work_dir'] . '/state.json');
        if ($case === 'direct_rollback') {
            $updater->rollback();
            expect($updater->getClientKey() === $key && $updater->status()['version_code'] === 10
                && file_get_contents($root . '/app.php') === 'base', 'Rollback works directly when state is missing');
            continue;
        }
        $complete = in_array($case, ['complete', 'legacy'], true);
        $state = $updater->status();
        expect($state['version_code'] === ($complete ? 50 : 10) && $state['client_key'] === $key,
            'Missing state recovers the original identity and committed version: ' . $case);
        expect(file_get_contents($journalPath) === $journalBytes, 'Metadata recovery never rewrites the recovery plan');
        if ($complete) {
            expect(!isset($state['package_id'], $state['package_sha256'], $state['updated_at'])
                && !isset(Files::json($local['work_dir'] . '/state.json')['installed_files']),
                'Completed journal does not reuse the previous package baseline or commit time');
            $nextBase = releaseFixture(50, array_replace($contents, ['app.php' => 'latest']));
            releaseFixture(80, array_replace($contents, ['app.php' => 'next']), $nextBase);
            expect($updater->update()['package_type'] === 1 && $updater->status()['version_code'] === 80,
                'Recovered completed transaction upgrades directly with the full package');
            $updater->rollback();
            expect($updater->status()['version_code'] === 50 && file_get_contents($root . '/app.php') === 'latest',
                'New full upgrade rolls back to the recovered version');
        } elseif ($case !== 'rolled_back') {
            rejects(static function () use ($updater): void { $updater->update(); }, 'Unfinished transaction still requires explicit rollback');
            $updater->rollback();
            expect($updater->status()['version_code'] === 10 && file_get_contents($root . '/app.php') === 'base',
                'Missing state does not prevent recovery of an unfinished transaction');
        } else {
            expect($updater->update()['package_type'] === 2, 'Rolled-back state retains its valid previous baseline');
        }
    }

    $path = $local['work_dir'] . '/state.json';
    $original = Files::json($journalPath);
    unlink($path);
    foreach (['directory', 'id', 'status', 'previous', 'identity', 'client_key', 'machine', 'version'] as $fault) {
        $bad = $original;
        $bad['status'] = 'complete';
        if ($fault === 'directory') { $bad['project_dir'] .= '/other'; }
        if ($fault === 'id') { $bad['id'] = '../other'; }
        if ($fault === 'status') { $bad['status'] = 'unknown'; }
        if ($fault === 'previous') { unset($bad['previous_state']); }
        if ($fault === 'identity') { $bad['previous_state']['installation_scope'] = str_repeat('0', 64); }
        if ($fault === 'client_key') { $bad['previous_state']['client_key'] = ''; }
        if ($fault === 'machine') { $bad['previous_state']['machine_tag'] = str_repeat('0', 64); }
        if ($fault === 'version') { $bad['release']['version_code'] = -1; }
        Files::writeJson($journalPath, $bad);
        rejects(static function () use ($updater): void { $updater->status(); }, 'Invalid journal cannot create state: ' . $fault);
        expect(!file_exists($path) && file_get_contents($root . '/app.php') === 'base',
            'Failed metadata recovery leaves files and missing state untouched');
    }
})($config, $directory);
