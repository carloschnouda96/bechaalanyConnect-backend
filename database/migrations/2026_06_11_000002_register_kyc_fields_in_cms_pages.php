<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registers the KYC fields on the CMS "users" admin page and adds a
     * (hidden) CMS page for the verification_statuses lookup table, which the
     * select form field requires.
     */
    private const KYC_FIELD_NAMES = ['id_front_image', 'id_back_image', 'selfie_image', 'verification_statuses_id'];

    public function up(): void
    {
        // Lookup page required by the CMS select field on the users page
        DB::table('cms_pages')->insert([
            'icon' => null,
            'display_name' => 'Verification Status',
            'display_name_plural' => 'Verification Statuses',
            'database_table' => 'verification_statuses',
            'route' => 'verification-statuses',
            'model_name' => 'VerificationStatus',
            'order_display' => 'title',
            'fields' => json_encode([
                [
                    'name' => 'title',
                    'migration_type' => 'string',
                    'form_field' => 'text',
                    'form_field_additionals_1' => null,
                    'form_field_additionals_2' => null,
                    'description' => null,
                    'hide_index' => 0,
                    'hide_create' => 0,
                    'hide_edit' => 0,
                    'hide_show' => 0,
                    'nullable' => '0',
                    'unique' => '0',
                ],
            ]),
            'translatable_fields' => '[]',
            'add' => 0,
            'edit' => 0,
            'delete' => 0,
            'show' => 1,
            'single_record' => 0,
            'apis' => 0,
            'server_side_pagination' => 0,
            'with_export' => 0,
            'hidden' => 1,
            'custom_page' => 0,
            'parent_title' => null,
            'parent_icon' => null,
            'ht_pos' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Append the KYC fields to the users CMS page
        $page = DB::table('cms_pages')->where('route', 'users')->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        $existingNames = array_column($fields, 'name');

        $imageField = function ($name) {
            return [
                'name' => $name,
                'migration_type' => 'string',
                'form_field' => 'image',
                'form_field_additionals_1' => null,
                'form_field_additionals_2' => null,
                'description' => null,
                'hide_index' => 1,
                'hide_create' => 1,
                'hide_edit' => 0,
                'hide_show' => 0,
                'nullable' => '1',
                'unique' => '0',
            ];
        };

        $newFields = [
            $imageField('id_front_image'),
            $imageField('id_back_image'),
            $imageField('selfie_image'),
            [
                'name' => 'verification_statuses_id',
                'migration_type' => 'integer',
                'form_field' => 'select',
                'form_field_additionals_1' => 'verification_statuses',
                'form_field_additionals_2' => 'title',
                'description' => null,
                'hide_index' => 0,
                'hide_create' => 1,
                'hide_edit' => 0,
                'hide_show' => 0,
                'nullable' => '1',
                'unique' => '0',
            ],
        ];

        foreach ($newFields as $field) {
            if (!in_array($field['name'], $existingNames)) {
                $fields[] = $field;
            }
        }

        DB::table('cms_pages')->where('route', 'users')->update([
            'fields' => json_encode($fields),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $page = DB::table('cms_pages')->where('route', 'users')->first();
        if ($page) {
            $fields = json_decode($page->fields, true) ?: [];
            $fields = array_values(array_filter($fields, function ($field) {
                return !in_array($field['name'], self::KYC_FIELD_NAMES);
            }));
            DB::table('cms_pages')->where('route', 'users')->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }

        DB::table('cms_pages')->where('route', 'verification-statuses')->delete();
    }
};
