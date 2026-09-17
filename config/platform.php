<?php

return [
    'host' => env('PLATFORM_HOST', 'admin.banjar.localhost'),
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', 'banjar.localhost'),
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
