<?php

namespace App\Payments;

/**
 * V2Board / older Xboard drop-in: copy this file to app/Payments/SnapexPay.php
 * and add a payment method named SnapexPay in the admin. PHP 7.3+.
 */
class SnapexPay
{
    private const DEFAULT_API_URL = 'https://snapex.pro/api/v1/merchant';

    private const WEBHOOK_TOLERANCE = 300;

    /** V2Board cancels unpaid orders after 30 minutes; a longer window could take money for a cancelled order. */
    private const MAX_EXPIRES_MINUTES = 30;

    /** SnapEX external_id is unique per merchant, so a re-checkout after expiry gets "{trade_no}-{n}". */
    private const MAX_ATTEMPTS = 20;

    private const OPEN_STATUSES = ['open', 'awaiting', 'underpaid', 'paid'];

    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'api_key' => [
                'label' => 'API key',
                'description' => 'SnapEX Pay cabinet → API keys (invoices.read, invoices.write)',
                'type' => 'input',
            ],
            'webhook_secret' => [
                'label' => 'Webhook secret',
                'description' => 'whsec_… . Point the cabinet webhook URL at this method notify URL.',
                'type' => 'input',
            ],
            'fee_on' => [
                'label' => 'Fee paid by (merchant|customer)',
                'description' => 'merchant = client pays the invoice amount',
                'type' => 'input',
            ],
            'expires_minutes' => [
                'label' => 'Payment window, minutes',
                'description' => '1–30, default 20. V2Board cancels unpaid orders after 30 minutes.',
                'type' => 'input',
            ],
            'usd_rate' => [
                'label' => 'USD per 1 shop currency',
                'description' => '1 if amounts are already USD',
                'type' => 'input',
            ],
            'environment' => [
                'label' => 'Environment (live|test)',
                'description' => 'test invoices confirm without real money',
                'type' => 'input',
            ],
            'partner_id' => [
                'label' => 'Partner ID (optional)',
                'description' => 'Integrator\'s Partner ID (SnapEX Pay referral program). Leave empty if you set this up yourself.',
                'type' => 'input',
            ],
            'api_url' => [
                'label' => 'API URL',
                'description' => self::DEFAULT_API_URL,
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (trim((string) ($this->config['api_key'] ?? '')) === '') {
            abort(500, 'SnapEX Pay API key is missing');
        }
        $amountUsd = $this->amountUsd($order);
        if ($amountUsd === null) {
            abort(500, 'SnapEX Pay: set usd_rate so the order total can be converted to USD.');
        }
        $tradeNo = (string) $order['trade_no'];
        $minutes = (int) ($this->config['expires_minutes'] ?? 20);
        $minutes = max(1, min(self::MAX_EXPIRES_MINUTES, $minutes ?: 20));
        $environment = $this->environment();
        $payload = [
            'amount_usd' => $amountUsd,
            'fee_on' => (trim((string) ($this->config['fee_on'] ?? 'merchant')) === 'customer') ? 'customer' : 'merchant',
            'environment' => $environment,
            'description' => 'V2Board order '.$tradeNo,
            'expires_in' => max(60, $minutes * 60),
        ];
        $partnerId = trim((string) ($this->config['partner_id'] ?? ''));
        if ($partnerId !== '') {
            $payload['partner_ref'] = $partnerId;
        }
        // V2Board builds return_url from app_url; without it the URL is relative and SnapEX would reject it.
        $returnUrl = trim((string) ($order['return_url'] ?? ''));
        if ($returnUrl !== '' && strlen($returnUrl) <= 2048 && preg_match('#^https?://[^/\s]+#i', $returnUrl)) {
            $payload['success_url'] = $returnUrl;
            $payload['cancel_url'] = $returnUrl;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $externalId = $attempt === 0 ? $tradeNo : $tradeNo.'-'.$attempt;
            list($status, $decoded) = $this->send('POST', '/invoices', $payload + ['external_id' => $externalId], [
                'Idempotency-Key: v2board-'.$externalId.'-'.$amountUsd.'-'.$environment,
            ]);
            if ($status === 409 && preg_match('/external_id|Idempotency-Key/i', $this->errorMessage($decoded))) {
                continue; // The total, rate or environment changed: that external_id is taken.
            }
            $created = $this->dataOrAbort($status, $decoded);
            if (empty($created['pay_url'])) {
                abort(500, 'SnapEX Pay did not return a payment URL');
            }
            if (! in_array((string) ($created['status'] ?? 'open'), self::OPEN_STATUSES, true)) {
                continue; // Expired invoice returned for the same Idempotency-Key: open a fresh one.
            }

            return [
                'type' => 1,
                'data' => (string) $created['pay_url'],
            ];
        }

        abort(500, 'SnapEX Pay: too many payment attempts for this order. Create a new order.');
    }

