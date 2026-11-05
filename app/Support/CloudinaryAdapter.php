<?php

namespace App\Support;

use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use DateTimeInterface;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;

/**
 * Files are private ("authenticated" on Cloudinary) unless their path starts
 * with one of the public prefixes, such as profile photos. A private file is
 * only reachable through a signed download link that expires.
 */
class CloudinaryAdapter implements FilesystemAdapter, PublicUrlGenerator
{
    private const PRIVATE_TYPE = 'authenticated';

    private const PUBLIC_TYPE = 'upload';

    /** How long the link from url() lasts for a private file. */
    private const PRIVATE_URL_MINUTES = 30;

    private UploadApi $uploadApi;

    private string $baseUrl;

    /**
     * @param  list<string>  $publicPrefixes
     * @param  UploadApi|null  $uploadApi  Passed in by tests; built from $cloudinary otherwise
     */
    public function __construct(private Cloudinary $cloudinary, private array $publicPrefixes = [], ?UploadApi $uploadApi = null)
    {
        $this->uploadApi = $uploadApi ?? new UploadApi($cloudinary->configuration);
        $this->baseUrl = 'https://res.cloudinary.com/'.$cloudinary->configuration->cloud->cloudName;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cld_');

        try {
            file_put_contents($tmpFile, $contents);
            $this->uploadToCloudinary($tmpFile, $path);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cld_');

        try {
            $target = fopen($tmpFile, 'wb');
            stream_copy_to_stream($contents, $target);
            fclose($target);
            $this->uploadToCloudinary($tmpFile, $path);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function read(string $path): string
    {
        $url = $this->buildUrl($path);

        $contents = @file_get_contents($url);

        if ($contents === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not fetch from Cloudinary.');
        }

        return $contents;
    }

    /**
     * @return resource
     */
    public function readStream(string $path): mixed
    {
        $url = $this->buildUrl($path);

        $stream = @fopen($url, 'rb');

        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not fetch from Cloudinary.');
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        $publicId = $this->pathToPublicId($path);
        $resourceType = $this->detectResourceType($path);

        try {
            $result = $this->uploadApi->destroy($publicId, ['resource_type' => $resourceType, 'type' => $this->deliveryType($path)]);

            // A private file uploaded before private delivery existed may
            // not have been moved yet (uploads:make-private).
            if (($result['result'] ?? null) === 'not found' && ! $this->isPublic($path)) {
                $this->uploadApi->destroy($publicId, ['resource_type' => $resourceType, 'type' => self::PUBLIC_TYPE]);
            }
        } catch (\Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->cloudinary->adminApi()->deleteAssetsByPrefix($path);
        } catch (\Throwable) {
        }
    }

    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void {}

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, $this->isPublic($path) ? 'public' : 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeMap = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
            'ogg' => 'audio/ogg', 'aac' => 'audio/aac', 'webm' => 'audio/webm',
        ];

        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        return new FileAttributes($path, null, null, null, $mime);
    }

    public function lastModified(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::lastModified($path, 'Not supported by Cloudinary adapter.');
    }

    public function fileSize(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::fileSize($path, 'Not supported by Cloudinary adapter.');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (\Throwable $e) {
            throw UnableToMoveFile::because($e->getMessage(), $source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $dstPublicId = $this->pathToPublicId($destination);
        $resourceType = $this->detectResourceType($source);

        try {
            $this->uploadApi->upload($this->buildUrl($source), [
                'public_id' => $dstPublicId,
                'resource_type' => $resourceType,
                'type' => $this->deliveryType($destination),
                'overwrite' => true,
            ]);
        } catch (\Throwable $e) {
            throw UnableToCopyFile::because($e->getMessage(), $source, $destination, $e);
        }
    }

    public function fileExists(string $path): bool
    {
        $publicId = $this->pathToPublicId($path);
        $resourceType = $this->detectResourceType($path);

        $types = $this->isPublic($path) ? [self::PUBLIC_TYPE] : [self::PRIVATE_TYPE, self::PUBLIC_TYPE];

        foreach ($types as $type) {
            try {
                $this->cloudinary->adminApi()->asset($publicId, ['resource_type' => $resourceType, 'type' => $type]);

                return true;
            } catch (\Throwable) {
            }
        }

        return false;
    }

    public function directoryExists(string $path): bool
    {
        return true;
    }

    public function getUrl(string $path): string
    {
        return $this->buildUrl($path);
    }

    public function publicUrl(string $path, Config $config): string
    {
        return $this->buildUrl($path);
    }

    /**
     * Called by Storage::temporaryUrl(). A public file's link never expires.
     */
    public function getTemporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        if ($this->isPublic($path)) {
            return $this->buildUrl($path);
        }

        return $this->signedDownloadUrl($path, $expiration);
    }

    public function isPublic(string $path): bool
    {
        foreach ($this->publicPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moves files uploaded as public before private delivery existed. A file
     * that cannot be moved is reported and the rest carry on.
     *
     * @param  list<string>  $prefixes  Folders to move, such as "driver-documents/"
     * @return array{moved: list<string>, failed: array<string, string>} Paths moved (or, on a dry run, that would be moved), and failures by path
     */
    public function movePublicFilesToPrivate(array $prefixes, bool $dryRun = false): array
    {
        $moved = [];
        $failed = [];

        foreach ($prefixes as $prefix) {
            if ($this->isPublic($prefix)) {
                continue;
            }

            foreach (['image', 'video', 'raw'] as $resourceType) {
                $cursor = null;

                do {
                    $page = $this->cloudinary->adminApi()->assets(array_filter([
                        'resource_type' => $resourceType,
                        'type' => self::PUBLIC_TYPE,
                        'prefix' => $prefix,
                        'max_results' => 500,
                        'next_cursor' => $cursor,
                    ]));

                    foreach ($page['resources'] ?? [] as $asset) {
                        $publicId = $asset['public_id'];
                        $path = $resourceType === 'raw' ? $publicId : $publicId.'.'.$asset['format'];

                        if (! $dryRun) {
                            try {
                                // invalidate clears the old public link from the CDN cache.
                                $this->uploadApi->rename($publicId, $publicId, [
                                    'resource_type' => $resourceType,
                                    'type' => self::PUBLIC_TYPE,
                                    'to_type' => self::PRIVATE_TYPE,
                                    'invalidate' => true,
                                ]);
                            } catch (\Throwable $e) {
                                $failed[$path] = $e->getMessage();

                                continue;
                            }
                        }

                        $moved[] = $path;
                    }

                    $cursor = $page['next_cursor'] ?? null;
                } while ($cursor);
            }
        }

        return ['moved' => $moved, 'failed' => $failed];
    }

    private function deliveryType(string $path): string
    {
        return $this->isPublic($path) ? self::PUBLIC_TYPE : self::PRIVATE_TYPE;
    }

    private function signedDownloadUrl(string $path, DateTimeInterface $expiration): string
    {
        $resourceType = $this->detectResourceType($path);
        $format = $resourceType === 'raw' ? '' : strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $this->uploadApi->privateDownloadUrl($this->pathToPublicId($path), $format, [
            'resource_type' => $resourceType,
            'type' => self::PRIVATE_TYPE,
            'expires_at' => $expiration->getTimestamp(),
        ]);
    }

    private function buildUrl(string $path): string
    {
        if (! $this->isPublic($path)) {
            return $this->signedDownloadUrl($path, now()->addMinutes(self::PRIVATE_URL_MINUTES));
        }

        $publicId = $this->pathToPublicId($path);
        $resourceType = $this->detectResourceType($path);

        if ($resourceType === 'raw') {
            return "{$this->baseUrl}/{$resourceType}/upload/{$publicId}";
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return "{$this->baseUrl}/{$resourceType}/upload/{$publicId}.{$ext}";
    }

    private function uploadToCloudinary(string $filePath, string $storagePath): void
    {
        $publicId = $this->pathToPublicId($storagePath);
        $resourceType = $this->detectResourceType($storagePath);

        try {
            $this->uploadApi->upload($filePath, [
                'public_id' => $publicId,
                'resource_type' => $resourceType,
                'type' => $this->deliveryType($storagePath),
                'overwrite' => true,
                'use_filename' => false,
                'unique_filename' => false,
            ]);
        } catch (\Throwable $e) {
            throw UnableToWriteFile::atLocation($storagePath, $e->getMessage(), $e);
        }
    }

    private function pathToPublicId(string $path): string
    {
        if ($this->detectResourceType($path) === 'raw') {
            return $path;
        }

        return pathinfo($path, PATHINFO_FILENAME)
            ? preg_replace('/\.[^.]+$/', '', $path)
            : $path;
    }

    private function detectResourceType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (\in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff'])) {
            return 'image';
        }

        if (\in_array($ext, ['mp3', 'm4a', 'wav', 'ogg', 'webm', 'aac', 'mp4', 'avi', 'mov'])) {
            return 'video';
        }

        return 'raw';
    }
}
