<?php

namespace App\Modules\Payment;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MidtransPaymentGateway implements PaymentGatewayInterface
{
    private function client(object $payment): PendingRequest
    {
        if (! config('payment.server_key') || $payment->production !== (int) (bool) config('payment.production') || $payment->merchant_id !== config('payment.merchant_id')) {
            throw new RuntimeException('Gateway configuration unavailable.');
        }

        return Http::baseUrl($payment->production ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2')
            ->withBasicAuth(config('payment.server_key'), '')->acceptJson()->asJson()->connectTimeout(5)->timeout(15)
            ->withoutRedirecting();
    }

    public function createQrisPayment(object $payment): array
    {
        // Never automatically retry a charge after a timeout.
        $response = $this->client($payment)->post('/charge', ['payment_type' => 'qris', 'qris' => ['acquirer' => 'gopay'],
            'transaction_details' => ['order_id' => $payment->order_id, 'gross_amount' => (int) $payment->gross_amount]]);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Charge outcome unknown.');
        }

        return $response->json();
    }

    public function getStatus(object $payment): array
    {
        $response = $this->client($payment)->get('/'.rawurlencode($payment->order_id).'/status');
        // 404 can be transient after charge; it never permits a new attempt.
        if (! $response->successful() || ! is_array($response->json()) || ($response->json('status_code') === '404')) {
            throw new RuntimeException('Status unavailable.');
        }

        return $response->json();
    }

    public function verifyNotification(array $payload): bool
    {
        if (! config('payment.server_key')) {
            return false;
        }
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                return false;
            }
        }

        return hash_equals(hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('payment.server_key')), $payload['signature_key']);
    }
}
