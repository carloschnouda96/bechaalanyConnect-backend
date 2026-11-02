<?php

namespace App\Services\Reseller;

use RuntimeException;

/**
 * A webhook URL that WebhookUrlGuard will not let our server request.
 *
 * `retryable` is true only when the host did not resolve — DNS can be down for
 * a minute. A URL that resolves to a private address is refused for good.
 */
class WebhookUrlRejected extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
