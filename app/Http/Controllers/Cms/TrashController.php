<?php

namespace App\Http\Controllers\Cms;

use App\CreditsTransfer;
use App\Http\Controllers\Controller;
use App\Order;
use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The records the CMS deleted but did not remove.
 *
 * orders, credits_transfer and users soft-delete on purpose (2026_08_10_000009): a
 * delete must not erase the money that moved through an account. But the vendor CMS
 * strips only the `cms_draft_flag` global scope from its queries and never calls
 * withTrashed(), so `deleted_at IS NULL` is appended to everything an admin sees. The
 * row was therefore invisible while still holding every RESTRICT foreign key pointed
 * at it — which is what made a product variation undeletable with a raw SQLSTATE 1451
 * and nothing on screen to explain it.
 *
 * This page is the missing half of that design: the history is kept, and the admin can
 * see it, put it back, or destroy it deliberately.
 */
class TrashController extends Controller
{
    /**
     * Whitelist, not a lookup. `$type` arrives from the URL, so it must never be able
     * to name an arbitrary class.
     *
     * @var array<string, class-string<Model>>
     */
    private const MODELS = [
        'orders' => Order::class,
        'credits-transfer' => CreditsTransfer::class,
        'users' => User::class,
    ];

    /**
     * Nothing here is ever cleaned up automatically, so the list only grows. Capped
     * rather than paginated: the page exists to find something you just deleted, and the
     * newest rows are the ones being looked for.
     */
    private const LIMIT = 200;

    public function index()
    {
        $orders = Order::onlyTrashed()
            ->with(['users', 'product_variation', 'statuses'])
            ->orderByDesc('deleted_at')
            ->limit(self::LIMIT)
            ->get();

        $transfers = CreditsTransfer::onlyTrashed()
            ->with(['users', 'statuses'])
            ->orderByDesc('deleted_at')
            ->limit(self::LIMIT)
            ->get();

        $users = User::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->limit(self::LIMIT)
            ->get();

        $limit = self::LIMIT;

        return view('cms::pages/deleted-records/index', compact('orders', 'transfers', 'users', 'limit'));
    }

    public function restore(Request $request, $type, $id)
    {
        $record = $this->find($type, $id);

        if (!$record) {
            return back()->with('success', 'That record no longer exists.');
        }

        // Only `deleted_at` is touched. credits_applied_status stays exactly as it was,
        // so the ledger and the order's status remain in agreement — restoring must not
        // look like a status change and must not move any credits.
        $record->restore();

        return back()->with('success', $this->label($type, $id) . ' restored.');
    }

    public function purge(Request $request, $type, $id)
    {
        $record = $this->find($type, $id);

        if (!$record) {
            return back()->with('success', 'That record no longer exists.');
        }

        $label = $this->label($type, $id);

        try {
            $record->forceDelete();
        } catch (QueryException $e) {
            Log::warning('CMS purge blocked by a foreign key', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('success', $label . ' cannot be deleted permanently: ' . $this->explain($type));
        }

        return back()->with('success', $label . ' deleted permanently.');
    }

    /**
     * Why a permanent delete was refused by the database.
     *
     * Both of these are RESTRICT foreign keys that exist precisely to stop an audit
     * trail from being orphaned, so the answer is never "try again".
     */
    private function explain(string $type): string
    {
        if ($type === 'users') {
            return 'this account has credit ledger entries, which are an immutable record of '
                . 'money that moved. Leave the account deleted — it is already hidden and cannot sign in.';
        }

        if ($type === 'orders') {
            return 'a supplier purchase record still points at this order. Resolve it on the '
                . 'Supplier health page first.';
        }

        return 'another record still references it.';
    }

    private function label(string $type, $id): string
    {
        $noun = [
            'orders' => 'Order',
            'credits-transfer' => 'Credit transfer',
            'users' => 'User',
        ][$type] ?? 'Record';

        return $noun . ' #' . $id;
    }

    private function find(string $type, $id): ?Model
    {
        $model = self::MODELS[$type] ?? null;

        if (!$model) {
            abort(404);
        }

        return $model::withTrashed()->withoutGlobalScope('cms_draft_flag')->find($id);
    }
}
