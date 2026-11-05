<?php

return [
    'host' => env('PLATFORM_HOST', 'admin.banjar.localhost'),
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', 'localhost'),
    // §53: portal krama pusat untuk semua Banjar; Banjar ditentukan dari akun warga, bukan dari domain.
    'portal_host' => env('PORTAL_HOST', 'portal.banjar.localhost'),
    // Banjar demo untuk reviewer: dipulihkan ke snapshot setiap hari (demo:snapshot, demo:reset). Kosong = tidak ada.
    'demo_banjar_id' => env('DEMO_BANJAR_ID'),
    'modules' => [
        'WEBSITE' => 'Website', 'KRAMA' => 'Krama & KK',
        'ADMINISTRATION' => 'Administrasi', 'FINANCE' => 'Keuangan',
        'PAYMENT' => 'Pembayaran', 'ACTIVITY' => 'Kegiatan',
        'ASSET' => 'Aset', 'PORTAL' => 'Portal Krama', 'NOTIFICATION' => 'Notifikasi',
    ],
    'dependencies' => [
        'ADMINISTRATION' => ['KRAMA'], 'FINANCE' => ['KRAMA'],
        'PAYMENT' => ['FINANCE'], 'PORTAL' => ['KRAMA'], 'ACTIVITY' => ['KRAMA'],
    ],
];
