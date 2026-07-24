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

      'domain_lookup' => [
    'providers' => env('DOMAIN_AVAILABILITY_PROVIDERS', 'enom,webnic'),
],

'webnic' => [
    'base_url'   => env('WEBNIC_API_URL', 'https://api.webnic.cc'),
    'url'        => env('WEBNIC_API_URL', 'https://api.webnic.cc'),
    'token_url'  => env('WEBNIC_TOKEN_URL', 'https://api.webnic.cc/reseller/v2/api-user/token'),

    'username'   => env('WEBNIC_USERNAME', env('WEBNIC_API_KEY')),
    'password'   => env('WEBNIC_PASSWORD'),

    // Optional fallback if you still use old env names
    'api_key'    => env('WEBNIC_API_KEY'),
    'api_secret' => env('WEBNIC_API_SECRET'),
],

];
