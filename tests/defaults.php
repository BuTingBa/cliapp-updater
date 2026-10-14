<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\Files;
use BuTing\CliAppUpdater\IgnoreRules;
use BuTing\CliAppUpdater\Setup;
use BuTing\CliAppUpdater\Updater;

(static function (array $config, string $directory): void {
    $root = Files::directory($directory . '/defaults');
    $paths = Setup::initialize($root);
    expect(is_dir($root . '/.updater') && is_file($root . '/updater.php'), 'Scaffold creates root configuration and work directory');
    $template = require $paths['config_file'];
    expect(str_replace('\\', '/', $template['project_dir']) === $root && str_replace('\\', '/', $template['work_dir']) === $root . '/.updater', 'Generated configuration links project-local state');
    $local = array_replace($config, ['project_dir' => $root, 'exclude' => "*.log\n!keep.log\n!/updater.php\n!/.updater/**"]);
    unset($local['work_dir']);
    Files::write($root . '/updater.php', '<?php return ' . var_export($local, true) . ';');
    $original = file_get_contents($root . '/updater.php');
    Setup::initialize($root);
    expect(file_get_contents($root . '/updater.php') === $original, 'Repeated setup preserves user configuration');
    $updater = Updater::fromFile($root . '/updater.php');
    $key = $updater->getClientKey();
    expect(is_file($root . '/.updater/state.json'), 'Omitted work_dir uses project-root .updater');
    Setup::initialize($root);
    expect($updater->getClientKey() === $key, 'Repeated setup preserves identity and state');
    $rules = new IgnoreRules("# comment\n*.log\n!/keep.log\n/cache/\nassets/**/private?.php");
    foreach (['a.log' => true, 'logs/a.log' => true, 'keep.log' => false, 'sub/keep.log' => true,
        'cache/file' => true, 'sub/cache/file' => false, 'assets/private1.php' => true,
        'assets/a/b/private2.php' => true, 'assets/private12.php' => false] as $path => $expected) {
        expect($rules->matches($path) === $expected, 'Exclude rule: ' . $path);
    }
    rejects(static function (): void { new IgnoreRules(['../outside']); }, 'Exclude traversal rejected');
    Files::copy($directory . '/package.zip', $directory . '/original.zip');
    $scenario = Files::json($directory . '/scenario.json');
    try {
        Files::write($root . '/app.php', 'old');
        Files::write($root . '/secret.log', 'local');
        package(['app.php' => 'new', 'secret.log' => 'replace', 'keep.log' => 'allowed',
            'updater.php' => 'replace', '.updater/state.json' => 'replace']);
        expect($updater->check()['installed'] === false && file_get_contents($root . '/app.php') === 'old', 'Optional check does not install');
        scenario('', ['force_update' => 'true']);
        rejects(static function () use ($updater): void { $updater->check(); }, 'Malformed force flag rejected');
        scenario('', ['force_update' => true]);
        $events = [];
        $hooked = new Updater($local + ['hooks' => ['after_commit' => static function () use (&$events): void { $events[] = 'commit'; }]]);
        $release = $hooked->check();
        expect($release['installed'] && $release['installation']['package_type'] === 1 && $hooked->status()['version_code'] === 1, 'Forced check installs and commits without a separate update call');
        expect($events === ['commit'], 'Forced install uses configured hooks');
        expect(Updater::summary($release)['installed'] && !isset(Updater::summary($release)['installation']), 'Safe summary signals completed forced installation');
        expect(file_get_contents($root . '/updater.php') === $original && $hooked->getClientKey() === $key, 'Negation cannot override configuration or work directory protection');
        expect(file_get_contents($root . '/secret.log') === 'local' && file_get_contents($root . '/keep.log') === 'allowed', 'Installation honors excludes and re-inclusion');
        expect(!$hooked->check()['update'] && count($events) === 1, 'Forced version is not installed twice');
        $hooked->rollback();
        expect(file_get_contents($root . '/app.php') === 'old' && $hooked->status()['version_code'] === 0, 'Forced update supports rollback');
        rejects(static function () use ($hooked): void {
            $hooked->check(['after_apply' => static function (): void { throw new RuntimeException('fixture'); }]);
        }, 'Forced update propagates hook failure');
        expect(file_get_contents($root . '/app.php') === 'old' && $hooked->status()['version_code'] === 0, 'Failed forced install restores files and version');
    } finally {
        Files::copy($directory . '/original.zip', $directory . '/package.zip');
        Files::writeJson($directory . '/scenario.json', $scenario);
        unlink($directory . '/original.zip');
    }
})($config, $directory);
