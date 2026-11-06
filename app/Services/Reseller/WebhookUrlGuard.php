<?php

namespace App\Services\Reseller;

/**
 * SSRF guard for reseller webhook URLs.
 *
 * A webhook URL is typed by a customer and then requested by OUR server, so
 * without this a reseller could point it at 127.0.0.1, the MAMP MySQL port, a
 * cloud metadata address (169.254.169.254) or anything else on our private
 * network, and read the result in last_delivery_error.
 *
 * Rules: https only, no credentials in the URL, and EVERY address the host
 * resolves to must be public. It runs twice — when the URL is saved (to give a
 * clear error) and again on each delivery against fresh DNS, because a name
 * that was public when saved can be re-pointed at 10.0.0.1 afterwards (DNS
 * rebinding). The delivery then pins the connection to the address checked
 * here (CURLOPT_RESOLVE), so curl cannot resolve the name a second time to
 * something else.
 *
 * The resolver is injectable so tests never touch real DNS.
 */
class WebhookUrlGuard
{
    /** @var callable(string): string[] */
    private $resolver;

    /** Ranges no webhook may reach, beyond what filter_var() already refuses. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // 6to4 relays, and the address cloud hosts use for their own platform services.
        '192.88.99.0/24', '168.63.129.16/32',
    ];

    private const BLOCKED_V6 = [
        '::/96', '::1/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '100::/64',
        // These forms carry or translate to an IPv4 address on a path we cannot judge.
        '2002::/16', '2001::/32', '64:ff9b:1::/48', '::ffff:0:0:0/96',
    ];

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? [self::class, 'dns'];
    }

    /**
     * Validate `$url` and resolve it. Returns ['host', 'port', 'ip'] where `ip`
     * is the address the delivery must connect to.
     *
     * @throws WebhookUrlRejected
     */
    public function resolve(string $url): array
    {
        $parts = parse_url(trim($url));

        if ($parts === false || empty($parts['host'])) {
            throw new WebhookUrlRejected('The webhook URL is not a valid URL.');
        }
        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new WebhookUrlRejected('The webhook URL must use https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WebhookUrlRejected('The webhook URL must not contain a username or password.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? 443);

        // Any other port makes the test button a scanner for what answers where.
        if ($port !== 443) {
            throw new WebhookUrlRejected('The webhook URL must use the standard https port.');
        }

        // CURLOPT_RESOLVE silently ignores an entry for a name it cannot parse, such as one
        // starting with a hyphen, and curl then resolves it itself: the pin is gone.
        if (!filter_var($host, FILTER_VALIDATE_IP)
            && !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host)) {
            throw new WebhookUrlRejected('The webhook host is not a valid host name.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new WebhookUrlRejected('The webhook URL must be a public address.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) call_user_func($this->resolver, $host);

        if ($ips === []) {
            throw new WebhookUrlRejected('The webhook host could not be resolved.', retryable: true);
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new WebhookUrlRejected('The webhook URL must not point to a private, loopback or reserved address.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);

            // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) carry an
            // IPv4 address that is what actually gets reached — judge that one.
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")
                || str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
                return self::isPublicIp(inet_ntop(substr($bin, 12)));
            }

            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }
        } else {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr(inet_pton($ip), $cidr)) {
                    return false;
                }
            }
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);

        if (strlen($netBin) !== strlen($bin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xff << (8 - $rest)) & 0xff;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** @return string[] every A and AAAA address of `$host` */
    private static function dns(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique(array_merge($v4, $v6)));
    }
}
