# Xboard / V2Board Crypto Payment Plugin: accept USDT (TRC20) with SnapEX Pay

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Xboard plugin](https://img.shields.io/badge/Xboard-payment%20plugin-4f46e5.svg)](https://github.com/cedar2025/Xboard)
[![V2Board](https://img.shields.io/badge/V2Board-app%2FPayments-0ea5e9.svg)](https://github.com/v2board/v2board)
[![PHP](https://img.shields.io/badge/PHP-7.3%2B%20%7C%208.2%2B-777bb4.svg?logo=php&logoColor=white)](#requirements)
[![USDT TRC20](https://img.shields.io/badge/USDT-TRC20%20%7C%20ERC20%20%7C%20BEP20%20%7C%20Solana%20%7C%20TON%20%2B-26a17b.svg)](https://pay.snapex.pro)

A payment plugin for the [Xboard](https://github.com/cedar2025/Xboard) and [V2Board](https://github.com/v2board/v2board) VPN / proxy panels that lets your customers pay subscriptions in crypto through [SnapEX Pay](https://snapex.pro). At checkout the customer is sent to the SnapEX Pay hosted payment page, pays in USDT on TRC20, ERC20 or BEP20 (any invoice amount), BTC, ETH, LTC or TRX (also on smaller invoices when the network fee allows) or, on invoices from $8, in USDT on Solana, TON and other networks or another coin, and the order is opened automatically after a signed webhook. You don't need to run a wallet, a node or a self-hosted gateway such as BEpusdt; the funds land in your SnapEX Pay merchant balance.

[Русская версия](README.ru.md) · [中文说明](README.zh-CN.md)

> **Connecting to SnapEX Pay?** Create a merchant account, check the pricing and see every ready-made module on **[pay.snapex.pro](https://pay.snapex.pro)**.

## Features

- Native Xboard plugin (`plugins/Snapexpay`, installable from the admin plugin manager) plus a drop-in `app/Payments/SnapexPay.php` class for V2Board and older Xboard releases.
- Hosted checkout: `pay()` returns a redirect (`type = 1`) to the SnapEX Pay page.
- Correct money units: Xboard and V2Board store totals in cents, the plugin sends `amount_usd` as a decimal string (`1999` → `"19.99"`), with an optional rate for non-USD shops.
- Signed webhooks: HMAC-SHA256 over the exact raw body, 5-minute timestamp window, constant-time comparison.
- The webhook body is never trusted on its own: the invoice is re-read from the SnapEX API and the order is paid only when the status is `paid`.
- Test invoices (`environment = test`) can never open an order while the method is set to `live`.
- Idempotent invoice creation (`Idempotency-Key`), so a repeated click on "Pay" reuses the same invoice. If the invoice has expired, a fresh one is opened for the same order.
- The payment window is capped below the panel's own order timeout, so a customer can't pay an order that the panel already cancelled.
- No extra dependencies: plain PHP and cURL.

## How it works

```
customer ── checkout ──▶ Xboard / V2Board ── POST /invoices ──▶ SnapEX Pay
         ◀──────────── redirect to pay_url ───────────────────┘
SnapEX Pay ── POST notify URL (Webhook-Signature) ──▶ plugin notify()
                         plugin ── GET /invoices/{id} ──▶ SnapEX Pay (status = paid?)
                         └── returns trade_no ──▶ panel marks the order paid and opens the subscription
```

1. `pay()` creates a SnapEX Pay invoice with `external_id` = the panel `trade_no` and returns the hosted payment URL.
2. When the invoice is paid, SnapEX Pay posts an `invoice.paid` webhook to the panel's notify URL for this payment method.
3. `notify()` checks `Webhook-Timestamp` and `Webhook-Signature`, re-reads the invoice from the API, and returns `trade_no` and `callback_no` (the SnapEX invoice id, e.g. `INV-1A2B3C4D`) to the panel, which marks the order paid.

## Requirements

- Xboard with the plugin system (cedar2025/Xboard, PHP 8.2+), **or** V2Board / an older Xboard that loads gateways from `app/Payments` (PHP 7.3+).
- PHP cURL extension.
- The panel reachable from the internet over **HTTPS** (SnapEX Pay only delivers webhooks to public HTTPS URLs).
- A [SnapEX Pay](https://pay.snapex.pro) merchant account.

## Installation

### Xboard (plugin manager)

The plugin code is `snapexpay`, so Xboard looks for it in **`plugins/Snapexpay/`** (the directory name is case-sensitive on Linux).

Option A, upload:

1. Download the archive [snapexpay-xboard-1.3.0.zip](https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip) (it must contain `config.json` and `Plugin.php`, either at the root or inside one top-level folder).
2. Xboard admin → **Plugins** → upload the ZIP → **Install** → **Enable** "SnapEX Pay".

Option B, copy the files:

```bash
cd /path/to/Xboard            # with Docker Compose this is the folder that has ./plugins mounted to /www/plugins
curl -fsSLO https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip && unzip snapexpay-xboard-1.3.0.zip
mv snapexpay-xboard-1.3.0 plugins/Snapexpay
```

Then in the admin: **Plugins** → **Install** → **Enable** "SnapEX Pay".

After the plugin is enabled:

3. **Payment configuration** → **Add payment method** → payment gateway **SnapexPay**.
4. Fill in the fields described in [Configuration](#configuration) and save.
5. Copy the notify URL that Xboard shows for this method and register it in SnapEX Pay (see [Webhook / notify URL](#webhook--notify-url)).

### V2Board (and older Xboard without plugins)

1. Copy [`app/Payments/SnapexPay.php`](app/Payments/SnapexPay.php) into your panel as **`app/Payments/SnapexPay.php`**:

   ```bash
   curl -fsSLO https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip
   unzip -p snapexpay-xboard-1.3.0.zip snapexpay-xboard-1.3.0/app/Payments/SnapexPay.php > /path/to/v2board/app/Payments/SnapexPay.php
   ```

2. If you use OPcache or Octane, restart PHP-FPM / Octane so the new class is picked up.
3. Admin → **Payment configuration** → **Add payment method** → gateway **SnapexPay**, fill in the fields, save.
4. Make sure **Site URL** (`app_url`) is set in the V2Board settings. Without it the return link to the order page is relative; the plugin then omits it and the customer is not redirected back after paying.
5. Register the notify URL in SnapEX Pay (next section).

## Configuration

Both variants have the same fields. In V2Board all fields are plain text inputs.

| Field (key) | Default | Description |
|---|---|---|
| API key (`api_key`) | — | Required. SnapEX Pay cabinet → API keys. Scopes: `invoices.read`, `invoices.write`. |
| Webhook secret (`webhook_secret`) | — | Required. The `whsec_…` secret shown when you create the webhook endpoint. Without a valid signature no order is paid. |
| Network fee paid by (`fee_on`) | `merchant` | `merchant`: the customer pays exactly the order total. `customer`: the network fee is added on top. |
| Payment window, minutes (`expires_minutes`) | Xboard `60`, V2Board `20` | How long the invoice stays payable. Capped at 120 minutes on Xboard and 30 on V2Board, because those panels cancel unpaid orders after 2 hours and 30 minutes respectively. |
| USD per 1 shop currency (`usd_rate`) | `1` | Panel totals are converted with `amount_usd = total / 100 × usd_rate`. Use `1` if prices are in USD, for example `0.14` for CNY. A comma decimal separator is accepted. |
| Environment (`environment`) | `live` | `live` or `test`. Test invoices confirm without real money; use them only while setting things up. |
| Partner ID (`partner_id`) | empty | Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself. |
| API URL (`api_url`) | `https://snapex.pro/api/v1/merchant` | Leave the default unless SnapEX tells you otherwise. |

The minimum invoice amount is set by SnapEX Pay (currently 0.50 USD). Smaller orders fail at checkout with an error message.

## Webhook / notify URL

Each payment method in the panel has its own notify URL:

```
https://your-panel.example/api/v1/guest/payment/notify/SnapexPay/{uuid}
```

`{uuid}` is the method's UUID. Xboard shows the full URL in the payment method list; if you set a **custom notify domain** on the method, that domain is used instead.

In the [SnapEX Pay cabinet](https://snapex.pro):

1. Set this URL as the account's webhook URL. It must be public HTTPS.
2. The cabinet has one webhook URL per account and sends it every event. Only **`invoice.paid`** opens an order; other events are signed and verified and answered `200 {"ok":true,"ignored":"<type>"}` with nothing changed.
3. Copy the `whsec_…` secret into the **Webhook secret** field of the payment method.

The plugin verifies:

- `Webhook-Signature: v1=<hex>`, where `<hex>` = `HMAC-SHA256(secret, "{Webhook-Timestamp}.{raw request body}")`;
- `Webhook-Timestamp` within ±300 seconds of the server clock (keep NTP running);
- the invoice status via `GET /invoices/{invoice_id}` with your API key.

A partially paid invoice (`invoice.partially_paid`, when the customer sent less than the total) does **not** open the order. The received amount is credited to your SnapEX balance; settle it with the customer manually.

## Testing

### Unit tests

```bash
php tests/run.php
```

The script needs nothing but PHP 8.1+ with cURL. It stubs the few Xboard/Laravel classes the plugin uses and starts a local mock SnapEX API with `php -S`, so the real HTTP code path runs without network access or real keys. It covers amount conversion, request fields, idempotency, re-checkout after expiry, error handling, signature checks (wrong secret, modified body, stale or future timestamp), status checks and the `pay()` / `notify()` return shapes for both the Xboard plugin and the V2Board class.

### End-to-end with SnapEX test invoices

1. Set **Environment** to `test` and create an order in the panel.
2. Pay it on the SnapEX page (test invoices confirm without real money).
3. The order should switch to paid within seconds. If it doesn't, check the webhook delivery log in the SnapEX cabinet and the panel log (`storage/logs`).
4. Switch **Environment** back to `live`.

## FAQ / troubleshooting

**SnapexPay does not appear in the gateway list (Xboard).**
Check that the folder is exactly `plugins/Snapexpay` and that the plugin is both installed and enabled in **Plugins**.

**Checkout shows "SnapEX Pay: A valid merchant API key is required." or "This source IP is not allowed."**
The API key is wrong, revoked or expired, or the key has an IP allowlist that doesn't include your panel server's outgoing IP.

**Checkout shows "Amount is below the … USD minimum".**
The order total converted with `usd_rate` is below the SnapEX Pay minimum. Check `usd_rate` for non-USD shops.

**Customers pay but the order stays unpaid.**
Look at the delivery log in the SnapEX cabinet. A rejected notification (`422 verify error` on Xboard, `500` on V2Board) usually means one of: the webhook secret doesn't match the endpoint, the server clock is off by more than 5 minutes, the URL points to another payment method's UUID, a reverse proxy rewrites the request body, or the panel can't reach the SnapEX API to re-check the invoice. A disabled payment method is rejected too.

**Can a test invoice open a real order?**
Not while the method is set to `live`: `notify()` rejects paid invoices whose environment is `test`.

**What if the customer clicks "Pay" twice or comes back later?**
The same invoice is returned while it is still open. If it has expired, a new invoice is created with `external_id` `{trade_no}-1`, `{trade_no}-2` and so on; the suffix is stripped again when the webhook arrives.

**Which coins can customers use?**
USDT on TRC20, ERC20 and BEP20 on invoices of any amount. BTC, ETH, LTC and TRX can also be offered on smaller invoices when the network fee allows. USDT on Solana, TON and other networks and other coins are offered on invoices from $8 (the current SnapEX Pay minimum; the payment page shows which methods are available for the amount). The customer picks the method on the payment page.

## Other SnapEX Pay integrations

All modules, pricing and sign-up: **[pay.snapex.pro](https://pay.snapex.pro)**.

- [Marzban](https://pay.snapex.pro/integrations/marzban)
- [3x-ui](https://pay.snapex.pro/integrations/3x-ui)
- [Remnawave](https://pay.snapex.pro/integrations/remnawave)
- [Telegram VPN bot](https://pay.snapex.pro/integrations/telegram-vpn-bot)
- [FOSSBilling](https://pay.snapex.pro/integrations/fossbilling)
- [Paymenter](https://pay.snapex.pro/integrations/paymenter)
- [n8n](https://pay.snapex.pro/integrations/n8n)

## Support

- Website, merchant cabinet and API docs: https://snapex.pro
- Bugs and feature requests: write to us in the chat on [pay.snapex.pro](https://pay.snapex.pro).

## License

[MIT](LICENSE)
