<?php

return [
    // QRIS warga lewat iPaymu (akun Bali Santih, sama dengan aplikasi undangan). Dinyalakan eksplisit per instalasi.
    'enabled' => (bool) env('PAYMENT_ENABLED', false),
    'ipaymu' => [
        'va' => env('IPAYMU_VA'),
        'api_key' => env('IPAYMU_API_KEY'),
        'sandbox' => (bool) env('IPAYMU_SANDBOX', true),
        'qris_channel' => env('IPAYMU_QRIS_CHANNEL', 'mpm'),
        'expiry_hours' => (int) env('IPAYMU_QRIS_EXPIRY_HOURS', 24),
        // iPaymu mewajibkan e-mail dan telepon pembeli; warga tidak selalu punya keduanya.
        'fallback_email' => env('IPAYMU_FALLBACK_EMAIL', 'warga@banjar.balisantih.com'),
        'fallback_phone' => env('IPAYMU_FALLBACK_PHONE', '081000000000'),
    ],
    // Biaya layanan QRIS dibayar warga, persen dari nilai tagihan dibulatkan ke atas per rupiah. Banjar menerima
    // nilai tagihan utuh; biaya layanan dikurangi biaya iPaymu adalah pendapatan platform.
    'service_fee_percent' => (float) env('PAYMENT_SERVICE_FEE_PERCENT', 3.5),
    // Nilai tagihan ditahan sekian hari setelah lunas sebelum bisa dicairkan Banjar (jaga-jaga pembatalan/refund).
    // Kosong = ditahan sampai kebijakan ditetapkan.
    'settlement_hold_days' => env('PAYMENT_SETTLEMENT_HOLD_DAYS', 2) === '' ? null : env('PAYMENT_SETTLEMENT_HOLD_DAYS', 2),
];
