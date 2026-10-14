# 更新日志

## v1.1.0.202610141806

- 正式包版本 v1.1.0：统一产品 API、相对已安装版本的更新日志查询，以及中心 v0.14 文件清单与增量安装；Composer 包名保持 buting/cliapp-updater。以下历史 v0.x 条目是系统联动维护记录，不对应 Composer 标签。
- 新增 manifest(release) 和 prefer_delta 配置。基准包与本地文件一致且增量更小时自动选择增量；首次安装、旧状态和本地修改回退完整包。校验包哈希、基准/目标身份、变更文件和完整目标清单，损坏包直接拒绝。
- 完整包与增量共用事务恢复，清理已记录且未修改的废弃文件；保护路径和客户自建文件不删除，修改过的废弃文件通过 retained_files 返回。回滚同时还原删除文件、版本和安装基准，支持仅删除的增量包。
- ZIP 文件名按 UTF-8 处理，支持中文与纯数字文件名；明确拒绝含糊编码、路径冲突及不安全的结构变更。保留命名空间、命令入口、client_key 和旧状态兼容。
- README 补全清单、增量、返回字段与接口要求，CLI/完整示例/HTTP 方法同步。维护者手册与发布说明位于父仓库 updater/docs。
- 验证：PHP 8.4.11 和 PHP 7.4.9（启用 sodium）各通过 200 项断言；真实中心 MySQL/HTTP 联调通过 35 项，含平台生成增量、中文与数字路径、PHP 7.4 CLI 安装、删除与回滚。中断恢复、完整包回退及 HTTP 禁用命令函数场景已验证。临时数据自动清理，未部署客户生产站点。

## v0.13.1.202610141542

- 增加changelog(direction='after',page=1,size=10)，相对已安装版本查询日志；支持before方向，使用中心v0.13.1的action=changelog。
- CLI新增changelog，HTTP处理函数和完整示例同步；保留身份/锁/安装保护与check原契约。
- PHP8.4通过149项、PHP7.4通过143项（签名因本机缺sodium跳过），真实平台及CLI联调27项。未发布远端标签。

## v0.13.0.202610141331

- 中心 v0.13 对接使用固定 /api/{product_code}，以 action 选择登记、授权、检查与下载；PHP目标由参数显式提交。
- 新下载路径验证产品 Code，保留旧票据路径、可信来源限制、安装身份与恢复机制。
- PHP8.4：142项；PHP7.4：136项（本机缺sodium，签名测试跳过）；真实中心接入23项通过。尚未推送 GitHub 或发布 Composer 标签。

## v0.11.1.202610131339

- 包名统一为buting/cliapp-updater，README增加实际GitHub/Packagist链接、当前dev-main安装方式及稳定版本发布后的用法；补充包名、命名空间、命令入口和安装目录的区别。
- composer.json增加homepage、support.source及support.issues；保留BuTing\CliAppUpdater、bin/cli-app-updater与已有实例ID派生算法，未改变安装器行为。
- 从Packagist已收录的dev-main提交11216a4验证真实安装、PHP7.4/8.4自动加载和CLI入口。该条目是文档维护记录，不代表已创建同名Composer版本标签。
