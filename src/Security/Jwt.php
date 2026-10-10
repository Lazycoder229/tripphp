<?php

declare(strict_types=1);

namespace Framework\Security;

use Framework\Config\Config;
use Framework\Exception\InvalidTokenException;

/**
 * Stateless token signing/verification (JWT, HS256 only).
 *
 * Deliberately supports exactly one algorithm — HMAC-SHA256 — and never
 * reads 'alg' off the incoming token to decide how to verify it. Trusting
 * an attacker-supplied algorithm field is how the classic "alg: none" /
 * RS256-to-HS256 confusion forgeries happen; hardcoding the algorithm on
 * the verifying side closes that off entirely.
 *
 * This is for stateless API auth (e.g. a mobile client or third-party
 * consumer that can't carry a session cookie). For anything that already
 * has a browser session, Session + Csrf is simpler and easier to revoke —
 * a JWT can't be invalidated before its 'exp' without a server-side
 * denylist, since nothing here is checked against storage on decode().
 *
 * Usage:
 * ```php
 * $token = $this->jwt->encode(['sub' => $user->id, 'role' => $user->role]);
 * // ... later, on an incoming request ...
 * try {
 *     $claims = $this->jwt->decode($request->header('authorization') ?? '');
 * } catch (InvalidTokenException $e) {
 *     return Response::json(['error' => 'Unauthorized'], 401);
 * }
 * ```
 *
 * @package Framework\Security
 */
final class Jwt
{
    private const ALGO_HEADER = ['alg' => 'HS256', 'typ' => 'JWT'];

    /** @var string HMAC signing secret. Never logged, never included in exception messages. */
    private readonly string $secret;

    private readonly int $defaultTtl;

    private readonly ?string $issuer;

    /**
     * @param string|null $secret Falls back to config/jwt.php ('secret', from JWT_SECRET)
     *                            when not passed explicitly — matches how every other
     *                            framework service pulls its config.
     * @throws InvalidTokenException If no secret is configured. Fails at construction,
     *                            not on first encode()/decode(), so a missing JWT_SECRET
     *                            surfaces at boot (wherever this is first resolved from
     *                            the Container) instead of on a live request.
     */
    public function __construct(?string $secret = null, ?int $defaultTtl = null, ?string $issuer = null)
    {
        $secret ??= (string) Config::get('jwt.secret', '');

        if ($secret === '') {
            throw new InvalidTokenException(
                '500 JWT secret is not configured. Set JWT_SECRET in .env.'
            );
        }

        // A weak or copy-pasted placeholder secret makes every token forgeable, so refuse
        // to boot with one instead of silently accepting any non-empty string.
        $placeholders = ['your-jwt-secret-here', 'secret', 'changeme', 'change-me', 'jwt-secret'];
        if (strlen($secret) < 32 || in_array(strtolower($secret), $placeholders, true)) {
            throw new InvalidTokenException(
                '500 JWT secret is too weak. Use at least 32 random characters — generate one with: php trip jwt:secret'
            );
        }

        $this->secret     = $secret;
        $this->defaultTtl = $defaultTtl ?? (int) Config::get('jwt.ttl', 3600);
        $this->issuer     = $issuer ?? Config::get('jwt.issuer', null);
    }

    /**
     * Signs a set of claims into a compact JWT string.
     *
     * Automatically sets 'iat' (issued-at) and 'exp' (expiry, from $ttl or
     * the configured default) — callers never need to set these themselves.
     * Sets 'iss' too, if an issuer is configured.
     *
     * @param  array    $claims Arbitrary payload — e.g. ['sub' => $userId, 'role' => 'admin'].
     *                          Avoid putting anything secret in here: the payload is only
     *                          base64url-encoded, not encrypted, and readable by anyone
     *                          holding the token.
     * @param  int|null $ttl    Seconds until expiry. Defaults to config/jwt.php's 'ttl'.
     *                          Pass 0 to mint a token with no 'exp' claim at all — only do
     *                          this for tokens you have another way to invalidate.
     * @return string
     */
    public function encode(array $claims, ?int $ttl = null): string
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $now = time();

        $payload = $claims;
        $payload['iat'] = $now;

        if ($ttl !== 0) {
            $payload['exp'] = $now + $ttl;
        }

        if ($this->issuer !== null) {
            $payload['iss'] = $this->issuer;
        }

        $segments = [
            self::base64UrlEncode(json_encode(self::ALGO_HEADER, JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);
        $signature    = self::base64UrlEncode(
            hash_hmac('sha256', $signingInput, $this->secret, binary: true)
        );

        return $signingInput . '.' . $signature;
    }

    /**
     * Verifies a token's signature, structure, and expiry, and returns its claims.
     *
     * Accepts either a bare token or a full 'Authorization: Bearer <token>'
     * header value — strips the 'Bearer ' prefix if present, so callers can
     * pass $request->header('authorization') straight through.
     *
     * @param  string $token
     * @return array Decoded claims.
     * @throws InvalidTokenException If the token is malformed, uses an
     *                                unexpected algorithm, has a bad
     *                                signature, has expired, or (when an
     *                                issuer is configured) was issued by
     *                                someone else.
     */
    public function decode(string $token): array
    {
        $token = preg_replace('/^Bearer\s+/i', '', trim($token)) ?? $token;

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('401 Malformed token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode(self::base64UrlDecode($encodedHeader) ?? '', true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidTokenException('401 Unsupported or missing algorithm.');
        }

        $signingInput      = $encodedHeader . '.' . $encodedPayload;
        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', $signingInput, $this->secret, binary: true)
        );

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            throw new InvalidTokenException('401 Signature verification failed.');
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload) ?? '', true);
        if (!is_array($payload)) {
            throw new InvalidTokenException('401 Malformed token payload.');
        }

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            throw new InvalidTokenException('401 Token has expired.');
        }

        if ($this->issuer !== null && ($payload['iss'] ?? null) !== $this->issuer) {
            throw new InvalidTokenException('401 Token issuer mismatch.');
        }

        return $payload;
    }

    /** RFC 4648 §5 base64url, no padding — the variant JWT actually uses. */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Inverse of base64UrlEncode(). Returns null (not false) on malformed input. */
    private static function base64UrlDecode(string $data): ?string
    {
        $padded  = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }
}