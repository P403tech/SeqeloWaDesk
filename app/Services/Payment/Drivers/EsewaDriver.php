<?php

namespace App\Services\Payment\Drivers;

use App\Models\Order;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * eSewa (Nepal) — ePay v2. Signed HTML-form redirect, verified server-side:
 *   1. initiate()      -> auto-submitting POST form to eSewa's hosted page. The
 *                         3 mandatory fields (total_amount,transaction_uuid,
 *                         product_code) are HMAC-SHA256 signed (base64) with the
 *                         merchant secret.
 *   2. eSewa redirects back to success_url with a base64 `data` param.
 *   3. handleCallback() -> GET /api/epay/transaction/status/ to confirm; only
 *                         status "COMPLETE" is a real, server-verified payment.
 *
 * Amount is in NPR whole rupees (no minor units). Test host = rc-epay/rc.esewa;
 * live host = epay/esewa. Test product_code = EPAYTEST, test secret = 8gBm/:&EnhH.1/q(.
 *
 * @see https://developer.esewa.com.np/pages/Epay
 */
class EsewaDriver extends AbstractGatewayDriver
{
    public static function credentialFields(): array
    {
        return [
            'product_code' => ['label' => 'Merchant / Product Code', 'type' => 'text',     'required' => true, 'hint' => 'Your eSewa merchant product code (test: EPAYTEST).'],
            'secret_key'   => ['label' => 'Secret Key',              'type' => 'password', 'required' => true, 'hint' => 'Your eSewa signing secret (test: 8gBm/:&EnhH.1/q().'],
        ];
    }

    private function formUrl(): string
    {
        return $this->isLive()
            ? 'https://epay.esewa.com.np/api/epay/main/v2/form'
            : 'https://rc-epay.esewa.com.np/api/epay/main/v2/form';
    }

    private function statusUrl(): string
    {
        return $this->isLive()
            ? 'https://epay.esewa.com.np/api/epay/transaction/status/'
            : 'https://rc.esewa.com.np/api/epay/transaction/status/';
    }

    public function initiate(Order $order, string $callbackUrl): PaymentResult
    {
        $code   = (string) $this->cred('product_code');
        $secret = (string) $this->cred('secret_key');
        if ($code === '' || $secret === '') return PaymentResult::failed('esewa_credentials_missing');

        // eSewa is NPR whole-rupees. The signed total_amount string must match the
        // form value EXACTLY, so build it once and reuse it verbatim.
        $totalAmount = rtrim(rtrim(number_format((float) $order->amount, 2, '.', ''), '0'), '.'); // "100", "99.5"
        if ($totalAmount === '' || (float) $totalAmount <= 0) return PaymentResult::failed('esewa_invalid_amount');
        $txnUuid = (string) $order->order_number;   // unique, hyphens allowed

        $fields = [
            'amount'                  => $totalAmount,
            'tax_amount'              => '0',
            'total_amount'            => $totalAmount,
            'transaction_uuid'        => $txnUuid,
            'product_code'            => $code,
            'product_service_charge'  => '0',
            'product_delivery_charge' => '0',
            'success_url'             => $callbackUrl,
            'failure_url'             => $callbackUrl,
            'signed_field_names'      => 'total_amount,transaction_uuid,product_code',
        ];
        // Signature over exactly: total_amount=…,transaction_uuid=…,product_code=…
        $message = "total_amount={$totalAmount},transaction_uuid={$txnUuid},product_code={$code}";
        $fields['signature'] = base64_encode(hash_hmac('sha256', $message, $secret, true));

        // Auto-submitting form (eSewa v2 accepts only a browser POST, no API URL).
        $inputs = '';
        foreach ($fields as $k => $v) {
            $inputs .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES) . '">';
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Redirecting to eSewa…</title></head>'
            . '<body onload="document.forms[0].submit()"><form method="POST" action="' . htmlspecialchars($this->formUrl(), ENT_QUOTES) . '">'
            . $inputs
            . '<noscript><p>Click to continue to eSewa:</p><button type="submit">Pay with eSewa</button></noscript>'
            . '</form></body></html>';

        return PaymentResult::form($html, $txnUuid, ['total_amount' => $totalAmount]);
    }

    public function handleCallback(array $payload): PaymentResult
    {
        $code = (string) $this->cred('product_code');

        // eSewa returns a base64 `data` param on success_url; decode it, then
        // confirm authoritatively via the status API.
        $data = null;
        if (! empty($payload['data'])) {
            $decoded = json_decode(base64_decode((string) $payload['data']), true);
            $data = is_array($decoded) ? $decoded : null;
        }
        $txnUuid     = (string) ($data['transaction_uuid'] ?? $payload['transaction_uuid'] ?? '');
        $totalAmount = (string) ($data['total_amount'] ?? $payload['total_amount'] ?? '');
        // eSewa echoes total_amount with a thousands comma in `data` (e.g. "1,000") —
        // the status API wants it plain.
        $totalAmount = str_replace(',', '', $totalAmount);
        if ($txnUuid === '') return PaymentResult::failed('missing_esewa_txn');

        try {
            $r      = Http::acceptJson()->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get($this->statusUrl(), ['product_code' => $code, 'total_amount' => $totalAmount, 'transaction_uuid' => $txnUuid]);
            $json   = $r->json() ?: [];
            $status = strtoupper((string) ($json['status'] ?? ($data['status'] ?? '')));
            Log::info('[esewa] status', ['txn' => $txnUuid, 'http' => $r->status(), 'status' => $status]);

            if ($status === 'COMPLETE') {
                return PaymentResult::paid(
                    gatewayPaymentId: (string) ($json['ref_id'] ?? $data['transaction_code'] ?? $txnUuid),
                    gatewayOrderId:   $txnUuid,
                    payload:          ($json ?: $data),
                );
            }
            return PaymentResult::failed('esewa_status: ' . $status, ($json ?: $data));
        } catch (\Throwable $e) {
            return PaymentResult::failed('esewa_status_exception: ' . $e->getMessage());
        }
    }

    public function handleWebhook(array $payload): PaymentResult
    {
        return $this->handleCallback($payload);
    }
}
