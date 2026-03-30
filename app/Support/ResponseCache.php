<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class ResponseCache
{
    public static function store(): Repository
    {
        return Cache::store((string) config('cache.response_store', config('cache.default')));
    }

    public static function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        return self::store()->remember($key, $ttl, $callback);
    }
}