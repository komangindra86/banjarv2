<?php

namespace App\Modules\Notification;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// Mengirim notifikasi warga di antrean agar tombol Simpan/Terbitkan pengurus tidak menunggu ratusan penerima.
class NotifyWarga implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param 'content'|'bills' $kind */
    public function __construct(public string $kind, public int $banjarId, public ?int $id = null) {}

    public function handle(WargaNotifier $notifier): void
    {
        match ($this->kind) {
            'content' => $notifier->publishDue($this->banjarId),
            'bills' => $notifier->billsIssued($this->banjarId, (int) $this->id),
        };
    }
}
