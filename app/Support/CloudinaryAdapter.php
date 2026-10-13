<?php

namespace App\Support;

use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
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

class CloudinaryAdapter implements FilesystemAdapter, PublicUrlGenerator
{
    private UploadApi $uploadApi;

    private string $baseUrl;

    private string $apiSecret;

    private static array $sensitivePathPrefixes = ['disputes/', 'driver-documents/'];

    public function __construct(private Cloudinary $cloudinary)
    {
        $this->uploadApi = new UploadApi($cloudinary->configuration);
        $this->baseUrl = 'https://res.cloudinary.com/'.$cloudinary->configuration->cloud->cloudName;
        $this->apiSecret = $cloudinary->configuration->cloud->apiSecret;
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
            $options = ['resource_type' => $resourceType];

            if ($this->isSensitivePath($path)) {
                $options['type'] = 'authenticated';
            }

            $this->uploadApi->destroy($publicId, $options);
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
        return new FileAttributes($path, null, 'public');
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
            $options = [
                'public_id' => $dstPublicId,
                'resource_type' => $resourceType,
                'overwrite' => true,
            ];

            if ($this->isSensitivePath($destination)) {
                $options['type'] = 'authenticated';
            }

            $this->uploadApi->upload($this->buildUrl($source), $options);
        } catch (\Throwable $e) {
            throw UnableToCopyFile::because($e->getMessage(), $source, $destination, $e);
        }
    }

    public function fileExists(string $path): bool
    {
        $publicId = $this->pathToPublicId($path);
        $resourceType = $this->detectResourceType($path);

        try {
            $options = ['resource_type' => $resourceType];

            if ($this->isSensitivePath($path)) {
                $options['type'] = 'authenticated';
            }

            $this->cloudinary->adminApi()->asset($publicId, $options);

            return true;
        } catch (\Throwable) {
            return false;
        }
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

    private function buildUrl(string $path): string
    {
        $publicId = $this->pathToPublicId($path);
        $resourceType = $this->detectResourceType($path);
        $isRaw = $resourceType === 'raw';

        if ($this->isSensitivePath($path)) {
            $timestamp = time();
            $toSign = "public_id={$publicId}&timestamp={$timestamp}{$this->apiSecret}";
            $signature = sha1($toSign);

            if ($isRaw) {
                return "{$this->baseUrl}/{$resourceType}/authenticated/s--".substr(base64_encode(hex2bin($signature)), 0, 8)."--/{$publicId}";
            }

            $ext = pathinfo($path, PATHINFO_EXTENSION);

            return "{$this->baseUrl}/{$resourceType}/authenticated/s--".substr(base64_encode(hex2bin($signature)), 0, 8)."--/{$publicId}.{$ext}";
        }

        if ($isRaw) {
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
            $options = [
                'public_id' => $publicId,
                'resource_type' => $resourceType,
                'overwrite' => true,
                'use_filename' => false,
                'unique_filename' => false,
            ];

            if ($this->isSensitivePath($storagePath)) {
                $options['type'] = 'authenticated';
            }

            $this->uploadApi->upload($filePath, $options);
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

    private function isSensitivePath(string $path): bool
    {
        foreach (self::$sensitivePathPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
