<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes `user_notifications.data` off the Notifications create and edit forms.
 *
 * The column is written by the application — NotificationController::create* build it as
 * an array and App\UserNotification casts it back — and the vendor renders it as a plain
 * textarea. Submitting that form posts a string, which the `array` cast then json_encodes
 * a second time, producing exactly the double-encoded rows that
 * NotificationController::dataOf() already exists to tolerate. There is nothing an admin
 * should be hand-editing here, so the round trip is removed rather than defended against.
 *
 * hide_index and hide_show stay 0: the payload is the only place a notification's message
 * is stored, so the list and the show page have to keep displaying it. Rendering it is the
 * job of the published views (pages/user-notifications/index and
 * components/show-fields/text), which route it through App\Services\Cms\FieldDisplay.
 *
 * This matches how the other two json columns are already registered —
 * orders.external_response and products_variations.external_qty_values are both
 * hide_index/hide_edit — and the field entry itself stays registered, because
 * CmsPagesController::editDatabase() drops every column absent from cms_pages.fields on
 * the next page save.
 */
return new class extends Migration
{
    private const ROUTE = 'user-notifications';

    private const COLUMN = 'data';

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
            if (($field['name'] ?? null) !== self::COLUMN) {
                continue;
            }

            $field['hide_create'] = $hidden;
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
