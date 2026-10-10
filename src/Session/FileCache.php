<?php

declare(strict_types=1);

namespace Framework\Session;

/**
 * 'file' cache driver. Each entry is one file under $directory holding a
 * serialized [expires_at, value] pair. Configured from config/cache.php
 * (CACHE_* in .env).
 * @package Framework\Cache
 */
final class FileCache implements CacheInterface
{
    /**
     * @param string $directory  Absolute path where cache entries are stored.
     * @param int    $defaultTtl Seconds. Used whenever set()/remember() get no explicit TTL.
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $defaultTtl = 3600,
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return $default;
        }

        $entry = @unserialize((string) file_get_contents($path), ['allowed_classes' => false]);

        if (!is_array($entry) || !isset($entry['expires_at'], $entry['value'])) {
            return $default;
        }

        // expires_at === 0 means "never expires".
        if ($entry['expires_at'] !== 0 && $entry['expires_at'] < time()) {
            @unlink($path);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;

        $entry = [
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            'value'      => $value,
        ];

        return file_put_contents($this->pathFor($key), serialize($entry), LOCK_EX) !== false;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return $this->get($key, $sentinel) !== $sentinel;
    }

    public function forget(string $key): bool
    {
        $path = $this->pathFor($key);
        return !is_file($path) || @unlink($path);
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function flush(): bool
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
        return true;
    }

    /** Hashes the key so arbitrary strings become safe, flat filenames. */
    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }
}