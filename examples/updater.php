<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\Updater;

// 在包内直接运行；复制到自己的应用后改为 require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit('本示例通过 CLI 运行；Web 接入请将 check()/getClientKey() 放入自己的后台控制器。');
}

// 先修改同目录 config.php 中的平台信息与目录。
$updater = new Updater(require __DIR__ . '/config.php');

try {
    $release = $updater->check(); // 无须先调用 status()；没有实例 ID 会自动生成。
    // 确认应用维护流程已接入后，安装可直接调用 $updater->update()，不必预先手工下载。
    echo json_encode([
        'client_key' => $updater->getClientKey(), // 可在后台显示给客户，用于申请授权。
        'release' => Updater::summary($release), // 去掉下载票据和授权凭证后再展示。
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // 即使平台拒绝更新，也能单独 getClientKey() 取得标识，用于申请授权。
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
