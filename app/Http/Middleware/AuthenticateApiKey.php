<?php

namespace App\Http\Middleware;

use App\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a reseller API request (`/api/v1/*`) by its API key.
 *
 * Deliberately not Sanctum: Sanctum tokens share the storefront's global 30-day
 * expiry and are pruned daily, which would break a reseller's integration every
 * month. A reseller key lives until it is revoked or the admin switches
 * `users.api_enabled` off.
 *
 * Every refusal is the same 401, so a caller cannot tell a revoked key from a
 * disabled account from a wrong IP by probing.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $key = $plain !== '' ? ApiKey::findActive($plain) : null;
        $user = $key?->user;

        if (!$key || !$user || !$user->api_enabled || !$key->allowsIp($request->ip())) {
            return response()->json([
                'error' => ['code' => 'unauthenticated', 'message' => 'Missing, invalid or disabled API key.'],
            ], 401);
        }

        // At most one write a minute per key, so polling does not turn every
        // request into an UPDATE.
        if (!$key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
