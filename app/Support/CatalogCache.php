<?php

namespace App\Support;

use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * Renders public catalog responses once and serves them from cache.
 *
 * Entries hold only strings and integers, never models, so they survive stores
 * that refuse to unserialize objects (cache.serializable_classes = false). Each
 * entry records when it was built: fresh entries are served as-is; stale ones are
 * served immediately while a single lock holder rebuilds them after the response;
 * a missing entry is built behind the same lock so a burst of identical requests
 * does the work once. Any catalog write moves every key to a new version.
 */
final class CatalogCache
{
    private const VERSION_KEY = 'catalog:version';

    /**
     * @param  array<string, mixed>  $parameters  Everything that shapes the payload.
     * @param  Closure(): (array<string, mixed>|null)  $build  The payload, or null for "not found".
     */
    public static function respond(Request $request, string $name, array $parameters, Closure $build): Response
    {
        $store = self::store();
        $entry = self::entry($store, self::key($request, $name, $parameters), $build);
        abort_if($entry['missing'] ?? false, 404);

        return self::toResponse($request, $entry);
    }

    /** The cache key of one rendered response under the current catalog version. */
    public static function key(Request $request, string $name, array $parameters): string
    {
        return 'catalog:'.self::version(self::store()).':'.$name.':'
            .hash('xxh128', json_encode([$request->getSchemeAndHttpHost(), $parameters], JSON_THROW_ON_ERROR));
    }

    /** Invalidates every rendered response once the current transaction (if any) commits. */
    public static function flush(): void
    {
        DB::afterCommit(fn () => self::store()->forever(self::VERSION_KEY, bin2hex(random_bytes(8))));
    }

    private static function store(): Repository
    {
        return Cache::store(config('catalog.cache_store'));
    }

    private static function version(Repository $store): string
    {
        return (string) ($store->get(self::VERSION_KEY) ?? '0');
    }

    /** @return array{body?: string, etag?: string, missing?: true, created: int} */
    private static function entry(Repository $store, string $key, Closure $build): array
    {
        $entry = $store->get($key);
        if (self::isEntry($entry)) {
            if (now()->getTimestamp() - $entry['created'] >= self::freshSeconds()) {
                defer(fn () => self::rebuildIfUnlocked($store, $key, $build));
            }

            return $entry;
        }

        $lock = self::lock($store, $key);
        if ($lock !== null) {
            try {
                // Poll quickly and give up soon: a crashed builder must not stall traffic.
                return $lock->betweenBlockedAttemptsSleepFor(25)->block(max(1, (int) config('catalog.build_wait_seconds')), function () use ($store, $key, $build) {
                    $built = $store->get($key);

                    return self::isEntry($built) ? $built : self::build($store, $key, $build);
                });
            } catch (LockTimeoutException) {
                // Another request is still building; render this one directly.
            }
        }

        return self::build($store, $key, $build);
    }

    private static function rebuildIfUnlocked(Repository $store, string $key, Closure $build): void
    {
        $lock = self::lock($store, $key);
        if ($lock === null || ! $lock->get()) {
            return;
        }
        try {
            self::build($store, $key, $build);
        } finally {
            $lock->release();
        }
    }

    /** @return array{body?: string, etag?: string, missing?: true, created: int} */
    private static function build(Repository $store, string $key, Closure $build): array
    {
        $payload = $build();
        if ($payload === null) {
            // Remember misses briefly so floods of unknown IDs stay off the database.
            $entry = ['missing' => true, 'created' => now()->getTimestamp()];
            $store->put($key, $entry, self::freshSeconds());

            return $entry;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $entry = ['body' => $body, 'etag' => '"'.hash('xxh128', $body).'"', 'created' => now()->getTimestamp()];
        $store->put($key, $entry, self::freshSeconds() + max(0, (int) config('catalog.stale_seconds')));

        return $entry;
    }

    private static function toResponse(Request $request, array $entry): Response
    {
        $response = new Response('', 200, ['ETag' => $entry['etag'], 'Vary' => 'Accept-Encoding']);
        $known = array_map(fn (string $tag) => str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag, $request->getETags());
        if (in_array($entry['etag'], $known, true) || in_array('*', $known, true)) {
            return $response->setNotModified();
        }

        $body = $entry['body'];
        if (strlen($body) > 1024 && self::acceptsGzip($request)) {
            $body = gzencode($body, 5);
            $response->headers->set('Content-Encoding', 'gzip');
        }
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Length', (string) strlen($body));

        return $response->setContent($body);
    }

    private static function acceptsGzip(Request $request): bool
    {
        foreach (explode(',', strtolower((string) $request->headers->get('Accept-Encoding'))) as $coding) {
            $parts = array_map('trim', explode(';', $coding));
            if (in_array($parts[0], ['gzip', '*'], true)) {
                return ! preg_match('/^q=0(\.0{0,3})?$/', $parts[1] ?? '');
            }
        }

        return false;
    }

    private static function lock(Repository $store, string $key): ?Lock
    {
        return $store->getStore() instanceof LockProvider ? $store->getStore()->lock($key.':build', 10) : null;
    }

    private static function isEntry(mixed $entry): bool
    {
        return is_array($entry) && is_int($entry['created'] ?? null)
            && (($entry['missing'] ?? false) === true || (is_string($entry['body'] ?? null) && is_string($entry['etag'] ?? null)));
    }

    private static function freshSeconds(): int
    {
        return max(1, (int) config('catalog.fresh_seconds'));
    }
}
