<?php

namespace App\Console\Commands;

use App\Support\CloudinaryAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Files uploaded before private delivery existed are still public on
 * Cloudinary. This moves them to private delivery, so they open only through
 * the signed, expiring links the API hands out. Safe to run more than once.
 */
class MakeUploadsPrivateCommand extends Command
{
    protected $signature = 'uploads:make-private {--dry-run : List the files without moving them}';

    protected $description = 'Move existing private uploads (documents, evidence, photos) to private delivery on Cloudinary';

    /** The folders the app stores private files in. */
    private const PRIVATE_FOLDERS = [
        'driver-documents/',
        'vehicle-documents/',
        'vehicle-maintenance/',
        'repairs/',
        'disputes/',
        'lost-items/',
    ];

    public function handle(): int
    {
        $adapter = Storage::disk(config('filesystems.uploads'))->getAdapter();

        if (! $adapter instanceof CloudinaryAdapter) {
            $this->info('The uploads disk is not Cloudinary; nothing to move.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        ['moved' => $moved, 'failed' => $failed] = $adapter->movePublicFilesToPrivate(self::PRIVATE_FOLDERS, $dryRun);

        foreach ($moved as $path) {
            $this->line($path);
        }

        $this->info(sprintf($dryRun ? '%d file(s) would be made private.' : '%d file(s) made private.', count($moved)));

        foreach ($failed as $path => $error) {
            $this->error("Could not move {$path}: {$error}");
        }

        if ($failed !== []) {
            $this->error(count($failed).' file(s) are still public. Run this command again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
