<?php

namespace App\Services\Bycel;

use App\Services\Suppliers\SupplierApiException;

/**
 * A DEFINITIVE Bycel rejection meaning "nothing was purchased": an explicitly
 * recognised failure string (`NO`, `ERR`), or a pre-flight rejection we raise
 * ourselves before spending anything (quantity > 1, product not purchasable, daily
 * cap reached, unusable recipient).
 *
 * BycelConnector turns this into SupplierOrderResult::FAILED, so the engine refunds
 * the customer and marks the order REJECTED.
 *
 * IMPORTANT: this is an ALLOW-LIST, not a default. Bycel's error vocabulary is
 * free text and undocumented, so an unrecognised error is treated as
 * BycelIndeterminateException instead — refunding on a string we do not understand
 * risks refunding a customer whose card was actually bought, orphaning it.
 */
class BycelBusinessException extends SupplierApiException
{
    public array $body;

    public function __construct(string $message, array $body = [], ?int $apiErrorCode = null, int $httpStatus = 0)
    {
        parent::__construct($message, $apiErrorCode, $httpStatus);
        $this->body = $body;
    }
}
