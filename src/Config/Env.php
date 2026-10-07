<?php

declare(strict_types=1);

namespace Framework\Config;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Class Env
 * This class is responsible for loading and accessing environment variables from the .env file.
 *
 * @package Framework\Config
 */
final class Env
{
    private static bool $loaded = false;

    /**
     * Loads environment variables from the .env file at the given base path.
     */
    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $dotenv = Dotenv::createImmutable($basePath);
        $dotenv->safeLoad();

        self::$loaded = true;
    }

    /**
     * Retrieves an environment variable or returns its default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $value,
        };
    }

    /**
     * Retrieves a required environment variable.
     *
     * @throws RuntimeException If the variable is not set.
     */
    public static function required(string $key): mixed
    {
        $value = self::get($key);

        if ($value === null) {
            throw new RuntimeException(
                "Required environment variable [{$key}] is not set. " .
                'Check your .env file.'
            );
        }

        return $value;
    }

    /**
     * Retrieves and validates the application encryption key.
     *
     * Accepts either a raw 32-byte key or `base64:` followed by a
     * base64-encoded 32-byte key.
     *
     * @throws RuntimeException If APP_KEY is empty or invalid.
     */
    public static function appKey(): string
    {
        $key = (string) self::required('APP_KEY');

        if (trim($key) === '') {
            throw new RuntimeException(
                'Required environment variable [APP_KEY] must not be empty. ' .
                'Generate one with `php trip key:generate`.'
            );
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException(
                    'APP_KEY must be a valid base64-encoded 32-byte key. ' .
                    'Generate one with `php trip key:generate`.'
                );
            }

            return $decoded;
        }

        if (strlen($key) !== 32) {
            throw new RuntimeException(
                'APP_KEY must be exactly 32 bytes (or base64: followed by a 32-byte key). ' .
                'Generate one with `php trip key:generate`.'
            );
        }

        return $key;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV') === 'production';
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('APP_DEBUG', false);
    }

    public static function appUrl(): string
    {
        return rtrim((string) self::get('APP_URL', 'http://localhost'), '/');
    }
}