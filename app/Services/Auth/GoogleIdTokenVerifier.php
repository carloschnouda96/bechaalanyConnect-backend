<?php

namespace App\Services\Auth;

use App\Exceptions\InvalidGoogleTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Google-issued OpenID Connect ID token locally.
 *
 * WHY
 * ---
 * POST /api/auth/google-sync used to take an email, a username and a google_id
 * straight from the request body and mint a Sanctum token for whichever account
 * matched. It was public, so anyone could POST a victim's email address and be
 * handed a working bearer token for that account — bypassing the password, the
 * email-verification gate, and every ownership check downstream. It also stamped
 * email_verified = 1 and overwrote the victim's google_id on the way through.
 *
 * The only value the client may now supply is the ID token itself, and every
 * identity claim is read back out of it *after* the signature has been checked
 * against Google's published keys.
 *
 * WHAT IS CHECKED (all of it, in this order)
 *   1. shape .............. three base64url segments, RS256 in the header
 *   2. signature .......... RSA-SHA256 against the JWKS key named by `kid`
 *   3. iss ................ accounts.google.com / https://accounts.google.com
 *   4. aud ................ our own GOOGLE_CLIENT_ID — this is what stops a token
 *                           minted for some *other* Google app being replayed here
 *   5. exp / iat .......... expiry, plus a small clock-skew allowance
 *   6. email_verified ..... Google must vouch for the address, otherwise the
 *                           email->account match below would be forgeable
 *   7. sub ................ present and non-empty; this is the stable account key
 *
 * Verification is local: the JWKS is cached, so a sign-in costs no round-trip to
 * Google. That also means no external dependency on the /tokeninfo endpoint's
 * availability or rate limits.
 */
class GoogleIdTokenVerifier
{
    /** Google's JWKS (RFC 7517). Rotated every few days; `kid` tells us which key to use. */
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'google:oidc:jwks';

    private const CACHE_TTL_SECONDS = 21600; // 6h

    private const ALLOWED_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** Tolerance for clock drift between this host and Google, in seconds. */
    private const LEEWAY_SECONDS = 60;

    /**
     * @return array{sub: string, email: string, name: ?string}
     *
     * @throws InvalidGoogleTokenException
     */
    public function verify(string $idToken): array
    {
        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            // Fail closed. Without an audience to compare against, any Google token
            // from any app on the internet would otherwise be accepted.
            Log::error('Google sign-in attempted but services.google.client_id is not configured.');
            throw new InvalidGoogleTokenException('client_id not configured');
        }

        [$header, $claims, $signedPart, $signature] = $this->decode($idToken);

        if (($header['alg'] ?? null) !== 'RS256') {
            // Pinning the algorithm blocks the classic "alg: none" and HS256-confusion
            // attacks, where an attacker re-signs the token with the public key.
            throw new InvalidGoogleTokenException('unexpected alg: ' . ($header['alg'] ?? 'none'));
        }

        $this->assertSignature($header['kid'] ?? null, $signedPart, $signature);

        $now = time();

        if (!in_array($claims['iss'] ?? '', self::ALLOWED_ISSUERS, true)) {
            throw new InvalidGoogleTokenException('bad iss');
        }

        // `aud` may be a string or an array of strings per the OIDC spec.
        $audiences = (array) ($claims['aud'] ?? []);
        if (!in_array($clientId, $audiences, true)) {
            throw new InvalidGoogleTokenException('bad aud');
        }

        if (!isset($claims['exp']) || ($claims['exp'] + self::LEEWAY_SECONDS) < $now) {
            throw new InvalidGoogleTokenException('expired');
        }

        if (isset($claims['iat']) && ($claims['iat'] - self::LEEWAY_SECONDS) > $now) {
            throw new InvalidGoogleTokenException('issued in the future');
        }

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new InvalidGoogleTokenException('missing sub');
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '') {
            throw new InvalidGoogleTokenException('missing email');
        }

        // Google sends this as a real boolean, but some libraries stringify it.
        $emailVerified = $claims['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            // Without this, someone could register a Google account against an
            // address they do not control and be matched onto the existing local
            // account with that email.
            throw new InvalidGoogleTokenException('email not verified by Google');
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }

    /**
     * Split and base64url-decode the JWT.
     *
     * @return array{0: array, 1: array, 2: string, 3: string}
     */
    private function decode(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new InvalidGoogleTokenException('malformed token');
        }

        [$h, $p, $s] = $parts;

        $header = json_decode($this->base64UrlDecode($h), true);
        $claims = json_decode($this->base64UrlDecode($p), true);

        if (!is_array($header) || !is_array($claims)) {
            throw new InvalidGoogleTokenException('malformed token payload');
        }

        return [$header, $claims, $h . '.' . $p, $this->base64UrlDecode($s)];
    }

    /**
     * @throws InvalidGoogleTokenException
     */
    private function assertSignature(?string $kid, string $signedPart, string $signature): void
    {
        if (!$kid) {
            throw new InvalidGoogleTokenException('missing kid');
        }

        $jwk = $this->key($kid);

        // A cache miss on an unknown kid means Google rotated keys; refetch once
        // before rejecting, otherwise every sign-in fails until the TTL lapses.
        if ($jwk === null) {
            $jwk = $this->key($kid, true);
        }

        if ($jwk === null) {
            throw new InvalidGoogleTokenException('unknown kid');
        }

        $pem = $this->jwkToPem($jwk);
        $ok = openssl_verify($signedPart, $signature, $pem, OPENSSL_ALGO_SHA256);

        if ($ok !== 1) {
            throw new InvalidGoogleTokenException('signature mismatch');
        }
    }

    /**
     * @return array<string,string>|null
     */
    private function key(string $kid, bool $forceRefresh = false): ?array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $keys = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $response = Http::timeout(5)->retry(2, 200)->get(self::JWKS_URL);

            if (!$response->successful()) {
                throw new InvalidGoogleTokenException('jwks fetch failed: ' . $response->status());
            }

            return $response->json('keys') ?? [];
        });

        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    /**
     * Build a PEM SubjectPublicKeyInfo from the JWK's RSA modulus/exponent, so
     * openssl_verify can use it. Avoids pulling in a JWT library for ~40 lines of
     * well-defined DER.
     */
    private function jwkToPem(array $jwk): string
    {
        if (!isset($jwk['n'], $jwk['e'])) {
            throw new InvalidGoogleTokenException('incomplete jwk');
        }

        $rsaKey = $this->derSequence(
            $this->derInteger($this->base64UrlDecode($jwk['n']))
            . $this->derInteger($this->base64UrlDecode($jwk['e']))
        );

        // AlgorithmIdentifier: OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL params
        $algorithmIdentifier = $this->derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"
        );

        // BIT STRING wrapping the key, with a leading 0x00 "unused bits" octet
        $bitString = "\x03" . $this->derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;

        $spki = $this->derSequence($algorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derSequence(string $content): string
    {
        return "\x30" . $this->derLength(strlen($content)) . $content;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        // DER INTEGERs are signed; a leading high bit would read as negative.
        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function base64UrlDecode(string $value): string
    {
        $translated = strtr($value, '-_', '+/');

        if ($remainder = strlen($translated) % 4) {
            $translated .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($translated, true);

        if ($decoded === false) {
            throw new InvalidGoogleTokenException('bad base64url');
        }

        return $decoded;
    }
}
