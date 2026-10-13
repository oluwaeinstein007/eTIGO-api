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
    private Cloudinary $cloudinary;

    private UploadApi $uploadApi;

    private string $baseUrl;

    public function __construct(Cloudinary $cloudinary)
    {
        $this->cloudinary = $cloudinary;
        $this->uploadApi = new UploadApi($cloudinary->configuration);
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
        $url = $this->publicUrl($path, new Config);

        $contents = @file_get_contents($url);

        if ($contents === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not fetch from Cloudinary.');
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $url = $this->publicUrl($path, new Config);

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
            $this->uploadApi->destroy($publicId, ['resource_type' => $resourceType]);
        } catch (\Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->cloudinary->adminApi()->deleteAssetsByPrefix($path);
        } catch (\Throwable) {
            // Cloudinary does not have real directories
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Cloudinary does not require directory creation
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Cloudinary manages visibility through delivery types
    }

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
        $srcPublicId = $this->pathToPublicId($source);
        $dstPublicId = $this->pathToPublicId($destination);
        $resourceType = $this->detectResourceType($source);

        try {
            $this->uploadApi->upload(
                $this->publicUrl($source, new Config),
                [
                    'public_id' => $dstPublicId,
                    'resource_type' => $resourceType,
                    'overwrite' => true,
                ]
            );
        } catch (\Throwable $e) {
            throw UnableToCopyFile::because($e->getMessage(), $source, $destination, $e);
        }
    }

    public function fileExists(string $path): bool
    {
        $publicId = $this->pathToPublicId($path);
        $resourceType = $this->detectResourceType($path);

        try {
            $this->cloudinary->adminApi()->asset($publicId, ['resource_type' => $resourceType]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        return true;
    }

    public function publicUrl(string $path, Config $config): string
    {
        $publicId = $this->pathToPublicId($path);
        $resourceType = $this->detectResourceType($path);
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
        return pathinfo($path, PATHINFO_FILENAME)
            ? preg_replace('/\.[^.]+$/', '', $path)
            : $path;
    }

    private function detectResourceType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff'])) {
            return 'image';
        }

        if (in_array($ext, ['mp3', 'm4a', 'wav', 'ogg', 'webm', 'aac', 'mp4', 'avi', 'mov'])) {
            return 'video';
        }

        return 'raw';
    }
}
