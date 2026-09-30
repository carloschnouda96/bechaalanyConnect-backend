<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes secrets off the Users list and stops staff editing figures that money depends on.
 *
 * Only display flags change. No field is unregistered — a CMS page-schema save drops
 * every column missing from `cms_pages.fields` (CLAUDE.md, "The CMS rewrites your
 * schema"), so hiding is the only safe way to take a field off a form. A field with
 * hide_edit = 1 is also skipped by the vendor's update(), so its value can no longer be
 * overwritten from the CMS.
 *
 * - Users: the password-reset token, e-mail verification token and code are credentials;
 *   they were list columns (and two were editable). google_id is shown on the record
 *   page only. total_purchases / received_amount are running totals kept by the order and
 *   top-up paths, so they are read-only. credits_balance stays editable on purpose:
 *   UserCreditsObserver routes that edit through the ledger.
 * - Orders: who bought what, how many, and for how much is fixed once the customer has
 *   been debited — editing it never moved the ledger. Status and code stay editable
 *   (Cms\OrdersController drives refunds and fulfilment off the status).
 * - Orders / Statuses / Product type: add & delete flags off. The routes themselves are
 *   refused in routes/cms.php (LockedCmsActionController) — these flags just stop the
 *   buttons being offered.
 */
return new class extends Migration
{
    /** route => [field => [flag => new value]]; every original value was 0 unless noted. */
    private const FIELDS = [
        'users' => [
            'password_reset_token' => ['hide_index' => 1, 'hide_create' => 1],    // was edit/show hidden already
            'verification_token' => ['hide_index' => 1, 'hide_create' => 1, 'hide_edit' => 1, 'hide_show' => 1],
            'account_verification_code' => ['hide_index' => 1, 'hide_create' => 1, 'hide_edit' => 1, 'hide_show' => 1],
            'google_id' => ['hide_index' => 1, 'hide_create' => 1, 'hide_edit' => 1],
            'total_purchases' => ['hide_create' => 1, 'hide_edit' => 1],
            'received_amount' => ['hide_create' => 1, 'hide_edit' => 1],
        ],
        'orders' => [
            'users_id' => ['hide_edit' => 1],
            'product_variation_id' => ['hide_edit' => 1],
            'quantity' => ['hide_edit' => 1],
            'total_price' => ['hide_edit' => 1],
        ],
    ];

    /** route => [add, delete] */
    private const LOCKED_PAGES = [
        'orders' => ['add' => 0],
        'statuses' => ['add' => 0, 'delete' => 0],
        'product-type' => ['add' => 0, 'delete' => 0],
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $route => $fields) {
            $this->setFlags($route, $fields, false);
        }

        foreach (self::LOCKED_PAGES as $route => $flags) {
            $page = DB::table('cms_pages')->where('route', $route)->first();
            if (!$page) {
                continue;
            }
            DB::table('cms_pages')->where('id', $page->id)->update($flags + ['updated_at' => now()]);
            DB::table('admin_role_permissions')->where('cms_page_id', $page->id)->update($flags);
        }
    }

    public function down(): void
    {
        foreach (self::FIELDS as $route => $fields) {
            $this->setFlags($route, $fields, true);
        }

        foreach (self::LOCKED_PAGES as $route => $flags) {
            DB::table('cms_pages')->where('route', $route)->update(
                array_map(fn () => 1, $flags) + ['updated_at' => now()]
            );
        }
    }

    /** Sets (or, on revert, zeroes) the given flags, leaving every other attribute alone. */
    private function setFlags(string $route, array $fields, bool $revert): void
    {
        $page = DB::table('cms_pages')->where('route', $route)->first();
        if (!$page) {
            return;
        }

        $config = json_decode($page->fields, true) ?: [];
        foreach ($config as &$field) {
            foreach ($fields[$field['name']] ?? [] as $flag => $value) {
                $field[$flag] = $revert ? 0 : $value;
            }
        }
        unset($field);

        DB::table('cms_pages')->where('id', $page->id)->update([
            'fields' => json_encode($config),
            'updated_at' => now(),
        ]);
    }
};
