<?php

namespace App\Services\Payment\Drivers;

use App\Models\Order;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Facades\Http;

/**
 * Paytm payment gateway driver.
 *
 * Creates a transaction token via the Paytm Business API, then redirects
 * to the Paytm-hosted payment page. Callback uses AES-128-CBC checksum
 * verification.
 *
 * @see https://business.paytm.com/docs/api/initiate-transaction-api/
 */
class PaytmDriver extends AbstractGatewayDriver
{
    // Paytm migrated off *.paytm.in to *.paytmpayments.com (the old
    // business.paytm.com docs URL now 301s to paytmpayments.com, and the old
    // secure hosts are being retired). Use the new hosts for both prod + staging.
    //   OLD: https://securegw.paytm.in / https://securegw-stage.paytm.in
    //   NEW: https://secure.paytmpayments.com / https://securestage.paytmpayments.com
    private const STAGING_BASE = 'https://securestage.paytmpayments.com';
    private const PROD_BASE    = 'https://secure.paytmpayments.com';

    public static function credentialFields(): array
    {
        return [
            'merchant_id'  => ['label' => 'Merchant ID',  'type' => 'text',     'required' => true],
            'merchant_key' => ['label' => 'Merchant Key', 'type' => 'password', 'required' => true],
            'website'      => ['label' => 'Website',      'type' => 'text',     'required' => true],
        ];
    }

    public function initiate(Order $order, string $callbackUrl): PaymentResult
    {
        $merchantId  = (string) $this->cred('merchant_id');
        $merchantKey = (string) $this->cred('merchant_key');
        $website     = (string) $this->cred('website');
        if ($merchantId === '' || $merchantKey === '' || $website === '') {
            return PaymentResult::failed('paytm_credentials_missing');
        }

        // Paytm orderId must be alphanumeric + underscore only. order_number can
        // carry a hyphen (e.g. "BK-XXXX"); strip anything else so the id stays
        // compliant and matches the bytes we sign.
        $cleanOrderNumber = preg_replace('/[^A-Za-z0-9_]/', '', (string) $order->order_number);
        $orderId = 'PAYTM_' . $cleanOrderNumber . '_' . time();

        // Field order MATCHES Paytm's official cURL sample exactly (requestType,
        // mid, websiteName, orderId, txnAmount, userInfo, callbackUrl). Paytm
        // re-serialises the body in receive order before recomputing the hash —
        // a different order signs different bytes → "checksum invalid".
        $body = [
            'requestType' => 'Payment',
            'mid'         => $merchantId,
            'websiteName' => $website,
            'orderId'     => $orderId,
            'txnAmount'   => [
                'value'    => number_format((float) $order->amount, 2, '.', ''),
                'currency' => strtoupper($order->currency ?? 'INR'),
            ],
            'userInfo' => [
                'custId' => (string) ($order->user_id ?? 'GUEST'),
            ],
            'callbackUrl' => $callbackUrl,
        ];

        // CRITICAL: JSON_UNESCAPED_SLASHES on BOTH the signed body and the wire
        // body. Default json_encode escapes slashes (https:\/\/…); Paytm's server
        // reconstructs the body with UNescaped slashes before recomputing the
        // hash, so escaped bytes → checksum never matches → "501 System Error".
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $checksum = $this->generateChecksum($bodyJson, $merchantKey);

        $envelope = ['body' => $body, 'head' => ['signature' => $checksum]];
        $wireBody = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $url = $this->baseUrl() . "/theia/api/v1/initiateTransaction?mid={$merchantId}&orderId={$orderId}";

        try {
            // Send the EXACT pre-encoded bytes we signed — withBody(), NOT asJson()
            // which would re-encode with escaped slashes and break the checksum.
            $r = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->withBody((string) $wireBody, 'application/json')
                ->post($url);
            $json = $r->json() ?: [];
            $resultStatus = $json['body']['resultInfo']['resultStatus'] ?? '';
            $resultCode   = $json['body']['resultInfo']['resultCode'] ?? '';
            $txnToken     = $json['body']['txnToken'] ?? null;

            // Success indicator per Paytm docs: resultStatus = 'S' (resultCode is
            // '0000'/'0002', NOT 'S'). The old check `$resultCode === 'S'` treated
            // every successful response as a failure and returned resultMsg
            // ("Success") as the error — the "Payment failed: paytm: Success" bug.
            if (($resultStatus === 'S' || in_array($resultCode, ['0000', '0002'], true)) && $txnToken) {
                $redirectUrl = $this->baseUrl() . "/theia/api/v1/showPaymentPage?mid={$merchantId}&orderId={$orderId}&txnToken={$txnToken}";
                return PaymentResult::redirect($redirectUrl, $orderId, $json);
            }
            return PaymentResult::failed('paytm: ' . ($json['body']['resultInfo']['resultMsg'] ?? 'init_failed'));
        } catch (\Throwable $e) {
            return PaymentResult::failed('paytm_exception: ' . $e->getMessage());
        }
    }

