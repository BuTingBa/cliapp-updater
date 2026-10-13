<?php

declare(strict_types=1);

// Standalone CLI/tests only; Composer users should require their vendor/autoload.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'BuTing\\CliAppUpdater\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
