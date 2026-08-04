<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class RecaptchaVerifier
{
    public static function verify(?string $token): bool
    {
        if (! filled($token)) {
            return false;
        }

        $secret = Setting::query()->value('google_recaptcha_secret');

        if (! filled($secret)) {
            return false;
        }

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
        ]);

        if (! $response->ok()) {
            return false;
        }

        return (bool) $response->json('success');
    }
}
