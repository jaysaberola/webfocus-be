<?php

return [
    'rpf_url' => env(
        'PAYNAMICS_RPF_URL',
        'https://api.payserv.net/v1/rpf/transactions/rpf'
    ),

    'merchant_id' => env('PAYNAMICS_MERCHANT_ID'),
    'merchant_key' => env('PAYNAMICS_MERCHANT_KEY'),
    'username' => env('PAYNAMICS_BASIC_AUTH_USERNAME'),
    'password' => env('PAYNAMICS_BASIC_AUTH_PASSWORD'),

    'currency' => env('PAYNAMICS_CURRENCY', 'PHP'),
    'notification_status' => env('PAYNAMICS_NOTIFICATION_STATUS', '1'),
    'notification_channel' => env('PAYNAMICS_NOTIFICATION_CHANNEL', '1'),
    'descriptor' => env('PAYNAMICS_DESCRIPTOR', 'WebFocus Solutions'),
    'timeout' => (int) env('PAYNAMICS_TIMEOUT', 30),

    /*
     * These must be public HTTPS URLs that Paynamics can reach.
     * When omitted, the named Laravel API routes are used.
     */
    'notification_url' => env('PAYNAMICS_NOTIFICATION_URL'),
    'response_url' => env('PAYNAMICS_RESPONSE_URL'),
    'cancel_url' => env('PAYNAMICS_CANCEL_URL'),

    /*
     * Browser destination after Paynamics returns to the Laravel API.
     * It may already contain a query string, such as ?tab=orders.
     */
    'frontend_return_url' => env(
        'PAYNAMICS_FRONTEND_RETURN_URL',
        env('FRONTEND_URL', 'http://localhost:3000') . '/public/dashboard?tab=orders'
    ),
];