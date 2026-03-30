<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class RuntimeCache
{
    /**
     * Memo per-request / per-process cycle to avoid repeated hot cache reads
     * for the exact same key within one request lifecycle.
     *
     * @var array<string, mixed>
     */
    private static array $memo = [];

    public static function store(): CacheRepository
    {
        $store = (string) config('cache.hot_store', 'hot_failover');

        return Cache::store($store);
    }

    public static function remember(string $key, int $seconds, Closure $callback): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $value = self::store()->remember($key, now()->addSeconds($seconds), $callback);
        self::$memo[$key] = $value;

        return $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $value = self::store()->get($key, $default);
        self::$memo[$key] = $value;

        return $value;
    }

    public static function put(string $key, mixed $value, int $seconds): bool
    {
        self::$memo[$key] = $value;

        return self::store()->put($key, $value, now()->addSeconds($seconds));
    }

    public static function forever(string $key, mixed $value): bool
    {
        self::$memo[$key] = $value;

        return self::store()->forever($key, $value);
    }

    public static function has(string $key): bool
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key] !== null;
        }

        return self::store()->has($key);
    }

    public static function increment(string $key, int $value = 1): int|bool
    {
        unset(self::$memo[$key]);

        return self::store()->increment($key, $value);
    }

    public static function forget(string $key): bool
    {
        unset(self::$memo[$key]);

        return self::store()->forget($key);
    }

    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
