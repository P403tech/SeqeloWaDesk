<?php

namespace App\Services\Payment\Drivers;

use App\Models\Order;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Khalti (by IME) — Nepal. KPG-2 (ePayment) API. Redirect flow, verified
 * server-side:
 *   1. initiate()      -> POST /epayment/initiate/ (amount in PAISA), redirect
 *                         the customer to the returned `payment_url`.
 *   2. Khalti redirects back to return_url with ?pidx=… (+ status, transaction_id).
 *   3. handleCallback() -> POST /epayment/lookup/ {pidx}; only status
 *                         "Completed" is a real, server-verified payment.
 *
 * Auth: `Authorization: Key <secret_key>`. Amount is in PAISA (Rs.1 = 100),
 * minimum 1000 paisa (Rs.10). Sandbox = dev.khalti.com, live = khalti.com.
 *
 * @see https://docs.khalti.com/khalti-epayment/
 */
class KhaltiDriver extends AbstractGatewayDriver
{
    public static function credentialFields(): array
    {
        return [
            'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true, 'hint' => 'Your Khalti secret key (admin.khalti.com → Settings → Keys; test-admin.khalti.com in test mode). Sent as "Authorization: Key <key>".'],
        ];
    }

    private function base(): string
    {
        return $this->isLive() ? 'https://khalti.com/api/v2' : 'https://dev.khalti.com/api/v2';
    }

    private function http()
    {
        return Http::acceptJson()->asJson()
            ->connectTimeout(20)
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->withHeaders(['Authorization' => 'Key ' . (string) $this->cred('secret_key')]);
    }

    public function initiate(Order $order, string $callbackUrl): PaymentResult
    {
        $key = (string) $this->cred('secret_key');
        if ($key === '') return PaymentResult::failed('khalti_credentials_missing');

        // Khalti settles in NPR and expects the amount in PAISA (Rs.1 = 100).
        $amountPaisa = (int) round(((float) $order->amount) * 100);
        if ($amountPaisa < 1000) return PaymentResult::failed('khalti_amount_below_min'); // min Rs.10

        $body = [
            'return_url'          => $callbackUrl,
            'website_url'         => rtrim((string) config('app.url', ''), '/') ?: 'https://example.com',
            'amount'              => $amountPaisa,
            'purchase_order_id'   => (string) $order->order_number,
            'purchase_order_name' => 'Order #' . $order->order_number,
            'customer_info'       => array_filter([
                'name'  => (string) ($order->customer_name ?: optional($order->user)->name ?: ''),
                'email' => (string) ($order->customer_email ?: optional($order->user)->email ?: ''),
            ]),
        ];

        try {
            $r    = $this->http()->post($this->base() . '/epayment/initiate/', $body);
            $json = $r->json() ?: [];
            if ($r->successful() && ! empty($json['payment_url']) && ! empty($json['pidx'])) {
                return PaymentResult::redirect((string) $json['payment_url'], (string) $json['pidx'], $json);
            }
            Log::warning('[khalti] initiate rejected', ['order' => $order->order_number, 'http' => $r->status()]);
            return PaymentResult::failed('khalti: ' . (string) ($json['detail'] ?? ($json['error_key'] ?? 'initiate_failed')));
        } catch (\Throwable $e) {
            return PaymentResult::failed('khalti_exception: ' . $e->getMessage());
        }
    }

    public function handleCallback(array $payload): PaymentResult
    {
        $pidx = $payload['pidx'] ?? null;
        if (! $pidx) return PaymentResult::failed('missing_khalti_pidx');

        try {
            $r      = $this->http()->post($this->base() . '/epayment/lookup/', ['pidx' => $pidx]);
            $json   = $r->json() ?: [];
            $status = (string) ($json['status'] ?? '');
            if ($status === 'Completed') {
                return PaymentResult::paid(
                    gatewayPaymentId: (string) ($json['transaction_id'] ?? $pidx),
                    gatewayOrderId:   (string) $pidx,
                    payload:          $json,
                );
            }
            return PaymentResult::failed('khalti_status: ' . $status, $json);
        } catch (\Throwable $e) {
            return PaymentResult::failed('khalti_lookup_exception: ' . $e->getMessage());
        }
    }

    public function handleWebhook(array $payload): PaymentResult
    {
        return $this->handleCallback($payload);
    }
}
