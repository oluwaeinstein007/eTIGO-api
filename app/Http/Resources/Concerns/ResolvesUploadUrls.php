<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Turns a path on the uploads disk into a link the apps can open: a
 * 30-minute signed URL where the disk supports one, else its public URL.
 */
trait ResolvesUploadUrls
{
    protected function resolveUploadUrl(string $path): ?string
    {
        $uploadsDisk = Storage::disk(config('filesystems.uploads'));

        try {
            return $uploadsDisk->temporaryUrl($path, now()->addMinutes(30));
        } catch (\RuntimeException) {
            try {
                return $uploadsDisk->url($path);
            } catch (\RuntimeException) {
                // Ignore — fall through to local fallback
            }
        }

        $localDisk = Storage::disk('local');

        try {
            return $localDisk->temporaryUrl($path, now()->addMinutes(30));
        } catch (\RuntimeException) {
            return $localDisk->url($path);
        }
    }
}
