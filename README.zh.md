# ACG-Faka 的 PolyPay 支付插件

[English](README.md) · **简体中文**

新增 `app/Pay/PolyPay/` 支付模块及 `app/Plugin/PolyPayCallback/` 回调模块，不修改宿主已有文件、路由配置和数据库结构。支付后继续由宿主原生逻辑发货和充值。

版本：**0.2.0 预览版；适配器测试已通过，尚待真实宿主验收。** 当前核对基准：ACG-Faka `ea4f4e6`（3.8.4）。

## 使用 AI 快速接入

复制下面的提示词，发送给 Claude Code、Cursor、Codex 等 AI 编程工具：

```text
请安装并使用 ACG-Faka 的 PolyPay 接入 Skill：
https://github.com/PolyPayAi/acg-faka-plugin/tree/feat/standalone-payments/skills/polypay-acg-faka

然后为当前 ACG-Faka 项目接入 PolyPay 支付，配置支付和回调插件，完成建单、取消验证。
```

[查看 Skill 说明](skills/polypay-acg-faka/SKILL.md)

## 安装

1. 在宿主 PHP 8+ 环境启用 curl、json、bcmath、sodium。
2. 解压 `manual-install` 安装包，将两个模块目录复制到宿主对应位置；在后台「插件管理」启用 PolyPay Callback 后再配置支付。如果目标已存在，应先停止安装并核对归属，不能直接覆盖。无需修改宿主 Composer。
3. 后台「支付插件」配置 PolyPay：API Key、商户 ID、`usd_rate` 和私有存储绝对路径 `storage_dir`。Webhook 固定校验 production 环境，插件不提供 Sandbox 模式。
4. 当前核对的宿主向插件提交 CNY 网关金额；`usd_rate` 是每 1 CNY 折合多少 USD，例如 `0.14`。其他版本需先验证传入金额含义，避免二次换算。
5. 新增支付接口：插件选 `PolyPay`，支付标识填 `PolyPay`，按需启用商品购买及余额充值。
6. 回调地址为宿主域名下的 `/plugin/PolyPayCallback/notification/callback.订单号?kind=order`（充值为 `kind=recharge`），通过宿主控制器约定自动加载，无需改路由配置。确保 HTTPS、WAF 和回调 IP 白名单允许 PolyPay 通知。

配置档提供「强制 HTTPS」（`force_https`）开关，默认开启。内网测试可关闭（`0`），允许商城回调和浏览器返回地址使用 HTTP，无需填写地址白名单；正式环境建议保持开启（`1`）。PolyPay API 和收银台地址继续使用 HTTPS，Webhook 仍严格验证 production 签名。公网服务器无法直接回调内网 IP，因此内网测试不能验证付款后的自动发货；浏览器返回不会标记订单已支付。旧的 `lan_http_origin` 设置已移除，升级后需要显式关闭 HTTPS 开关才能继续使用 HTTP。

`storage_dir` 必须在网站根目录之外，持久保存且仅 PHP 进程和管理员可访问。仅支持单实例。备份时同时保留映射数据和宿主支付配置。真实凭据保存在宿主配置档，不写入本仓库。

## 面板语言

配置字段和插件说明跟随宿主面板语言，随包提供简体中文、繁体中文、英文和日文词包。商店安装会使用宿主原生机制导入 `Lang/*.json`；手动安装后需调用宿主 `Kernel\Util\Lang::scanExtensionPacks()` 导入词包并重建字典，再刷新面板。语言切换不改变配置字段名、API Key 或商户资料。

## Docker 部署

当前核对的镜像中，`app/Pay` 软链到持久化目录 `/data/pay`。将插件目录复制到 `/data/pay/PolyPay`，私有映射目录使用 `/data/polypay-storage`，所有者为 PHP 用户 UID 33，目录权限 0700。

回调模块放在宿主已持久化的 `app/Plugin/PolyPayCallback` 目录（当前镜像为 `/data/plugins/PolyPayCallback`）。两个目录都随数据卷保留，无需额外控制器挂载。升级 0.1.0 时暂留旧回调控制器以处理旧订单。

## 应用商店交付

商店需要分别登记支付插件 `PolyPay`（类型 1）和通用插件 `PolyPayCallback`（类型 0）。`scripts/package.py` 生成两个模块各自的首次安装包、升级包，以及手动安装/升级整包。商店包直接从 `Config/` 开始；升级包不含配置，不覆盖已有商户设置。首次安装包中的配置由打包脚本生成，不读取本机配置。具体包名、提交流程及升级步骤见 [MARKETPLACE.md](MARKETPLACE.md)。

## 支付校验

下单前保存原始 CNY 网关金额、USD 金额和汇率快照。买家在 PolyPay 托管收银台选择支付方式。使用随包附带的官方 SDK 验证 Webhook v2 的签名、商户、环境、时间和 nonce；只将状态 2、7 视为已付款。

回调核对 USD 快照后，将原始 CNY 金额交回宿主签名上下文，继续执行宿主原生订单核对、事务、发货和充值。浏览器返回不代表付款。独立控制器继续调用宿主原生事务服务。处理成功，或本次验签通过且宿主订单已支付时，才返回 HTTP 200；其他失败明确返回非 2xx，避免宿主错误 JSON 的 HTTP 200 被误认为成功。重复通知不会重复发货。

## 取消与查单

宿主原生支付扩展接口没有取消生命周期钩子，所以**商城关单或删除订单不会自动取消 PolyPay 订单**。旧链接可能继续可支付直至远端取消或过期；迟到付款需要根据宿主规则处理。

仓库中的 CLI 工具不包含在宿主安装包内。将对应支付配置导出到私有 PHP 文件（返回数组），设置 `POLYPAY_ACG_CONFIG`，在插件源码目录运行：

```bash
php bin/payment.php reconcile AC商户订单号
php bin/payment.php cancel AC商户订单号
```

`AC...` 编号可在 PolyPay 订单详情查看，不是商城订单号。取消前后查单，已付款状态不会被覆盖为已取消。工具只核对付款状态，发货仍通过宿主已验签回调执行。

## 验证

执行 `php tests/run.php`、`php tests/controller.php`、`php tests/trade.php`、`python3 tests/package.py`、`python3 scripts/package.py`。正式使用前，先验证真实接口下单和取消，不进行付款；经明确授权的小额真实付款后，再验证商品购买、余额充值、一次发货/到账、重复和非法回调、金额不符、汇率变更、过期、取消竞争及迟到付款测试。当前不宣称真实端到端联调通过。

当前 PolyPay 后端插件类型白名单尚无本平台，因此本预览版不会调用插件激活接口。
