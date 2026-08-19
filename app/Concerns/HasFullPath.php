<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared `full_path` accessor for every model that exposes an image to the API.
 *
 * Storage::url() is not safe to call blindly on these columns:
 *   - Storage::url(null) returns the *non-empty* string "<APP_URL>/storage/", so the
 *     frontend can't tell "no image" from a real path and renders a broken <img>.
 *   - Supplier-imported rows store the supplier's own absolute URL, and Laravel's
 *     FilesystemAdapter::concatPathToUrl() concatenates blindly, producing
 *     "<APP_URL>/storage/https://supplier.com/x.webp" — a guaranteed 404.
 *
 * The shape stays `{"image": …}` with a nullable value rather than collapsing to null,
 * because several frontend call sites dereference `full_path.image` without optional
 * chaining and a null object would throw and crash the page render.
 */
trait HasFullPath
{
    public function getFullPathAttribute(): array
    {
        return ['image' => self::publicImageUrl($this->image)];
    }

    /**
     * Public URL for a stored image reference:
     *   empty/null       → null   (callers render their placeholder)
     *   absolute URL     → as-is  (supplier-hosted image)
     *   local disk path  → Storage::url()
     */
    public static function publicImageUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return $path;
        }

        return Storage::url($path);
    }
}
