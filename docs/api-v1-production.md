# Liper API v1 production contract

## Scope

The iOS app has Discover, Create and Settings tabs. Discover reads this public catalog. Video-to-Live-Photo conversion and Photos saving happen on-device; personal videos are not uploaded to this API. Existing `/api/v1/home` and page pagination remain supported. No billing behavior changed: `is_premium` remains a catalog flag, not download authorization. Do not advertise protected premium downloads while source files remain public.

## Endpoints

- `GET /api/v1/categories`: `{ "data": [{ "id": 1, "name": "Nature", "slug": "nature", "thumbnail": "https://cdn.example.com/categories/nature.jpg" }] }`
- `GET /api/v1/wallpapers`: feed described below.
- `GET /api/v1/wallpapers/{id}`: `{ "data": { ...wallpaper } }`; missing, draft or incomplete records return 404.
- `GET /api/v1/home?page=1`: retained legacy category sections (three available wallpapers per category).
- `GET /up`: framework health check. Add separate monitored database/cache/storage probes at deployment.

### Feed request

`GET /api/v1/wallpapers?category_id=1&per_page=20&sort=newest&q=ocean`

All parameters are optional. `category_id`: positive existing category ID. `per_page`: 1–40, default 20. `sort`: `newest` only (stable descending ID/insertion order). `q`: title search, maximum 100 characters. `page`: 1–10000 for legacy pagination. `cursor`: opaque encrypted cursor, maximum 2048 characters; mutually exclusive with page. Invalid parameters return 422; missing category returns 404.

```json
{
  "data": [{
    "id": 42,
    "title": "Ocean light",
    "preview_image": "https://cdn.example.com/wallpapers/images/ocean.jpg",
    "preview_video": "https://cdn.example.com/wallpapers/previews/ocean.mp4",
    "file_url": "https://cdn.example.com/wallpapers/videos/ocean.mp4",
    "is_premium": false,
    "category": { "id": 1, "name": "Nature" }
  }],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 120,
    "last_page": 6,
    "next_cursor": "opaque-encrypted-value",
    "has_more": true
  }
}
```

The example omits standard Laravel metadata such as `from`, `to`, `path` and numbered pagination links. `preview_video` is nullable. Use a static poster when absent. Optional previews are short silent MP4s; recommended <=3 MB, admin validation allows <=5 MB. Original video uploads allow <=50 MB. Do not automatically play every source video in a scrolling grid.

For infinite browsing, start without `cursor` or `page`, read `meta.next_cursor`, then request the same filters with that cursor. Stop when `meta.has_more` is false or `next_cursor` is null. Reset cursor on filter/search changes. Cursor responses retain `links`/`meta`, but do not include total or last_page; clients must not require those fields. `links.next` on cursor responses is itself a cursor URL. The initial legacy response retains page URLs; the iOS app should use `meta.next_cursor` throughout. New insertions do not duplicate earlier items. Deleted/unpublished items disappear. Refresh from the beginning to receive new items. Category/search scope is bound to the encrypted cursor; changing filters invalidates it. Rotating APP_KEY invalidates active cursors, so clients should refresh after a 422 cursor error.

### Error envelope

```json
{
  "data": null,
  "message": "Some request parameters are invalid.",
  "error": { "code": "validation_failed", "message": "Some request parameters are invalid." },
  "errors": { "per_page": ["The per page field must be between 1 and 40."] }
}
```

Errors always use JSON for `/api/*` including callers without an Accept header. Codes include `bad_request`, `unauthenticated`, `forbidden`, `not_found`, `method_not_allowed`, `payload_too_large`, `validation_failed`, `rate_limited`, `service_unavailable`, `request_failed`. Every response carries an `X-Request-Id` header (a well-formed ID from an upstream proxy is kept); error bodies repeat it as `error.request_id`, and it is attached to log entries, so a user report can be matched to a log line. Detail IDs longer than 18 digits, zero-padded or non-numeric are a JSON 404. 500 responses omit exception messages and stack traces even when debug is enabled. 429 includes Retry-After; clients should respect it and retry only after that delay. Offline/network errors should present cached posters and a retry control.

## Data and publication

A non-destructive migration adds nullable `preview_video`, boolean `is_published` (existing rows default true to preserve the catalog), and feed indexes. The admin form supports previews and publication. Public endpoints exclude unpublished or empty media records. This is an editorial publication gate, not a transcoding or file-integrity guarantee. Verify uploaded files and codecs before publication; the server does not run ffprobe/transcoding. Relative storage paths and absolute HTTP(S) CDN URLs are supported. All production URLs must use HTTPS.

## Performance and operations

Every catalog endpoint renders its JSON once and serves it from cache (`App\Support\CatalogCache`):

