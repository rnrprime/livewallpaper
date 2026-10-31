<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpgradeDemoMotionTest extends TestCase
{
    use RefreshDatabase;

    private string $media;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->media = sys_get_temp_dir().'/liper-upgrade-'.bin2hex(random_bytes(8));
        mkdir($this->media);
        $manifest = ['version' => 'v2-60fps', 'fps' => 60, 'files' => []];
        foreach (['aurora', 'ocean', 'prism', 'ember', 'fluid', 'noir'] as $slug) {
            foreach ([0, 1] as $v) {
                foreach (['mp4', 'jpg'] as $ext) {
                    $file = "{$slug}-{$v}.{$ext}";
                    file_put_contents($this->media.'/'.$file, 'fixture-'.$file);
                    $manifest['files'][$file] = hash_file('sha256', $this->media.'/'.$file);
                }
            }
        }
        file_put_contents($this->media.'/manifest.json', json_encode($manifest));
        $this->artisan('catalog:import-demo', ['directory' => $this->media, '--publish' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->media.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->media);
        parent::tearDown();
    }

    public function test_upgrade_is_repeatable_preserves_flags_ids_and_unrelated_content(): void
    {
        $first = Wallpaper::first();
        $first->update(['is_published' => false, 'is_premium' => true]);
        $before = Wallpaper::orderBy('id')->get(['id', 'is_published', 'is_premium'])->toArray();
        $otherCategory = Category::create(['name' => 'Other', 'slug' => 'other', 'thumbnail' => 'other.jpg']);
        $other = Wallpaper::create(['category_id' => $otherCategory->id, 'title' => 'Other',
            'file_url' => 'other.mp4', 'preview_image' => 'other.jpg', 'is_premium' => false]);
        $this->artisan('catalog:upgrade-demo-motion', ['directory' => $this->media, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(48, Wallpaper::where('file_url', 'like', 'demo-catalog/v1/%')->count());
        $this->assertCount(24, Storage::disk('public')->allFiles());
        foreach ([1, 2] as $run) {
            $this->artisan('catalog:upgrade-demo-motion', ['directory' => $this->media])->assertSuccessful();
        }
        $this->assertSame(48, Wallpaper::where('file_url', 'like', 'demo-catalog/v2-60fps/%')->count());
        $this->assertSame($before, Wallpaper::whereKey(array_column($before, 'id'))->orderBy('id')->get(['id', 'is_published', 'is_premium'])->toArray());
        $this->assertSame('other.mp4', $other->fresh()->file_url);
        $this->assertCount(48, Storage::disk('public')->allFiles());
        $this->assertSame(49, Wallpaper::count());
    }

    public function test_changed_bundle_is_rejected_before_writes(): void
    {
        file_put_contents($this->media.'/noir-1.mp4', 'tampered');
        $this->artisan('catalog:upgrade-demo-motion', ['directory' => $this->media])->assertFailed();
        $this->assertCount(24, Storage::disk('public')->allFiles());
        $this->assertSame(0, Wallpaper::where('file_url', 'like', 'demo-catalog/v2-60fps/%')->count());
    }

    public function test_admin_edited_entry_is_not_overwritten(): void
    {
        Wallpaper::first()->update(['preview_video' => 'custom.mp4']);
        $this->artisan('catalog:upgrade-demo-motion', ['directory' => $this->media])->assertFailed();
        $this->assertSame('custom.mp4', Wallpaper::first()->preview_video);
        $this->assertCount(24, Storage::disk('public')->allFiles());
    }

    public function test_different_versioned_destination_is_not_overwritten(): void
    {
        Storage::disk('public')->put('demo-catalog/v2-60fps/aurora-0.mp4', 'existing');
        $this->artisan('catalog:upgrade-demo-motion', ['directory' => $this->media])->assertFailed();
        $this->assertSame('existing', Storage::disk('public')->get('demo-catalog/v2-60fps/aurora-0.mp4'));
    }
}
