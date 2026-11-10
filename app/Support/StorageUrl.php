<?php

namespace App\Support;

class StorageUrl
{
    public static function publicAsset(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return self::rewriteStorageUrlOnAppHost($path);
        }

        $relative = StorageAssetUrl::publicDisk($path);
        if (!$relative) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').$relative;
    }

    /** Fix legacy URLs when APP_URL was the domain root (missing …/webfocus-be/public). */
    private static function rewriteStorageUrlOnAppHost(string $url): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') {
            return $url;
        }

        $marker = '/storage/';
        $pos = stripos($url, $marker);
        if ($pos === false) {
            return $url;
        }

        $storagePath = substr($url, $pos + strlen($marker));

        return $appUrl.$marker.ltrim($storagePath, '/');
    }
}
