# cliapp-updater

[GitHub](https://github.com/BuTingBa/cliapp-updater) · [Packagist](https://packagist.org/packages/buting/cliapp-updater)

用于 apps.cli.cc 发布平台的 PHP 下载更新库。ThinkPHP/FPM、Webman、普通 PHP、队列和 CLI 共用同一套方法。后台按钮可直接调用 `update()`，同步完成检查、下载、校验、备份和文件覆盖，不要求客户登录服务器运行命令。

要求：64 位 PHP 7.4 或 PHP 8.x，`curl`、`json`、`hash`、`zip` 扩展；离线授权验签另需 `sodium`。没有第三方 PHP 包依赖。

## 安装与最小调用

```sh
composer require buting/cliapp-updater:dev-main
```

目前提供 `dev-main` 开发分支，适合接入验证；请提交应用的 composer.lock 固定实际安装的提交。稳定版本发布后可使用 `composer require buting/cliapp-updater`，或按已发布版本指定约束，不需要降低整个项目的 minimum-stability。

Composer 包名是 `buting/cliapp-updater`，安装目录为 `vendor/buting/cliapp-updater`；PHP 命名空间仍为 `BuTing\CliAppUpdater`，可选命令入口仍为 `vendor/bin/cli-app-updater`。后两者与 Composer 包名不是同一项配置。

```php
<?php

use BuTing\CliAppUpdater\Updater;

require __DIR__ . '/vendor/autoload.php'; // 框架已自动加载时省略。

$updater = new Updater([
    'base_url' => 'https://apps.example.com',
    'product_code' => 'my-shop',
    'product_key' => getenv('UPDATER_PRODUCT_KEY'),
    'project_dir' => __DIR__,                         // 被更新的应用根目录。
    'work_dir' => dirname(__DIR__) . '/shop-updater', // 独立持久工作目录。
    'version_code' => 100,                           // 首次接入时的实际版本序号。
    'domain' => 'shop.example.com',
]);

$clientKey = $updater->getClientKey(); // 后台展示实例标识；不是安装前置条件。
$release = $updater->check();         // 自动初始化 client_key，可单独调用。
$display = Updater::summary($release);

// 在已通过后台登录、升级权限和 CSRF 校验的 POST 控制器中：
$result = $updater->update(); // 无须先 check/download；完成后才返回。
// null：没有可获取的新版本；array：文件已安装，含版本摘要和本地备份目录。
```

`check()` 自动登记实例，但登记不等于授权。授权下载产品需在平台配置更新权益。检查结果可能含在线凭证和短期下载票据，展示或记录前请用 `summary()` 提取摘要。

## 后台点击升级怎么接

后台按钮 POST 到应用自己的升级控制器，控制器校验权限后调用方法，并返回框架的 JSON 响应。库不提供公开路由、不接管登录或会话。下面是 ThinkPHP/Webman 控制器内的核心代码：

```php
// 前置：路由限制 POST；后台登录、升级权限、CSRF 校验均已完成。
// $config 来自服务器配置，不能允许请求传入目录、密钥或回调。
$updater = new Updater($config);

// 若应用使用 PHP 原生 session，鉴权及必要写入完成后释放会话锁。
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$handler = require '/你的示例文件目录/http.php'; // 把 examples/http.php 复制到应用内。
$data = $handler($updater, 'update');            // 同步执行，完成后返回。
return json(['success' => true, 'data' => $data]); // 使用所在框架的响应方法。
```

[examples/http.php](examples/http.php) 支持 `check`、`update`、`rollback`、`status`、`client-key`。它只返回函数，直接访问文件不会安装。示例返回的 `updated` / `rolled_back` 是展示字段，与下文库方法的原始返回值不同。异常由应用统一处理，不向浏览器返回密钥、完整堆栈或磁盘路径。

前端等待返回，期间禁用重复点击并显示“正在更新”。超时、断网或页面关闭表示**结果尚未确认**，不能据此认定安装失败，也不要自动重试或自动恢复。

### 执行方式

| 方式 | 如何调用 | 适用条件 |
| --- | --- | --- |
| FPM 同步请求 | 控制器直接 `update()` | 包体/耗时可控，服务器允许请求等待；不需要命令执行权限 |
| 已有队列/任务 worker | 控制器投递任务，任务内 `update()` | 大包/长任务；页面查应用自己的任务状态，客户仍只需点击 |
| Webman 请求或自定义 worker | 直接 `update()` | 同步执行；较长更新建议交给已有专用 worker |
| CLI | 命令内调用相同方法 | 可选运维入口，不是安装必需步骤 |

本库不使用 `fastcgi_finish_request()`，不在安装时执行 shell 或拉起进程，不内置队列、实时百分比或分段续传。Windows 自动探测系统 ID 时可选调用 `reg.exe`，禁用 `proc_open` 会回退；安装不要求开启它，也可显式配置稳定 `machine_id`。

FPM 需给下载、解压、备份、覆盖留出完整时间。核对 PHP `max_execution_time`、FPM `request_terminate_timeout`、Nginx `fastcgi_read_timeout` 及上游代理/浏览器超时。`http.download_timeout` 仅限制出站 cURL 下载，不能延长入站请求期限。`set_time_limit(0)`、`ignore_user_abort(true)` 也不能保证进程不被服务器终止；库不会改动这些全局设置。[PHP FPM 配置](https://www.php.net/manual/en/install.fpm.configuration.php)、[Nginx FastCGI 超时](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_read_timeout)

常驻不等于自动异步。同步 cURL、ZIP 和文件操作在普通 Webman worker 中会占用该 worker。协程 I/O 行为取决于驱动和 hook，本库不承诺协程非阻塞；Fiber 本身不会把阻塞 I/O 变为异步。重载框架由应用或外部管理器完成。[Webman 协程说明](https://webman.workerman.net/doc/zh-cn/coroutine/coroutine.html)

## 构造配置：`new Updater(array $config)`

所有配置在构造时传入，没有公开可写属性或公共常量。改变配置时创建新实例，同一站点仍共用 `work_dir`。

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `base_url` | string | 必填 | 可信 HTTPS 平台地址，可带部署子路径；不能含账号、查询串、片段 |
| `product_code` | string | 必填 | 平台产品 Code，非数字 ID |
| `product_key` | string | 必填 | 产品通信密钥，通过 Product-Key 请求头发送 |
| `project_dir` | string | 必填 | 已存在的应用根目录，推荐绝对路径；ZIP 根直接对应这里 |
| `work_dir` | string | 必填 | 可自动创建的独立持久工作目录；不得与项目根互相包含，不可通过 Web 访问 |
| `version_code` | int | `0` | 0～9007199254740991；仅首次初始化使用，之后读状态；如100，不是字符串1.0.0 |
| `client_key` | string | `''` | 空值自动生成；显式值8～64位字母、数字、下划线或短横线，必须匹配已有状态 |
| `machine_id` | string或null | `null` | null自动探测；非空值为稳定实例ID，最多512字节；空串禁用探测、使用持久随机ID |
| `upgrade_token` | string | `''` | 可选客户在线凭证；通过 Upgrade-Token 发送，空值由平台按实例查授权 |
| `domain` | string | `''` | 可信配置域名，不取请求Host；无协议/端口/路径，不支持IP；无域名绑定可空 |
| `client_name` | string | `''` | 平台展示的安装实例名称 |
| `channel` | string | `'stable'` | stable 或 beta |
| `preserve` | string[] | `[]` | 追加项目根相对保护路径，如 config/local.php；目录含子文件，不支持通配符 |
| `max_package_bytes` | int | `2147483648` | ZIP下载上限，正整数，默认2 GiB；不是服务器承载能力保证 |
| `http` | array | `[]` | 下表中的传输选项 |
| `archive_limits` | array | `[]` | 下表中的解压选项 |

`http` 完整参数：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `allow_http` | bool | `false` | 仅本地开发显式允许明文HTTP |
| `connect_timeout` | int | `10` | cURL连接超时，正整数秒 |
| `timeout` | int | `30` | 单次平台API总超时，正整数秒 |
| `download_timeout` | int | `1800` | 单次下载总超时，正整数秒 |
| `ca_file` | string | `''` | 自定义可信CA路径；空值使用环境默认CA |

HTTPS始终校验证书，不跟随重定向。下载仅接受受限相对票据路径，不使用 file_full_url 跳转其他来源。

`archive_limits` 完整参数：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `max_files` | int | `50000` | ZIP条目数上限，正整数 |
| `max_expanded_bytes` | int | `10737418240` | 解压总字节上限，正整数，默认10 GiB |

```php
'http' => ['connect_timeout' => 10, 'timeout' => 30, 'download_timeout' => 600],
'archive_limits' => ['max_files' => 20000, 'max_expanded_bytes' => 2147483648],
'preserve' => ['config/local.php', 'public/customer-files', 'data/customer.sqlite'],
```

### 两个目录与权限

```text
/srv/shop/
├── app/                    project_dir：被更新的程序
│   ├── app/
│   ├── public/              网站 Web 根目录
│   ├── vendor/
│   └── .env
└── shop-updater/            work_dir：状态、下载、备份
    ├── state.json
    ├── update.lock
    ├── transaction.json
    ├── downloads/
    └── backups/
```

执行PHP的账户需能读写相应文件，并预留ZIP、解压和备份空间。同站点Web、队列、CLI共用工作目录；不同站点分别配置。不要把工作目录放进 vendor/bin、系统临时目录或网站公开目录。

## Updater 方法参考

所有实例方法使用同一工作目录的非阻塞排他锁：冲突立即抛异常，不排队；返回/抛出时释放。status/getClientKey也使用这把锁。summary是纯静态转换，不取锁。

### `getClientKey(): string`

无参数。读取或首次生成实例标识，无网络请求，可在后台展示，无须先status/report。

新实例默认按系统安装ID、平台地址、产品Code、项目真实路径派生32位十六进制ID。Linux读取machine-id，Windows读取MachineGuid；不可读则随机生成并保存。原始系统ID不上传、不保存，旧随机ID继续复用。

同机同产品同路径可稳定派生；不同站点路径会区分。复制站点应使用新的工作目录，镜像/容器可能复制系统ID，应配置不同的持久machine_id或client_key。它不是硬件防复制凭据。即使ID可重建，也不要删除状态来重置版本。

### `status(): array`

无参数，无网络请求，可能首次创建状态。返回本地已提交版本和最近文件事务：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `client_key` | string | 实例标识 |
| `client_key_source` | string | machine / random / configured / legacy |
| `version_code` | int | 当前已提交版本序号 |
| `version` | string，可缺省 | 更新后记录的显示版本，首次初始化可能没有 |
| `updated_at` | string，可缺省 | 更新记录的UTC ISO 8601时间；恢复时回到旧状态 |
| `transaction_status` | string | none / applying / complete / rolling_back / rolled_back / unknown |

安装持锁时会抛异常，**不是实时进度接口**。无锁时出现applying/rolling_back表示未完成事务，新check/update会拒绝，先处理恢复。complete仅表示文件与版本提交，不代表框架已重载或所有回调成功。

### `report(): array`

无参数。POST登记/心跳，自动初始化并上报版本、目标php、渠道、配置域名及系统/PHP信息。返回平台data：`registered: bool`、`client_id: int`、`last_time: string`。登记不表示授权，check也会登记，通常不用单独调用。

### `authorize(): array`

无参数。POST在线验证更新权益，返回平台data：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `authorized` | bool | 成功为true；拒绝抛异常 |
| `license_value` | string或null | 在线凭证，不交给浏览器或普通日志 |
| `expires_at` | string或null | 更新服务截止日，不是软件运行到期日 |
| `license_mode` | int | 平台下载模式：1授权、2公开 |

不安装文件，也不自动保存返回凭证到配置。

### `check(): array`

无参数。自动初始化、登记、验证权益，返回平台data。有更新时校验完整PHP ZIP元数据及递增版本。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `update` | bool | 有无可获取的新版本 |
| `status_code` | int | 有更新200，无更新304，这是data中的业务字段 |
| `license_value` | string或null | 可能携带在线凭证 |
| `version` | string | 有更新时的显示版本 |
| `version_code` | int | 有更新时的版本序号 |
| `changelog` | string | 有更新时的说明 |
| `published_at` | string | 有更新时的平台首次发布时间 |
| `package` | array | 有更新时的包信息，见下表 |

package中本库使用的字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `platform` | string | 必须php |
| `package_type` | int | 必须1（完整包） |
| `file_name` | string | 必须ZIP文件名 |
| `file_size` | int | ZIP字节数，正数且不超过上限 |
| `sha256` | string | 64位小写十六进制校验值 |
| `file_url` | string | 短期票据相对路径，供download使用，不展示 |
| `requirements` | string | 运行要求文字，不自动解析成环境检查 |

平台可能返回更多元数据，file_full_url/config不会用于跨站下载或执行命令。无更新时不保证存在版本/package字段。拒绝授权、传输错误、非法元数据抛异常，不伪装成update=false。

### `download(array $release): string`

release是近期check的完整返回值且update=true，不能传summary的结果。返回校验完毕的ZIP绝对路径，位于work_dir/downloads。只下载、不安装、不推进版本。

成功后的文件由调用方保管或清理，失败临时下载自动清理。票据失效或出口IP变化时重新check。没有断点续传；不能把下载路径传给update，update会重新检查并下载。

### `update(array $hooks = []): ?array`

hooks是可选本地回调，见后文。在FPM/Webman/队列/CLI中均可调用，**同步完成后返回，不返回后台任务ID**。

顺序：取锁 → 检查授权与版本 → 下载校验 → 安全解压 → 备份与恢复计划落盘 → before_apply → 覆盖文件 → after_apply → 提交版本/事务 → after_commit → 返回。

返回null表示无更新，不运行安装回调；成功返回summary字段加 `backup_dir: string`（本地备份目录）。update=true是本次发布摘要，不表示还有下一次更新。

覆盖或提交前回调发生可捕获异常时，尝试恢复文件和版本；数据库及回调副作用不自动回滚。after_commit失败会抛异常，但已安装版本保留，需补完后续动作。硬终止进程不能依赖catch/finally，需按持久事务恢复。

### `rollback(array $hooks = []): void`

hooks为可选恢复回调。同步恢复最近文件事务和原版本，删除该事务新增文件及空目录。无事务时抛异常，已rolled_back时直接返回。不能指定任意历史版本，不回滚数据库、不重启框架。

恢复前校验全部旧文件备份，损坏则拒绝覆盖。保留最近事务引用的backups和状态文件。

### `Updater::summary(array $release): array`

静态方法，输入原始check结果，保留其中已有的update/status_code/version/version_code/changelog/published_at，并添加requirements（取package.requirements，缺省空串）。去掉凭证、票据和其他包元数据，适合展示/日志，不能传给download。只是字段筛选，不验证发布数据。

## 回调参数与时机

形式为 `array<string, callable>`，每个回调接收一个 `array $release` 版本摘要。返回false或抛异常视为失败，无返回值/其他值视为成功。未知名称或不可调用值抛InvalidArgumentException。回调只能来自本地应用，不能来自平台或ZIP。

| 名称 | 所属方法 | 时机与用途 |
| --- | --- | --- |
| `before_apply` | update | 备份/事务已写好、覆盖前；检查条件并确认维护状态 |
| `after_apply` | update | 覆盖后、版本提交前；应用健康检查或自行实现的迁移 |
| `after_commit` | update | 提交后；通知外部管理器/应用后续动作，失败不自动撤销安装 |
| `before_rollback` | rollback | 备份校验后、恢复前；确认维护状态 |
| `after_rollback` | rollback | 文件/版本恢复后；应用检查与后续处理 |

```php
$result = $updater->update([
    'after_apply' => static function (array $release): void {
        // 调用应用自己的健康检查，不通过则抛 RuntimeException。
        // 数据库迁移及其恢复策略由应用负责。
    },
    'after_commit' => static function (array $release): void {
        // 可通知已有进程管理器；重载自身前确保任务结果已记录。
    },
]);
```

回调在锁内执行，**不要在回调里再次调用同站点的Updater实例方法**。自动故障恢复不调用before_rollback/after_rollback，它们只用于显式rollback。

需要一致性备份时，在update前就进入维护并暂停相关写入；before_apply已经晚于备份。逐个文件替换不是整站原子切换，其他请求可能读到混合代码。库不创建维护页面或管理业务流量。

## 错误处理与恢复

参数错误通常抛InvalidArgumentException；传输、授权、锁、ZIP/文件/状态错误通常抛RuntimeException；非法JSON可能抛JsonException。无专用错误枚举，错误文本不是稳定业务码；调用边界可捕获Throwable。不要把所有异常都解释为版本未安装，after_commit异常发生在提交后。

| 情况 | 处理 |
| --- | --- |
| 升级接口超时/失联 | 结果待确认，不自动重试；等原操作结束，查询状态并核对应用 |
| 另一操作持锁 | 等待；status同样可能被锁拒绝，不能查百分比 |
| 无锁且事务applying/rolling_back | 保持维护，通过rollback处理后再继续 |
| after_commit失败、事务complete | 文件已提交，补完缓存/进程/维护动作 |
| 恢复失败/备份损坏 | 保留工作目录，修复备份/权限后再恢复，不删状态绕过检查 |

HTTP恢复入口可调用同一个rollback。若更新破坏了应用路由，该路由就无法承担恢复。需要较强恢复能力的项目，可将受保护的恢复入口及其更新器副本部署在被更新目录之外。

## ZIP 与文件更新规则

ZIP根对应project_dir，不要多套项目文件夹。发布前准备依赖与构建产物，库不在线执行Composer、数据库命令或包内脚本。

默认保护 `.git`、`.env`、任意层级的 `.env.*`、`.user.ini`、`runtime`、`storage`、`upload`、`uploads`、`public/upload`、`public/uploads`、`public/storage`；preserve追加客户配置/数据。ZIP未包含的旧文件不自动删除。拒绝危险路径、符号链接、大小写碰撞及超限/损坏ZIP。

SHA-256核对可信平台提供的包内容；离线授权签名不等于ZIP签名。框架缓存、常驻进程重载及数据库由宿主应用负责。

## OfflineLicense 方法参考

```php
use BuTing\CliAppUpdater\OfflineLicense;

$license = OfflineLicense::verify(
    file_get_contents('/srv/private/license.json'),
    $trustedPublicKeyBase64,
    'my-shop',
    $updater->getClientKey(),
    'shop.example.com',
    '2026-10-01T12:00:00+08:00'
);
```

### `OfflineLicense::verify(string $document, string $publicKey, string $productCode, string $clientKey, string $domain = '', ?string $publishedAt = null): array`

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| document | 是 | 授权文件JSON原文，不是路径；最多65536字节 |
| publicKey | 是 | 可信预置的Ed25519公钥Base64（解码32字节），不能取待验证文件自带公钥 |
| productCode | 是 | 应匹配的产品Code |
| clientKey | 是 | 当前实例标识；证书声明该绑定时必须匹配 |
| domain | 否 | 当前可信域名；证书声明该绑定时必须匹配 |
| publishedAt | 否 | 可信版本首次发布时间，ISO 8601带时区，供更新权益截止日比较 |

返回验证后的原始签名payload数组：schema、key_id、license_id（string）、product_code、issued_at、updates_until（string或null）、constraints（证书声明的client_key/domain）。未知绑定拒绝，失败抛异常。

updates_until=null表示不限制版本日期；不传publishedAt时只检查签名、产品、绑定等，不证明任意版本均可获取。截止日不限制软件运行。离线文件不是下载票据，导出后不能即时远程撤销。本库尚无离线ZIP安装方法。

### `OfflineLicense::domain(string $domain): string`

去首尾空白、尾部点、转小写；空串返回空串。有intl时可转IDN，否则请传punycode。必须带点的合法主机名，拒绝IP、协议、端口、路径；非法输入抛InvalidArgumentException。

### API 范围

以上是应用接入API的全部公开方法与配置，没有公开属性、全局配置变量、队列开关或async参数。Files、HttpClient、Installer、ClientIdentity是包内实现辅助类，通过Updater/OfflineLicense接入，不依赖其内部格式作为应用接口。

## 可选命令行与示例

```sh
php vendor/bin/cli-app-updater check /srv/private/updater.php
php vendor/bin/cli-app-updater update /srv/private/updater.php
php vendor/bin/cli-app-updater rollback /srv/private/updater.php
```

配置文件return数组，与构造配置一致；CLI专用可另加hooks数组。PHP API需把hooks单独传给update/rollback，放进构造配置不能代替方法参数。

支持client-key/status/report/authorize/check/download/update/rollback，输出JSON；退出码0成功/无更新，1失败，2参数错误。

- [HTTP处理函数](examples/http.php)：后台检查、升级、恢复及注释。
- [完整CLI演示](examples/demo.php)：主要操作与回调位置。
- [精简检查示例](examples/updater.php)：初始化、检查、读取client_key。
- [共享配置](examples/config.php)：带中文注释的配置。

## 中央接口版本

本版本使用中心 v0.13 的固定产品入口 `/api/{product_code}`，action 参数选择 report、authorize、check、download；PHP 包查询明确提交 platform=php、version_code 和 channel。配置的 base_url 仍是可信中心站点地址，已有 client_key 和状态文件不变。

下载只接受同产品的相对票据 URL（兼容旧票据路径），忽略 file_full_url，拒绝跨产品地址及跳转。中心在每次下载时复查授权和所属用户的月流量额度；403 不应无限重试。先升级中心，再更新客户端库。本地版本尚未自动发布到 GitHub/Packagist。
