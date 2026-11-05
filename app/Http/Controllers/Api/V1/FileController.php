<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\UploadLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a private upload through its authenticated link (see UploadLinks).
 * The file is streamed through the API, so the storage address is never
 * handed out.
 */
class FileController extends Controller
{
    public function show(Request $request, string $ref): Response
    {
        $path = UploadLinks::pathFor($ref, $request->user());

        if ($path === null || UploadLinks::isPublic($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $disk = Storage::disk(config('filesystems.uploads'));

        try {
            $stream = $disk->readStream($path);
        } catch (\Throwable) {
            $stream = null;
        }

        if (! $stream) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        try {
            $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        } catch (\Throwable) {
            $mime = 'application/octet-stream';
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            // The link never changes for this user and the file behind it is
            // never edited in place, so the apps may cache it.
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
