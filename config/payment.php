<?php

return [
    'enabled' => env('MIDTRANS_ENABLED', false),
    'production' => env('MIDTRANS_PRODUCTION', false),
    'server_key' => env('MIDTRANS_SERVER_KEY'),
    'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
    // Null keeps funds pending until the platform has agreed a calendar-day policy.
    'settlement_hold_days' => env('PAYMENT_SETTLEMENT_HOLD_DAYS') === '' ? null : env('PAYMENT_SETTLEMENT_HOLD_DAYS'),
];
