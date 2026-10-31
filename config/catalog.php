<?php

// A blank or non-numeric value means "use the default". Plain (int) casts would turn
// `CATALOG_RATE_LIMIT=` into a limit of one request per minute for everyone.
$integer = fn (string $key, int $default): int => is_numeric($value = env($key)) ? (int) $value : $default;

return [

    /*
    | Public catalog delivery. Rendered responses are cached as plain JSON strings,
    | served stale while one request refreshes them, and invalidated on every
    | catalog change. See App\Support\CatalogCache.
    */

    // Requests per minute per client IP. Generous because cached reads are cheap and
    // mobile carriers put many users behind one NAT address.
    'rate_limit' => $integer('CATALOG_RATE_LIMIT', 600),

    // Store for rendered responses; blank uses the default store. Prefer redis, or
    // file on a single server, over database for high traffic.
    'cache_store' => env('CATALOG_CACHE_STORE') ?: null,

    // Seconds a rendered response is fresh, then how long it may be served stale
    // while a single request rebuilds it.
    'fresh_seconds' => $integer('CATALOG_CACHE_FRESH', 60),
    'stale_seconds' => $integer('CATALOG_CACHE_STALE', 600),

    // How long a request waits for another request that is already building the same
    // response before building it itself.
    'build_wait_seconds' => $integer('CATALOG_BUILD_WAIT', 2),

    // Browser, app and CDN caching of successful responses.
    'max_age' => $integer('CATALOG_MAX_AGE', 30),
    'stale_while_revalidate' => $integer('CATALOG_STALE_WHILE_REVALIDATE', 60),

];
