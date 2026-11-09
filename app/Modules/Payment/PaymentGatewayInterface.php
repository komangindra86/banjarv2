<?php

namespace App\Modules\Payment;

/**
 * Penyedia QRIS. Data dari penyedia dinormalkan agar PaymentManager tidak bergantung pada format penyedia:
 * - createQrisPayment(): ['transaction_id' => string, 'qr_string' => string]
 * - getStatus(): ['transaction_id' => string, 'reference_id' => string, 'amount' => int,
 *   'status' => 'paid'|'pending'|'expired'|'cancelled'|'refunded'|'failed'|'unknown', 'fee' => ?int]
 */
interface PaymentGatewayInterface
{
    public static function configured(): bool;

    /** Penanda akun penyedia (tanpa rahasia) untuk memastikan status dibaca dari akun yang sama dengan saat dibuat. */
    public static function merchantId(): string;

    public static function production(): bool;

    /** @param  array{label: string, items: list<array{name: string, price: int}>, name: string, phone: string, email: string}  $buyer */
    public function createQrisPayment(object $payment, array $buyer): array;

    public function getStatus(object $payment): array;
}
