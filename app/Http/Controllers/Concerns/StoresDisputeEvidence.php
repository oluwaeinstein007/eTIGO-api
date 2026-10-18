<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\DisputeEvidenceType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Put dispute evidence files on the uploads disk under the ride, and remove
 * them again if the dispute change that needed them fails.
 */
trait StoresDisputeEvidence
{
    /**
     * @param  list<array{file: UploadedFile, caption?: ?string}>  $files
     * @return list<array{path: string, type: DisputeEvidenceType, mime: ?string, size: ?int, name: ?string, caption: ?string}>|null null when a file could not be stored
     */
    protected function storeEvidenceFiles(string $rideId, array $files): ?array
    {
        $disk = config('filesystems.uploads');
        $stored = [];

        foreach ($files as $entry) {
            $file = $entry['file'];
            $type = DisputeEvidenceType::fromMime($file->getMimeType());
            $folder = $type === DisputeEvidenceType::Audio ? "disputes/{$rideId}/audio" : "disputes/{$rideId}";
            $path = $file->store($folder, $disk);

            if ($path === false) {
                $this->deleteEvidenceFiles($stored);

                return null;
            }

            $stored[] = [
                'path' => $path,
                'type' => $type,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize() ?: null,
                'name' => mb_substr((string) $file->getClientOriginalName(), 0, 255) ?: null,
                'caption' => $entry['caption'] ?? null,
            ];
        }

        return $stored;
    }

    /**
     * @param  list<array{path: string}>  $stored
     */
    protected function deleteEvidenceFiles(array $stored): void
    {
        $disk = Storage::disk(config('filesystems.uploads'));

        foreach ($stored as $file) {
            rescue(fn () => $disk->delete($file['path']), report: false);
        }
    }
}
