<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportCatalogPackTest extends TestCase
{
    use RefreshDatabase;

    private string $pack;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->pack = sys_get_temp_dir().'/liper-pack-test-'.bin2hex(random_bytes(8));
        mkdir($this->pack.'/media', 0777, true);
        $this->writePack([
            ['nebula-crimson-1', 'Crimson Veil', 'space'],
            ['aurora-polar-1', 'Polar Lights', 'aurora'],
            ['nebula-teal-1', 'Teal Veil', 'space'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pack.'/media/*') as $file) {
            unlink($file);
        }
        rmdir($this->pack.'/media');
        @unlink($this->pack.'/pack.json');
        rmdir($this->pack);
        parent::tearDown();
    }

    private function writePack(array $entries, array $overrides = []): void
    {
        $wallpapers = [];
        foreach ($entries as [$key, $title, $category]) {
            $entry = ['key' => $key, 'title' => $title, 'category' => $category, 'sha256' => []];
            foreach (['video' => "{$key}.mp4", 'preview' => "{$key}-preview.mp4", 'poster' => "{$key}.jpg"] as $kind => $file) {
                file_put_contents($this->pack.'/media/'.$file, "fixture {$file}");
                $entry[$kind] = 'media/'.$file;
                $entry['sha256'][$kind] = hash_file('sha256', $this->pack.'/media/'.$file);
            }
            $wallpapers[] = $entry;
        }
        file_put_contents($this->pack.'/pack.json', json_encode(array_merge([
            'format' => 1, 'version' => 'v1',
            'categories' => ['space' => 'Space', 'aurora' => 'Aurora'],
            'wallpapers' => $wallpapers,
        ], $overrides)));
    }

    public function test_dry_run_validates_without_changing_anything(): void
    {
        $this->artisan('catalog:import-pack', ['directory' => $this->pack, '--dry-run' => true])
            ->expectsOutputToContain('2 categories, 3 wallpapers, 9 files')->assertSuccessful();
        $this->assertSame(0, Wallpaper::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_import_creates_drafts_then_publishes_in_pack_order(): void
    {
        $this->artisan('catalog:import-pack', ['directory' => $this->pack])->assertSuccessful();
        $this->assertSame(3, Wallpaper::where('is_published', false)->count());
        Storage::disk('public')->assertExists('catalog/v1/nebula-crimson-1.mp4');
        Storage::disk('public')->assertExists('catalog/v1/aurora-polar-1-preview.mp4');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');

        $this->artisan('catalog:import-pack', ['directory' => $this->pack, '--publish' => true])->assertSuccessful();
        $feed = $this->getJson('/api/v1/wallpapers')->assertOk()->json('data');
        $this->assertSame(['Crimson Veil', 'Polar Lights', 'Teal Veil'], array_column($feed, 'title'));
        $this->assertStringEndsWith('/storage/catalog/v1/nebula-crimson-1-preview.mp4', $feed[0]['preview_video']);
        $this->assertSame('catalog/v1/nebula-crimson-1.jpg', Category::where('slug', 'space')->value('thumbnail'));
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_reimporting_is_idempotent(): void
    {
        $this->artisan('catalog:import-pack', ['directory' => $this->pack, '--publish' => true])->assertSuccessful();
        $this->artisan('catalog:import-pack', ['directory' => $this->pack, '--publish' => true])
            ->expectsOutputToContain('0 new and 0 updated')->assertSuccessful();
        $this->assertSame(3, Wallpaper::count());
        $this->assertSame(2, Category::count());
    }

    public function test_checksum_mismatch_changes_nothing(): void
    {
        file_put_contents($this->pack.'/media/aurora-polar-1.mp4', 'tampered');
        $this->artisan('catalog:import-pack', ['directory' => $this->pack])
            ->expectsOutputToContain('Checksum mismatch: media/aurora-polar-1.mp4')->assertFailed();
        $this->assertSame(0, Wallpaper::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_different_file_on_the_server_is_never_overwritten(): void
    {
        Storage::disk('public')->put('catalog/v1/nebula-teal-1.jpg', 'someone else');
        $this->artisan('catalog:import-pack', ['directory' => $this->pack])
            ->expectsOutputToContain('different file already exists')->assertFailed();
        $this->assertSame('someone else', Storage::disk('public')->get('catalog/v1/nebula-teal-1.jpg'));
        $this->assertSame(['catalog/v1/nebula-teal-1.jpg'], Storage::disk('public')->allFiles());
    }

    public function test_paths_outside_media_are_rejected(): void
    {
        $pack = json_decode(file_get_contents($this->pack.'/pack.json'), true);
        $pack['wallpapers'][0]['video'] = 'media/../../.env';
        file_put_contents($this->pack.'/pack.json', json_encode($pack));
        $this->artisan('catalog:import-pack', ['directory' => $this->pack])
            ->expectsOutputToContain('bad video path')->assertFailed();
        $this->assertSame(0, Wallpaper::count());
    }

    public function test_retire_demo_unpublishes_the_placeholder_catalog(): void
    {
        $demo = Category::create(['name' => 'Fluid', 'slug' => 'liper-demo-v1-fluid', 'thumbnail' => 'demo-catalog/v1/fluid-0.jpg']);
        Wallpaper::create(['title' => 'Satin', 'category_id' => $demo->id, 'preview_image' => 'demo-catalog/v1/fluid-0.jpg',
            'file_url' => 'demo-catalog/v1/fluid-0.mp4', 'is_published' => true, 'is_premium' => false]);
        $this->artisan('catalog:import-pack', ['directory' => $this->pack, '--publish' => true, '--retire-demo' => true])
            ->expectsOutputToContain('Unpublished 1 demo wallpapers')->assertSuccessful();
        $this->assertSame(['Aurora', 'Space'], array_column($this->getJson('/api/v1/categories')->json('data'), 'name'));
    }
}
