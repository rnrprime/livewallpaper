<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteCatalogContentTest extends TestCase
{
    use RefreshDatabase;

    private Category $demo;

    private Category $keep;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $disk = Storage::disk('public');
        foreach (['demo-catalog/v1/a.mp4', 'demo-catalog/v2/a.mp4', 'demo-catalog/v2/a.jpg', 'wallpapers/videos/x.mov',
            'wallpapers/images/x.jpg', 'categories/anime.png', 'catalog/v1/k.mp4', 'catalog/v1/k.jpg'] as $file) {
            $disk->put($file, 'x');
        }
        $this->demo = Category::create(['name' => 'Fluid', 'slug' => 'liper-demo-v1-fluid', 'thumbnail' => 'demo-catalog/v2/a.jpg']);
        $this->wallpaper($this->demo, 'demo-catalog/v2/a.mp4', 'demo-catalog/v2/a.jpg');
        $this->wallpaper($this->demo, 'demo-catalog/v2/a.mp4', 'demo-catalog/v2/a.jpg');
        $anime = Category::create(['name' => 'Anime', 'slug' => 'anime', 'thumbnail' => 'categories/anime.png']);
        $this->wallpaper($anime, 'wallpapers/videos/x.mov', 'wallpapers/images/x.jpg');
        $this->keep = Category::create(['name' => 'Space', 'slug' => 'space', 'thumbnail' => 'catalog/v1/k.jpg']);
        $this->wallpaper($this->keep, 'catalog/v1/k.mp4', 'catalog/v1/k.jpg');
    }

    private function wallpaper(Category $category, string $video, string $poster): Wallpaper
    {
        return Wallpaper::create(['title' => 'W', 'category_id' => $category->id, 'file_url' => $video,
            'preview_video' => $video, 'preview_image' => $poster, 'is_published' => true, 'is_premium' => false]);
    }

    public function test_without_force_it_only_lists(): void
    {
        $this->artisan('catalog:delete', ['--category' => ['liper-demo-v1-fluid', 'anime'], '--directory' => ['demo-catalog']])
            ->expectsOutputToContain('category liper-demo-v1-fluid (Fluid): 2 wallpapers')
            ->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(4, Wallpaper::count());
        Storage::disk('public')->assertExists('demo-catalog/v1/a.mp4');
    }

    public function test_force_deletes_rows_files_and_folders_but_keeps_the_rest(): void
    {
        $this->artisan('catalog:delete', ['--category' => ['liper-demo-v1-fluid', 'anime'], '--directory' => ['demo-catalog'], '--force' => true])
            ->expectsOutputToContain('Deleted 2 categories, 3 wallpapers and 5 media files')->assertSuccessful();
        $disk = Storage::disk('public');
        $this->assertSame(['space'], Category::pluck('slug')->all());
        $this->assertSame(1, Wallpaper::count());
        $disk->assertMissing(['demo-catalog/v1/a.mp4', 'demo-catalog/v2/a.mp4', 'wallpapers/videos/x.mov', 'categories/anime.png']);
        $disk->assertExists(['catalog/v1/k.mp4', 'catalog/v1/k.jpg']);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_files_still_used_elsewhere_are_kept(): void
    {
        $this->wallpaper($this->keep, 'demo-catalog/v2/a.mp4', 'catalog/v1/k.jpg');
        $this->artisan('catalog:delete', ['--category' => ['liper-demo-v1-fluid'], '--force' => true])->assertSuccessful();
        Storage::disk('public')->assertExists('demo-catalog/v2/a.mp4');
        $this->artisan('catalog:delete', ['--directory' => ['demo-catalog'], '--force' => true])
            ->expectsOutputToContain('still used by remaining content')->assertFailed();
        Storage::disk('public')->assertExists('demo-catalog/v1/a.mp4');
    }

    public function test_unknown_categories_and_unsafe_folders_delete_nothing(): void
    {
        $this->artisan('catalog:delete', ['--category' => ['anime', 'typo'], '--force' => true])
            ->expectsOutputToContain('Unknown categories: typo')->assertFailed();
        $this->artisan('catalog:delete', ['--directory' => ['../storage'], '--force' => true])
            ->expectsOutputToContain('Refusing unsafe directory')->assertFailed();
        $this->assertSame(4, Wallpaper::count());
    }
}
