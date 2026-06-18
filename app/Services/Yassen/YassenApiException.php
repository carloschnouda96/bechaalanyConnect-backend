<?php

namespace App\Services\Yassen;

use RuntimeException;

/**
 * Thrown when the Yassen-Card API returns a transport error, a non-2xx status,
 * or an in-body error code (e.g. 121 = bad/expired api-token).
 */
class YassenApiException extends RuntimeException
{
    public ?int $apiErrorCode;

    public function __construct(string $message, ?int $apiErrorCode = null, int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
        $this->apiErrorCode = $apiErrorCode;
    }
}