    public function notify($params)
    {
        // Only the signed raw body counts; $params also carries query-string input.
        $request = function_exists('request') ? request() : null;
        $body = $request !== null ? (string) $request->getContent() : (string) file_get_contents('php://input');
        $secret = trim((string) ($this->config['webhook_secret'] ?? ''));
        if ($secret === '' || ! $this->validWebhook($request, $body, $secret)) {
            return false;
        }
        $event = json_decode($body, true);
        if (! is_array($event)) {
            return false;
        }
        $invoiceId = (string) ($event['data']['invoice_id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if ($invoiceId === '' || strpos($type, 'invoice.') !== 0) {
            return false;
        }
        if ($type !== 'invoice.paid' && ($event['data']['status'] ?? '') !== 'paid') {
            return false;
        }
        list($status, $decoded) = $this->send('GET', '/invoices/'.rawurlencode($invoiceId));
        $remote = ($status >= 200 && $status < 300 && is_array($decoded['data'] ?? null)) ? $decoded['data'] : [];
        if (($remote['status'] ?? '') !== 'paid' || (string) ($remote['invoice_id'] ?? '') !== $invoiceId) {
            return false;
        }
        // A test invoice confirms without money: never let it pay an order while the method is live.
        if (($remote['environment'] ?? 'live') === 'test' && $this->environment() !== 'test') {
            return false;
        }
        $externalId = (string) ($remote['external_id'] ?? '');
        $tradeNo = preg_match('/^(\d+)(?:-\d{1,2})?$/', $externalId, $m) ? $m[1] : $externalId;
        if ($tradeNo === '') {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $invoiceId,
        ];
    }

    private function environment(): string
    {
        return strtolower(trim((string) ($this->config['environment'] ?? 'live'))) === 'test' ? 'test' : 'live';
    }

    private function amountUsd($order): ?string
    {
        // V2Board keeps money in cents (integer).
        $cents = (int) ($order['total_amount'] ?? 0);
        $rate = (float) str_replace(',', '.', trim((string) ($this->config['usd_rate'] ?? '1')));
        if ($cents <= 0 || $rate <= 0) {
            return null;
        }
        $usd = round(($cents / 100) * $rate, 2);

        return $usd > 0 ? number_format($usd, 2, '.', '') : null;
    }

    private function validWebhook($request, string $body, string $secret): bool
    {
        if ($request !== null) {
            $timestamp = (string) $request->header('Webhook-Timestamp', '');
            $signature = (string) $request->header('Webhook-Signature', '');
        } else {
            $timestamp = (string) ($_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '');
            $signature = (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '');
        }
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE) {
            return false;
        }

        return hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }

    private function errorMessage($decoded): string
    {
        return is_array($decoded) && is_string($decoded['error']['message'] ?? null) ? $decoded['error']['message'] : '';
    }

    private function dataOrAbort(int $status, $decoded): array
    {
        if ($status < 200 || $status >= 300 || ! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
            $message = $this->errorMessage($decoded);
            abort(500, $message !== '' ? 'SnapEX Pay: '.$message : 'SnapEX Pay request failed (HTTP '.$status.')');
        }

        return $decoded['data'];
    }

    /** @return array{0: int, 1: mixed} HTTP status (0 when unreachable) and decoded JSON. */
    private function send(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        $base = rtrim(trim((string) ($this->config['api_url'] ?? '')) ?: self::DEFAULT_API_URL, '/');
        $headers = array_merge([
            'Authorization: Bearer '.$apiKey,
            'Accept: application/json',
            'User-Agent: SnapEX-Pay-V2Board/1.2.0',
        ], $extraHeaders);
        $json = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($base.$path);
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
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, json_decode((string) $raw, true)];
    }
}
