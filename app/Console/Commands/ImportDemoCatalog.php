<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Wallpaper;
use App\Support\CatalogCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportDemoCatalog extends Command
{
    protected $signature = 'catalog:import-demo {directory : Directory containing the 12 MP4 and 12 JPG files} {--dry-run : Validate without changing files or database} {--publish : Publish imported demo wallpapers}';

    protected $description = 'Import the Liper demo catalog using server-relative media paths; preserve existing catalog data';

    public function handle(): int
    {
        if (! Schema::hasColumn('wallpapers', 'is_published') || ! Schema::hasColumn('wallpapers', 'preview_video')) {
            $this->error('Deploy the updated API and run its catalog migration before importing.');

            return self::FAILURE;
        }
        $collections = self::collections();
        $source = realpath($this->argument('directory'));
        $disk = Storage::disk('public');
        $prefix = 'demo-catalog/v1/';
        $createdFiles = [];
        try {
            if (! $source || ! is_dir($source)) {
                throw new RuntimeException('Media directory does not exist.');
            }
            foreach ($collections as $slug => [$name]) {
                $category = Category::where('slug', 'liper-demo-v1-'.$slug)->first();
                if ($category && $category->thumbnail !== $prefix.$slug.'-0.jpg') {
                    throw new RuntimeException('A category conflicts with the demo namespace: '.$slug);
                }
                for ($variant = 0; $variant < 2; $variant++) {
                    foreach (['mp4', 'jpg'] as $extension) {
                        $file = "{$slug}-{$variant}.{$extension}";
                        $path = $source.DIRECTORY_SEPARATOR.$file;
                        if (! is_file($path) || ! is_readable($path) || filesize($path) === 0 || filesize($path) > 50 * 1024 * 1024) {
                            throw new RuntimeException('Missing, unreadable or oversized media: '.$file);
                        }
                        if ($disk->exists($prefix.$file) && hash('sha256', $disk->get($prefix.$file)) !== hash_file('sha256', $path)) {
                            throw new RuntimeException('Existing server media differs; refusing to overwrite: '.$file);
                        }
                    }
                }
            }
            if ($this->option('dry-run')) {
                $this->info('Validated 6 categories, 48 entries and 24 media files. No changes made.');

                return self::SUCCESS;
            }
            foreach (array_keys($collections) as $slug) {
                for ($variant = 0; $variant < 2; $variant++) {
                    foreach (['mp4', 'jpg'] as $extension) {
                        $file = "{$slug}-{$variant}.{$extension}";
                        if (! $disk->exists($prefix.$file)) {
                            $stream = fopen($source.DIRECTORY_SEPARATOR.$file, 'rb');
                            try {
                                $ok = $disk->put($prefix.$file, $stream, ['visibility' => 'public']);
                            } finally {
                                fclose($stream);
                            }
                            if (! $ok) {
                                throw new RuntimeException('Could not write media: '.$file);
                            }
                            $createdFiles[] = $prefix.$file;
                        }
                    }
                }
            }
            DB::transaction(function () use ($collections, $prefix): void {
                $categories = [];
                foreach ($collections as $slug => [$name]) {
                    $categories[$slug] = Category::firstOrCreate(['slug' => 'liper-demo-v1-'.$slug], [
                        'name' => $name, 'thumbnail' => $prefix.$slug.'-0.jpg',
                    ]);
                }
                for ($edition = 7; $edition >= 0; $edition--) {
                    foreach (array_reverse($collections, true) as $slug => [$name, $titles]) {
                        $media = $prefix.$slug.'-'.($edition % 2);
                        $wallpaper = Wallpaper::firstOrCreate([
                            'category_id' => $categories[$slug]->id, 'title' => $titles[$edition],
                        ], [
                            'preview_image' => $media.'.jpg', 'file_url' => $media.'.mp4',
                            'preview_video' => $media.'.mp4', 'is_premium' => $edition % 3 === 1,
                            'is_published' => (bool) $this->option('publish'),
                        ]);
                        if ($wallpaper->file_url !== $media.'.mp4' || $wallpaper->preview_image !== $media.'.jpg') {
                            throw new RuntimeException('Existing wallpaper conflicts with demo entry: '.$wallpaper->title);
                        }
                        if ($this->option('publish') && ! $wallpaper->is_published) {
                            $wallpaper->update(['is_published' => true]);
                        }
                    }
                }
            });
        } catch (Throwable $error) {
            foreach ($createdFiles as $file) {
                $disk->delete($file);
            }
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        CatalogCache::flush();
        $this->info('Demo catalog imported: 6 categories, 48 entries, 12 unique videos. Existing entries preserved.');
        $this->line('Media URL example: '.$disk->url($prefix.'aurora-0.mp4'));
        if (! $this->option('publish')) {
            $this->warn('New wallpapers are drafts. Run with --publish when ready.');
        }

        return self::SUCCESS;
    }

    public static function collections(): array
    {
        return [
            'aurora' => ['Aurora', ['Northern Light', 'Polar Drift', 'Emerald Sky', 'Arctic Glow', 'Midnight Ribbon', 'Boreal Haze', 'Quiet Horizon', 'After Dark']],
            'ocean' => ['Ocean', ['Blue Hour', 'Deep Current', 'Tidal Light', 'Still Water', 'Coastal Drift', 'Lagoon', 'Sea Glass', 'Moon Tide']],
            'prism' => ['Prism', ['Iridescence', 'Soft Spectrum', 'Colour Study', 'Refraction', 'Violet Hour', 'Chromatic', 'Prism Shift', 'Afterimage']],
            'ember' => ['Ember', ['Golden Hour', 'Copper Light', 'Desert Sun', 'Warm Current', 'Amber Glow', 'Sunset Study', 'Slow Burn', 'Terracotta']],
            'fluid' => ['Fluid', ['Liquid Silk', 'Soft Focus', 'Sage Flow', 'Mint Drift', 'Ebb & Flow', 'Still Motion', 'Satin', 'Olive Light']],
            'noir' => ['Noir', ['Silver Lining', 'Graphite', 'Low Light', 'Carbon', 'Monochrome', 'Shadow Play', 'Obsidian', 'Nightfall']],
        ];
    }
}
