# ACG-Faka 商店交付清单 / Marketplace delivery

版本：0.2.0 预览版。此文档用于准备上传，不表示已通过商店审核。

| 商店条目 | 类型 | plugin_key | 首次安装包 | 升级包 |
| --- | --- | --- | --- | --- |
| PolyPay | 支付插件 / 1 | PolyPay | `*-store-payment-install.zip` | `*-store-payment-update.zip` |
| PolyPay Callback | 通用插件 / 0 | PolyPayCallback | `*-store-callback-install.zip` | `*-store-callback-update.zip` |

两个条目必须安装并启用回调插件后才能创建支付。支付包不会安装回调插件；宿主商店没有在此处自动安装依赖的能力。建议先安装回调插件，再安装支付插件。两个模块保持相同版本。

Both entries are required. Install and enable the callback companion before creating payments. The store does not automatically install this dependency. Keep both modules on the same version.

## 包结构 / Archive layout

- 商店包从 `Config/` 开始，直接解压到对应插件目录；不能上传 `manual` 整包。
- 手动包包含 `app/Pay/PolyPay/` 和 `app/Plugin/PolyPayCallback/`，从宿主根目录安装。
- 首次安装包只含空支付配置及默认关闭的回调配置；升级包完全省略 `Config/Config.php`，保留宿主已有设置。升级包不能用于首次安装。
- 不包含 API Key、账户、私有配置、订单快照或日志。`dist/manifest.json` 给出包类型和 SHA-256。

Store archives start at `Config/` and extract into their own extension directory. Manual archives start at the host root. Install archives contain generated clean defaults; update archives omit configuration entirely. Never upload a manual archive to a store entry.

## 提交前准备 / Before submission

1. 确认商店账号具有开发者权限；注册表中上述两个标识是否可用，以商店实际返回为准。
2. 准备各条目的名称、120×120 图标、支持版本、说明和定价。说明应显著标注两个条目的依赖关系、仅生产环境、CNY→USD 汇率及取消不同步限制。
3. 创建版本时上传对应 ZIP 资源，填写与包内一致的 `0.2.0`。Docker 中支付目录软链到 `/data/pay`，宿主自动打包会拒绝站点外真实路径，应使用这里预生成的 ZIP。
4. 商店安装、远端上传及审核尚未执行。通过本地解包与宿主回调路由检查不能代替商店审核和真实付款验收。

Verify developer access and key availability, prepare a 120×120 icon and listing details, then upload each matching archive as a resource. On Docker installations with an external payment-directory symlink, use these prebuilt archives instead of the host auto-packer. Actual store installation, submission and review remain unverified.

## 从 0.1.0 升级 / Migrating from 0.1.0

暂停新的 PolyPay 下单，等待未完成订单结束或在远端取消，并备份支付配置和私有存储；新增并启用回调插件，再用手动升级包或两个商店升级包更新代码，最后恢复下单。原有 `app/Controller/User/Api/PolyPayNotification.php` 及挂载应保留到旧订单回调全部结束；确认文件归属和无旧订单后，才移除旧控制器及对应挂载。回调地址参与支付尝试编号计算，升级后不要重试旧的未完成订单，以免产生第二个远端订单。新版本仅为新订单生成 `/plugin/PolyPayCallback/notification/callback.订单号?kind=order` 地址（充值为 `recharge`），不重写已有远端订单。

Pause checkout, drain or remotely cancel outstanding payments, back up settings and private storage, install and enable the companion, then update both modules. Keep the old 0.1.0 controller and mount until all old callback URLs have drained. New orders use the companion route; existing remote orders are not rewritten. Confirm ownership before removing old files.

禁止在有未完成付款时卸载或禁用任一模块，否则回调和发货会中断。升级不修改宿主数据库，不删除订单记录或私有映射。

Do not disable or uninstall either component while payments are outstanding. Upgrades do not alter host schemas or remove payment mappings.

## 本次验证记录

- 本地支付适配器、签名校验、回调 HTTP 边界、缺少/禁用回调模块的下单拦截测试通过。
- 六种包格式解包检查通过；注入测试密钥和日志后打包，确认未进入安装包；升级解包保留原配置。
- 96 服务器 ACG-Faka 3.8.4 原生 `Zip::unzip` 和 Composer 类加载通过；临时启用回调模块后，真实回调路由对未知订单返回 HTTP 409；验证目录已清理，现有安装保留。
- 站内安装说明同步覆盖 11 种语言。尚未验证商店远端上传、审核、付费下载安装或真实付款发货。
