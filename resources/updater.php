<?php

declare(strict_types=1);

// 本文件由应用加载，不接受 HTTP 请求参数；填写中心地址、产品 Code 和产品密钥。
return [
    'base_url' => getenv('UPDATER_BASE_URL') ?: 'https://updates.example.com', // 可更换中心地址；保留原 .updater 状态。
    'product_code' => getenv('UPDATER_PRODUCT_CODE') ?: '',
    'product_key' => getenv('UPDATER_PRODUCT_KEY') ?: '',
    'project_dir' => __DIR__,
    'work_dir' => __DIR__ . '/.updater',
    'version_code' => 0, // 首次接入填写当前安装版本序号；已有状态后以状态为准。
    'domain' => getenv('UPDATER_DOMAIN') ?: '',
    // 相对根目录的排除规则，每行一条；支持 # 注释、*、?、** 和 ! 取消自定义排除。
    // .updater、updater.php、.env、上传和运行目录始终受保护，不能通过 ! 取消。
    'exclude' => <<<'IGNORE'
/config/local.php
*.log
IGNORE
    ,
    // 配置中的回调用于 check() 强制安装、update() 和 rollback()。
    'hooks' => [],
];
