<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a Google ID token cannot be proven genuine.
 *
 * The caller is never told which specific check failed — signature, audience,
 * issuer, expiry or unverified email all surface the same message, so the endpoint
 * cannot be used as an oracle to probe our configuration.
 */
class InvalidGoogleTokenException extends Exception
{
    public function __construct(string $reason = 'invalid token')
    {
        // The precise reason goes to the logs, not to the client.
        parent::__construct($reason);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Google sign-in could not be verified. Please try again.',
            'code' => 'google_token_invalid',
        ], 401);
    }
}
