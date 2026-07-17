<?php

namespace App\Support;

class StorageAssetUrl
{
    public static function publicDisk(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', (string) $path), '/');

        return '/storage/' . $normalized;
    }
}
