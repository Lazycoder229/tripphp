<?php

declare(strict_types=1);

namespace App\Middleware;

use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Middleware\Attribute\Middleware;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Config\Env;
use Framework\Config\Config;
use Framework\Exception\InvalidTokenException;

/**
 * Fail-fast guard for required secrets.
 *
 * config/app.php and config/jwt.php are only evaluated lazily — the first
 * time something actually calls config('app.key') or instantiates Jwt.
 * That means a missing APP_KEY or JWT_SECRET can sit silent until the one
 * route that touches encryption or JWT auth finally gets hit, possibly in
 * production. Running this on every request forces that check up front,
 * the same posture Laravel's EncryptCookies gives APP_KEY by running on
 * every 'web' request instead of waiting for Crypt:: to be called.
 *
 * Runs first in the global group (see app/Middleware ordering) so it
 * fails before any other middleware or controller code executes.
 */
#[Middleware(alias: 'ensure-env-configured', groups: ['global'])]
final class EnsureEnvConfiguredMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Throws RuntimeException if APP_KEY is missing/unset.
        Env::appKey();

        // JWT_SECRET is optional at the Env layer (Env::get, not
        // required) since not every app uses JWT auth. Only enforce it
        // here if a secret was actually meant to be configured — i.e.
        // config/jwt.php exists and JWT auth is in use. If your app
        // doesn't use JWT at all, remove this block.
        $jwtSecret = (string) Config::get('jwt.secret', '');
        if ($jwtSecret === '') {
            throw new InvalidTokenException(
                '500 JWT secret is not configured. Set JWT_SECRET in .env.'
            );
        }

        return $next($request);
    }
}