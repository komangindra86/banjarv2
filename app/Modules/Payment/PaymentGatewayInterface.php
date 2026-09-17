<?php

namespace App\Modules\Payment;

interface PaymentGatewayInterface
{
    public function createQrisPayment(object $payment): array;

    public function getStatus(object $payment): array;

    public function verifyNotification(array $payload): bool;
}
