<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\Updater;

// 用法：php examples/demo.php check；动作见下方 switch。
// 访问 config.php 指定的真实平台，update 会覆盖指定项目；先在测试项目试用。
require dirname(__DIR__) . '/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('请在独立 PHP CLI 进程中运行完整演示。');
}

try {
    $config = require __DIR__ . '/config.php';
    $updater = new Updater($config);
    $action = $argv[1] ?? 'check';

    // 回调只来自本地程序。这里演示接入位置，没有实现你的应用维护、迁移或重载。
    // 运行 update/rollback 前先手工进入维护模式；正式接入时替换这些回调的内容。
    $hooks = [
        'before_apply' => static function (array $release): void {
            // 确认维护和暂停写入状态；需要一致性备份时必须在 update 之前开启。
            fwrite(STDERR, '准备安装 ' . $release['version'] . "；请确保应用已进入维护模式。\n");
        },
        'after_apply' => static function (array $release): void {
            // 文件已覆盖，版本尚未提交。接入应用实际迁移和健康检查。
            // 检查失败可 throw new RuntimeException('健康检查失败');，文件会自动恢复。
            fwrite(STDERR, "文件已覆盖；应用迁移和健康检查由接入方实现。\n");
        },
        'after_commit' => static function (array $release): void {
            // 版本已提交。交给已有部署服务刷新 FPM 缓存或平滑重载 Webman，再退出维护。
            // 当前 CLI 的 opcache_reset() 不会刷新其他 FPM 进程。
            fwrite(STDERR, "版本已提交；请完成应用进程生效与退出维护。\n");
        },
        'before_rollback' => static function (array $release): void {
            // 手工恢复前也需维护；自动故障恢复只还原文件，不运行这组回调。
            fwrite(STDERR, "准备恢复旧文件，请确保应用已进入维护模式。\n");
        },
        'after_rollback' => static function (array $release): void {
            // 数据库不自动恢复；检查迁移兼容性，刷新/重载应用后再恢复访问。
            fwrite(STDERR, "文件与版本已恢复，请核对数据库及应用进程。\n");
        },
    ];

    switch ($action) {
        case 'client-key':
            $result = ['client_key' => $updater->getClientKey()]; // 不访问平台，无须先登记。
            break;
        case 'status':
            $result = $updater->status(); // 只在需要版本、事务状态时调用。
            break;
        case 'report':
            $result = $updater->report(); // 登记/心跳，不代表取得授权。
            break;
        case 'authorize':
            $result = $updater->authorize();
            unset($result['license_value']); // 在线凭证不输出到终端日志。
            break;
        case 'check':
        case 'download':
            $release = $updater->check(); // 自动初始化 ID，并向平台报告当前版本。
            $result = Updater::summary($release);
            if ($action === 'download' && $release['update']) {
                $result['downloaded_file'] = $updater->download($release);
            }
            break;
        case 'update':
            // 无须先 check/download；同一锁内重新获取授权和下载票据。
            $result = $updater->update($hooks) ?? ['update' => false];
            break;
        case 'rollback':
            $updater->rollback($hooks);
            $result = $updater->status();
            break;
        default:
            throw new InvalidArgumentException('支持：client-key/status/report/authorize/check/download/update/rollback');
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // 不打印完整请求、密钥、票据或异常堆栈；用 status 核对失败后的实际版本。
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
