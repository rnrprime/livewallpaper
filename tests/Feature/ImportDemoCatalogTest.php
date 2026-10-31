<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportDemoCatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $media;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->media = sys_get_temp_dir().'/liper-import-test-'.bin2hex(random_bytes(8));
        mkdir($this->media);
        foreach (['aurora', 'ocean', 'prism', 'ember', 'fluid', 'noir'] as $slug) {
            foreach ([0, 1] as $variant) {
                foreach (['jpg', 'mp4'] as $ext) {
                    file_put_contents($this->media."/{$slug}-{$variant}.{$ext}", 'fixture-'.$slug.$variant.$ext);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->media.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->media);
        parent::tearDown();
    }

    public function test_dry_run_does_not_write_and_import_is_repeatable_and_preserves_catalog(): void
    {
        $existing = Category::create(['name' => 'Aurora', 'slug' => 'aurora', 'thumbnail' => 'existing.jpg']);
        $wallpaper = Wallpaper::create(['category_id' => $existing->id, 'title' => 'Original',
            'preview_image' => 'existing.jpg', 'file_url' => 'existing.mp4', 'is_premium' => false]);
        $this->artisan('catalog:import-demo', ['directory' => $this->media, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, Category::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->artisan('catalog:import-demo', ['directory' => $this->media])->assertSuccessful();
        $this->assertSame(48, Wallpaper::where('is_published', false)->count());
        $this->artisan('catalog:import-demo', ['directory' => $this->media, '--publish' => true])->assertSuccessful();
        $this->artisan('catalog:import-demo', ['directory' => $this->media, '--publish' => true])->assertSuccessful();
        $this->assertSame(7, Category::count());
        $this->assertSame(49, Wallpaper::count());
        $this->assertSame('existing.mp4', $wallpaper->fresh()->file_url);
        $this->assertSame(0, Wallpaper::where('is_published', false)->count());
        $this->assertCount(24, Storage::disk('public')->allFiles());
        $this->assertSame(48, Wallpaper::where('file_url', 'like', 'demo-catalog/v1/%')->count());
    }

    public function test_incomplete_bundle_is_rejected_before_any_writes(): void
    {
        unlink($this->media.'/noir-1.mp4');
        $this->artisan('catalog:import-demo', ['directory' => $this->media])->assertFailed();
        $this->assertSame(0, Wallpaper::count());
        $this->assertSame(0, Category::count());
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_existing_different_media_is_not_overwritten(): void
    {
        Storage::disk('public')->put('demo-catalog/v1/aurora-0.mp4', 'keep me');
        $this->artisan('catalog:import-demo', ['directory' => $this->media])->assertFailed();
        $this->assertSame('keep me', Storage::disk('public')->get('demo-catalog/v1/aurora-0.mp4'));
        $this->assertSame(0, Category::count());
    }
}
