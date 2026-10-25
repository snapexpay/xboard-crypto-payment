<?php

namespace Plugin\Snapexpay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Http\Request;

/**
 * Xboard payment plugin: open a SnapEX Pay page, mark the order paid from the signed webhook.
 *
 * Xboard loads it from plugins/Snapexpay/ (Str::studly of the config.json code "snapexpay").
 */
class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const METHOD_NAME = 'SnapexPay';

    private const DEFAULT_API_URL = 'https://snapex.pro/api/v1/merchant';

    private const WEBHOOK_TOLERANCE = 300;

    /** Xboard cancels unpaid orders after 2 hours; a longer window could take money for a cancelled order. */
    private const MAX_EXPIRES_MINUTES = 120;

    /** SnapEX external_id is unique per merchant, so a re-checkout after expiry gets "{trade_no}-{n}". */
    private const MAX_ATTEMPTS = 20;

    private const OPEN_STATUSES = ['open', 'awaiting', 'underpaid', 'paid'];

    /** HTTP status of the last SnapEX call. Not used as the ApiException code: Xboard turns that into the response status. */
    private int $lastStatus = 0;

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            $methods[self::METHOD_NAME] = [
                'name' => 'SnapEX Pay (Crypto)',
                'icon' => 'USDT',
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];

            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'api_key' => [
                'label' => 'API key',
                'type' => 'string',
                'required' => true,
                'description' => 'SnapEX Pay cabinet → API keys. Scopes: invoices.read, invoices.write.',
            ],
            'webhook_secret' => [
                'label' => 'Webhook secret',
                'type' => 'string',
                'required' => true,
                'description' => 'whsec_… from the cabinet. Set the webhook URL to this method notify URL.',
            ],
            'fee_on' => [
                'label' => 'Network fee paid by',
                'type' => 'string',
                'default' => 'merchant',
                'description' => 'merchant = client pays the invoice amount. customer = fee added on top.',
            ],
            'expires_minutes' => [
                'label' => 'Payment window (minutes)',
                'type' => 'string',
                'default' => '60',
                'description' => '1–120. Default 60. Xboard cancels unpaid orders after 2 hours.',
            ],
            'usd_rate' => [
                'label' => 'USD per 1 shop currency',
                'type' => 'string',
                'default' => '1',
                'description' => 'Use 1 if Xboard amounts are already USD. Example: 0.14 if the shop is CNY.',
            ],
            'environment' => [
                'label' => 'Environment',
                'type' => 'string',
                'default' => 'live',
                'description' => 'live or test. test invoices confirm without real money.',
            ],
            'partner_id' => [
                'label' => 'Partner ID (optional)',
                'type' => 'string',
                'default' => '',
                'description' => 'Integrator\'s Partner ID (SnapEX Pay referral program). Leave empty if you set this up yourself.',
            ],
            'api_url' => [
                'label' => 'API URL',
                'type' => 'string',
                'default' => self::DEFAULT_API_URL,
                'description' => 'Leave as https://snapex.pro/api/v1/merchant unless SnapEX says otherwise.',
            ],
        ];
    }

    public function pay($order): array
    {
        $apiKey = trim((string) $this->getConfig('api_key'));
        if ($apiKey === '') {
            throw new ApiException('SnapEX Pay API key is missing');
        }

        $amountUsd = $this->amountUsd($order);
        if ($amountUsd === null) {
            throw new ApiException('SnapEX Pay: could not convert the order total to USD. Set usd_rate.');
        }

        $tradeNo = (string) $order['trade_no'];
        $minutes = (int) $this->getConfig('expires_minutes', 60);
        $minutes = max(1, min(self::MAX_EXPIRES_MINUTES, $minutes ?: 60));
        $environment = $this->environment();
        $returnUrl = $this->httpUrl($order['return_url'] ?? null);
        $payload = [
            'amount_usd' => $amountUsd,
            'fee_on' => $this->getConfig('fee_on', 'merchant') === 'customer' ? 'customer' : 'merchant',
            'environment' => $environment,
            'description' => $this->description($tradeNo),
            // SnapEX accepts 60..86400 seconds.
            'expires_in' => max(60, $minutes * 60),
        ];
        $partnerId = trim((string) $this->getConfig('partner_id', ''));
        if ($partnerId !== '') {
            $payload['partner_ref'] = $partnerId;
        }
        if ($returnUrl !== null) {
            $payload['success_url'] = $returnUrl;
            $payload['cancel_url'] = $returnUrl;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $externalId = $attempt === 0 ? $tradeNo : $tradeNo.'-'.$attempt;
            try {
                $created = $this->request('POST', '/invoices', $payload + ['external_id' => $externalId], [
                    'Idempotency-Key: xboard-'.$externalId.'-'.$amountUsd.'-'.$environment,
                ]);
            } catch (ApiException $e) {
                // The order total, rate or environment changed: that external_id is taken, try the next one.
                if ($this->lastStatus === 409 && preg_match('/external_id|Idempotency-Key/i', (string) $e->getMessage())) {
                    continue;
                }
                throw $e;
            }
            if (empty($created['invoice_id']) || empty($created['pay_url'])) {
                throw new ApiException('SnapEX Pay did not return a payment URL');
            }
            // An expired invoice comes back for the same Idempotency-Key: open a fresh one instead.
            if (! in_array((string) ($created['status'] ?? 'open'), self::OPEN_STATUSES, true)) {
                continue;
            }

            return [
                'type' => 1,
                'data' => (string) $created['pay_url'],
            ];
        }

        throw new ApiException('SnapEX Pay: too many payment attempts for this order. Create a new order.');
    }

    public function notify($params): array|bool
    {
        $request = request();
        if (! $request instanceof Request) {
            return false;
        }
        // Only the signed raw body counts; $params also carries query-string input.
        $body = (string) $request->getContent();
        $secret = trim((string) $this->getConfig('webhook_secret'));
        if ($secret === '' || ! $this->validWebhook($request, $body, $secret)) {
            return false;
        }
        $event = json_decode($body, true);
        if (! is_array($event)) {
            return false;
        }

        $type = (string) ($event['type'] ?? '');
        $invoiceId = (string) ($event['data']['invoice_id'] ?? '');
        if ($invoiceId === '' || ! str_starts_with($type, 'invoice.')) {
            return false;
        }
        if ($type !== 'invoice.paid' && ($event['data']['status'] ?? '') !== 'paid') {
            return false;
        }

        try {
            $remote = $this->request('GET', '/invoices/'.rawurlencode($invoiceId));
        } catch (ApiException) {
            return false;
        }
        if (($remote['status'] ?? '') !== 'paid' || (string) ($remote['invoice_id'] ?? '') !== $invoiceId) {
            return false;
        }
        // A test invoice confirms without money: never let it pay an order while the method is live.
        if (($remote['environment'] ?? 'live') === 'test' && $this->environment() !== 'test') {
            return false;
        }

        $tradeNo = $this->tradeNo((string) ($remote['external_id'] ?? ''));
        if ($tradeNo === '') {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $invoiceId,
            'custom_result' => 'success',
        ];
    }

    private function tradeNo(string $externalId): string
    {
        // Xboard trade numbers are digits; re-checkouts carry a "-{n}" suffix.
        return preg_match('/^(\d+)(?:-\d{1,2})?$/', $externalId, $m) ? $m[1] : $externalId;
    }

    private function amountUsd($order): ?string
    {
        // Xboard keeps money in cents (integer).
        $cents = (int) ($order['total_amount'] ?? 0);
        if ($cents <= 0) {
            return null;
        }
        $rate = (float) str_replace(',', '.', trim((string) $this->getConfig('usd_rate', '1')));
        if ($rate <= 0) {
            return null;
        }
        $usd = round(($cents / 100) * $rate, 2);

        return $usd > 0 ? number_format($usd, 2, '.', '') : null;
    }

    private function environment(): string
    {
        return strtolower(trim((string) $this->getConfig('environment', 'live'))) === 'test' ? 'test' : 'live';
    }

    private function httpUrl($url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' && strlen($url) <= 2048 && preg_match('#^https?://[^/\s]+#i', $url) ? $url : null;
    }

    private function description(string $tradeNo): string
    {
        $shop = function_exists('admin_setting') ? (string) admin_setting('app_name', 'Xboard') : 'Xboard';

        return function_exists('mb_substr')
            ? mb_substr($shop.' order '.$tradeNo, 0, 255)
            : substr($shop.' order '.$tradeNo, 0, 255);
    }

    private function validWebhook(Request $request, string $body, string $secret): bool
    {
        $timestamp = (string) $request->header('Webhook-Timestamp', '');
        $signature = (string) $request->header('Webhook-Signature', '');
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            return false;
        }
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expected, $signature);
    }

    private function apiUrl(): string
    {
        $url = trim((string) $this->getConfig('api_url'));

        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    private function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $apiKey = trim((string) $this->getConfig('api_key'));
        if ($apiKey === '') {
            throw new ApiException('SnapEX Pay API key is missing');
        }
        $headers = array_merge([
            'Authorization: Bearer '.$apiKey,
            'Accept: application/json',
            'User-Agent: SnapEX-Pay-Xboard/1.2.0',
        ], $extraHeaders);
        $this->lastStatus = 0;
        $json = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($this->apiUrl().$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        $raw = curl_exec($ch);
        $status = $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new ApiException('SnapEX Pay is unreachable: '.$error);
        }
        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data'])) {
            $message = is_array($decoded) && is_string($decoded['error']['message'] ?? null) ? $decoded['error']['message'] : '';
            if (is_array($decoded) && is_array($decoded['error']['details'] ?? null)) {
                $first = $this->firstDetail($decoded['error']['details']);
                $message .= $first !== '' ? ' '.$first : '';
            }
            throw new ApiException($message !== '' ? 'SnapEX Pay: '.$message : 'SnapEX Pay returned HTTP '.$status);
        }

        return $decoded['data'];
    }

    private function firstDetail(array $details): string
    {
        foreach ($details as $value) {
            if (is_string($value)) {
                return $value;
            }
            if (is_array($value) && ($nested = $this->firstDetail($value)) !== '') {
                return $nested;
            }
        }

        return '';
    }
}
