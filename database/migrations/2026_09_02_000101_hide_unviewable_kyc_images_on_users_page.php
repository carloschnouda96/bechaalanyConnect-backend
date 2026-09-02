<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes the three KYC image fields off the Users page.
 *
 * They cannot render. The documents moved to the private disk, and the vendor's `image`
 * field calls Storage::url() on the default (public) disk, so the Users list, show and
 * edit screens each paint three broken images. That is exactly why Cms\KycController and
 * its document() streaming route exist — the KYC queue is the review surface, and it is
 * the only place in the CMS that can actually display these files.
 *
 * hide_edit is set too, deliberately: replacing a user's identity photos by hand from a
 * generic CRUD form is not something an admin should be able to do at all.
 *
 * The field entries stay registered. Removing them would arm the vendor's other hazard —
 * CmsPagesController::editDatabase() drops every column of the table that is absent from
 * cms_pages.fields on the next page save, which would delete the KYC photos outright.
 */
return new class extends Migration
{
    private const ROUTE = 'users';

    private const COLUMNS = ['id_front_image', 'id_back_image', 'selfie_image'];

    public function up(): void
    {
        $this->setHidden(1);
    }

    public function down(): void
    {
        $this->setHidden(0);
    }

    private function setHidden(int $hidden): void
    {
        $page = DB::table('cms_pages')->where('route', self::ROUTE)->first();

        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true) ?: [];
        $changed = false;

        foreach ($fields as &$field) {
            if (!in_array($field['name'] ?? null, self::COLUMNS, true)) {
                continue;
            }

            $field['hide_index'] = $hidden;
            $field['hide_show'] = $hidden;
            $field['hide_edit'] = $hidden;
            $changed = true;
        }
        unset($field);

        if ($changed) {
            DB::table('cms_pages')->where('id', $page->id)->update([
                'fields' => json_encode($fields),
                'updated_at' => now(),
            ]);
        }
    }
};
