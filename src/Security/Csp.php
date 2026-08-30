<?php

declare(strict_types=1);

namespace Framework\Security;

/**
 * Csp
 *
 * Generates a single cryptographically-random nonce per request and caches
 * it for the request's lifetime. SecurityHeadersMiddleware reads it when
 * assembling the Content-Security-Policy header, and views read the exact
 * same value (via the @cspNonce directive) to stamp their inline <script>
 * tags — so the header and the markup always agree, without ever needing
 * 'unsafe-inline'.
 *
 * @package Framework\Security
 */
final class Csp
{
    private static ?string $nonce = null;

    /**
     * Returns the current request's CSP nonce, generating one on first
     * access. Safe to call from the middleware or from a view — whichever
     * runs first generates it, everyone after gets the same value.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }

        return self::$nonce;
    }

    /**
     * Clears the cached nonce. Framework internals only — the request
     * kernel should call this at the start of each request once the app
     * runs as a persistent process (Fiber/TCP runtime) instead of the
     * current one-process-per-request Apache model, so a nonce never
     * leaks across requests. Never call this mid-request.
     */
    public static function reset(): void
    {
        self::$nonce = null;
    }
}