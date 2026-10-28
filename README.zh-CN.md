# Xboard / V2Board 加密货币支付插件：通过 SnapEX Pay 收取 USDT（TRC20）

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Xboard plugin](https://img.shields.io/badge/Xboard-payment%20plugin-4f46e5.svg)](https://github.com/cedar2025/Xboard)
[![V2Board](https://img.shields.io/badge/V2Board-app%2FPayments-0ea5e9.svg)](https://github.com/v2board/v2board)
[![USDT TRC20](https://img.shields.io/badge/USDT-TRC20%20%7C%20ERC20%20%7C%20BEP20%20%7C%20Solana%20%7C%20TON%20%2B-26a17b.svg)](https://pay.snapex.pro)

适用于 [Xboard](https://github.com/cedar2025/Xboard) 和 [V2Board](https://github.com/v2board/v2board) 面板的支付插件，让用户通过 [SnapEX Pay](https://snapex.pro) 使用加密货币购买订阅。下单时用户跳转到 SnapEX Pay 收银台，使用 TRC20 / ERC20 / BEP20 网络的 USDT 付款（任意金额）；网络手续费允许时，更小金额的账单也可使用 BTC、ETH、LTC 或 TRX；$8 起的账单还可使用 Solana / TON 等网络的 USDT 或其他币种（当前 SnapEX Pay 最低金额，支付页会显示该金额可用的付款方式），签名 Webhook 到达后订单自动开通。无需自建钱包、节点或 BEpusdt 等自托管网关，资金进入 SnapEX Pay 商户余额。

[English](README.md) · [Русский](README.ru.md)

> **准备接入 SnapEX Pay？** 在 **[pay.snapex.pro](https://pay.snapex.pro)** 注册商户账户、查看费率和全部现成模块。

## 功能

- Xboard 插件（`plugins/Snapexpay`，可在后台插件管理中安装）以及供 V2Board / 旧版 Xboard 使用的 `app/Payments/SnapexPay.php`。
- 托管收银台：`pay()` 返回跳转（`type = 1`）到 SnapEX Pay 页面。
- 金额单位正确：面板以「分」存储金额，插件以字符串发送 `amount_usd`（`1999` → `"19.99"`），非 USD 店铺可设置汇率。
- 签名校验：对原始请求体做 HMAC-SHA256，时间窗口 5 分钟，常数时间比较。
- 不信任 Webhook 内容：通过 API 重新查询账单，只有 `status = paid` 才标记订单已支付。
- 方法处于 `live` 时，测试账单（`environment = test`）不能开通订单。
- 幂等创建账单（`Idempotency-Key`）；账单过期后再次支付会为同一订单创建新账单。
- 付款时限不超过面板自身的订单超时，避免用户为已取消的订单付款。

## 安装

### Xboard（插件）

插件代码为 `snapexpay`，Xboard 从 **`plugins/Snapexpay/`** 加载（Linux 下区分大小写）。

- 上传：下载 ZIP 包 https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip（包内需含 `config.json` 和 `Plugin.php`），后台 → **插件** → 上传 → 安装 → 启用「SnapEX Pay」。
- 或复制：`curl -fsSLO https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip && unzip snapexpay-xboard-1.3.0.zip && mv snapexpay-xboard-1.3.0 plugins/Snapexpay`，然后在后台安装并启用。

之后：**支付配置** → **添加支付方式** → 网关 **SnapexPay**，填写下表字段并保存。

### V2Board（及无插件系统的旧版 Xboard）

将 [`app/Payments/SnapexPay.php`](app/Payments/SnapexPay.php) 复制为面板的 **`app/Payments/SnapexPay.php`**（使用 OPcache / Octane 时需重启），然后添加支付方式 **SnapexPay**。请在站点设置中填写站点地址（`app_url`），否则支付完成后无法自动返回订单页。

## 配置

| 字段（键） | 默认值 | 说明 |
|---|---|---|
| API key（`api_key`） | — | 必填。SnapEX Pay 控制台 → API Keys，权限 `invoices.read`、`invoices.write`。 |
| Webhook secret（`webhook_secret`） | — | 必填。创建 Webhook 时显示的 `whsec_…`。 |
| Network fee paid by（`fee_on`） | `merchant` | `merchant`：用户支付订单金额；`customer`：网络费用另加。 |
| Payment window, minutes（`expires_minutes`） | Xboard `60`，V2Board `20` | 账单有效分钟数。Xboard 最多 120，V2Board 最多 30（两者分别在 2 小时和 30 分钟后取消未支付订单）。 |
| USD per 1 shop currency（`usd_rate`） | `1` | `amount_usd = total / 100 × usd_rate`。价格为 USD 时填 `1`，CNY 例如 `0.14`。 |
| Environment（`environment`） | `live` | `live` 或 `test`（测试账单无需真实付款）。 |
| Partner ID（`partner_id`） | 空 | 可选。为您配置模块的集成商的 Partner ID（SnapEX Pay 推荐计划）。自行配置请留空。 |
| API URL（`api_url`） | `https://snapex.pro/api/v1/merchant` | 一般无需修改。 |

## Webhook / 回调地址

```
https://your-panel.example/api/v1/guest/payment/notify/SnapexPay/{uuid}
```

在 SnapEX Pay 控制台创建指向该地址的 Webhook（必须是公网 HTTPS），订阅 **`invoice.paid`**，并把 `whsec_…` 填入 **Webhook secret**。签名格式：`Webhook-Signature: v1=HMAC-SHA256(secret, "{Webhook-Timestamp}.{原始请求体}")`，时间误差需在 ±300 秒内。部分支付（`invoice.partially_paid`）不会开通订单。控制台会把账户的所有事件都发到该地址：只有 `invoice.paid` 会开通订单，其余已验签的事件返回 `200 {"ok":true,"ignored":"<type>"}`，不做任何改动。

## 测试

```bash
php tests/run.php
```

只需 PHP 8.1+ 和 cURL：脚本模拟 Xboard/Laravel 类，并用 `php -S` 启动本地 SnapEX API 模拟服务，不访问网络，也不需要真实密钥。

## 其他 SnapEX Pay 集成

全部模块、费率与注册：**[pay.snapex.pro](https://pay.snapex.pro)**。

- [Marzban](https://pay.snapex.pro/integrations/marzban)
- [3x-ui](https://pay.snapex.pro/integrations/3x-ui)
- [Remnawave](https://pay.snapex.pro/integrations/remnawave)
- [Telegram VPN bot](https://pay.snapex.pro/integrations/telegram-vpn-bot)
- [FOSSBilling](https://pay.snapex.pro/integrations/fossbilling)
- [Paymenter](https://pay.snapex.pro/integrations/paymenter)
- [n8n](https://pay.snapex.pro/integrations/n8n)

## 支持

- 官网、商户控制台与 API 文档：https://snapex.pro
- 问题反馈：https://pay.snapex.pro

## 许可证

[MIT](LICENSE)
