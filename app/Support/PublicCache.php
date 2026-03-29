<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

class PublicCache
{
    private const CONTENT_VERSION_KEY = 'cache_version:public_content';
    private const CATALOG_VERSION_KEY = 'cache_version:public_catalog';
    private const DASHBOARD_VERSION_KEY = 'cache_version:admin_dashboard';

    private static function currentVersion(string $versionKey): int
    {
        $value = Cache::get($versionKey);

        if (!$value) {
            Cache::forever($versionKey, 1);
            return 1;
        }

        return (int) $value;
    }

    private static function bumpVersion(string $versionKey): void
    {
        if (!Cache::has($versionKey)) {
            Cache::forever($versionKey, 1);
        }

        Cache::increment($versionKey);
    }

    private static function buildKey(string $prefix, string $suffix, string $versionKey): string
    {
        return sprintf('%s:v%s:%s', $prefix, self::currentVersion($versionKey), $suffix);
    }

    public static function rememberContent(string $suffix, int $seconds, Closure $callback): mixed
    {
        return Cache::remember(
            self::buildKey('public-content', $suffix, self::CONTENT_VERSION_KEY),
            now()->addSeconds($seconds),
            $callback
        );
    }

    public static function rememberCatalog(string $suffix, int $seconds, Closure $callback): mixed
    {
        return Cache::remember(
            self::buildKey('public-catalog', $suffix, self::CATALOG_VERSION_KEY),
            now()->addSeconds($seconds),
            $callback
        );
    }

    public static function rememberDashboard(string $suffix, int $seconds, Closure $callback): mixed
    {
        return Cache::remember(
            self::buildKey('admin-dashboard', $suffix, self::DASHBOARD_VERSION_KEY),
            now()->addSeconds($seconds),
            $callback
        );
    }

    public static function bumpContent(): void
    {
        self::bumpVersion(self::CONTENT_VERSION_KEY);
    }

    public static function bumpCatalog(): void
    {
        self::bumpVersion(self::CATALOG_VERSION_KEY);
    }

    public static function bumpDashboard(): void
    {
        self::bumpVersion(self::DASHBOARD_VERSION_KEY);
    }

    public static function rememberCatalogTaxonomy(string $suffix, int $seconds, Closure $callback): mixed
    {
        return self::rememberCatalog("taxonomy:{$suffix}", $seconds, $callback);
    }
    
}