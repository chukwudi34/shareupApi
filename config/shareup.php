<?php

return [
    'payment_provider' => env('SHAREUP_PAYMENT_PROVIDER', 'SANDBOX'),

    'sandbox' => [
        'secret' => env('SHAREUP_SANDBOX_SECRET', env('APP_KEY', 'shareup-sandbox')),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'defaults' => [
        'fee_bps' => (int) env('SHAREUP_FEE_BPS', 350),
        'reservation_minutes' => (int) env('SHAREUP_RESERVATION_MINUTES', 30),
        'partial_funding_threshold' => 0.8,
    ],
];
