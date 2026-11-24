<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier product's `subcategory_id` was re-written by every catalog sync, so
 * an admin could not group a mixed supplier category by brand (Swift's "Design &
 * Creative Tools" → a "Canva" subcategory): the move was undone within the hour.
 *
 * `manual_subcategory` gives placement the same second owner `manual_price` gives
 * Price: it flips on when anyone but the sync changes `subcategory_id`
 * (App\Observers\ProductObserver), and from then on SupplierCatalogSync leaves the
 * product where it is. Unlike `manual_price` it is a visible checkbox on the edit
 * form, so unticking it hands placement back to the sync.
 *
 * A plain boolean round-trips through the CMS's argument-less type re-assertion,
 * so it needs no SchemaManifest entry — but it must be registered in
 * cms_pages.fields, or the next Products page save drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'manual_subcategory')) {
                $table->boolean('manual_subcategory')->default(false)->nullable()->after('subcategory_id');
            }
        });

        $this->addField('products', [
            'name' => 'manual_subcategory',
            'migration_type' => 'boolean',
            'form_field' => 'checkbox',
            'form_field_additionals_1' => null,
            'form_field_additionals_2' => null,
            'description' => 'Keep this placement. Set automatically when you move a supplier product to another subcategory, so the supplier sync leaves it there. Untick to let the sync place it again.',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $this->updateFieldDescription(
            'products',
            'subcategory_id',
            'For a supplier-imported product, choosing another subcategory here keeps it there: the supplier sync stops moving it back (see "Manual subcategory" below).'
        );
    }

    public function down(): void
    {
        $this->removeField('products', 'manual_subcategory');
        $this->updateFieldDescription('products', 'subcategory_id', null);

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'manual_subcategory')) {
                $table->dropColumn('manual_subcategory');
            }
        });
    }
    private function addField(string $route, array $field): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        if (!in_array($field['name'], array_column($fields, 'name'), true)) {
            $fields[] = $field;
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }

    private function removeField(string $route, string $name): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $fields = array_values(array_filter($fields, fn ($f) => $f['name'] !== $name));
        DB::table('cms_pages')->where('route', $route)->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    /** Updates one field's `description` in place, leaving every other attribute untouched. */
    private function updateFieldDescription(string $route, string $name, ?string $description): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }
        $fields = json_decode($page->fields, true) ?: [];
        $changed = false;
        foreach ($fields as &$f) {
            if ($f['name'] === $name) {
                $f['description'] = $description;
                $changed = true;
                break;
            }
        }
        unset($f);
        if ($changed) {
            DB::table('cms_pages')->where('route', $route)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }
};
