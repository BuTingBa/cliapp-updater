<?php

declare(strict_types=1);

// 仅由测试程序在随机回环端口启动，不是可部署的管理端入口。
require dirname(__DIR__) . '/autoload.php';

header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST required');
    }
    $config = require getenv('UPDATER_TEST_DIRECTORY') . '/http-config.php';
    $handler = require dirname(__DIR__) . '/examples/http.php';
    $action = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $hooks = [];
    if ($action === 'fail-update') {
        $action = 'update';
        $hooks['after_apply'] = static function (): bool {
            return false;
        };
    }
    $data = $handler(new BuTing\CliAppUpdater\Updater($config), $action, $hooks);
    echo \BuTing\CliAppUpdater\Json::encode(['data' => $data, 'sapi' => PHP_SAPI, 'disabled' => ini_get('disable_functions')]);
} catch (Throwable $error) {
    http_response_code(500);
    echo \BuTing\CliAppUpdater\Json::encode(['error' => $error->getMessage()]);
}
