<?php

namespace App\Services\Payment\Drivers;

use App\Models\Order;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Binance Pay (crypto) — Merchant API v3. Redirect flow, verified server-side:
 *   1. initiate()      -> POST /binancepay/openapi/v3/order, redirect the
 *                         customer to the returned data.checkoutUrl.
 *   2. Binance redirects back to returnUrl (browser) + POSTs a webhook.
 *   3. handleCallback() -> POST /binancepay/openapi/v2/order/query
 *                         {merchantTradeNo}; only status "PAID" is a real,
 *                         server-verified payment.
 *
 * Every call is signed: HMAC-SHA512 (hex, UPPERCASE) over
 *   "<timestamp>\n<nonce>\n<body>\n"
 * with the API secret; the API key is sent as BinancePay-Certificate-SN.
 *
 * @see https://developers.binance.com/docs/binance-pay/api-order-create-v3
 */
class BinanceDriver extends AbstractGatewayDriver
{
    private const API_BASE = 'https://bpay.binanceapi.com';
    // Binance Pay settles in crypto (USDT/USDC/BNB/BTC) or USD.
    private const SUPPORTED = ['USDT', 'USDC', 'BUSD', 'BNB', 'BTC', 'USD'];

    public static function credentialFields(): array
    {
        return [
            'api_key'    => ['label' => 'API Key',    'type' => 'text',     'required' => true, 'hint' => 'Binance Merchant "API Key" (sent as BinancePay-Certificate-SN).'],
            'api_secret' => ['label' => 'API Secret', 'type' => 'password', 'required' => true, 'hint' => 'Binance Merchant "API Secret" (signs every request).'],
            'currency'   => ['label' => 'Settlement currency', 'type' => 'text', 'required' => false, 'hint' => 'e.g. USDT (default). One of USDT, USDC, BUSD, BNB, BTC, USD.'],
        ];
    }

    /** Build the signed headers for a request body (already JSON-encoded). */
    private function signedHeaders(string $body): array
    {
        $ts    = (string) round(microtime(true) * 1000);
        $nonce = Str::random(32);
        $secret = (string) $this->cred('api_secret');
        $payload = $ts . "\n" . $nonce . "\n" . $body . "\n";
        $sig = strtoupper(hash_hmac('sha512', $payload, $secret));

        return [
            'Content-Type'                => 'application/json',
            'BinancePay-Timestamp'        => $ts,
            'BinancePay-Nonce'            => $nonce,
            'BinancePay-Certificate-SN'   => (string) $this->cred('api_key'),
            'BinancePay-Signature'        => $sig,
        ];
    }

    /** POST a signed JSON body (encoded once so the signed + wire bytes match). */
    private function post(string $path, array $data)
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES);
        return Http::withHeaders($this->signedHeaders((string) $body))
            ->acceptJson()->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->withBody((string) $body, 'application/json')
            ->post(self::API_BASE . $path);
    }

    public function initiate(Order $order, string $callbackUrl): PaymentResult
    {
        if ((string) $this->cred('api_key') === '' || (string) $this->cred('api_secret') === '') {
            return PaymentResult::failed('binance_credentials_missing');
        }

        $currency = strtoupper(trim((string) $this->cred('currency')) ?: (in_array(strtoupper((string) $order->currency), self::SUPPORTED, true) ? strtoupper((string) $order->currency) : 'USDT'));
        // merchantTradeNo: alphanumeric only, max 32.
        $tradeNo = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $order->order_number) . strtoupper(Str::random(6)), 0, 32);

        $data = [
            'env'             => ['terminalType' => 'WEB'],
            'merchantTradeNo' => $tradeNo,
            'orderAmount'     => (float) $order->amount,
            'currency'        => $currency,
            'description'     => 'Order ' . $order->order_number,
            'goodsDetails'    => [[
                'goodsType'        => '02',            // virtual goods
                'goodsCategory'    => 'Z000',          // others
                'referenceGoodsId' => (string) $order->order_number,
                'goodsName'        => 'Order ' . $order->order_number,
            ]],
            'returnUrl'  => $callbackUrl,
            'cancelUrl'  => $callbackUrl,
            'webhookUrl' => route('payment.webhook', ['gateway' => 'binance']),
        ];

        try {
            $r    = $this->post('/binancepay/openapi/v3/order', $data);
            $json = $r->json() ?: [];
            if ((string) ($json['status'] ?? '') === 'SUCCESS' && ! empty($json['data']['checkoutUrl'])) {
                // Stash our tradeNo as the gateway order id so the query/webhook resolve back.
                return PaymentResult::redirect((string) $json['data']['checkoutUrl'], $tradeNo, $json['data']);
            }
            Log::warning('[binance] create rejected', ['order' => $order->order_number, 'http' => $r->status(), 'code' => $json['code'] ?? null]);
            return PaymentResult::failed('binance: ' . (string) ($json['errorMessage'] ?? ($json['code'] ?? 'create_failed')));
        } catch (\Throwable $e) {
            return PaymentResult::failed('binance_exception: ' . $e->getMessage());
        }
    }

    public function handleCallback(array $payload): PaymentResult
    {
        // Resolve our merchantTradeNo from the return params or webhook body.
        $tradeNo = $payload['merchantTradeNo']
            ?? ($payload['data']['merchantTradeNo'] ?? null);
        if (! $tradeNo && ! empty($payload['data']) && is_string($payload['data'])) {
            $d = json_decode((string) $payload['data'], true);
            $tradeNo = $d['merchantTradeNo'] ?? null;
        }
        if (! $tradeNo) return PaymentResult::failed('missing_binance_trade_no');

        try {
            $r      = $this->post('/binancepay/openapi/v2/order/query', ['merchantTradeNo' => (string) $tradeNo]);
            $json   = $r->json() ?: [];
            $status = strtoupper((string) ($json['data']['status'] ?? ''));
            Log::info('[binance] query', ['trade' => $tradeNo, 'http' => $r->status(), 'status' => $status]);

            if ((string) ($json['status'] ?? '') === 'SUCCESS' && $status === 'PAID') {
                return PaymentResult::paid(
                    gatewayPaymentId: (string) ($json['data']['transactionId'] ?? $tradeNo),
                    gatewayOrderId:   (string) $tradeNo,
                    payload:          $json['data'] ?? $json,
                );
            }
            return PaymentResult::failed('binance_status: ' . ($status ?: 'query_failed'), $json);
        } catch (\Throwable $e) {
            return PaymentResult::failed('binance_query_exception: ' . $e->getMessage());
        }
    }

    public function handleWebhook(array $payload): PaymentResult
    {
        return $this->handleCallback($payload);
    }
}
