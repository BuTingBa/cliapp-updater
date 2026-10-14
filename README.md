# cliapp-updater

[![Latest Stable Version](https://img.shields.io/packagist/v/buting/cliapp-updater.svg)](https://packagist.org/packages/buting/cliapp-updater)
[![PHP Version](https://img.shields.io/packagist/php-v/buting/cliapp-updater.svg)](https://packagist.org/packages/buting/cliapp-updater)
[![Total Downloads](https://img.shields.io/packagist/dt/buting/cliapp-updater.svg)](https://packagist.org/packages/buting/cliapp-updater)

框架无关的 PHP 应用更新库，配合 apps.cli 发布与授权服务使用。提供更新检查、日志查询、完整包/文件级增量下载与安装、文件清单校验、备份回滚及离线授权验签。支持在 ThinkPHP/FPM、Webman、队列和 CLI 中调用，不依赖第三方 PHP 包。

## 环境要求与安装

- **64 位 PHP 7.2 及以上**，Composer 约束为 `>=7.2`。
- 必需扩展：`curl`、`hash`、`json`、`zip`。
- 可选扩展：`sodium` 用于 Ed25519 离线验签；`intl` 用于国际化域名转换。
- PHP 运行账户能够读写应用文件和工作目录，并有足够空间保存下载包、解压文件和旧文件备份。

```bash
composer require buting/cliapp-updater
```

已有项目更新依赖：

```bash
composer update buting/cliapp-updater
```

命名空间为 `BuTing\CliAppUpdater`，命令入口为 `vendor/bin/cli-app-updater`。应用应提交 `composer.lock` 固定依赖版本。

## 快速开始

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use BuTing\CliAppUpdater\Updater;

$updater = new Updater([
    'base_url' => 'https://updates.example.com',
    'product_code' => 'my-shop',
    'product_key' => getenv('UPDATER_PRODUCT_KEY'),
    'project_dir' => __DIR__,
    'work_dir' => dirname(__DIR__) . '/my-shop-updater',
    'version_code' => 100,
    'domain' => 'shop.example.com',
    'preserve' => ['config/local.php', 'public/customer-files'],
]);

// 检查更新：首次调用自动创建安装实例标识和本地状态。
$release = $updater->check();
$display = Updater::summary($release);

// 展示安装实例标识，不需要网络请求。
$clientKey = $updater->getClientKey();
```

在应用已完成权限校验、进入维护并暂停相关写入后，安装只需调用：

```php
$result = $updater->update();
// null：没有可获取的新版本。
// array：安装完成，返回版本摘要、备份目录、实际包类型及保留文件列表。
```

`update()` 自行检查并下载，不需要预先调用 `check()`、`status()` 或 `download()`。`check()` 的完整结果含授权信息和下载票据；展示版本信息时使用 `Updater::summary()`。

## 配置

### `new Updater(array $config)`

配置在构造时传入。`Updater` 没有公开属性或常量；修改配置需创建新实例。

| 参数 | 类型 | 默认值 | 含义与限制 |
| --- | --- | --- | --- |
| `base_url` | string | 必填 | 可信中心地址，如 `https://updates.example.com`；可带部署子路径，不含用户名、密码、查询串或片段 |
| `product_code` | string | 必填 | 中心产品 Code，不是数据库 ID；由库编码为 URL 路径 |
| `product_key` | string | 必填 | 产品通信密钥，发送到 `Product-Key` 请求头 |
| `project_dir` | string | 必填 | 被更新的应用根目录，必须已存在；建议绝对路径 |
| `work_dir` | string | 必填 | 独立持久工作目录，可自动创建；与 `project_dir` 互不包含 |
| `version_code` | int | `0` | 首次初始化时的已安装版本序号，范围 `0`～`9007199254740991`；已有状态后以状态为准 |
| `client_key` | string | `''` | 安装实例 ID；空串自动生成。显式值为 8～64 位字母、数字、下划线或短横线，须与已有状态一致 |
| `machine_id` | string\|null | `null` | `null` 自动探测系统 ID；非空串提供最多 512 字节的稳定实例 ID；`''` 禁用探测并使用持久随机 ID |
| `upgrade_token` | string | `''` | 可选客户在线凭证，发送到 `Upgrade-Token`；空串时中心按安装实例查找授权 |
| `domain` | string | `''` | 可信配置中的域名；无协议、端口、路径，不接受 IP。无需域名绑定时可为空 |
| `client_name` | string | `''` | 上报给中心的安装实例名称 |
| `channel` | string | `'stable'` | 发布渠道：`stable` 或 `beta` |
| `prefer_delta` | bool | `true` | 条件满足时优先使用更小的增量包；`false` 强制完整包 |
| `preserve` | string[] | `[]` | 追加保护路径，相对项目根目录；目录包含子路径，不支持通配符 |
| `max_package_bytes` | int | `2147483648` | 单个 ZIP 的最大下载字节数，正整数，默认 2 GiB |
| `http` | array | `[]` | HTTP 选项，见下表 |
| `archive_limits` | array | `[]` | 解压与清单限制，见下表 |

`version_code` 是单调递增的整数，不是显示版本字符串。示例：版本 `1.2.0` 可对应序号 `10200`；显示版本由中心返回，客户端按序号判断新旧。

### HTTP 选项

以下字段放在 `http` 数组内：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `allow_http` | bool | `false` | 允许明文 HTTP；用于本地开发 |
| `connect_timeout` | int | `10` | 单次连接超时，正整数秒 |
| `timeout` | int | `30` | 单次 API 或清单请求总超时，正整数秒 |
| `download_timeout` | int | `1800` | 单次 ZIP 下载总超时，正整数秒 |
| `ca_file` | string | `''` | 自定义可信 CA 文件；空串使用运行环境默认 CA |

HTTPS 校验证书与主机名，不跟随重定向。API 响应上限为 2 MiB，清单响应上限为 16 MiB；传输连续 60 秒低于 1 字节/秒会中止。HTTP 超时仅控制出站请求，不延长 FPM 或代理的入站请求时限。

### 解压限制

以下字段放在 `archive_limits` 数组内：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `max_files` | int | `50000` | 完整 ZIP 条目及安装清单文件数上限；增量 ZIP 另允许一个元数据条目，删除清单也受此数量限制 |
| `max_expanded_bytes` | int | `10737418240` | ZIP 解压总量及安装清单总大小上限，默认 10 GiB |

两个值均为正整数。ZIP 条目计数包含目录；增量 ZIP 解压总量包含 `update.json`。

```php
$config['http'] = [
    'connect_timeout' => 10,
    'timeout' => 30,
    'download_timeout' => 600,
];
$config['archive_limits'] = [
    'max_files' => 20000,
    'max_expanded_bytes' => 2147483648,
];
```

### 目录布局与持久状态

```text
/srv/shop/
├── app/                    project_dir：应用根目录
│   ├── app/
│   ├── public/              Web 根目录
│   ├── vendor/
│   └── .env
└── updater/                work_dir：不对 Web 开放
    ├── state.json          实例 ID、已安装版本与文件基准
    ├── update.lock         跨进程排他锁
    ├── transaction.json    最近文件事务
    ├── downloads/          下载文件
    └── backups/            回滚备份
```

ZIP 根目录直接映射到 `project_dir`，不能额外套一层项目文件夹。`work_dir` 应位于应用目录之外，不能放进 `vendor`、Web 公开目录或会自动清理的临时目录。同一安装的 HTTP、队列和 CLI 共用工作目录；不同安装分别配置。

## Updater API

以下为应用接入的全部公开方法。实例方法共用 `work_dir` 的非阻塞排他锁：其他操作持锁时立即抛异常，不排队。`status()` 和 `getClientKey()` 也取锁。静态方法 `summary()` 不取锁。

### `getClientKey(): string`

读取或首次生成安装实例标识，无参数、无网络请求。可单独调用，无需先初始化其他方法。

生成与复用规则：

1. 已有状态时复用原 `client_key`，显式配置必须与其一致。
2. 新状态优先使用显式配置的 `client_key`。
3. 否则按系统 ID、中心地址、产品 Code、项目真实路径派生 32 位十六进制 ID。
4. 无法取得系统 ID 时生成随机 ID，并写入 `work_dir/state.json`。

Linux 读取 `/etc/machine-id`，备选 `/var/lib/dbus/machine-id`；Windows 查询 `MachineGuid`。Windows 探测可选使用 `proc_open` 调用系统 `reg.exe`，不可用时回退随机 ID。原始系统 ID 不写入状态、不上传中心。

同一机器的不同项目路径会产生不同实例 ID。克隆镜像或容器时应提供独立且持久的 `machine_id`，复制出的新站点使用新的工作目录。已有状态检测到不同机器标识时拒绝使用。ID 可重新派生不意味着版本记录和备份可重建，不应删除状态来重置版本。

### `status(): array`

读取本地已提交状态，无参数、无网络请求；首次调用可能创建状态。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `client_key` | string | 安装实例 ID |
| `client_key_source` | string | `configured`、`machine`、`random` 或 `legacy` |
| `version_code` | int | 当前已提交版本序号 |
| `version` | string，可缺省 | 成功安装后记录的显示版本 |
| `updated_at` | string，可缺省 | 安装提交时间，UTC ISO 8601；回滚后恢复旧值 |
| `package_id` | int，可缺省 | 目标完整包 ID；`0` 表示未知，不能用于增量匹配 |
| `package_sha256` | string，可缺省 | 目标完整 ZIP 的 SHA-256 |
| `transaction_status` | string | `none`、`applying`、`complete`、`rolling_back`、`rolled_back` 或 `unknown` |

不返回内部安装身份摘要、机器摘要和文件清单。此方法不是进度接口；安装持锁时同样会被拒绝。`complete` 表示文件与版本已提交，不保证后续回调或框架重载成功。

### `report(): array`

登记实例并上报当前版本、`platform=php`、渠道、域名及操作系统/PHP 信息。无参数，使用 POST 请求。返回：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `registered` | bool | 成功为 `true` |
| `client_id` | int | 中心安装实例记录 ID |
| `last_time` | string | 中心记录的最近上报时间 |

登记不授予更新权益。`check()`、`authorize()` 和 `changelog()` 也会在中心登记实例，通常不必单独调用。

### `authorize(): array`

在线验证更新权益，无参数，使用 POST 请求。拒绝时抛异常，成功返回：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `authorized` | bool | `true` |
| `license_value` | string\|null | 在线凭证，公开产品可为 `null` |
| `expires_at` | string\|null | 可获取版本的发布时间截止点；`null` 不限制，不是软件运行到期时间 |
| `license_mode` | int | `1` 授权下载，`2` 公开下载 |

不安装文件，也不自动把返回凭证保存到配置。授权产品需在中心配置安装实例、域名、来源 IP 等绑定；`product_key`、`client_key` 和 `upgrade_token` 分别用于产品通信、实例识别和客户在线授权。

### `check(): array`

检查是否有可获取的新版本。无参数，使用 GET 请求；自动初始化本地状态并登记实例。中心按产品、PHP 目标、渠道、发布状态和更新权益筛选版本。

| 字段 | 类型 | 返回条件与含义 |
| --- | --- | --- |
| `update` | bool | 始终返回，有无更新 |
| `status_code` | int | 有更新 `200`，无更新 `304`；这是业务字段，不是 HTTP 状态码 |
| `license_value` | string\|null | 在线凭证 |
| `version` | string | 有更新：显示版本 |
| `version_code` | int | 有更新：递增版本序号 |
| `changelog` | string | 有更新：更新说明 |
| `published_at` | string | 有更新：中心记录的首次发布时间 |
| `package` | array | 有更新：目标完整包 |
| `delta_package` | array\|null | 有更新：可选增量候选；未提供增量的中心可缺省 |

`package` 中与本库接入有关的字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int | 完整包 ID，安装后保存为增量基准 |
| `platform` | string | 必须为 `php` |
| `package_type` | int | 完整包为 `1` |
| `file_name` | string | 扩展名必须为 `.zip`，不区分大小写 |
| `file_size` | int | ZIP 字节数，大于 0 且不超过 `max_package_bytes` |
| `sha256` | string | ZIP 的 64 位小写十六进制 SHA-256 |
| `file_url` | string | 中心返回的受控相对下载票据路径 |
| `requirements` | string | 运行要求文本；库不自动解析或执行 |
| `manifest_state` | string | 清单状态；`ready` 时可读取 |
| `manifest_url` | string\|null | 受控清单票据路径 |
| `manifest_sha256` | string | 清单原始 JSON 字节的 SHA-256 |

增量候选沿用包字段，`package_type=2`，另有整数 `base_package_id` 和 `target_package_id`。`check()` 提交持久状态中的基准 ID；返回候选不表示本地文件已经通过核验，实际选择在 `update()` 内完成。

中心可能返回其他元数据；库不使用 `file_full_url` 跨站下载，不执行扩展 `config`。原始结果不能直接交给浏览器或普通日志。授权拒绝、损坏响应和非法包信息会抛异常，不转换成 `update=false`。

### `changelog(string $direction = 'after', int $page = 1, int $size = 10): array`

查询相对于本地已安装 `version_code` 的更新日志。

| 参数 | 默认值 | 取值 |
| --- | --- | --- |
| `direction` | `'after'` | `after`：之后的版本；`before`：之前的版本，均排除当前版本 |
| `page` | `1` | 整数 `1`～`1000000` |
| `size` | `10` | 整数 `1`～`100` |

```php
$pending = $updater->changelog();
$history = $updater->changelog('before', 1, 20);
```

返回字段：`list: array`、`total: int`、`page: int`、`size: int`、`direction: string`、`current_version_code: int`。`list` 按版本序号倒序，每项包含 `version: string`、`version_code: int`、`changelog: string`、`published_at: string`、`channel: string`；无结果时为 `[]`。

筛选范围与更新检查一致。不下载包、不改变本地版本；首次调用可能初始化本地状态。长日志应减小 `size` 以满足 2 MiB 响应上限；展示时按应用规则转义或过滤 HTML。

### `download(array $release): string`

传入近期 `check()` 的**完整返回值**，且 `update=true`。始终下载 `release['package']` 的完整包，校验大小与 SHA-256，返回 `work_dir/downloads` 中 ZIP 的绝对路径。

只下载，不安装、不推进版本。成功文件由调用方保管或清理；失败的临时下载自动清理。不能传入 `summary()` 的结果，也不能将返回路径传给 `update()`。票据过期或来源 IP 变化后重新 `check()`。

```php
$release = $updater->check();
if ($release['update']) {
    $zipPath = $updater->download($release);
}
```

### `manifest(array $release): array`

传入近期 `check()` 的完整返回值，要求 `update=true` 且 `package.manifest_state='ready'`。读取并校验目标完整包清单，不修改应用文件或版本。

返回：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `schema` | string | `apps.manifest.v1` |
| `files` | array | 相对文件路径到文件信息的映射 |
| `file_count` | int | 文件数 |
| `expanded_size` | int | 文件总字节数 |

每项文件信息为 `['size' => int, 'sha256' => string, 'mode' => int]`，`mode` 为 `0644` 或 `0755`（JSON 中为十进制 `420` 或 `493`）。校验原始字节哈希、路径、文件数量、大小和配置限额；未就绪、拒绝授权或校验失败均抛异常。

```php
$release = $updater->check();
if ($release['update'] && ($release['package']['manifest_state'] ?? '') === 'ready') {
    $manifest = $updater->manifest($release);
    foreach ($manifest['files'] as $path => $file) {
        // $path 为项目相对路径，$file['size'] 为字节数。
    }
}
```

清单描述中心完整包，可能包含客户端保护路径；它不是删除本地额外文件的授权指令。

### `update(array $hooks = []): ?array`

同步完成：取锁 → 检查授权与版本 → 选择完整/增量包 → 下载校验 → 解压与清单核验 → 备份及恢复计划落盘 → 覆盖/删除 → 提交版本和文件基准 → 返回。

`hooks` 是可选回调数组，详见后文。无更新返回 `null`，不运行安装回调。成功返回版本摘要字段以及：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `backup_dir` | string | 本次事务备份的本地绝对路径 |
| `package_type` | int | 实际安装方式：`1` 完整，`2` 增量 |
| `retained_files` | string[] | 新版本已废弃、但因本地修改而保留的文件相对路径 |

返回的 `update=true` 是本次安装的版本摘要，不表示还有下一个版本。安装失败会尝试恢复文件和版本；提交后回调失败不撤销已提交结果，见错误处理。

### `rollback(array $hooks = []): void`

恢复最近一次事务：还原覆盖或删除的旧文件，移除新增文件及事务创建的空目录，恢复原版本和文件基准。恢复前检查全部备份完整性。

无事务时抛异常；事务已为 `rolled_back` 时直接返回。不能选择任意历史备份，不回滚数据库或外部服务。备份目录和状态文件必须保留。

### `Updater::summary(array $release): array`

静态字段筛选方法，返回输入中存在的 `update`、`status_code`、`version`、`version_code`、`changelog`、`published_at`，并添加 `requirements`（取自 `package.requirements`，缺省 `''`）。

不包含凭证、票据、包详情或本地备份路径。不验证输入，不取锁；结果用于展示，不能再传给 `download()` 或 `manifest()`。

## 完整包、增量包与文件保护

完整 ZIP 直接包含应用文件，依赖与前端产物应在发布前构建好。库不在线运行 Composer、不执行 ZIP 内脚本、不创建数据库或生成业务配置。

增量安装同时满足以下条件才启用：

- `prefer_delta=true`，中心提供精确基准对应的增量，且增量比完整包小。
- 状态记录的基准完整包 ID 与候选一致，具有完整包哈希和文件清单。
- 非保护基准文件在本地存在，大小和 SHA-256 与记录一致。
- 目标完整清单已就绪，增量元数据中的基准/目标身份通过核验。

无基准、本地文件缺失或修改、候选不匹配或不够小时使用完整包。已选择增量后发现包或清单损坏、授权拒绝等错误则停止，不通过自动回退掩盖校验失败。增量逐文件替换，不是二进制差分；支持只删除文件的增量。

两种安装方式使用相同事务规则：

| 文件情况 | 处理 |
| --- | --- |
| 位于保护路径 | 不覆盖、不删除 |
| 新包包含的普通文件 | 安装或覆盖；已有文件保留原权限，新文件按包权限设置 |
| 旧清单记录、新版本不再需要、本地内容未修改 | 备份后删除 |
| 旧清单记录、新版本不再需要、本地内容已修改 | 保留，列入 `retained_files` |
| 本地自建、旧清单未记录、新包未包含 | 保留 |
| 旧状态没有安装清单 | 完整安装建立基准，不猜测历史文件归属 |

**完整包回退仍会覆盖新包包含的非保护文件，包括本地改动。** 需要长期保留的配置和客户数据应加入 `preserve`。

默认保护：`.git`、`.env`、`.user.ini`、`runtime`、`storage`、`upload`、`uploads`、`public/upload`、`public/uploads`、`public/storage`；任意层级的 `.git`、`.env`、`.env.*` 也受保护。追加的目录保护所有子路径，路径比较不区分大小写；中心扩展配置不能改写本地保护规则。

ZIP 文件名使用 UTF-8，支持中文与纯数字文件名。拒绝路径穿越、绝对路径、符号链接、特殊文件、加密条目、重复/大小写冲突、含糊编码及超限 ZIP。文件与目录互换、仅改大小写的已记录路径变更会被拒绝，应单独规划迁移。

## 回调

`update($hooks)`、`rollback($hooks)` 接受 `array<string, callable>`。每个回调接收一个 `array $release` 版本摘要；返回 `false` 或抛异常视为失败，其他返回值（含无返回值）视为成功。

| 名称 | 所属操作 | 调用时机 |
| --- | --- | --- |
| `before_apply` | update | 备份和事务记录已完成，覆盖前 |
| `after_apply` | update | 文件已更新，版本状态提交前 |
| `after_commit` | update | 文件和版本已提交 |
| `before_rollback` | 显式 rollback | 全部备份校验后，恢复前 |
| `after_rollback` | 显式 rollback | 文件和版本恢复后 |

```php
$result = $updater->update([
    'after_apply' => static function (array $release): void {
        // 调用应用健康检查；不通过时抛出 RuntimeException。
    },
    'after_commit' => static function (array $release): void {
        // 完成应用缓存刷新或交由外部管理器安排进程重载。
    },
]);
```

回调在更新锁内执行，不能再次调用同一站点的 `Updater` 实例方法。自动故障恢复不调用恢复回调；恢复回调仅由显式 `rollback($hooks)` 执行。未知回调名称或不可调用值抛 `InvalidArgumentException`。

维护与暂停写入应在调用 `update()` 前完成，`before_apply` 已晚于备份。数据库迁移及其恢复策略由应用负责。安装逐文件替换，不是整站原子切换。

## HTTP 与常驻进程接入

在应用已有的后台控制器中完成登录、升级权限、POST 和 CSRF 校验后调用方法。配置与回调来自服务端，不能由请求传入。

```php
// 框架已完成自动加载和权限校验，应用已进入维护。
$updater = new \BuTing\CliAppUpdater\Updater($config);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$result = $updater->update($hooks);
if ($result !== null) {
    unset($result['backup_dir']);
}
return json(['updated' => $result !== null, 'release' => $result]);
```

这里的 `json()` 使用宿主框架响应函数。完整适配函数见 [examples/http.php](examples/http.php)，可由控制器 `require` 后调用；它不会自行注册公开路由。

| 运行方式 | 执行行为 |
| --- | --- |
| FPM 请求 | 同步等待结束；需配置足够的 PHP、FPM 和代理请求时限 |
| Webman 请求/worker | 同步下载和文件操作占用执行 worker；耗时任务可交给已有专用 worker |
| 队列任务 | 在任务内调用同一个 `update()`，由应用记录任务状态 |
| CLI | 可选命令入口，调用同一套方法 |

安装不依赖 `exec`、`shell_exec`、`proc_open`、`popen` 或 `fastcgi_finish_request()`。Windows 系统 ID 自动探测会尝试可用的进程函数，但允许回退或通过 `machine_id` 显式配置。

库不内置队列、异步任务 ID、实时进度或断点续传，不更改 PHP 全局执行时限。FPM 的 `request_terminate_timeout`、代理超时与出站下载超时需分别配置。常驻进程重载和 OPcache 刷新由应用安排；CLI 中刷新缓存不能代表 FPM 缓存已刷新。

## 异常与恢复

参数错误使用 `InvalidArgumentException`；网络、授权、JSON、锁、文件和事务错误通常使用 `RuntimeException`。调用边界可捕获 `Throwable`。错误文本不是稳定业务码，不应依靠字符串判断流程。

HTTP 拒绝通常通过异常 `getCode()` 返回实际 HTTP 状态码；传输错误和本地校验错误没有统一业务码。`check()` 无更新返回 `update=false`，授权拒绝则抛异常。

| 情况 | 状态与处理 |
| --- | --- |
| 下载/解压/安装前校验失败 | 未修改应用文件，处理具体原因后重新检查 |
| 覆盖、`before_apply`、`after_apply` 或提交失败 | 自动尝试恢复文件和版本；回调中的数据库与外部副作用由应用处理 |
| `after_commit` 失败 | 安装已提交；补完缓存、重载或退出维护步骤 |
| HTTP 超时、断网、页面关闭 | 结果未确认；等待原操作结束后核对状态，不立即重试安装或回滚 |
| 其他操作持锁 | 本次调用被拒绝；`status()` 也不能越过该锁 |
| 无锁且事务为 `applying` / `rolling_back` | 保持维护，通过 `rollback()` 恢复；新的 `check()` / `update()` 会被拒绝 |
| 备份损坏或恢复失败 | 保留工作目录，修复备份或文件权限后恢复 |

普通日志和 HTTP 响应不应包含密钥、完整平台响应、下载票据或异常堆栈。保留最近事务引用的备份；旧备份和只下载未安装的 ZIP 由应用在无任务运行时清理，库不自动轮转备份。

应用路由本身可能被更新替换。需要独立恢复能力时，可将受保护的恢复入口和更新器副本部署在被更新目录之外。

## 离线授权 API

离线验签独立于在线更新，需要 `ext-sodium`。离线文件是更新权益快照，不是下载票据；`update()` 仍使用在线授权和下载流程。

### `OfflineLicense::verify(string $document, string $publicKey, string $productCode, string $clientKey, string $domain = '', ?string $publishedAt = null): array`

| 参数 | 说明 |
| --- | --- |
| `document` | 授权 JSON 原文，不是文件路径；最多 65536 字节 |
| `publicKey` | 可信预置的 Ed25519 公钥 Base64，解码后 32 字节；不能信任待验证文件自带的公钥 |
| `productCode` | 预期产品 Code |
| `clientKey` | 当前实例 ID；证书声明该绑定时必须匹配 |
| `domain` | 可信当前域名；证书声明该绑定时必须匹配 |
| `publishedAt` | 可选的可信版本首次发布时间，ISO 8601 且带时区，用于检查更新权益截止点 |

```php
use BuTing\CliAppUpdater\OfflineLicense;

$license = OfflineLicense::verify(
    file_get_contents('/srv/private/update.license.json'),
    $trustedPublicKeyBase64,
    'my-shop',
    $updater->getClientKey(),
    'shop.example.com',
    '2026-10-01T12:00:00+08:00'
);
```

返回已验证的签名载荷：

| 字段 | 类型 | 含义 |
| --- | --- | --- |
| `schema` | string | `apps.update-license.v1` |
| `key_id` | string | 公钥 SHA-256 |
| `license_id` | string | 授权记录 ID |
| `product_code` | string | 产品 Code |
| `issued_at` | string | ISO 8601 签发时间 |
| `updates_until` | string\|null | 允许版本的首次发布时间截止点，`null` 不限制 |
| `constraints` | array | 证书声明的 `client_key`、`domain` 绑定，可为空 |

校验签名、产品和全部声明的绑定，未知绑定被拒绝。不传 `publishedAt` 时不会证明某个版本在权益范围内。在线接口的时间字符串须按中心实际时区转换为带时区的 ISO 8601 后传入，不能直接假定为 UTC。

离线文件无法验证公网来源 IP，导出后不能即时远程撤销。更新权益截止点不限制软件运行时间。本库不提供本地离线 ZIP 安装方法，授权文件签名也不等同于更新 ZIP 签名。

### `OfflineLicense::domain(string $domain): string`

规范化并验证域名：去首尾空白和末尾点，转小写；空串返回空串。有 `intl` 时可转换 IDN，否则传入 Punycode。要求包含点的合法主机名，拒绝 IP、协议、端口和路径；非法输入抛 `InvalidArgumentException`。

## CLI 与示例

CLI 配置文件返回与构造参数一致的数组，可额外包含 `hooks`。PHP 方法调用应把回调直接传给 `update()` / `rollback()`；构造配置中的 `hooks` 不会自动应用。

```bash
php vendor/bin/cli-app-updater check /srv/private/updater.php
php vendor/bin/cli-app-updater update /srv/private/updater.php
php vendor/bin/cli-app-updater rollback /srv/private/updater.php
```

| 命令 | 输出 |
| --- | --- |
| `client-key` | `{"client_key":"..."}` |
| `status` | 本地状态 |
| `report` | 登记结果 |
| `authorize` | 授权结果，去掉 `license_value` |
| `check` | 版本摘要 |
| `changelog` | 默认 `after / 1 / 10` 日志结果 |
| `manifest` | 检查后读取目标清单；无更新时返回摘要 |
| `download` | 完整包摘要及 `downloaded_file`；无更新时返回摘要 |
| `update` | 安装结果；无更新时 `{"update":false}` |
| `rollback` | `rolled_back=true` 与恢复后的状态 |

输出 JSON；退出码 `0` 成功或无更新，`1` 执行失败，`2` 命令参数错误。

- [examples/config.php](examples/config.php)：共享配置及注释。
- [examples/updater.php](examples/updater.php)：精简检查示例。
- [examples/demo.php](examples/demo.php)：完整命令演示与回调。
- [examples/http.php](examples/http.php)：控制器处理函数，支持 `client-key/status/check/changelog/manifest/update/rollback`。安装返回 `updated` 和 `release`，去掉本地备份路径；恢复返回 `rolled_back`。

示例使用占位配置，接入时配置实际中心和项目目录。`Files`、`HttpClient`、`Installer`、`Manifest`、`Json`、`ClientIdentity` 是内部辅助类，不属于应用集成 API。

## 中心协议兼容

| 功能 | 最低中心版本 | 请求 |
| --- | --- | --- |
| 登记、授权、更新检查、完整包下载 | v0.13 | `/api/{product_code}`，`action=report/authorize/check/download` |
| 更新日志 | v0.13.1 | `action=changelog` |
| 文件清单与增量分发 | v0.14 | `action=manifest`，检查提交 `supports_delta/base_package_id` |

库固定请求 `platform=php`，并提交已安装版本与配置渠道。产品通信密钥和实例 ID 经请求头传输；下载和清单使用中心签发的同产品相对票据地址，不附带产品密钥，不跟随跨站跳转。检查与下载须使用相同出口 IP，中心会在下载时重新验证授权及包流量额度。

不提供增量候选的中心按完整包更新。离线授权签发公钥由应用通过可信渠道配置。
