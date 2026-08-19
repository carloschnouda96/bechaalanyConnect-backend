<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves already-uploaded KYC documents and payment receipts off the public disk.
 *
 * KycController and CreditsController now write to the `private` disk, but every
 * file uploaded before that change still sits under storage/app/public/{kyc,receipts},
 * which is symlinked to public/storage and served by the webserver to anyone with
 * the URL. Those are government ID photos, selfies and bank screenshots.
 *
 * The database stores disk-relative paths ("kyc/abc.jpg"), so nothing in the
 * database changes — only which disk root the file lives under.
 *
 * Run AFTER deploying the code change, so the old public URLs stop resolving
 * immediately rather than during a window where new uploads are private but old
 * ones are still exposed.
 *
 *   php artisan uploads:privatize --dry-run   # report only
 *   php artisan uploads:privatize             # move
 */
class MigratePrivateUploads extends Command
{
    protected $signature = 'uploads:privatize
                            {--dry-run : List what would move without touching anything}';

    protected $description = 'Move KYC documents and payment receipts from the public disk to the private disk';

    /** Directories that must never be publicly served. */
    private const SENSITIVE_DIRECTORIES = ['kyc', 'receipts'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $public = Storage::disk('public');
        $private = Storage::disk('private');

        $moved = 0;
        $skipped = 0;
        $failed = 0;

        foreach (self::SENSITIVE_DIRECTORIES as $directory) {
            if (!$public->exists($directory)) {
                $this->line("No public/{$directory} directory — nothing to do.");
                continue;
            }

            $files = $public->files($directory);
            $this->info(sprintf('%s: %d file(s) on the public disk.', $directory, count($files)));

            foreach ($files as $path) {
                if ($private->exists($path)) {
                    // Already migrated on a previous run; remove the public copy so
                    // the file stops being served, but never overwrite the private one.
                    if ($dryRun) {
                        $this->line("  [dry-run] would delete public duplicate: {$path}");
                    } else {
                        $public->delete($path);
                    }
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [dry-run] would move: {$path}");
                    $moved++;
                    continue;
                }

                try {
                    // Copy-then-delete rather than move, so a failure mid-way leaves
                    // the original in place instead of losing the document.
                    $private->put($path, $public->get($path));

                    if (!$private->exists($path)) {
                        throw new \RuntimeException('write verification failed');
                    }

                    $public->delete($path);
                    $moved++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("  failed: {$path} — {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d moved, %d already private, %d failed.',
            $dryRun ? '[dry-run] ' : '',
            $moved,
            $skipped,
            $failed
        ));

        if (!$dryRun && $failed === 0 && $moved > 0) {
            $this->warn('Verify a KYC URL under /storage/kyc/ now returns 404 before considering this done.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
