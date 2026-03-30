<?php

namespace App\Support;

use Closure;

class PublicCache
{
    private const CONTENT_VERSION_KEY = 'cache_version:public_content';
    private const CATALOG_PRODUCTS_VERSION_KEY = 'cache_version:public_catalog_products';
    private const CATALOG_TAXONOMY_VERSION_KEY = 'cache_version:public_catalog_taxonomy';
    private const DASHBOARD_VERSION_KEY = 'cache_version:admin_dashboard';

    private static function currentVersion(string $versionKey): int
    {
        $value = RuntimeCache::get($versionKey);

        if (!$value) {
            RuntimeCache::forever($versionKey, 1);
            return 1;
        }

        return (int) $value;
    }

    private static function bumpVersion(string $versionKey): void
    {
        if (!RuntimeCache::has($versionKey)) {
            RuntimeCache::forever($versionKey, 1);
        }

        RuntimeCache::increment($versionKey);
    }

    private static function buildKey(string $prefix, string $suffix, string $versionKey): string
    {
        return sprintf('%s:v%s:%s', $prefix, self::currentVersion($versionKey), $suffix);
    }

    public static function rememberContent(string $suffix, int $seconds, Closure $callback): mixed
    {
        return RuntimeCache::remember(
            self::buildKey('public-content', $suffix, self::CONTENT_VERSION_KEY),
            $seconds,
            $callback
        );
    }

    public static function rememberCatalog(string $suffix, int $seconds, Closure $callback): mixed
    {
        return self::rememberCatalogProducts($suffix, $seconds, $callback);
    }

    public static function rememberCatalogProducts(string $suffix, int $seconds, Closure $callback): mixed
    {
        return RuntimeCache::remember(
            self::buildKey('public-catalog-products', $suffix, self::CATALOG_PRODUCTS_VERSION_KEY),
            $seconds,
            $callback
        );
    }

    public static function rememberCatalogTaxonomy(string $suffix, int $seconds, Closure $callback): mixed
    {
        return RuntimeCache::remember(
            self::buildKey('public-catalog-taxonomy', $suffix, self::CATALOG_TAXONOMY_VERSION_KEY),
            $seconds,
            $callback
        );
    }

    public static function rememberDashboard(string $suffix, int $seconds, Closure $callback): mixed
    {
        return RuntimeCache::remember(
            self::buildKey('admin-dashboard', $suffix, self::DASHBOARD_VERSION_KEY),
            $seconds,
            $callback
        );
    }

    public static function bumpContent(): void
    {
        self::bumpVersion(self::CONTENT_VERSION_KEY);
    }

    public static function bumpCatalog(): void
    {
        self::bumpCatalogProducts();
        self::bumpCatalogTaxonomy();
    }

    public static function bumpCatalogProducts(): void
    {
        self::bumpVersion(self::CATALOG_PRODUCTS_VERSION_KEY);
    }

    public static function bumpCatalogTaxonomy(): void
    {
        self::bumpVersion(self::CATALOG_TAXONOMY_VERSION_KEY);
    }

    public static function bumpDashboard(): void
    {
        self::bumpVersion(self::DASHBOARD_VERSION_KEY);
    }
}