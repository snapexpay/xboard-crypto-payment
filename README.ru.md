# Плагин оплаты криптовалютой для Xboard / V2Board: приём USDT (TRC20) через SnapEX Pay

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Xboard plugin](https://img.shields.io/badge/Xboard-payment%20plugin-4f46e5.svg)](https://github.com/cedar2025/Xboard)
[![V2Board](https://img.shields.io/badge/V2Board-app%2FPayments-0ea5e9.svg)](https://github.com/v2board/v2board)
[![PHP](https://img.shields.io/badge/PHP-7.3%2B%20%7C%208.2%2B-777bb4.svg?logo=php&logoColor=white)](#требования)
[![USDT TRC20](https://img.shields.io/badge/USDT-TRC20%20%7C%20ERC20%20%7C%20BEP20%20%7C%20Solana%20%7C%20TON%20%2B-26a17b.svg)](https://pay.snapex.pro)

Платёжный плагин для VPN/прокси-панелей [Xboard](https://github.com/cedar2025/Xboard) и [V2Board](https://github.com/v2board/v2board): клиенты оплачивают подписку криптовалютой через [SnapEX Pay](https://snapex.pro). При оформлении заказа клиент попадает на платёжную страницу SnapEX Pay, платит USDT в сетях TRC20, ERC20 или BEP20 (счёт любой суммы), BTC, ETH, LTC или TRX (и на меньшие суммы, если позволяет комиссия сети), а в счетах от $8 — ещё и USDT в Solana, TON и других сетях или другой монетой, и после подписанного вебхука заказ открывается автоматически. Свой кошелёк, ноду или self-hosted шлюз вроде BEpusdt поднимать не нужно: деньги приходят на мерчант-баланс SnapEX Pay.

[English](README.md) · [中文说明](README.zh-CN.md)

> **Подключаетесь к SnapEX Pay?** Мерчант-аккаунт, тарифы и все готовые модули — на **[pay.snapex.pro](https://pay.snapex.pro)**.

## Возможности

- Плагин для Xboard (`plugins/Snapexpay`, ставится через менеджер плагинов) и класс `app/Payments/SnapexPay.php` для V2Board и старых версий Xboard.
- Хостинговая страница оплаты: `pay()` возвращает редирект (`type = 1`) на страницу SnapEX Pay.
- Правильные единицы: Xboard и V2Board хранят суммы в центах, плагин отправляет `amount_usd` десятичной строкой (`1999` → `"19.99"`), для магазинов не в USD есть курс.
- Подписанные вебхуки: HMAC-SHA256 по точному сырому телу запроса, окно по времени 5 минут, сравнение за постоянное время.
- Телу вебхука плагин не доверяет: счёт перечитывается через API SnapEX, заказ оплачивается только при статусе `paid`.
- Тестовые счета (`environment = test`) не могут открыть заказ, пока метод работает в режиме `live`.
- Идемпотентное создание счёта (`Idempotency-Key`): повторное нажатие «Оплатить» возвращает тот же счёт. Если он истёк, для того же заказа создаётся новый.
- Окно оплаты ограничено сроком жизни заказа в панели, чтобы клиент не оплатил уже отменённый заказ.
- Без зависимостей: только PHP и cURL.

## Как это работает

```
клиент ── оформление ──▶ Xboard / V2Board ── POST /invoices ──▶ SnapEX Pay
       ◀──────────── редирект на pay_url ─────────────────────┘
SnapEX Pay ── POST notify URL (Webhook-Signature) ──▶ notify() плагина
                         плагин ── GET /invoices/{id} ──▶ SnapEX Pay (status = paid?)
                         └── возвращает trade_no ──▶ панель отмечает заказ оплаченным
```

1. `pay()` создаёт счёт SnapEX Pay с `external_id` = `trade_no` заказа и возвращает ссылку на оплату.
2. После оплаты SnapEX Pay отправляет вебхук `invoice.paid` на notify URL этого способа оплаты.
3. `notify()` проверяет `Webhook-Timestamp` и `Webhook-Signature`, перечитывает счёт через API и возвращает панели `trade_no` и `callback_no` (id счёта SnapEX, например `INV-1A2B3C4D`).

## Требования

- Xboard с системой плагинов (cedar2025/Xboard, PHP 8.2+) **или** V2Board / старый Xboard, который берёт шлюзы из `app/Payments` (PHP 7.3+).
- Расширение PHP cURL.
- Панель доступна из интернета по **HTTPS** (SnapEX Pay шлёт вебхуки только на публичные HTTPS-адреса).
- Мерчант-аккаунт [SnapEX Pay](https://pay.snapex.pro).

## Установка

### Xboard (менеджер плагинов)

Код плагина `snapexpay`, поэтому Xboard ищет его в **`plugins/Snapexpay/`** (на Linux регистр букв важен).

Вариант А, загрузка архива:

1. Скачайте архив [snapexpay-xboard-1.3.0.zip](https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip) (в нём должны быть `config.json` и `Plugin.php`, в корне или в одной папке верхнего уровня).
2. Админка Xboard → **Плагины** → загрузить ZIP → **Установить** → **Включить** «SnapEX Pay».

Вариант Б, копирование файлов:

```bash
cd /path/to/Xboard            # в Docker Compose это папка, где ./plugins смонтирована в /www/plugins
curl -fsSLO https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip && unzip snapexpay-xboard-1.3.0.zip
mv snapexpay-xboard-1.3.0 plugins/Snapexpay
```

Затем в админке: **Плагины** → **Установить** → **Включить** «SnapEX Pay».

После включения плагина:

3. **Настройки оплаты** → **Добавить способ оплаты** → шлюз **SnapexPay**.
4. Заполните поля из раздела [Настройки](#настройки) и сохраните.
5. Скопируйте notify URL этого способа и укажите его в SnapEX Pay (см. [Вебхук / notify URL](#вебхук--notify-url)).

### V2Board (и старый Xboard без плагинов)

1. Скопируйте [`app/Payments/SnapexPay.php`](app/Payments/SnapexPay.php) в панель как **`app/Payments/SnapexPay.php`**:

   ```bash
   curl -fsSLO https://pay.snapex.pro/downloads/xboard/snapexpay-xboard-1.3.0.zip
   unzip -p snapexpay-xboard-1.3.0.zip snapexpay-xboard-1.3.0/app/Payments/SnapexPay.php > /path/to/v2board/app/Payments/SnapexPay.php
   ```

2. Если включены OPcache или Octane, перезапустите PHP-FPM / Octane.
3. Админка → **Настройки оплаты** → **Добавить способ оплаты** → шлюз **SnapexPay**, заполните поля, сохраните.
4. Проверьте, что в настройках V2Board задан **адрес сайта** (`app_url`). Без него ссылка возврата на страницу заказа получается относительной; плагин её не передаёт, и клиент после оплаты не вернётся на сайт автоматически.
5. Укажите notify URL в SnapEX Pay (следующий раздел).

## Настройки

Поля одинаковые в обоих вариантах. В V2Board все поля текстовые.

| Поле (ключ) | По умолчанию | Описание |
|---|---|---|
| API key (`api_key`) | — | Обязательно. Кабинет SnapEX Pay → API-ключи. Скоупы: `invoices.read`, `invoices.write`. |
| Webhook secret (`webhook_secret`) | — | Обязательно. Секрет `whsec_…`, который показывается при создании вебхука. Без верной подписи заказ не оплачивается. |
| Network fee paid by (`fee_on`) | `merchant` | `merchant`: клиент платит ровно сумму заказа. `customer`: сетевая комиссия добавляется сверху. |
| Payment window, minutes (`expires_minutes`) | Xboard `60`, V2Board `20` | Сколько минут счёт можно оплатить. Максимум 120 минут в Xboard и 30 в V2Board: эти панели отменяют неоплаченные заказы через 2 часа и 30 минут соответственно. |
| USD per 1 shop currency (`usd_rate`) | `1` | Сумма пересчитывается как `amount_usd = total / 100 × usd_rate`. `1`, если цены в USD; например `0.14` для CNY. Запятая как разделитель тоже подходит. |
| Environment (`environment`) | `live` | `live` или `test`. Тестовые счета подтверждаются без реальных денег, используйте их только при настройке. |
| Partner ID (`partner_id`) | пусто | Необязательно. Partner ID интегратора, который настроил модуль (реферальная программа SnapEX Pay). Оставьте пустым, если ставите сами. |
| API URL (`api_url`) | `https://snapex.pro/api/v1/merchant` | Не меняйте без указания SnapEX. |

Минимальную сумму счёта задаёт SnapEX Pay (сейчас 0,50 USD). Заказ на меньшую сумму завершится ошибкой при оформлении.

## Вебхук / notify URL

У каждого способа оплаты в панели свой notify URL:

```
https://your-panel.example/api/v1/guest/payment/notify/SnapexPay/{uuid}
```

`{uuid}` — UUID способа оплаты. Xboard показывает полный адрес в списке способов оплаты; если у способа задан **свой домен уведомлений**, используется он.

В [кабинете SnapEX Pay](https://snapex.pro):

1. Создайте вебхук с этим адресом. Он должен быть публичным и с HTTPS.
2. Кабинет шлёт на этот адрес все события аккаунта. Заказ открывает только **`invoice.paid`**; остальные события проходят проверку подписи, и панель отвечает на них `200 {"ok":true,"ignored":"<тип>"}`, ничего не меняя.
3. Скопируйте секрет `whsec_…` в поле **Webhook secret** способа оплаты.

Плагин проверяет:

- `Webhook-Signature: v1=<hex>`, где `<hex>` = `HMAC-SHA256(secret, "{Webhook-Timestamp}.{сырое тело запроса}")`;
- `Webhook-Timestamp` в пределах ±300 секунд от часов сервера (держите NTP включённым);
- статус счёта через `GET /invoices/{invoice_id}` вашим API-ключом.

Частично оплаченный счёт (`invoice.partially_paid`, клиент прислал меньше суммы) заказ **не** открывает. Полученная сумма зачисляется на баланс SnapEX, с клиентом нужно разобраться вручную.

## Тестирование

### Юнит-тесты

```bash
php tests/run.php
```

Нужен только PHP 8.1+ с cURL. Скрипт подменяет несколько классов Xboard/Laravel, которые использует плагин, и поднимает локальный mock API SnapEX через `php -S`, так что реальный HTTP-код работает без сети и без настоящих ключей. Проверяются пересчёт сумм, поля запроса, идемпотентность, повторная оплата после истечения счёта, обработка ошибок, подпись (чужой секрет, изменённое тело, старый или будущий timestamp), проверка статуса и формат ответов `pay()` / `notify()` для плагина Xboard и класса V2Board.

### Сквозная проверка на тестовых счетах

1. Поставьте **Environment** = `test` и создайте заказ в панели.
2. Оплатите его на странице SnapEX (тестовый счёт подтверждается без реальных денег).
3. Заказ должен стать оплаченным за несколько секунд. Если нет, смотрите журнал доставки вебхуков в кабинете SnapEX и лог панели (`storage/logs`).
4. Верните **Environment** = `live`.

## FAQ / решение проблем

**SnapexPay нет в списке шлюзов (Xboard).**
Проверьте, что папка называется ровно `plugins/Snapexpay`, а плагин установлен и включён в разделе **Плагины**.

**При оформлении «SnapEX Pay: A valid merchant API key is required.» или «This source IP is not allowed.»**
Ключ неверный, отозван или истёк, либо у ключа есть список разрешённых IP без исходящего IP сервера панели.

**При оформлении «Amount is below the … USD minimum».**
Сумма заказа после пересчёта по `usd_rate` меньше минимума SnapEX Pay. Для магазинов не в USD проверьте `usd_rate`.

**Клиент оплатил, а заказ не оплачен.**
Откройте журнал доставки в кабинете SnapEX. Отклонённое уведомление (`422 verify error` в Xboard, `500` в V2Board) обычно значит одно из: секрет не совпадает с вебхуком, часы сервера расходятся больше чем на 5 минут, адрес указывает на UUID другого способа оплаты, обратный прокси меняет тело запроса или панель не может достучаться до API SnapEX для перепроверки счёта. Выключенный способ оплаты тоже отклоняет уведомления.

**Может ли тестовый счёт открыть настоящий заказ?**
Нет, пока способ оплаты в режиме `live`: `notify()` отклоняет оплаченные счета с окружением `test`.

**Что если клиент нажмёт «Оплатить» дважды или вернётся позже?**
Пока счёт открыт, возвращается он же. Если он истёк, создаётся новый с `external_id` `{trade_no}-1`, `{trade_no}-2` и т. д.; при получении вебхука суффикс отбрасывается.

**Какими монетами можно платить?**
USDT в сетях TRC20, ERC20 и BEP20 — на счёт любой суммы. BTC, ETH, LTC и TRX — и на меньшие суммы, если позволяет комиссия сети. USDT в Solana, TON и других сетях и другие монеты — в счетах от $8 (текущий минимум SnapEX Pay; какие способы доступны для суммы, видно на странице оплаты). Способ клиент выбирает на странице оплаты.

## Другие интеграции SnapEX Pay

Все модули, тарифы и регистрация: **[pay.snapex.pro](https://pay.snapex.pro)**.

- [Marzban](https://pay.snapex.pro/integrations/marzban)
- [3x-ui](https://pay.snapex.pro/integrations/3x-ui)
- [Remnawave](https://pay.snapex.pro/integrations/remnawave)
- [Telegram VPN bot](https://pay.snapex.pro/integrations/telegram-vpn-bot)
- [FOSSBilling](https://pay.snapex.pro/integrations/fossbilling)
- [Paymenter](https://pay.snapex.pro/integrations/paymenter)
- [n8n](https://pay.snapex.pro/integrations/n8n)

## Поддержка

- Сайт, кабинет мерчанта и документация API: https://snapex.pro
- Ошибки и предложения — пишите в чат на [pay.snapex.pro](https://pay.snapex.pro).

## Лицензия

[MIT](LICENSE)
