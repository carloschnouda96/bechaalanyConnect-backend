<?php

namespace App\Http\Controllers;

use App\Services\Suppliers\SupplierRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * HTTP cron entrypoints for the URL-based production scheduler (the host panel
 * hits these with `wget` on a schedule, e.g. every 5 min / hourly). They run the
 * supplier sync / order-status work IN-PROCESS via Artisan::call — NOT
 * `schedule:run`, whose spawned `php artisan` subprocess uses PHP_BINARY and is
 * unreliable under PHP-FPM. Each endpoint runs every enabled supplier, so adding
 * a future supplier needs no cron change.
 *
 * Optional shared-secret guard: when `services.cron.token` (env CRON_TOKEN) is
 * set, a matching `?token=` is required; otherwise the endpoint is open (matching
 * the host's other tokenless cron jobs).
 */
class CronController extends Controller
{
    public function __construct(private SupplierRegistry $registry)
    {
    }

    /** Catalog & price sync for every enabled supplier (recommended: hourly). */
    public function suppliersSync(Request $request): JsonResponse
    {
        return $this->runForEnabledSuppliers($request, 'sync');
    }

    /** Poll pending supplier orders for every enabled supplier (recommended: every 5 min). */
    public function suppliersCheckOrders(Request $request): JsonResponse
    {
        return $this->runForEnabledSuppliers($request, 'check-orders');
    }

    /**
     * Run `{supplierKey}:{action}` for each enabled+configured connector. The
     * underlying commands re-check enabled/configured, so this no-ops cleanly
     * until a supplier's credentials are set.
     */
    private function runForEnabledSuppliers(Request $request, string $action): JsonResponse
    {
        $this->authorizeCron($request);

        // A sync run can hit several slow supplier APIs; let it finish even if
        // the wget client disconnects.
        @set_time_limit(0);
        ignore_user_abort(true);

        $ran = [];
        foreach ($this->registry->enabled() as $connector) {
            $key = $connector->key();
            Artisan::call("{$key}:{$action}");
            $ran[] = $key;
        }

        return response()->json(['ok' => true, 'action' => $action, 'suppliers' => $ran]);
    }

    /** Enforce the shared secret only when one is configured. */
    private function authorizeCron(Request $request): void
    {
        $token = (string) config('services.cron.token');
        if ($token !== '' && !hash_equals($token, (string) $request->query('token'))) {
            abort(403);
        }
    }
}
