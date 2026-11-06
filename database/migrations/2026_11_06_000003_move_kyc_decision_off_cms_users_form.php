<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A KYC decision belongs to the review queue, which binds it to the documents the reviewer
 * saw. Hidden from the edit form, never unregistered: a CMS page save drops any column
 * missing from cms_pages.fields. Both stay visible on the list.
 */
return new class extends Migration
{
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
        $page = DB::table('cms_pages')->where('route', 'users')->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true);
        foreach ($fields as $i => $field) {
            if (in_array($field['name'], ['verification_statuses_id', 'rejection_reason'], true)) {
                $fields[$i]['hide_edit'] = $hidden;
            }
        }

        DB::table('cms_pages')->where('id', $page->id)->update(['fields' => json_encode($fields)]);
    }
};
