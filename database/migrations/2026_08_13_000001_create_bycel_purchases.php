<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intent + claim ledger for Bycel purchases.
 *
 * WHY THIS TABLE EXISTS
 *
 * Bycel's `POST /buy_voucher` returns `[{"Result":"OK"}]` — no order id, no PIN.
 * PINs appear only in `GET /last_pin_report?last=N`, which carries nothing that
 * links a row back to the order that caused it. Claiming the wrong row hands a
 * customer someone else's card, or the same card to two customers: real money,
 * unrecoverable. So the correlation has to be reconstructed, and reconstructing it
 * requires durable state that survives a crash mid-purchase.
 *
 * Each row is an INTENT recorded *before* the POST, carrying the `watermark_id`
 * (the highest MerchantPurchaseId that existed beforehand). Because purchases are
 * serialised by a mutex, our intents form an ordered sequence, and our purchase for
 * intent I lies strictly inside (I.watermark_id, next_intent.watermark_id] — a
 * bounded window that is still computable days later. See BycelPinResolver.
 *
 * DESIGN NOTES
 *
 * - NOT a CMS-managed table (no cms_pages row), so CmsPagesController::editDatabase()
 *   can never drop a column, revert a type, or force it NULLable. That matters more
 *   here than anywhere: losing merchant_purchase_id's UNIQUE index would silently
 *   remove the last line of defence against double-claiming a PIN.
 *
 * - `merchant_purchase_id` UNIQUE is the structural guard, mirroring
 *   credit_ledger_entries.idempotency_key. Even if the mutex is wrong — and it is a
 *   file-based cache lock, so it is only atomic on a single host — the second
 *   attempt to claim the same report row is rejected by the database. NULL is
 *   allowed many times over, which is exactly right for unclaimed intents.
 *
 * - `order_uuid` UNIQUE means one intent per local order, ever. Combined with the
 *   pre-write of orders.external_order_id inside the connector, this is what stops
 *   FulfillSupplierOrderJob's 3 retries — or the CMS "Retry" button, which only
 *   refuses when external_order_id is filled — from buying a second voucher.
 *
 * - The full PIN is deliberately NOT stored. orders.code already holds it; a second
 *   plaintext copy of a bearer instrument buys nothing that pin_last4 + pin_hash
 *   don't. The serial IS stored in full — it is not a secret, and it is what Bycel
 *   support asks for.
 *
 * - ON DELETE RESTRICT on order_id: deleting an order must not erase the record of
 *   money spent with the supplier. Note orders_users_id_foreign is ON DELETE
 *   CASCADE, so this will block a hard user delete — intended, same stance as the
 *   credit ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bycel_purchases')) {
            return;
        }

        Schema::create('bycel_purchases', function (Blueprint $table) {
            $table->id();

            // orders.id is int(10) unsigned in this schema.
            $table->unsignedInteger('order_id');
            $table->string('order_uuid', 191);

            // 'voucher' | 'recharge'
            $table->string('family', 16);
            $table->string('product_id', 32);
            $table->string('recipient', 32)->nullable();

            // Highest MerchantPurchaseId observed immediately BEFORE the POST.
            $table->unsignedBigInteger('watermark_id')->default(0);

            // The claimed last_pin_report row. UNIQUE = a report row can back at
            // most one local order.
            $table->unsignedBigInteger('merchant_purchase_id')->nullable();

            // sent | claimed | failed | ambiguous | abandoned
            $table->string('state', 16)->default('sent');

            // Product fields as they were at purchase time, so the resolver matches
            // against what we actually bought rather than a since-repriced catalog.
            $table->json('snapshot');
            // The claimed report row, PIN redacted.
            $table->json('report_row')->nullable();

            $table->string('pin_last4', 8)->nullable();
            $table->string('pin_hash', 64)->nullable();
            $table->string('serial', 64)->nullable();

            // PriceAfterDiscount_LBP — the true cost of goods sold, which only
            // appears post-purchase in last_pin_report.
            $table->decimal('cost_lbp', 18, 4)->nullable();
            $table->decimal('discount_percentage', 8, 4)->nullable();

            $table->string('response_result', 255)->nullable();
            $table->string('resolver_reason', 191)->nullable();

            $table->timestamp('request_at')->nullable();
            $table->timestamp('response_at')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            $table->unique('order_uuid', 'bycel_purchases_order_uuid_unique');
            $table->unique('merchant_purchase_id', 'bycel_purchases_merchant_purchase_unique');
            $table->index('order_id', 'bycel_purchases_order_id_index');
            $table->index('product_id', 'bycel_purchases_product_id_index');
            $table->index('watermark_id', 'bycel_purchases_watermark_index');
            $table->index(['state', 'created_at'], 'bycel_purchases_state_created_index');

            $table->foreign('order_id', 'bycel_purchases_order_id_foreign')
                ->references('id')->on('orders')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bycel_purchases');
    }
};
