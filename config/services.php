<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],


'enom' => [
    'url' => env('ENOM_API_URL'),
    'uid' => env('ENOM_UID'),
    'password' => env('ENOM_PASSWORD'),
    'currency' => env('ENOM_CURRENCY', 'USD'),
],

'domain_lookup' => [
    'providers' => array_filter(
        array_map('trim', explode(',', env(
            'DOMAIN_AVAILABILITY_PROVIDERS',
            'enom,webnic'
        )))
    ),

    'price_markup_percent' => (float) env(
        'DOMAIN_PRICE_MARKUP_PERCENT',
        20
    ),
],

'exchange_rate' => [
    'url' => env(
        'EXCHANGE_RATE_API_URL',
        'https://open.er-api.com/v6/latest'
    ),
],

];
