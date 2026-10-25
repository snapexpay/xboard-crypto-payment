<?php

/*
 * Self-contained test: php tests/run.php
 *
 * Stubs the few Xboard/Laravel classes the plugin touches and starts this same file as a
 * mock SnapEX API (php -S) so the real cURL code path is exercised. No network, no real keys.
 */

namespace {
    // ---- Mock SnapEX API (this file is the php -S router) ----
    if (PHP_SAPI === 'cli-server') {
        $state = getenv('SNAPEX_MOCK_STATE');
        $db = is_file($state) ? json_decode((string) file_get_contents($state), true) : ['invoices' => [], 'keys' => [], 'log' => []];
        $reply = function (int $status, array $payload) use (&$db, $state) {
            file_put_contents($state, json_encode($db));
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode($payload);

            return true;
        };
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $db['log'][] = ['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'body' => file_get_contents('php://input'),
            'idempotency' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null];
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk_test_dummy') {
            return $reply(401, ['error' => ['code' => 'unauthorized', 'message' => 'A valid merchant API key is required.']]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/v1/merchant/invoices') {
            $data = json_decode((string) file_get_contents('php://input'), true);
            $key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            if ($key === '') {
                return $reply(422, ['error' => ['code' => 'validation_error', 'message' => 'A valid Idempotency-Key header is required.']]);
            }
            if (! preg_match('/^[0-9]+(?:\.[0-9]{1,8})?$/', (string) ($data['amount_usd'] ?? '')) || (float) $data['amount_usd'] < 0.5) {
                return $reply(409, ['error' => ['code' => 'conflict', 'message' => 'Amount is below the 0.50 USD minimum.']]);
            }
            foreach (['success_url', 'cancel_url'] as $f) {
                if (isset($data[$f]) && ! filter_var($data[$f], FILTER_VALIDATE_URL)) {
                    return $reply(422, ['error' => ['code' => 'validation_error', 'message' => 'The request is invalid.', 'details' => [$f => ['The '.$f.' field must be a valid URL.']]]]);
                }
            }
            if (isset($db['keys'][$key])) {
                $existing = $db['invoices'][$db['keys'][$key]];
                if ($existing['external_id'] !== ($data['external_id'] ?? null) || $existing['fee_on'] !== $data['fee_on']) {
                    return $reply(409, ['error' => ['code' => 'conflict', 'message' => 'Idempotency-Key was already used with a different request.']]);
                }

                return $reply(200, ['data' => $existing]);
            }
            foreach ($db['invoices'] as $inv) {
                if (($data['external_id'] ?? null) !== null && $inv['external_id'] === $data['external_id']) {
                    return $reply(409, ['error' => ['code' => 'conflict', 'message' => 'external_id has already been used by this merchant.']]);
                }
            }
            $id = 'INV-'.strtoupper(bin2hex(random_bytes(4)));
            $db['invoices'][$id] = [
                'invoice_id' => $id, 'environment' => $data['environment'] ?? 'live', 'amount_usd' => $data['amount_usd'],
                'fee_on' => $data['fee_on'] ?? 'merchant', 'status' => 'open', 'pay_url' => 'https://pay.example.test/pay/'.$id,
                'external_id' => $data['external_id'] ?? null, 'expires_in' => $data['expires_in'] ?? null,
                'success_url' => $data['success_url'] ?? null,
            ];
            $db['keys'][$key] = $id;

            return $reply(201, ['data' => $db['invoices'][$id]]);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/api/v1/merchant/invoices/([^/]+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);

            return isset($db['invoices'][$id])
                ? $reply(200, ['data' => $db['invoices'][$id]])
                : $reply(404, ['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']]);
        }

        return $reply(404, ['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']]);
    }
}

namespace App\Contracts {
    interface PaymentInterface
    {
        public function form(): array;

        public function pay($order): array;

        public function notify($params);
    }
}

namespace App\Exceptions {
    class ApiException extends \Exception
    {
        public function __construct($message = null, $code = 400)
        {
            parent::__construct((string) $message, (int) $code);
        }
    }
}

namespace App\Services\Plugin {
    abstract class AbstractPlugin
    {
        protected array $config = [];

        public static array $filters = [];

        public function __construct(protected string $pluginCode) {}

        public function getPluginCode(): string
        {
            return $this->pluginCode;
        }