- **No database work on repeat traffic.** Responses are cached as plain JSON strings (never models, so they survive `cache.serializable_classes = false`). Cached reads run zero SQL queries; validation and cursor checks still run per request, so bad input is rejected with a 422 before any cache work.
- **Stale-while-refresh with stampede protection.** A response is fresh for `CATALOG_CACHE_FRESH` seconds (60), then served stale for up to `CATALOG_CACHE_STALE` (600) while exactly one request rebuilds it after its response is sent. A cold miss is built behind a lock, so a burst of identical requests does the work once; a waiter gives up after `CATALOG_BUILD_WAIT` seconds (2) and builds directly, so a crashed builder can't stall traffic.
- **Instant invalidation.** Saving or deleting a category or wallpaper (admin panel, import commands) moves every key to a new catalog version once the transaction commits. Writes that bypass Eloquent (raw `DB::table` updates) surface within the fresh window.
- **Not-found results are cached** for the fresh window, so floods of unknown IDs or categories stay off the database. Publishing a wallpaper ends that immediately.
- **Conditional requests.** Responses carry a strong `ETag`; `If-None-Match` (including weak or listed tags) returns an empty `304`. URLSession on iOS revalidates automatically.
- **Compression.** Bodies over 1 KB are gzipped in PHP when the client accepts it (a 20-item feed page: ~7.4 KB → ~1.2 KB), with `Vary: Accept-Encoding` and an exact `Content-Length`. `gzip;q=0` is honoured.
- **HTTP caching.** 200 and 304 responses send `public, max-age=CATALOG_MAX_AGE, stale-while-revalidate=CATALOG_STALE_WHILE_REVALIDATE` (30/60); errors send `no-store`. Edge caches must include the full query string in their key.
- **Rate limiting.** `CATALOG_RATE_LIMIT` requests/minute/IP (600; generous because cached reads are cheap and mobile carriers place many users behind one NAT address). Set `TRUSTED_PROXIES` when a CDN or load balancer is in front, or every user shares the proxy's bucket.
- **Health.** `GET /up` also runs `select 1`, so monitors see database outages.
- **Logging under load.** Duplicate reports are suppressed and database/connection errors are sampled 1 in 25 during an outage; sampling deliberately avoids the cache, which may be what is down.

Measured locally (6 categories, 3,000 wallpapers, Laravel optimize caches, 4 PHP workers, 16 concurrent clients, interleaved A/B against the previous code): wallpapers first page 172 → 328 req/s, home 125 → 345 req/s, detail 230 → 361 req/s, with lower p99 latency; categories went from failing on every cached hit to serving normally. Absolute production numbers depend on the server, so load-test the deployed stack.

Large title catalogs will need a dedicated indexed/full-text search strategy; current bounded substring search is appropriate for the initial catalog.

## Deployment checklist

1. Back up the database and media. Run the test suite against an isolated testing database, never production. Run `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force` and your existing admin asset build in the release process. This implementation does not run production migrations or deploy anything.
2. Set production environment, `APP_DEBUG=false`, stable strong APP_KEY, HTTPS APP_URL and public storage/CDN URL. Keep secrets in the deployment secret manager. Configure HTTPS redirects, security headers and exact trusted proxies at ingress.
3. Restrict Filament administrator access. Set `ADMIN_EMAILS=owner@example.com,editor@example.com` before release. Non-local environments deny admin access by default; only this server-side case-insensitive allowlist permits access. Local development retains existing login behavior. Clear/rebuild config cache after changes. Use strong credentials, MFA/network restrictions and login throttling.
4. Set Redis/shared cache, production database, least-privilege credentials, automated backups and restore drills. Run config/route/view caching after environment is finalized. Ensure writable storage/log directories. For high traffic, keep catalog responses and rate limits off the database: on a single server set `CACHE_LIMITER_STORE=file` and `CATALOG_CACHE_STORE=file` (or `redis` for both when Redis is available). Enable OPcache with `opcache.validate_timestamps=0` in production and reload PHP after each deploy.
5. Serve public media through object storage/CDN or a correctly configured static server. Ensure HTTPS, correct video Content-Type, byte-range requests, stable URLs and immutable/versioned object keys. Never stream large files through PHP unnecessarily. Set upload/body/time limits consistently with the 50 MB admin source limit.
6. Verify every published source on supported iPhones: portrait composition, H.264-compatible MOV/MP4, reasonable duration and sizes; verify lightweight silent previews, poster dimensions, range support and sufficient rights to distribute all assets. There is no automated codec validation/transcoding pipeline yet.
7. Configure error monitoring, latency/error-rate/429 dashboards, uptime probes, storage-capacity alerts and CDN bandwidth budgets. Ensure logs never include personal source videos or secrets. Load-test realistic scrolling and category/search combinations before increasing catalog scale.
8. Confirm privacy policy, support and terms URLs in the app; verify Photos access wording, real-device Live Photo creation, lock screen activation, interruption/cancellation and limited-library behavior. Keep personal video processing entirely on device.
9. Smoke-test first and final pages, cursor/filter reset, empty categories, disabled assets, offline/retry, 404/422/429/500, and correct production base URL. Deploy API before shipping the app version that uses its optional preview fields.
10. Deploying a release: upload the code, then run `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan optimize` (config, routes, events, views, Filament) and reload PHP-FPM/Apache so OPcache picks up the new code. No cache purge is needed for catalog responses: any catalog write bumps their version. After deploying the categories fix, any categories entry cached by the previous code is ignored because keys changed. Verify with `curl -sI https://<host>/api/v1/categories` (repeat it: every call must be 200, with `ETag` and `X-Request-Id` headers) and `curl -s -H 'Accept-Encoding: gzip' -o /dev/null -w '%{size_download}' https://<host>/api/v1/wallpapers`.

## Verification

`php artisan test` runs feature tests with SQLite in memory configured by phpunit.xml. Catalog tests cover legacy fields, cursor stability across insertions, end-of-feed, filters/cursor scope, draft visibility, optional CDN previews, validation, sanitized server errors and throttling. `CatalogDeliveryTest` runs against a serializing file cache that refuses objects (as production does) and covers repeat traffic with zero queries, 304s, gzip negotiation, immediate invalidation, cached not-found, stale-while-refresh, dead build locks, malformed IDs, request IDs, 405s, the home per-category limit and the health check. `vendor/bin/pint --test` checks style.
