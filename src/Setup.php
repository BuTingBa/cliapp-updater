<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

use RuntimeException;

/** Idempotent scaffolding. Existing configuration and persistent state are never overwritten. */
final class Setup
{
    public static function initialize(string $projectDir): array
    {
        if (!is_dir($projectDir)) {
            throw new RuntimeException('Project directory must already exist');
        }
        $root = Files::directory($projectDir);
        if (is_link($root . '/.updater')) {
            throw new RuntimeException('Updater work directory cannot be a symbolic link');
        }
        $work = Files::directory($root . '/.updater');
        self::create($work . '/.gitignore', "*\n");
        self::create($work . '/.htaccess', "Require all denied\n");
        self::create($work . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>' . "\n");
        $template = file_get_contents(dirname(__DIR__) . '/resources/updater.php');
        if ($template === false) {
            throw new RuntimeException('Cannot read updater configuration template');
        }
        self::create($root . '/updater.php', $template);
        return ['config_file' => $root . '/updater.php', 'work_dir' => $work];
    }

    private static function create(string $path, string $content): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Expected a regular file: ' . $path);
        }
        if (is_file($path)) {
            return;
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create file: ' . $path);
        }
        try {
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new RuntimeException('Cannot write file: ' . $path);
            }
        } finally {
            fclose($handle);
        }
        chmod($path, 0600);
    }
}