        public function setConfig(array $config): void
        {
            $this->config = $config;
        }

        public function getConfig(?string $key = null, $default = null): mixed
        {
            return $key ? ($this->config[$key] ?? $default) : $this->config;
        }

        protected function filter(string $hook, callable $callback, int $priority = 20): void
        {
            self::$filters[$hook][] = $callback;
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private string $content = '', private array $headers = []) {}

        public function getContent(): string
        {
            return $this->content;
        }

        public function header(string $key, $default = null)
        {
            foreach ($this->headers as $name => $value) {
                if (strcasecmp($name, $key) === 0) {
                    return $value;
                }
            }

            return $default;
        }
    }
}

namespace Symfony\Component\HttpKernel\Exception {
    class HttpException extends \RuntimeException
    {
        public function __construct(public int $status, string $message)
        {
            parent::__construct($message);
        }
    }
}

namespace {
    use App\Exceptions\ApiException;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpKernel\Exception\HttpException;

    $GLOBALS['request'] = null;
    function request()
    {
        return $GLOBALS['request'];
    }

    function abort($code, $message = '')
    {
        throw new HttpException((int) $code, (string) $message);
    }

    $root = dirname(__DIR__);
    require $root.'/Plugin.php';
    require $root.'/app/Payments/SnapexPay.php';

    $failures = 0;
    function check($cond, string $label): void
    {
        global $failures;
        echo ($cond ? '  ok   ' : '  FAIL ').$label."\n";
        if (! $cond) {
            $failures++;
        }
    }

    function signed(string $secret, array $payload, ?int $ts = null, ?string $sig = null): Request
    {
        $ts = (string) ($ts ?? time());
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Request($body, [
            'Content-Type' => 'application/json',
            'Webhook-Id' => 'evt-1',
            'Webhook-Timestamp' => $ts,
            'Webhook-Signature' => $sig ?? 'v1='.hash_hmac('sha256', $ts.'.'.$body, $secret),
        ]);
    }

