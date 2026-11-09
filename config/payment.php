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
    // Biaya layanan aplikasi (tarif jasa aplikasi, bukan biaya QRIS yang dioper ke warga — §29.3). Bertingkat menurut
    // total tagihan yang dibayar sekaligus: "batas:biaya" (total ≤ batas → biaya). Di atas batas terakhir: persen dari
    // total (dibulatkan ke atas per rupiah). Banjar menerima nilai tagihan utuh; biaya layanan dikurangi biaya iPaymu
    // adalah pendapatan platform. Tingkatan disusun agar selalu ≥ biaya iPaymu 0,7% dari total yang dibayar.
    'service_fee_tiers' => env('PAYMENT_SERVICE_FEE_TIERS', '10000:500,50000:1000,200000:2000,500000:5000'),
    'service_fee_above_percent' => (float) env('PAYMENT_SERVICE_FEE_ABOVE_PERCENT', 1),
    // Paling banyak tagihan dalam satu QRIS.
    'max_bills' => 50,
    // Nilai tagihan ditahan sekian hari setelah lunas sebelum bisa dicairkan Banjar (jaga-jaga pembatalan/refund).
    // Kosong = ditahan sampai kebijakan ditetapkan.
    'settlement_hold_days' => env('PAYMENT_SETTLEMENT_HOLD_DAYS', 2) === '' ? null : env('PAYMENT_SETTLEMENT_HOLD_DAYS', 2),
];
