<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * credits_balance was an ordinary input on the CMS Users form. Every save wrote back the
 * value the form had loaded, so saving a KYC or tier change could undo purchases made
 * while the form was open, with no ledger row. Hidden from both forms, never unregistered:
 * a CMS page save drops any column missing from cms_pages.fields. It stays visible on the
 * list and detail pages. Balances now change through Cms\UserBalanceController.
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
            if ($field['name'] === 'credits_balance') {
                $fields[$i]['hide_create'] = $hidden;
                $fields[$i]['hide_edit'] = $hidden;
            }
        }

        DB::table('cms_pages')->where('id', $page->id)->update(['fields' => json_encode($fields)]);
    }
};
