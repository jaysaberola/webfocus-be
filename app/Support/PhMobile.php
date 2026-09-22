<?php

namespace App\Support;

class PhMobile
{
    public static function normalize(?string $raw): ?string
    {
        $text = trim((string) $raw);
        $compact = preg_replace('/[^\d+]/', '', $text) ?? '';
        if (str_contains($compact, '+')) {
            $compact = (str_starts_with($compact, '+') ? '+' : '') . str_replace('+', '', $compact);
        }

        $digits = preg_replace('/\D+/', '', $compact);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($compact, '+') && !preg_match('/^\+639\d{9}$/', $compact)) {
            return null;
        }

        $local = $digits;
        if (str_starts_with($local, '63') && strlen($local) === 12) {
            $local = substr($local, 2);
        } elseif (str_starts_with($local, '0') && strlen($local) === 11) {
            $local = substr($local, 1);
        }

        if (strlen($digits) === 11 && !preg_match('/^09\d{9}$/', $digits)) {
            return null;
        }

        if (!preg_match('/^9\d{9}$/', $local)) {
            return null;
        }

        return '+63' . $local;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    public static function rule(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:20',
            function (string $attribute, mixed $value, \Closure $fail) use ($required) {
                $text = trim((string) $value);
                if ($text === '') {
                    if ($required) {
                        $fail('Mobile number is required.');
                    }
                    return;
                }
                if (preg_match('/[a-zA-Z]/', $text)) {
                    $fail('Mobile number cannot contain letters.');
                    return;
                }
                if (!self::isValid($text)) {
                    $fail('Mobile number must be 11 digits (09XXXXXXXXX) or +639XXXXXXXXX.');
                }
            },
        ];
    }
}
