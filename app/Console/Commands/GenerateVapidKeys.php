<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

final class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Buat satu pasang kunci VAPID untuk notifikasi push; salin hasilnya ke .env sekali per instalasi.';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\RuntimeException $e) {
            $this->error('Gagal membuat kunci: '.$e->getMessage());
            $this->warn('Di Windows, set variabel lingkungan OPENSSL_CONF ke lokasi openssl.cnf PHP sebelum menjalankan perintah ini, misalnya extras/ssl/openssl.cnf pada folder instalasi PHP.');

            return self::FAILURE;
        }
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->warn('Simpan VAPID_PRIVATE_KEY sebagai rahasia. Mengganti kunci membatalkan seluruh langganan push yang tersimpan.');

        return self::SUCCESS;
    }
}
