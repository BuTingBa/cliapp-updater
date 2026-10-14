# 更新日志

## v0.13.0.202610141331

- 中心 v0.13 对接使用固定 /api/{product_code}，以 action 选择登记、授权、检查与下载；PHP目标由参数显式提交。
- 新下载路径验证产品 Code，保留旧票据路径、可信来源限制、安装身份与恢复机制。
- PHP8.4：142项；PHP7.4：136项（本机缺sodium，签名测试跳过）；真实中心接入23项通过。尚未推送 GitHub 或发布 Composer 标签。

## v0.11.1.202610131339

- 包名统一为buting/cliapp-updater，README增加实际GitHub/Packagist链接、当前dev-main安装方式及稳定版本发布后的用法；补充包名、命名空间、命令入口和安装目录的区别。
- composer.json增加homepage、support.source及support.issues；保留BuTing\CliAppUpdater、bin/cli-app-updater与已有实例ID派生算法，未改变安装器行为。
- 从Packagist已收录的dev-main提交11216a4验证真实安装、PHP7.4/8.4自动加载和CLI入口。该条目是文档维护记录，不代表已创建同名Composer版本标签。
