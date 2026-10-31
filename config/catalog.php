<?php

return [

    /*
    | Public catalog delivery. Rendered responses are cached as plain JSON strings,
    | served stale while one request refreshes them, and invalidated on every
    | catalog change. See App\Support\CatalogCache.
    */

    // Requests per minute per client IP. Generous because cached reads are cheap and
    // mobile carriers put many users behind one NAT address.
    'rate_limit' => (int) env('CATALOG_RATE_LIMIT', 600),

    // Store for rendered responses; null uses the default store. Prefer redis, or
    // file on a single server, over database for high traffic.
    'cache_store' => env('CATALOG_CACHE_STORE'),

    // Seconds a rendered response is fresh, then how long it may be served stale
    // while a single request rebuilds it.
    'fresh_seconds' => (int) env('CATALOG_CACHE_FRESH', 60),
    'stale_seconds' => (int) env('CATALOG_CACHE_STALE', 600),

    // How long a request waits for another request that is already building the same
    // response before building it itself.
    'build_wait_seconds' => (int) env('CATALOG_BUILD_WAIT', 2),

    // Browser, app and CDN caching of successful responses.
    'max_age' => (int) env('CATALOG_MAX_AGE', 30),
    'stale_while_revalidate' => (int) env('CATALOG_STALE_WHILE_REVALIDATE', 60),

];
