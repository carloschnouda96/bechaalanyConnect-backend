<?php

namespace App\Services\Suppliers\Contracts;

/**
 * A supplier with no dedupe key: a re-sent purchase is a second purchase. Fulfillment
 * commits a parked id before the request, so a worker that dies after sending cannot
 * lead to a re-send. Only a definite refusal clears it again.
 */
interface ParksBeforeWrite
{
}
