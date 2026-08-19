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
 * REQUIRED shared-secret guard: `services.cron.token` (env CRON_TOKEN) must be set,
 * and the caller must present it as an `X-Cron-Token` header or a `?token=` query
 * parameter. With no token configured the endpoints return 503 rather than running.
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
     * Verify every balance against the credit ledger (recommended: nightly).
     *
     * Reports rather than fixes. A mismatch means credits moved through a path that
     * did not record itself, which is the signal for a double-credit, a missed refund,
     * or a hand-edited balance — the class of bug that was previously undetectable.
     * Applying a correction is a deliberate human decision (`--fix` on the CLI).
     */
    public function reconcileCredits(Request $request): JsonResponse
    {
        $this->authorizeCron($request);

        $exitCode = Artisan::call('credits:reconcile');

        return response()->json([
            'ok' => $exitCode === 0,
            'action' => 'credits:reconcile',
            'balanced' => $exitCode === 0,
            'output' => trim(Artisan::output()),
        ], $exitCode === 0 ? 200 : 409);
    }

    /**
     * Drain the queue (recommended: every minute).
     *
     * This host has no shell crontab and no supervisor, so there is nowhere to run a
     * long-lived `queue:work`. Without this endpoint, moving QUEUE_CONNECTION off
     * `sync` would silently park every supplier fulfillment in the jobs table
     * forever — worse than running them inline.
     *
     * --stop-when-empty so the request ends as soon as there is nothing left, and
     * --max-time so a busy queue cannot hold a PHP-FPM worker indefinitely; the next
     * cron tick picks up whatever is left.
     */
    public function queueWork(Request $request): JsonResponse
    {
        $this->authorizeCron($request);

        @set_time_limit(0);
        ignore_user_abort(true);

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 50,
            // A failing job must not be retried forever against a supplier that
            // charges per attempt; the job's own $tries still applies.
            '--tries' => 3,
        ]);

        return response()->json(['ok' => true, 'action' => 'queue:work']);
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

    /**
     * Require the shared secret. Fails CLOSED.
     *
     * This used to skip the check entirely when CRON_TOKEN was empty — and
     * .env.example shipped it empty, so the default deployment left both endpoints
     * fully public. Each hit runs a complete multi-supplier sync in-process with
     * set_time_limit(0) and ignore_user_abort(true), so an anonymous caller could
     * pin PHP-FPM workers indefinitely and burn the suppliers' rate-limited API
     * quotas (U-Manage allows 1000 requests/hour per key).
     *
     * 503 rather than 403 when unconfigured: the endpoint is not refusing the
     * caller, it is not ready to serve, and that distinction is what tells an
     * operator their cron is silently doing nothing.
     */
    private function authorizeCron(Request $request): void
    {
        $token = (string) config('services.cron.token');

        if ($token === '') {
            abort(503, 'Cron endpoints are disabled until CRON_TOKEN is configured.');
        }

        // Accept the token from a header as well as the query string. Query strings
        // are recorded verbatim in web-server access logs and browser history, so a
        // header is the better channel where the caller can manage it.
        $provided = (string) ($request->header('X-Cron-Token') ?: $request->query('token'));

        if (!hash_equals($token, $provided)) {
            abort(403);
        }
    }
}