    public function handleCallback(array $payload): PaymentResult
    {
        $orderId     = $payload['ORDERID'] ?? null;
        $bankTxnId   = $payload['BANKTXNID'] ?? null;
        $status      = $payload['STATUS'] ?? '';
        $checksumStr = $payload['CHECKSUMHASH'] ?? '';

        if (!$orderId) return PaymentResult::failed('missing_paytm_order_id');

        $merchantKey = (string) $this->cred('merchant_key');
        $paramsToVerify = $payload;
        unset($paramsToVerify['CHECKSUMHASH']);

        if (!$checksumStr || !$this->verifyChecksum($paramsToVerify, $merchantKey, $checksumStr)) {
            return PaymentResult::failed('paytm_checksum_invalid');
        }

        // Cross-verify via Transaction Status API
        $result = $this->queryTransactionStatus($orderId);
        if ($result !== null) return $result;

        if ($status === 'TXN_SUCCESS') {
            return PaymentResult::paid(
                gatewayPaymentId: (string) ($bankTxnId ?? $orderId),
                gatewayOrderId:   (string) $orderId,
                payload:          $payload,
            );
        }
        return PaymentResult::failed('paytm: ' . ($payload['RESPMSG'] ?? "status: {$status}"), $payload);
    }

    public function verify(Order $order): PaymentResult
    {
        $orderId = $order->gateway_order_id;
        if (!$orderId) return PaymentResult::failed('no_order_id');
        return $this->queryTransactionStatus((string) $orderId) ?? PaymentResult::failed('paytm_verify_failed');
    }

    private function queryTransactionStatus(string $orderId): ?PaymentResult
    {
        $merchantId  = (string) $this->cred('merchant_id');
        $merchantKey = (string) $this->cred('merchant_key');

        $body     = ['mid' => $merchantId, 'orderId' => $orderId];
        // Same JSON_UNESCAPED_SLASHES + raw-body rule as initiate().
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $checksum = $this->generateChecksum((string) $bodyJson, $merchantKey);
        $envelope = ['body' => $body, 'head' => ['signature' => $checksum]];
        $wireBody = json_encode($envelope, JSON_UNESCAPED_SLASHES);

        try {
            $r = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->acceptJson()
                ->withBody((string) $wireBody, 'application/json')
                ->post($this->baseUrl() . '/v3/order/status');
            $json = $r->json() ?: [];
            $resultCode   = $json['body']['resultInfo']['resultCode'] ?? '';
            $resultStatus = $json['body']['resultInfo']['resultStatus'] ?? '';

            if ($resultCode === '01' || $resultStatus === 'TXN_SUCCESS') {
                return PaymentResult::paid(
                    gatewayPaymentId: (string) ($json['body']['txnId'] ?? $orderId),
                    gatewayOrderId:   $orderId,
                    payload:          $json,
                );
            }
            if ($resultStatus === 'PENDING') {
                return new PaymentResult(status: 'pending', gatewayOrderId: $orderId, payload: $json);
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function baseUrl(): string
    {
        return $this->isLive() ? self::PROD_BASE : self::STAGING_BASE;
    }

    /**
     * Paytm checksum algorithm (port of Paytm PHP SDK):
     *   sha256(body|salt) + salt, then AES-128-CBC encrypt with fixed IV.
     */
    private function generateChecksum(string $body, string $key): string
    {
        // Paytm's official SDK html_entity_decode()s the key before AES (a no-op
        // for plain keys; the real fix for keys that picked up HTML entities on
        // the storage path). Match it in both directions.
        $key        = html_entity_decode($key, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $salt       = substr(bin2hex(random_bytes(4)), 0, 4);
        $hash       = hash('sha256', $body . '|' . $salt);
        $hashString = $hash . $salt;
        $iv         = '@@@@&&&&####$$$$';
        $encrypted  = openssl_encrypt($hashString, 'AES-128-CBC', $key, 0, $iv);
        if ($encrypted === false) throw new \RuntimeException('paytm_aes_failed');
        return $encrypted;
    }

    private function verifyChecksum(array $params, string $key, string $checksum): bool
    {
        $key = html_entity_decode($key, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $iv = '@@@@&&&&####$$$$';
        $decrypted = openssl_decrypt($checksum, 'AES-128-CBC', $key, 0, $iv);
        if ($decrypted === false || strlen($decrypted) < 68) return false;

        $providedHash = substr($decrypted, 0, 64);
        $salt         = substr($decrypted, 64);

        ksort($params);
        // Official Paytm SDK coerces null and the literal string "null" to ''
        // before joining the params — without this, any callback field that
        // arrives null shifts the joined string and verification fails for a
        // legitimate response. Mirror that behaviour exactly.
        $params = array_map(
            fn ($v) => ($v !== null && strtolower((string) $v) !== 'null') ? $v : '',
            $params
        );
        $expectedHash = hash('sha256', implode('|', $params) . '|' . $salt);
        return hash_equals($expectedHash, $providedHash);
    }
}
