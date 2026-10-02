<?php

namespace App\Services\Payment\Drivers;

use App\Models\Order;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Str;

/**
 * JazzCash (Pakistan) — Hosted Checkout (Page Redirect) v1.1/2.0. Signed
 * HTML-form redirect, verified on return by re-computing the secure hash:
 *   1. initiate()      -> auto-submitting POST form to the JazzCash merchant
 *                         page. Every pp_* / ppmpf_* field is included in the
 *                         pp_SecureHash = HMAC-SHA256(salt & sorted-values, salt).
 *   2. JazzCash redirects back to pp_ReturnURL POSTing the result + its own
 *                         pp_SecureHash.
 *   3. handleCallback() -> re-compute the hash over the returned fields; a valid
 *                         hash AND pp_ResponseCode "000" is a real payment.
 *
 * Amount is in PAISA (PKR minor, Rs.1 = 100), currency PKR. Sandbox +
 * production hosts differ only by domain.
 *
 * @see https://sandbox.jazzcash.com.pk/  (Merchant Integration — Hosted Checkout)
 */
class JazzcashDriver extends AbstractGatewayDriver
{
    public static function credentialFields(): array
    {
        return [
            'merchant_id'    => ['label' => 'Merchant ID',    'type' => 'text',     'required' => true, 'hint' => 'Your JazzCash Merchant ID (pp_MerchantID).'],
            'password'       => ['label' => 'Password',       'type' => 'password', 'required' => true, 'hint' => 'Your JazzCash integration Password (pp_Password).'],
            'integrity_salt' => ['label' => 'Integrity Salt', 'type' => 'password', 'required' => true, 'hint' => 'Your JazzCash Integrity Salt — signs the pp_SecureHash.'],
        ];
    }

    private function formUrl(): string
    {
        $host = $this->isLive() ? 'payments.jazzcash.com.pk' : 'sandbox.jazzcash.com.pk';
        return "https://{$host}/CustomerPortal/transactionmanagement/merchantform/";
    }

    /**
     * JazzCash secure hash: take every pp_/ppmpf_ field that has a value, sort by
     * key (ASCII), join their VALUES with '&' prefixed by the Integrity Salt, then
     * HMAC-SHA256 with the salt as the key (hex).
     */
    private function secureHash(array $fields, string $salt): string
    {
        $signed = array_filter($fields, fn ($v) => $v !== '' && $v !== null);
        ksort($signed);
        $str = $salt . '&' . implode('&', array_map('strval', $signed));
        return strtoupper(hash_hmac('sha256', $str, $salt));
    }

    public function initiate(Order $order, string $callbackUrl): PaymentResult
    {
        $mid  = (string) $this->cred('merchant_id');
        $pwd  = (string) $this->cred('password');
        $salt = (string) $this->cred('integrity_salt');
        if ($mid === '' || $pwd === '' || $salt === '') return PaymentResult::failed('jazzcash_credentials_missing');

        $amountPaisa = (int) round(((float) $order->amount) * 100);   // PKR → paisa
        if ($amountPaisa <= 0) return PaymentResult::failed('jazzcash_invalid_amount');

        $now    = now();
        $txnRef = 'T' . $now->format('YmdHis') . strtoupper(Str::random(4));

        $fields = [
            'pp_Version'            => '1.1',
            'pp_TxnType'            => 'MPAY',
            'pp_Language'           => 'EN',
            'pp_MerchantID'         => $mid,
            'pp_SubMerchantID'      => '',
            'pp_Password'           => $pwd,
            'pp_BankID'             => '',
            'pp_ProductID'          => '',
            'pp_TxnRefNo'           => $txnRef,
            'pp_Amount'             => (string) $amountPaisa,
            'pp_TxnCurrency'        => 'PKR',
            'pp_TxnDateTime'        => $now->format('YmdHis'),
            'pp_BillReference'      => (string) $order->order_number,
            'pp_Description'        => 'Order ' . $order->order_number,
            'pp_TxnExpiryDateTime'  => $now->copy()->addHours(1)->format('YmdHis'),
            'pp_ReturnURL'          => $callbackUrl,
            'ppmpf_1'               => (string) $order->id,
            'ppmpf_2'               => (string) $order->order_number,
        ];
        $fields['pp_SecureHash'] = $this->secureHash($fields, $salt);

        $inputs = '';
        foreach ($fields as $k => $v) {
            $inputs .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES) . '">';
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Redirecting to JazzCash…</title></head>'
            . '<body onload="document.forms[0].submit()"><form method="POST" action="' . htmlspecialchars($this->formUrl(), ENT_QUOTES) . '">'
            . $inputs
            . '<noscript><p>Continue to JazzCash:</p><button type="submit">Pay with JazzCash</button></noscript>'
            . '</form></body></html>';

        return PaymentResult::form($html, $txnRef, ['pp_Amount' => $amountPaisa]);
    }

    public function handleCallback(array $payload): PaymentResult
    {
        $salt = (string) $this->cred('integrity_salt');
        $given = (string) ($payload['pp_SecureHash'] ?? '');
        $txnRef = (string) ($payload['pp_TxnRefNo'] ?? '');

        // Re-compute the hash over the returned pp_ fields (excluding the hash).
        $fields = $payload;
        unset($fields['pp_SecureHash']);
        // Keep only pp_/ppmpf_ keys — JazzCash signs only those.
        $fields = array_filter($fields, fn ($k) => str_starts_with($k, 'pp_') || str_starts_with($k, 'ppmpf_'), ARRAY_FILTER_USE_KEY);
        $expected = $this->secureHash($fields, $salt);

        if ($given === '' || ! hash_equals($expected, strtoupper($given))) {
            return PaymentResult::failed('jazzcash_hash_invalid', $payload);
        }

        $code = (string) ($payload['pp_ResponseCode'] ?? '');
        // 000 = success; 121 = already-paid/success on some flows.
        if (in_array($code, ['000', '121'], true)) {
            return PaymentResult::paid(
                gatewayPaymentId: (string) ($payload['pp_RetreivalReferenceNo'] ?? $txnRef),
                gatewayOrderId:   $txnRef,
                payload:          $payload,
            );
        }
        return PaymentResult::failed('jazzcash_code: ' . $code . ' ' . (string) ($payload['pp_ResponseMessage'] ?? ''), $payload);
    }

    public function handleWebhook(array $payload): PaymentResult
    {
        return $this->handleCallback($payload);
    }
}