    function throws(callable $fn): ?Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    // ---- start the mock API ----
    $state = tempnam(sys_get_temp_dir(), 'snapex-xboard-mock-');
    file_put_contents($state, json_encode(['invoices' => [], 'keys' => [], 'log' => []]));
    $port = 0;
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, __FILE__], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['SNAPEX_MOCK_STATE' => $state]);
    register_shutdown_function(function () use ($proc, $state) {
        proc_terminate($proc);
        @unlink($state);
    });
    for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    $db = fn () => json_decode((string) file_get_contents($state), true);
    $mutate = function (callable $fn) use ($state, $db) {
        $d = $db();
        $fn($d);
        file_put_contents($state, json_encode($d));
    };

    $cfg = [
        'api_key' => 'sk_test_dummy',
        'webhook_secret' => 'whsec_dummy',
        'fee_on' => 'merchant',
        'expires_minutes' => '60',
        'usd_rate' => '1',
        'environment' => 'test',
        'api_url' => 'http://127.0.0.1:'.$port.'/api/v1/merchant/',
    ];
    $order = [
        'notify_url' => 'https://panel.example/api/v1/guest/payment/notify/SnapexPay/uuid',
        'return_url' => 'https://panel.example/#/order/2026100912345612345678901',
        'trade_no' => '2026100912345612345678901',
        'total_amount' => 1999,
        'user_id' => 1,
        'stripe_token' => null,
    ];

    echo "Xboard plugin (plugins/Snapexpay)\n";
    $plugin = new Plugin\Snapexpay\Plugin('snapexpay');
    $plugin->setConfig($cfg);
    check($plugin instanceof App\Contracts\PaymentInterface, 'implements PaymentInterface');
    check(class_exists('Plugin\\'.str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', json_decode(file_get_contents($root.'/config.json'), true)['code']))).'\\Plugin'), 'namespace matches Str::studly(config.json code)');
    $plugin->boot();
    $methods = array_reduce(App\Services\Plugin\AbstractPlugin::$filters['available_payment_methods'], fn ($m, $f) => $f($m), []);
    check(($methods['SnapexPay']['plugin_code'] ?? '') === 'snapexpay' && ($methods['SnapexPay']['type'] ?? '') === 'plugin', 'registers SnapexPay payment method');
    $form = $plugin->form();
    check(array_keys($form) === ['api_key', 'webhook_secret', 'fee_on', 'expires_minutes', 'usd_rate', 'environment', 'partner_id', 'api_url'], 'form fields');
    check(! array_filter($form, fn ($f) => ! isset($f['type'], $f['label'])), 'every form field has type and label');

    $amount = (new ReflectionMethod($plugin, 'amountUsd'));
    check($amount->invoke($plugin, ['total_amount' => 1999]) === '19.99', 'cents → USD: 1999 → 19.99');
    check($amount->invoke($plugin, ['total_amount' => 1]) === '0.01', 'cents → USD: 1 → 0.01');
    check($amount->invoke($plugin, ['total_amount' => 0]) === null, 'zero total rejected');
    $cny = new Plugin\Snapexpay\Plugin('snapexpay');
    $cny->setConfig(['usd_rate' => '0,14'] + $cfg);
    check($amount->invoke($cny, ['total_amount' => 10000]) === '14.00', 'CNY 100.00 × 0,14 → 14.00');
    $cny->setConfig(['usd_rate' => 'abc'] + $cfg);
    check($amount->invoke($cny, ['total_amount' => 10000]) === null, 'invalid rate rejected');

    $res = $plugin->pay($order);
    $d = $db();
    $first = array_values($d['invoices'])[0];
    $post = $d['log'][0];
    $sent = json_decode($post['body'], true);
    check(($res['type'] ?? null) === 1 && $res['data'] === $first['pay_url'], 'pay() returns [type => 1, data => pay_url]');
    check($post['path'] === '/api/v1/merchant/invoices' && $post['auth'] === 'Bearer sk_test_dummy', 'POST /invoices with Bearer key (trailing slash in api_url ok)');
    check($sent['amount_usd'] === '19.99' && is_string($sent['amount_usd']), 'amount_usd sent as decimal string');
    check($sent['external_id'] === $order['trade_no'] && $sent['environment'] === 'test' && $sent['fee_on'] === 'merchant', 'external_id/environment/fee_on');
    check($sent['expires_in'] === 3600 && $sent['success_url'] === $order['return_url'], 'expires_in seconds and success_url');
    check($post['idempotency'] === 'xboard-'.$order['trade_no'].'-19.99-test', 'Idempotency-Key');
    $again = $plugin->pay($order);
    check($again['data'] === $res['data'] && count($db()['invoices']) === 1, 'second checkout reuses the same invoice');

    $mutate(function (&$d) use ($first) {
        $d['invoices'][$first['invoice_id']]['status'] = 'expired';
    });
    $fresh = $plugin->pay($order);
    $d = $db();
    $second = end($d['invoices']);
    check($fresh['data'] !== $res['data'] && $second['external_id'] === $order['trade_no'].'-1', 'expired invoice → new invoice with external_id {trade_no}-1');

    $withFee = $order;
    $withFee['total_amount'] = 2099;
    $plugin->pay($withFee);
    $d = $db();
    check(end($d['invoices'])['external_id'] === $order['trade_no'].'-2', 'changed total (handling fee) → next external_id instead of 409');

    $long = new Plugin\Snapexpay\Plugin('snapexpay');
    $long->setConfig(['expires_minutes' => '1440'] + $cfg);
    $long->pay(['trade_no' => '111', 'total_amount' => 500] + $order);
    $d = $db();
    check(end($d['invoices'])['expires_in'] === 7200, 'payment window capped at 120 minutes');

    $rel = $order;
    $rel['trade_no'] = '222';
    $rel['return_url'] = '/#/order/222';
    $plugin->pay($rel);
    $d = $db();
    check(end($d['invoices'])['success_url'] === null, 'relative return_url is not sent');

    $e = throws(fn () => $plugin->pay(['trade_no' => '333', 'total_amount' => 10] + $order));
    check($e instanceof ApiException && str_contains($e->getMessage(), 'minimum') && $e->getCode() === 400, 'API error surfaces as ApiException(400) with message');
    $bad = new Plugin\Snapexpay\Plugin('snapexpay');
    $bad->setConfig(['api_key' => 'sk_wrong'] + $cfg);
    $e = throws(fn () => $bad->pay(['trade_no' => '444'] + $order));
    check($e instanceof ApiException && $e->getCode() === 400, 'SnapEX 401 is not passed through as an Xboard 401');
    $down = new Plugin\Snapexpay\Plugin('snapexpay');
    $down->setConfig(['api_url' => 'http://127.0.0.1:1/api/v1/merchant'] + $cfg);
    check(throws(fn () => $down->pay(['trade_no' => '555'] + $order)) instanceof ApiException, 'unreachable API → ApiException');
    $nokey = new Plugin\Snapexpay\Plugin('snapexpay');
    $nokey->setConfig(['api_key' => ''] + $cfg);
    check(throws(fn () => $nokey->pay($order)) instanceof ApiException, 'missing API key → ApiException');

    // ---- notify ----
    $paidId = $second['invoice_id'];
    $mutate(function (&$d) use ($paidId) {
        $d['invoices'][$paidId]['status'] = 'paid';
    });
    $event = ['id' => 'evt-1', 'type' => 'invoice.paid', 'created_at' => date('c'), 'data' => ['invoice_id' => $paidId, 'status' => 'paid', 'external_id' => $order['trade_no'].'-1']];

    $GLOBALS['request'] = signed('whsec_dummy', $event);
    $ok = $plugin->notify(json_decode($GLOBALS['request']->getContent(), true));
    check($ok === ['trade_no' => $order['trade_no'], 'callback_no' => $paidId, 'custom_result' => 'success'], 'notify() returns trade_no (suffix stripped), callback_no, custom_result');

    $GLOBALS['request'] = signed('whsec_other', $event);
    check($plugin->notify([]) === false, 'wrong secret rejected');
    $GLOBALS['request'] = signed('whsec_dummy', $event, time() - 301);
    check($plugin->notify([]) === false, 'stale timestamp rejected (replay window 300 s)');
    $GLOBALS['request'] = signed('whsec_dummy', $event, time() + 301);
    check($plugin->notify([]) === false, 'future timestamp rejected');
    $GLOBALS['request'] = signed('whsec_dummy', $event, null, 'garbage');
    check($plugin->notify([]) === false, 'malformed signature rejected');
    $r = signed('whsec_dummy', $event);
    $GLOBALS['request'] = new Request($r->getContent().' ', ['Webhook-Timestamp' => $r->header('Webhook-Timestamp'), 'Webhook-Signature' => $r->header('Webhook-Signature')]);
    check($plugin->notify([]) === false, 'modified body rejected (HMAC over exact raw bytes)');
    $GLOBALS['request'] = new Request($r->getContent(), []);
    check($plugin->notify($event) === false, 'unsigned request with $params only rejected');

    $openId = array_values($db()['invoices'])[2]['invoice_id'];
    $GLOBALS['request'] = signed('whsec_dummy', ['type' => 'invoice.paid', 'data' => ['invoice_id' => $openId, 'status' => 'paid']]);
    check($plugin->notify([]) === false, 'webhook says paid but API says open → rejected');
    $GLOBALS['request'] = signed('whsec_dummy', ['type' => 'invoice.partially_paid', 'data' => ['invoice_id' => $paidId, 'status' => 'partially_paid']]);
    check($plugin->notify([]) === false, 'invoice.partially_paid does not pay the order');
    $GLOBALS['request'] = signed('whsec_dummy', ['type' => 'invoice.created', 'data' => ['invoice_id' => $paidId, 'status' => 'open']]);
    check($plugin->notify([]) === false, 'invoice.created ignored');
    $GLOBALS['request'] = signed('whsec_dummy', ['type' => 'invoice.paid', 'data' => ['invoice_id' => 'INV-NOPE']]);
    check($plugin->notify([]) === false, 'unknown invoice rejected');
    $GLOBALS['request'] = signed('whsec_dummy', ['type' => 'payment.credited', 'data' => ['invoice_id' => $paidId, 'status' => 'paid']]);
    check($plugin->notify([]) === false, 'non-invoice event ignored');

    $live = new Plugin\Snapexpay\Plugin('snapexpay');
    $live->setConfig(['environment' => 'live'] + $cfg);
    $GLOBALS['request'] = signed('whsec_dummy', $event);
    check($live->notify([]) === false, 'paid TEST invoice cannot pay an order when the method is live');
    $nosecret = new Plugin\Snapexpay\Plugin('snapexpay');
    $nosecret->setConfig(['webhook_secret' => ''] + $cfg);
    $GLOBALS['request'] = signed('', $event);
    check($nosecret->notify([]) === false, 'empty webhook secret never verifies');

    // ---- V2Board legacy class ----
    echo "V2Board app/Payments/SnapexPay.php\n";
    $legacyCfg = ['expires_minutes' => ''] + $cfg;
    $v2 = new App\Payments\SnapexPay($legacyCfg);
    $form = $v2->form();
    check(array_keys($form) === array_keys($plugin->form()) && ! array_filter($form, fn ($f) => ($f['type'] ?? '') !== 'input'), 'form fields match the plugin, all type=input');
    $v2order = ['trade_no' => '2026100900000000000000001', 'return_url' => 'https://v2.example/#/order/2026100900000000000000001', 'total_amount' => 1000] + $order;
    $res = $v2->pay($v2order);
    $d = $db();
    $inv = end($d['invoices']);
    $log = end($d['log']);
    check(($res['type'] ?? null) === 1 && $res['data'] === $inv['pay_url'], 'pay() returns [type => 1, data => pay_url]');
    check($inv['amount_usd'] === '10.00' && $inv['expires_in'] === 1200 && $log['idempotency'] === 'v2board-'.$v2order['trade_no'].'-10.00-test', 'amount, default 20 min window, Idempotency-Key');
    check(! array_key_exists('partner_ref', json_decode($log['body'], true)), 'no Partner ID → no partner_ref');
    (new App\Payments\SnapexPay(['partner_id' => ' int-42 '] + $cfg))->pay(['trade_no' => '779'] + $v2order);
    $log = end($db()['log']);
    check((json_decode($log['body'], true)['partner_ref'] ?? null) === 'int-42', 'Partner ID → partner_ref');
    (new App\Payments\SnapexPay(['expires_minutes' => '600'] + $cfg))->pay(['trade_no' => '777'] + $v2order);
    $d = $db();
    check(end($d['invoices'])['expires_in'] === 1800, 'window capped at 30 minutes');
    (new App\Payments\SnapexPay($cfg))->pay(['trade_no' => '778', 'return_url' => '/#/order/778'] + $v2order);
    $d = $db();
    check(end($d['invoices'])['success_url'] === null, 'relative return_url (no app_url) is not sent');
    $mutate(function (&$d) use ($inv) {
        $d['invoices'][$inv['invoice_id']]['status'] = 'expired';
    });
    $res2 = $v2->pay($v2order);
    $d = $db();
    check($res2['data'] !== $res['data'] && end($d['invoices'])['external_id'] === $v2order['trade_no'].'-1', 'expired invoice → new invoice');
    $e = throws(fn () => $v2->pay(['trade_no' => '779', 'total_amount' => 10] + $v2order));
    check($e instanceof HttpException && str_contains($e->getMessage(), 'minimum'), 'API error → abort(500, message)');

    $paid2 = end($d['invoices'])['invoice_id'];
    $mutate(function (&$d) use ($paid2) {
        $d['invoices'][$paid2]['status'] = 'paid';
    });
    $ev2 = ['type' => 'invoice.paid', 'data' => ['invoice_id' => $paid2, 'status' => 'paid']];
    $GLOBALS['request'] = signed('whsec_dummy', $ev2);
    check($v2->notify([]) === ['trade_no' => $v2order['trade_no'], 'callback_no' => $paid2], 'notify() returns trade_no and callback_no');
    $GLOBALS['request'] = signed('whsec_bad', $ev2);
    check($v2->notify([]) === false, 'bad signature rejected');
    $GLOBALS['request'] = signed('whsec_dummy', $ev2, time() - 1000);
    check($v2->notify([]) === false, 'stale timestamp rejected');
    $GLOBALS['request'] = signed('whsec_dummy', $ev2);
    check((new App\Payments\SnapexPay(['environment' => 'live'] + $cfg))->notify([]) === false, 'test invoice cannot pay a live method');
    $GLOBALS['request'] = signed('whsec_dummy', ['type' => 'invoice.paid', 'data' => ['invoice_id' => $openId]]);
    check($v2->notify([]) === false, 'API status open → rejected');

    echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} test(s) failed.\n";
    exit($failures === 0 ? 0 : 1);
}
