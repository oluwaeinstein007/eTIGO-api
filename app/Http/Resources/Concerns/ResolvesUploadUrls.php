<?php

namespace App\Http\Resources\Concerns;

use App\Support\UploadLinks;

/**
 * Turns a path on the uploads disk into a link the apps can open: the
 * public URL for profile photos, else an authenticated API link that only
 * opens with the requesting user's token (see UploadLinks).
 */
trait ResolvesUploadUrls
{
    protected function resolveUploadUrl(string $path): ?string
    {
        return UploadLinks::url($path);
    }
}
