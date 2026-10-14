<?php

declare(strict_types=1);

// 该文件只返回配置，不发请求。真实密钥配置放在 Web 根之外，不提交到 Git。
return [
    'base_url' => getenv('UPDATER_BASE_URL') ?: 'https://apps.example.com',
    'product_code' => getenv('UPDATER_PRODUCT_CODE') ?: 'my-shop',
    'product_key' => getenv('UPDATER_PRODUCT_KEY') ?: '',
    // 应用根目录：ZIP 中的 app/、public/、vendor/ 等文件会覆盖到这里。
    'project_dir' => getenv('UPDATER_PROJECT_DIR') ?: '/srv/shop/app',
    // 更新器的独立工作目录：保存实例 ID、版本、下载和备份，不对浏览器开放。
    'work_dir' => getenv('UPDATER_WORK_DIR') ?: '/srv/shop/shop-updater',
    'version_code' => 100, // 首次接入时已安装的版本序号；以后自动从 state.json 读取。
    'prefer_delta' => true, // 有准确本地基线且增量包更小时自动使用；false 强制完整包。
    'domain' => 'shop.example.com', // 可信配置，不读取请求 Host。
    'client_name' => '商城站点',
    'upgrade_token' => getenv('UPDATER_UPGRADE_TOKEN') ?: '', // 可选客户在线凭证。
    'preserve' => ['config/local.php', 'public/customer-files'],
    // 通常无需配置 client_key 或 machine_id，默认自动生成并保存。
    // 'machine_id' => 'deployment-instance-001', // 容器可指定持久、独立的实例 ID。
    // 'client_key' => 'customer_installation_001', // 也可显式提供既有授权的原实例 ID。
    // 'http' => ['allow_http' => true], // 仅本机 HTTP 测试需要。
];
