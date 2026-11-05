<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Links to uploaded files. Public uploads (profile photos) get the disk's
 * own URL. A private upload gets a stable API link, GET /api/v1/files/{ref},
 * that only opens with the token of the user it was issued to: the ref is
 * the path plus an HMAC of the user and the path, so it cannot be reused by
 * someone else or edited to point at another file.
 */
class UploadLinks
{
    public static function url(string $path, ?User $user = null): ?string
    {
        if (static::isPublic($path)) {
            return Storage::disk(config('filesystems.uploads'))->url($path);
        }

        $user ??= request()->user();

        if (! $user) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').'/api/v1/files/'.static::ref($path, $user);
    }

    public static function isPublic(string $path): bool
    {
        foreach ((array) config('filesystems.public_upload_prefixes', []) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function ref(string $path, User $user): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=').'.'.static::signature($path, $user);
    }

    /**
     * The path a ref points at, or null when it was not issued to this user.
     */
    public static function pathFor(string $ref, User $user): ?string
    {
        [$encoded, $signature] = array_pad(explode('.', $ref, 2), 2, '');
        $path = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($path === false || $path === '' || ! hash_equals(static::signature($path, $user), $signature)) {
            return null;
        }

        return $path;
    }

    private static function signature(string $path, User $user): string
    {
        return substr(hash_hmac('sha256', $user->getKey().'|'.$path, 'upload-links|'.config('app.key')), 0, 32);
    }
}
