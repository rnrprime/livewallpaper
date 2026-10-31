<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Wallpaper;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_catalog_is_repeatable_filterable_and_has_three_cursor_pages(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->assertSame(6, Category::count());
        $this->assertSame(48, Wallpaper::count());
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(6, 'data');
        $ids = [];
        $next = '/api/v1/wallpapers?per_page=20';
        $sizes = [];
        do {
            $response = $this->getJson($next)->assertOk();
            $data = $response->json('data');
            $sizes[] = count($data);
            $ids = array_merge($ids, array_column($data, 'id'));
            $cursor = $response->json('meta.next_cursor');
            $next = $cursor ? '/api/v1/wallpapers?'.http_build_query(['per_page' => 20, 'cursor' => $cursor]) : null;
        } while ($next);
        $this->assertSame([20, 20, 8], $sizes);
        $this->assertCount(48, array_unique($ids));
        foreach (Category::all() as $category) {
            $this->getJson('/api/v1/wallpapers?category_id='.$category->id)->assertOk()
                ->assertJsonCount(8, 'data')->assertJsonPath('data.0.category.id', $category->id);
        }
        $this->assertTrue(Wallpaper::where('is_premium', true)->exists());
        $this->assertTrue(Wallpaper::where('is_premium', false)->exists());
    }

    public function test_demo_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        (new DemoCatalogSeeder)->run();
    }
}
