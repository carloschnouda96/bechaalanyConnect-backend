<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The vendor list search runs LIKE over every field whose form_field is text-like, and it skips
 * by form_field only, never by hide_index. So a role with just `browse` on Users could read these
 * hidden columns one character at a time from whether a row came back, and with a reset token take
 * over the account. `password` is a form_field the search skips, and these three are never shown
 * or edited in the CMS, so nothing else about the page changes. The columns themselves are untouched.
 */
return new class extends Migration
{
    private const CREDENTIALS = [
        'password_reset_token' => 'text',
        'verification_token' => 'text',
        'account_verification_code' => 'number',
    ];

    public function up(): void
    {
        $this->rewrite(fn (array $field) => [
            'form_field' => 'password',
            'hide_index' => 1,
            'hide_create' => 1,
            'hide_edit' => 1,
            'hide_show' => 1,
        ] + $field);
    }

    public function down(): void
    {
        $this->rewrite(fn (array $field) => ['form_field' => self::CREDENTIALS[$field['name']]] + $field);
    }

    private function rewrite(callable $change): void
    {
        $page = DB::table('cms_pages')->where('route', 'users')->first();
        if (!$page) {
            return;
        }

        $fields = json_decode($page->fields, true);
        foreach ($fields as $i => $field) {
            if (isset(self::CREDENTIALS[$field['name']])) {
                $fields[$i] = $change($field);
            }
        }

        DB::table('cms_pages')->where('id', $page->id)->update(['fields' => json_encode($fields)]);
    }
};
