<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Logins reach Laravel from the Next.js server, so every customer used to share that
 * server's IP and its allowance of 5 a minute. NextAuth now forwards the client IP, and
 * only a request carrying the shared secret is believed.
 */
class LoginThrottleTest extends TestCase
{
    private string $serverIp;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh bucket per test, whichever cache store the limiter is using.
        $this->serverIp = '10.' . random_int(1, 250) . '.' . random_int(1, 250) . '.' . random_int(1, 250);
        config(['services.internal_proxy.secret' => 'test-proxy-secret']);
    }

    private function attemptLogin(array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->serverIp])
            ->withHeaders($headers)
            ->postJson('/api/en/login', ['email' => 'c' . uniqid() . '@example.test', 'password' => 'Wrong-Passw0rd']);
    }

    private function statuses(callable $headersFor, int $attempts = 6): array
    {
        $codes = [];
        for ($i = 0; $i < $attempts; $i++) {
            $codes[] = $this->attemptLogin($headersFor($i))->getStatusCode();
        }

        return $codes;
    }

    private function clientIp(int $i): string
    {
        return '203.0.' . random_int(1, 250) . '.' . ($i + 1);
    }

    public function test_different_customers_behind_the_proxy_get_their_own_allowance(): void
    {
        $codes = $this->statuses(fn ($i) => [
            'X-Internal-Proxy-Secret' => 'test-proxy-secret',
            'X-Proxied-Client-Ip' => $this->clientIp($i),
        ]);

        $this->assertSame(array_fill(0, 6, 401), $codes);
    }

    public function test_one_attacker_behind_the_proxy_is_still_limited(): void
    {
        $ip = $this->clientIp(0);

        $codes = $this->statuses(fn ($i) => [
            'X-Internal-Proxy-Secret' => 'test-proxy-secret',
            'X-Proxied-Client-Ip' => $ip,
        ]);

        $this->assertSame(429, $codes[5]);
    }

    public function test_a_spoofed_client_ip_without_the_secret_is_ignored(): void
    {
        $codes = $this->statuses(fn ($i) => [
            'X-Internal-Proxy-Secret' => 'wrong',
            'X-Proxied-Client-Ip' => $this->clientIp($i),
        ]);

        $this->assertSame(429, $codes[5]);
    }

    public function test_the_header_is_ignored_when_no_secret_is_configured(): void
    {
        config(['services.internal_proxy.secret' => '']);

        $codes = $this->statuses(fn ($i) => [
            'X-Internal-Proxy-Secret' => '',
            'X-Proxied-Client-Ip' => $this->clientIp($i),
        ]);

        $this->assertSame(429, $codes[5]);
    }

    public function test_a_malformed_client_ip_falls_back_to_the_connection_ip(): void
    {
        $codes = $this->statuses(fn ($i) => [
            'X-Internal-Proxy-Secret' => 'test-proxy-secret',
            'X-Proxied-Client-Ip' => "not-an-ip-{$i}",
        ]);

        $this->assertSame(429, $codes[5]);
    }
}
