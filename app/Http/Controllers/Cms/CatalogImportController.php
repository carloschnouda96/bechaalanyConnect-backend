<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Services\Cms\CatalogCsv;
use App\Services\Cms\CatalogImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk catalog editing.
 *
 * Adding a product by hand meant the Products page for the product, then the Products
 * Variations page for each of its variations, each in two languages, one record at a
 * time — and changing a batch of prices meant repeating that per variation. There was no
 * import, no export and no bulk edit anywhere in the CMS (composer.json carries no CSV
 * or spreadsheet package at all).
 *
 * Export and import share CatalogCsv's column definition, so the workflow is a closed
 * loop: download the catalog, edit it in a spreadsheet, upload it back, review the diff,
 * commit.
 *
 * Nothing is written until an admin has seen the diff. preview() plans and shows;
 * commit() re-reads the same file — verified by hash — and applies it.
 */
class CatalogImportController extends Controller
{
    /** Where an uploaded file waits between preview and commit. */
    private const STAGING_DIR = 'catalog-imports';

    public function index()
    {
        return view('cms::pages/catalog-import/index', [
            'header' => CatalogCsv::header(),
        ]);
    }

    /**
     * The whole catalog as CSV, in exactly the shape import expects.
     *
     * Streamed rather than built in memory: the catalog is small today, but an export
     * that falls over once the supplier syncs have run is an export nobody trusts.
     */
    public function export(): StreamedResponse
    {
        $header = CatalogCsv::header();
        $filename = 'catalog-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($header) {
            $out = fopen('php://output', 'w');

            // Excel will not read UTF-8 without the BOM, and this file is half Arabic.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');

            foreach (CatalogCsv::rows() as $row) {
                fputcsv($out, array_map(fn ($column) => $row[$column] ?? null, $header), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Parse and plan. Writes nothing to the catalog. */
    public function preview(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.mimes' => 'Upload a .csv file. Export the catalog first if you need the column layout.',
        ]);

        // Private disk: a catalog export carries cost prices, i.e. our margin on every
        // product. It must not sit under public/storage.
        $path = $request->file('file')->store(self::STAGING_DIR, 'private');
        $absolute = Storage::disk('private')->path($path);

        $parsed = CatalogCsv::parse($absolute);
        $plan = (new CatalogImporter())->plan($parsed['rows']);

        return view('cms::pages/catalog-import/preview', [
            'plan' => $plan,
            'errors_found' => $parsed['errors'],
            'path' => $path,
            'hash' => hash_file('sha256', $absolute),
            'summary' => $this->summarise($plan),
        ]);
    }

    /** Apply a previously previewed file. */
    public function commit(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'hash' => ['required', 'string'],
        ]);

        // Only a path this controller staged, and only the exact file that was
        // previewed. Without both checks, "commit" would be an arbitrary-file read and
        // an admin could approve one diff and apply another.
        if (!str_starts_with($data['path'], self::STAGING_DIR . '/') || str_contains($data['path'], '..')) {
            return back()->withErrors(['file' => 'That upload is no longer available. Please upload the file again.']);
        }

        if (!Storage::disk('private')->exists($data['path'])) {
            return back()->withErrors(['file' => 'That upload is no longer available. Please upload the file again.']);
        }

        $absolute = Storage::disk('private')->path($data['path']);

        if (!hash_equals($data['hash'], hash_file('sha256', $absolute))) {
            return back()->withErrors(['file' => 'The file changed since it was previewed. Please upload it again.']);
        }

        $parsed = CatalogCsv::parse($absolute);
        $summary = (new CatalogImporter())->apply($parsed['rows']);

        try {
            Storage::disk('private')->delete($data['path']);
        } catch (\Throwable $e) {
            // The import already succeeded; a leftover staging file is not worth an error.
            Log::warning('Catalog import staging file could not be deleted', ['path' => $data['path'], 'exception' => $e]);
        }

        return redirect(config('hellotree.cms_route_prefix') . '/catalog-import')
            ->with('success', sprintf(
                '%d created, %d updated, %d unchanged, %d skipped.',
                $summary['created'],
                $summary['updated'],
                $summary['unchanged'],
                $summary['skipped']
            ));
    }

    /** @return array<string, int> action => count */
    private function summarise(array $plan): array
    {
        $summary = [
            CatalogImporter::CREATE => 0,
            CatalogImporter::UPDATE => 0,
            CatalogImporter::UNCHANGED => 0,
            CatalogImporter::SKIPPED => 0,
            CatalogImporter::ERROR => 0,
        ];

        foreach ($plan as $entry) {
            $summary[$entry['action']]++;
        }

        return $summary;
    }
}
