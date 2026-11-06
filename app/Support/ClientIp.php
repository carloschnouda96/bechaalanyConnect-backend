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
                return self::network($forwarded);
            }
        }

        return self::network((string) $request->ip());
    }

    /**
     * The unit a limit should count. One host routinely holds a whole IPv6 /64, so a full
     * address would give it a fresh budget per request.
     */
    public static function network(string $ip): string
    {
        $bytes = @inet_pton($ip);

        if ($bytes === false || strlen($bytes) !== 16) {
            return $ip;
        }

        // An IPv4 address written as IPv6 is that one IPv4 address.
        if (str_starts_with($bytes, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bytes, 12));
        }

        return inet_ntop(substr($bytes, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
