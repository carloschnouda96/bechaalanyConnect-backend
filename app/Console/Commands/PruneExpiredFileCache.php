<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * The file cache removes an expired entry only when that key is read again. Rate-limit keys
 * include caller-chosen values, so most are never read twice and their files stay forever.
 */
class PruneExpiredFileCache extends Command
{
    protected $signature = 'cache:prune-expired';

    protected $description = 'Delete expired entries from the file cache';

    public function handle(): int
    {
        $store = Cache::store()->getStore();

        if (!$store instanceof \Illuminate\Cache\FileStore) {
            return self::SUCCESS;
        }

        $removed = 0;
        foreach (File::allFiles($store->getDirectory()) as $file) {
            // The first ten bytes of each entry are its expiry timestamp.
            $handle = @fopen($file->getPathname(), 'r');
            $expires = $handle ? fread($handle, 10) : false;
            if ($handle) {
                fclose($handle);
            }

            if ($expires !== false && ctype_digit($expires) && (int) $expires < time()) {
                $removed += @unlink($file->getPathname()) ? 1 : 0;
            }
        }

        $this->info("Removed {$removed} expired cache file(s).");

        return self::SUCCESS;
    }
}
