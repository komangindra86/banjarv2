<?php

namespace App\Modules\Payment;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessPaymentReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $receiptId) {}

    public function handle(PaymentManager $manager): void
    {
        $manager->processReceipt($this->receiptId);
    }
}
