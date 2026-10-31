<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class MediaUrl
{
    public static function public(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (filter_var($path, FILTER_VALIDATE_URL) && in_array(parse_url($path, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
