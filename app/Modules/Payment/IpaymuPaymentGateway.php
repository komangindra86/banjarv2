<?php

namespace App\Modules\Payment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * iPaymu API v2 (akun Bali Santih, sama dengan aplikasi undangan): QRIS langsung dan pemeriksaan transaksi.
 * Tanda tangan: HMAC-SHA256 dari "POST:{va}:{sha256(body)}:{apiKey}" dengan kunci API key.
 * Biaya iPaymu ditanggung merchant (platform) dari biaya layanan yang dibayar warga.
 */
final class IpaymuPaymentGateway implements PaymentGatewayInterface
{
    /** Kode status transaksi iPaymu yang berarti uang sudah dibayar. */
    public const PAID_STATUSES = [1, 6];

    public static function configured(): bool
    {
        return filled(config('payment.ipaymu.va')) && filled(config('payment.ipaymu.api_key'));
    }

    public static function merchantId(): string
    {
        return 'IPAYMU-'.substr(hash('sha256', (string) config('payment.ipaymu.va')), 0, 16);
    }

    public static function production(): bool
    {
        return ! config('payment.ipaymu.sandbox');
    }

    public function createQrisPayment(object $payment, array $buyer): array
    {
        $this->sameAccount($payment);
        // Akun iPaymu dipakai bersama penjualan Bali Santih lain, jadi setiap label menyebut Banjar dan tagihannya.
        $data = $this->post('/payment/direct', [
            'product' => array_column($buyer['items'], 'name'),
            'qty' => array_fill(0, count($buyer['items']), 1),
            'price' => array_column($buyer['items'], 'price'),
            'name' => mb_substr($buyer['name'], 0, 100),
            'phone' => $buyer['phone'],
            'email' => $buyer['email'],
            'amount' => (int) $payment->gross_amount,
            'notifyUrl' => rtrim((string) config('app.url'), '/').'/webhooks/ipaymu',
            'referenceId' => $payment->order_id,
            'paymentMethod' => 'qris',
            'paymentChannel' => (string) config('payment.ipaymu.qris_channel'),
            'feeDirection' => 'MERCHANT',
            'expired' => (int) config('payment.ipaymu.expiry_hours'),
            'comments' => mb_substr($buyer['label'], 0, 255),
        ]);
        if (empty($data['TransactionId']) || empty($data['QrString'])) {
            throw new RuntimeException('Respons iPaymu tidak berisi TransactionId atau QrString.');
        }

        return ['transaction_id' => (string) $data['TransactionId'], 'qr_string' => (string) $data['QrString']];
    }

    public function getStatus(object $payment): array
    {
        $this->sameAccount($payment);
        if (! $payment->provider_transaction_id) {
            throw new RuntimeException('Transaksi iPaymu belum diketahui.');
        }
        $data = $this->post('/transaction', ['transactionId' => (string) $payment->provider_transaction_id, 'account' => (string) config('payment.ipaymu.va')]);
        $amount = $data['Amount'] ?? $data['Total'] ?? null;
        $fee = $data['Fee'] ?? null;

        return [
            'transaction_id' => (string) ($data['TransactionId'] ?? ''),
            'reference_id' => (string) ($data['ReferenceId'] ?? ''),
            'amount' => is_numeric($amount) ? (int) round((float) $amount) : -1,
            'status' => self::status((int) ($data['Status'] ?? 0)),
            'fee' => is_numeric($fee) ? (int) round((float) $fee) : null,
        ];
    }

    public static function status(int $code): string
    {
        if (in_array($code, self::PAID_STATUSES, true)) {
            return 'paid';
        }

        return match ($code) {
            0 => 'pending',
            -2 => 'expired',
            2 => 'cancelled',
            3 => 'refunded',
            4, 5 => 'failed',
            default => 'unknown',
        };
    }

    private function sameAccount(object $payment): void
    {
        if (! self::configured() || $payment->merchant_id !== self::merchantId() || (bool) $payment->production !== self::production()) {
            throw new RuntimeException('Konfigurasi iPaymu tidak sesuai dengan pembayaran ini.');
        }
    }

    private function post(string $path, array $body): array
    {
        $va = (string) config('payment.ipaymu.va');
        $apiKey = (string) config('payment.ipaymu.api_key');
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', 'POST:'.$va.':'.strtolower(hash('sha256', $json)).':'.$apiKey, $apiKey);
        // Pembuatan QRIS tidak pernah diulang otomatis setelah timeout (bisa menimbulkan tagihan ganda).
        $response = Http::acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->withHeaders(['va' => $va, 'signature' => $signature, 'timestamp' => now()->format('YmdHis')])
            ->withBody($json, 'application/json')
            ->post((self::production() ? 'https://my.ipaymu.com/api/v2' : 'https://sandbox.ipaymu.com/api/v2').$path);
        $payload = $response->json();
        if ($response->failed() || ! is_array($payload) || (int) ($payload['Status'] ?? 0) !== 200 || ! is_array($payload['Data'] ?? null)) {
            // Jangan bawa isi respons/rahasia ke pesan galat.
            throw new RuntimeException('iPaymu '.$path.' gagal (HTTP '.$response->status().').');
        }

        return $payload['Data'];
    }
}
