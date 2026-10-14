<?php

declare(strict_types=1);

use BuTing\CliAppUpdater\Updater;

// 由应用控制器 require 本文件得到处理函数；直接访问本文件不会执行升级。
// 框架负责 Composer 自动加载、后台登录/升级权限、POST 与 CSRF 校验、JSON 响应。
// Updater 配置和 hooks 必须来自服务器本地，不能从请求传入密钥、路径或回调。
return static function (Updater $updater, string $action, array $hooks = []): array {
    switch ($action) {
        case 'client-key':
            return ['client_key' => $updater->getClientKey()];
        case 'status':
            // 安装持锁期间会抛异常；这不是实时进度查询接口。
            return $updater->status();
        case 'check':
            // 去掉平台响应中的授权凭证、下载票据，只把版本摘要交给浏览器。
            return Updater::summary($updater->check());
        case 'changelog':
            return $updater->changelog();
        case 'manifest':
            $release = $updater->check();
            return $release['update'] ? $updater->manifest($release) : ['update' => false];
        case 'update':
            // 同步等待检查、下载、校验、备份与文件覆盖；不启动命令/子进程。
            $result = $updater->update($hooks);
            if ($result === null) {
                return ['updated' => false];
            }
            unset($result['backup_dir']); // 本地备份路径留给服务端运维。
            return ['updated' => true, 'release' => $result];
        case 'rollback':
            $updater->rollback($hooks);
            return ['rolled_back' => true];
        default:
            throw new InvalidArgumentException('Unsupported updater action');
    }
};
