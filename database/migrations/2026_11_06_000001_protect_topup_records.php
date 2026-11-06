<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * credits_transfer.credits_types_id was ON DELETE CASCADE, so deleting a payment method
 * hard-deleted every top-up of that type, approved ones and soft-deleted ones included.
 * A top-up is a financial record; the database now refuses, on every path.
 *
 * Top-ups also cannot be created from the CMS any more: routes/cms.php refuses the
 * action, and add = 0 takes the button away. A staff credit belongs on the user balance
 * page, which records a reason in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setForeignKey('RESTRICT');
        DB::table('cms_pages')->where('route', 'credits-transfer')->update(['add' => 0]);
    }

    public function down(): void
    {
        $this->setForeignKey('CASCADE');
        DB::table('cms_pages')->where('route', 'credits-transfer')->update(['add' => 1]);
    }

    private function setForeignKey(string $onDelete): void
    {
        DB::statement('ALTER TABLE `credits_transfer` DROP FOREIGN KEY `credits_credits_types_id_foreign`');
        DB::statement('ALTER TABLE `credits_transfer` ADD CONSTRAINT `credits_credits_types_id_foreign` '
            . 'FOREIGN KEY (`credits_types_id`) REFERENCES `credits_types` (`id`) ON DELETE ' . $onDelete);
    }
};
