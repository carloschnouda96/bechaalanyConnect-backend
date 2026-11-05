<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The address to rate-limit a request by.
 *
 * Logins come through NextAuth, so Laravel sees the Next.js server's IP for every customer.
 * NextAuth forwards the real client IP, and it is believed only alongside the shared secret
 * (INTERNAL_PROXY_SECRET); otherwise anyone could pick a new address per guess. With no
 * secret configured this is plain $request->ip().
 */
class ClientIp
{
    public static function of(Request $request): string
    {
        $secret = (string) config('services.internal_proxy.secret');
        $provided = (string) $request->header('X-Internal-Proxy-Secret');

        if ($secret !== '' && hash_equals($secret, $provided)) {
            $forwarded = trim((string) $request->header('X-Proxied-Client-Ip'));

            if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
                return $forwarded;
            }
        }

        return (string) $request->ip();
    }
}
