<?php

namespace App\Services\Suppliers\Connectors;

use App\Services\Yassen\YassenClient;

/**
 * Allo Sharejle (https://api.allosharejle.com/api-docs).
 *
 * Runs the same white-label platform as Yassen-Card — `api-token` header,
 * /client/api/products, newOrder/{id}/params?qty&playerId&order_uuid,
 * check?orders=, statuses accept/wait/reject, `replay_api` codes, qty_values —
 * so everything but the key, config block and the final-error list is
 * inherited from YassenConnector.
 *
 * Prices: the docs state no currency. Assumed USD like Yassen; confirm against
 * the first live /client/api/profile balance before enabling the sync.
 */
class AlloSharejleConnector extends YassenConnector
{
    public const KEY = 'allosharejle';

    public function __construct()
    {
        // Cast to string: a null token or URL would make YassenClient fall back
        // to YASSEN's credentials and send them to this supplier.
        parent::__construct(new YassenClient(
            (string) config('services.allosharejle.base_url'),
            (string) config('services.allosharejle.token'),
            'Allo Sharejle'
        ));
    }

    /**
     * Documented order errors that mean "refused, nothing was bought":
     * 100 insufficient balance, 105 quantity not available, 106 quantity not
     * allowed, 107 player id blocked, 109 product deleted/not found, 110 product
     * not available now, 112 quantity too small, 113 quantity too large.
     * Everything else (111 try again in a minute, 120-130 auth/IP/maintenance,
     * 114/500 unknown) is retried — the platform dedupes order_uuid.
     */
    protected function finalErrorCodes(): array
    {
        return [100, 105, 106, 107, 109, 110, 112, 113];
    }
}
