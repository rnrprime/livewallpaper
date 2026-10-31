<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        Cache::flush();
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

    public function test_categories_and_legacy_page_response_remain_compatible(): void
    {
        $category = $this->category();
        $wallpaper = $this->wallpaper($category);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.id', $category->id)
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'thumbnail']]]);
        $this->getJson('/api/v1/wallpapers?page=1')->assertOk()
            ->assertJsonPath('data.0.id', $wallpaper->id)->assertJsonPath('data.0.is_premium', false)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.next_cursor', null)->assertJsonPath('meta.has_more', false)
            ->assertJsonStructure(['data' => [['id', 'title', 'preview_image', 'preview_video', 'file_url', 'is_premium', 'category' => ['id', 'name']]], 'links', 'meta']);
    }

    public function test_cursor_handles_new_insertions_without_duplicates_and_finishes(): void
    {
        $category = $this->category();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->wallpaper($category)->id;
        }
        $first = $this->getJson('/api/v1/wallpapers?per_page=2')->assertOk()->assertJsonPath('meta.has_more', true);
        $new = $this->wallpaper($category);
        $second = $this->getJson('/api/v1/wallpapers?'.http_build_query(['per_page' => 2, 'cursor' => $first->json('meta.next_cursor')]))
            ->assertOk()->assertJsonPath('meta.has_more', true);
        $third = $this->getJson($second->json('links.next'))->assertOk()->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_cursor', null);
        $received = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($third->json('data'), 'id'));
        $this->assertSame(array_reverse($ids), $received);
        $this->assertNotContains($new->id, $received);
    }

    public function test_filters_and_cursor_scope_are_enforced(): void
    {
        $nature = $this->category();
        $space = $this->category('Space');
        $this->wallpaper($nature, ['title' => 'Ocean sunrise']);
        $this->wallpaper($nature, ['title' => 'Ocean sunset']);
        $this->wallpaper($nature, ['title' => 'Forest']);
        $this->wallpaper($space, ['title' => 'Ocean planet']);
        $query = ['category_id' => $nature->id, 'q' => 'Ocean', 'per_page' => 1];
        $first = $this->getJson('/api/v1/wallpapers?'.http_build_query($query))->assertOk()->assertJsonCount(1, 'data');
        $query['cursor'] = $first->json('meta.next_cursor');
        $this->getJson('/api/v1/wallpapers?'.http_build_query($query))->assertOk()->assertJsonPath('meta.has_more', false);
        $query['category_id'] = $space->id;
        $this->getJson('/api/v1/wallpapers?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    public function test_search_treats_sql_wildcards_as_literal_title_text(): void
    {
        $category = $this->category();
        $match = $this->wallpaper($category, ['title' => '100%_Ocean!']);
        $this->wallpaper($category, ['title' => '100 blue ocean']);
        $this->getJson('/api/v1/wallpapers?'.http_build_query(['q' => '%_ocean!']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public function test_drafts_and_incomplete_media_are_hidden_everywhere(): void
    {
        $category = $this->category();
        $draft = $this->wallpaper($category, ['is_published' => false]);
        $this->wallpaper($category, ['file_url' => '']);
        $visible = $this->wallpaper($category);
        $this->getJson('/api/v1/wallpapers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/v1/wallpapers/'.$draft->id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(1, 'data.0.wallpapers');
    }

    public function test_invalid_requests_use_safe_json_even_without_accept_header(): void
    {
        foreach (['per_page=0', 'per_page=41', 'page=-1', 'category_id=hello', 'cursor=broken', 'sort=random', 'page=1&cursor=broken'] as $query) {
            $this->get('/api/v1/wallpapers?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonMissingPath('trace');
        }
        $this->get('/api/v1/wallpapers?category_id=99999')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/wallpapers?q=missing')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.has_more', false);
    }

    public function test_absolute_cdn_urls_and_optional_preview_are_returned_unchanged(): void
    {
        $wallpaper = $this->wallpaper($this->category(), [
            'preview_image' => 'https://cdn.example.test/poster.jpg',
            'preview_video' => 'https://cdn.example.test/preview.mp4',
            'file_url' => 'https://cdn.example.test/source.mp4',
        ]);
        $this->getJson('/api/v1/wallpapers/'.$wallpaper->id)->assertOk()
            ->assertJsonPath('data.file_url', 'https://cdn.example.test/source.mp4')
            ->assertJsonPath('data.preview_video', 'https://cdn.example.test/preview.mp4')
            ->assertJsonPath('data.preview_image', 'https://cdn.example.test/poster.jpg');
    }

    public function test_unexpected_errors_are_sanitized_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/test-error', fn () => throw new \RuntimeException('internal secret'));
        $this->getJson('/api/v1/test-error')->assertStatus(500)->assertJsonPath('message', 'The service is temporarily unavailable.')
            ->assertJsonMissingPath('trace')->assertDontSee('internal secret');
    }

    public function test_catalog_is_rate_limited_with_retry_header(): void
    {
        config(['catalog.rate_limit' => 5]);
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/categories')->assertOk();
        }
        $this->getJson('/api/v1/categories')->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
    }
}
