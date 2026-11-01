<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Wallpaper;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Production-shaped delivery: serializing cache stores, repeat traffic,
 * conditional requests, compression, invalidation and error envelopes.
 */
class CatalogDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->cachePath = sys_get_temp_dir().'/liper-cache-'.bin2hex(random_bytes(6));
        // Mirror production: a serializing store that refuses to unserialize objects.
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->cachePath,
            'cache.serializable_classes' => false,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    private function category(string $name = 'Nature'): Category
    {
        return Category::create(['name' => $name, 'slug' => strtolower($name), 'thumbnail' => 'categories/nature.jpg']);
    }

    private function wallpaper(Category $category, array $attributes = []): Wallpaper
    {
        return Wallpaper::create(array_merge([
            'title' => 'Ocean light', 'category_id' => $category->id,
            'preview_image' => 'wallpapers/images/ocean.jpg', 'file_url' => 'wallpapers/videos/ocean.mp4',
            'is_premium' => false, 'is_published' => true,
        ], $attributes));
    }

    public function test_categories_keep_working_when_served_from_a_hardened_cache(): void
    {
        $category = $this->category();
        $this->wallpaper($category);
        foreach (range(1, 3) as $attempt) {
            $this->getJson('/api/v1/categories')->assertOk()
                ->assertJsonPath('data.0.id', $category->id)
                ->assertJsonPath('data.0.name', 'Nature');
        }
    }

    public function test_repeat_traffic_is_served_without_touching_the_database(): void
    {
        $category = $this->category();
        $wallpaper = $this->wallpaper($category);
        $paths = ['/api/v1/categories', '/api/v1/home', '/api/v1/wallpapers?per_page=20',
            '/api/v1/wallpapers?category_id='.$category->id, '/api/v1/wallpapers/'.$wallpaper->id];
        foreach ($paths as $path) {
            $this->getJson($path)->assertOk();
        }

        DB::enableQueryLog();
        foreach ($paths as $path) {
            $this->getJson($path)->assertOk();
        }
        $this->assertSame([], DB::getQueryLog(), 'Cached catalog reads must not query the database');
    }

    public function test_conditional_requests_return_not_modified(): void
    {
        $wallpaper = $this->wallpaper($this->category());
        $first = $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertOk()->assertHeader('ETag');
        $etag = $first->headers->get('ETag');

        foreach ([$etag, 'W/'.$etag, '"other", '.$etag] as $ifNoneMatch) {
            $response = $this->getJson('/api/v1/wallpapers/'.$wallpaper->id, ['If-None-Match' => $ifNoneMatch])
                ->assertStatus(304)->assertHeader('ETag', $etag);
            $this->assertSame('', $response->getContent());
            $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        }
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id, ['If-None-Match' => '"stale"'])->assertOk();
    }

    public function test_large_responses_are_gzipped_only_when_accepted(): void
    {
        $category = $this->category();
        foreach (range(1, 20) as $i) {
            $this->wallpaper($category, ['title' => "Wallpaper $i"]);
        }

        $zipped = $this->get('/api/v1/wallpapers?per_page=20', ['Accept-Encoding' => 'br, gzip'])
            ->assertOk()->assertHeader('Content-Encoding', 'gzip')->assertHeader('Vary', 'Accept-Encoding');
        $decoded = json_decode(gzdecode($zipped->getContent()), true);
        $this->assertCount(20, $decoded['data']);
        $this->assertSame(strlen($zipped->getContent()), (int) $zipped->headers->get('Content-Length'));

        foreach (['identity', 'gzip;q=0', ''] as $encoding) {
            $plain = $this->get('/api/v1/wallpapers?per_page=20', ['Accept-Encoding' => $encoding])->assertOk();
            $this->assertFalse($plain->headers->has('Content-Encoding'), "Unexpected compression for '$encoding'");
            $this->assertCount(20, $plain->json('data'));
        }
    }

    public function test_catalog_changes_are_visible_immediately(): void
    {
        $category = $this->category();
        $wallpaper = $this->wallpaper($category, ['title' => 'Before']);
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertJsonPath('data.title', 'Before');
        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.name', 'Nature');

        $wallpaper->update(['title' => 'After']);
        $category->update(['name' => 'Forest']);
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertJsonPath('data.title', 'After');
        $this->getJson('/api/v1/wallpapers')->assertJsonPath('data.0.category.name', 'Forest');
        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.name', 'Forest');

        $wallpaper->delete();
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_cached_not_found_ends_when_a_wallpaper_is_published(): void
    {
        $draft = $this->wallpaper($this->category(), ['is_published' => false]);
        $this->getJson('/api/v1/wallpapers/'.$draft->id)->assertNotFound();
        $this->getJson('/api/v1/wallpapers/'.$draft->id)->assertNotFound();

        $draft->update(['is_published' => true]);
        $this->getJson('/api/v1/wallpapers/'.$draft->id)->assertOk()->assertJsonPath('data.id', $draft->id);
    }

    public function test_stale_responses_are_served_while_one_request_refreshes_them(): void
    {
        config(['catalog.fresh_seconds' => 1, 'catalog.stale_seconds' => 600]);
        $wallpaper = $this->wallpaper($this->category(), ['title' => 'Old']);
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertJsonPath('data.title', 'Old');

        // A write that bypasses model events: only expiry can surface it.
        DB::table('wallpapers')->where('id', $wallpaper->id)->update(['title' => 'New']);
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertJsonPath('data.title', 'Old');

        $this->travel(5)->seconds();
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertOk()->assertJsonPath('data.title', 'Old');
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertJsonPath('data.title', 'New');
    }

    public function test_a_crashed_builder_does_not_stall_requests(): void
    {
        config(['catalog.build_wait_seconds' => 1]);
        $wallpaper = $this->wallpaper($this->category());
        $url = '/api/v1/wallpapers/'.$wallpaper->id;

        // The build lock is held, as if the request building this response had died.
        $key = CatalogCache::key(Request::create(url($url)), 'wallpaper', ['id' => $wallpaper->id]);
        $this->assertTrue(Cache::store()->getStore()->lock($key.':build', 30)->acquire());

        $started = microtime(true);
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $wallpaper->id);
        $elapsed = microtime(true) - $started;
        $this->assertGreaterThan(0.9, $elapsed, 'The request should have waited for the held lock');
        $this->assertLessThan(2.5, $elapsed, 'The request must give up on a dead lock quickly');
    }

    public function test_malformed_and_oversized_ids_are_clean_json_404s(): void
    {
        foreach (['0', '00012', 'abc', '1e3', str_repeat('9', 19), str_repeat('9', 40)] as $id) {
            $this->get('/api/v1/wallpapers/'.$id)->assertNotFound()
                ->assertJsonPath('error.code', 'not_found')->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $ok = $this->getJson('/api/v1/categories')->assertOk();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $ok->headers->get('X-Request-Id'));

        $error = $this->postJson('/api/v1/categories')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
        $this->assertSame($error->headers->get('X-Request-Id'), $error->json('error.request_id'));

        $this->getJson('/api/v1/categories', ['X-Request-Id' => 'edge-7f3a9c21'])->assertHeader('X-Request-Id', 'edge-7f3a9c21');
        $spoofed = $this->getJson('/api/v1/categories', ['X-Request-Id' => '<script>alert(1)</script>']);
        $this->assertNotSame('<script>alert(1)</script>', $spoofed->headers->get('X-Request-Id'));
    }

    public function test_home_lists_the_three_newest_wallpapers_of_each_category(): void
    {
        foreach (['Aurora', 'Ember'] as $name) {
            $category = $this->category($name);
            foreach (range(1, 5) as $i) {
                $this->wallpaper($category, ['title' => "$name $i"]);
            }
        }

        $home = $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('meta.current_page', 1);
        foreach ($home->json('data') as $section) {
            $this->assertSame([$section['category']['name'].' 5', $section['category']['name'].' 4', $section['category']['name'].' 3'],
                array_column($section['wallpapers'], 'title'));
        }
    }

    public function test_categories_without_published_wallpapers_are_hidden(): void
    {
        $this->category('Empty');
        $drafts = $this->category('Drafts');
        $draft = $this->wallpaper($drafts, ['is_published' => false]);
        $this->wallpaper($this->category('Nature'));

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Nature');
        $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(1, 'data');

        $draft->update(['is_published' => true]);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_health_check_includes_the_database(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_blank_settings_fall_back_to_safe_defaults(): void
    {
        $keys = ['CATALOG_RATE_LIMIT', 'CATALOG_CACHE_FRESH', 'CATALOG_MAX_AGE', 'CATALOG_CACHE_STORE', 'CACHE_LIMITER_STORE'];
        $saved = array_intersect_key($_SERVER, array_flip($keys));
        $_SERVER = array_merge($_SERVER, array_fill_keys($keys, ''), ['CATALOG_MAX_AGE' => '0']);
        try {
            $catalog = require config_path('catalog.php');
            $cache = require config_path('cache.php');
        } finally {
            $_SERVER = array_merge(array_diff_key($_SERVER, array_flip($keys)), $saved);
        }

        $this->assertSame(600, $catalog['rate_limit']);
        $this->assertSame(60, $catalog['fresh_seconds']);
        $this->assertSame(0, $catalog['max_age']);
        $this->assertNull($catalog['cache_store']);
        $this->assertNull($cache['limiter']);
    }
}
