<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The homepage Categories section showed the first six active categories in
 * whatever order MySQL returned them (`->get()->take(6)`), so staff could not
 * choose which categories appear there.
 *
 * `homepage_categories` is a "select multiple" field on Homepage Settings, built
 * exactly as the CMS would build it (same as `featured_products`): the pivot is
 * named `{singular field}_{singular table}` and the relation `homepage_categories()`
 * is the one createModel generates, so a later page save neither drops the pivot
 * nor redeclares the method. Order is the pivot's `ht_pos` (drag order in the form).
 *
 * The pivot is seeded with the categories the homepage shows today, so the page
 * does not change on deploy.
 */
return new class extends Migration
{
    private const PIVOT = 'homepage_category_homepage_setting';

    public function up(): void
    {
        if (!Schema::hasTable(self::PIVOT)) {
            Schema::create(self::PIVOT, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('category_id')->unsigned();
                $table->integer('homepage_setting_id')->unsigned();
                $table->integer('ht_pos')->unsigned()->nullable();
                $table->timestamps();

                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
                $table->foreign('homepage_setting_id')->references('id')->on('homepage_settings')->onDelete('cascade');
            });
        }

        $this->addField('homepage-settings', [
            'name' => 'homepage_categories',
            'migration_type' => 'longText',
            'form_field' => 'select multiple',
            'form_field_additionals_1' => 'categories',
            'form_field_additionals_2' => 'title',
            'description' => 'Categories shown in the homepage Categories section, in this order. Inactive categories are skipped.',
            'hide_index' => 1,
            'hide_create' => 0,
            'hide_edit' => 0,
            'hide_show' => 0,
            'nullable' => '1',
            'unique' => '0',
        ]);

        $settingId = DB::table('homepage_settings')->where('cms_draft_flag', '!=', 1)->value('id');
        if ($settingId && !DB::table(self::PIVOT)->where('homepage_setting_id', $settingId)->exists()) {
            // The exact query the homepage used until now.
            $ids = DB::table('categories')
                ->where('cms_draft_flag', '!=', 1)
                ->where('is_active', 1)
                ->limit(6)
                ->pluck('id');

            $now = now();
            DB::table(self::PIVOT)->insert($ids->values()->map(fn ($id, $i) => [
                'category_id' => $id,
                'homepage_setting_id' => $settingId,
                'ht_pos' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function down(): void
    {
        $this->removeField('homepage-settings', 'homepage_categories');
        Schema::dropIfExists(self::PIVOT);
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
};
