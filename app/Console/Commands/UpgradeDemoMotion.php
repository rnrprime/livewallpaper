<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Wallpaper;
use App\Support\CatalogCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class UpgradeDemoMotion extends Command
{
    protected $signature = 'catalog:upgrade-demo-motion {directory} {--dry-run}';

    protected $description = 'Upgrade the 48 known demo entries to versioned 60 fps media; preserve IDs and publication/premium flags';

    private const OLD = 'demo-catalog/v1/';

    private const NEW = 'demo-catalog/v2-60fps/';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $written = [];
        $committed = false;
        $lock = Cache::lock('liper-demo-motion-upgrade', 600);
        if (! $lock->get()) {
            $this->error('Another demo upgrade is in progress.');

            return self::FAILURE;
        }
        try {
            $source = realpath($this->argument('directory'));
            if (! $source || ! is_dir($source)) {
                throw new RuntimeException('Media directory does not exist.');
            }
            $manifest = json_decode(file_get_contents($source.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['version'] ?? null) !== 'v2-60fps' || ($manifest['fps'] ?? null) !== 60) {
                throw new RuntimeException('Expected the verified v2-60fps bundle.');
            }
            $files = [];
            foreach (array_keys(ImportDemoCatalog::collections()) as $slug) {
                foreach ([0, 1] as $variant) {
                    foreach (['mp4', 'jpg'] as $extension) {
                        $name = "{$slug}-{$variant}.{$extension}";
                        $path = $source.'/'.$name;
                        if (! is_file($path) || ! is_readable($path) || filesize($path) < 1 || filesize($path) > 50 * 1024 * 1024
                            || ($manifest['files'][$name] ?? '') !== hash_file('sha256', $path)) {
                            throw new RuntimeException('Missing, oversized or changed bundle file: '.$name);
                        }
                        if ($disk->exists(self::NEW.$name) && hash('sha256', $disk->get(self::NEW.$name)) !== $manifest['files'][$name]) {
                            throw new RuntimeException('Versioned destination already contains different media: '.$name);
                        }
                        $files[] = $name;
                    }
                }
            }
            // Validate every known row before copying files or changing any URL.
            $this->catalogRows();
            if ($this->option('dry-run')) {
                $this->info('Validated 48 demo entries and 24 checksummed files. No catalog or media changes made.');

                return self::SUCCESS;
            }
            foreach ($files as $name) {
                if (! $disk->exists(self::NEW.$name)) {
                    $written[] = self::NEW.$name;
                    if (! $disk->put(self::NEW.$name, file_get_contents($source.'/'.$name), ['visibility' => 'public'])) {
                        throw new RuntimeException('Could not copy '.$name);
                    }
                }
            }
            DB::transaction(function (): void {
                // Revalidate under database locks in case an admin edited the
                // catalog during the filesystem stage. Leave edited rows alone.
                foreach ($this->catalogRows(true) as [$model, $attributes]) {
                    $model->fill($attributes);
                    if ($model->isDirty()) {
                        $model->save();
                    }
                }
            });
            $committed = true;
            CatalogCache::flush();
            $this->info('Upgraded 48 demo entries to 60 fps URLs. IDs, premium and publication flags preserved; v1 files retained.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            if (! $committed) {
                foreach ($written as $path) {
                    $disk->delete($path);
                }
            }
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function catalogRows(bool $lock = false): array
    {
        $rows = [];
        foreach (ImportDemoCatalog::collections() as $slug => [$name, $titles]) {
            $query = Category::where('slug', 'liper-demo-v1-'.$slug);
            $category = ($lock ? $query->lockForUpdate() : $query)->first();
            if (! $category) {
                throw new RuntimeException('Missing demo category: '.$slug);
            }
            $this->expectPath($category->thumbnail, $slug.'-0.jpg');
            $rows[] = [$category, ['thumbnail' => self::NEW.$slug.'-0.jpg']];
            foreach ($titles as $edition => $title) {
                $query = Wallpaper::where('category_id', $category->id)->where('title', $title);
                $matches = ($lock ? $query->lockForUpdate() : $query)->get();
                if ($matches->count() !== 1) {
                    throw new RuntimeException('Missing or ambiguous demo wallpaper: '.$title);
                }
                $wallpaper = $matches->first();
                $base = $slug.'-'.($edition % 2);
                $this->expectPath($wallpaper->file_url, $base.'.mp4');
                $this->expectPath($wallpaper->preview_video, $base.'.mp4');
                $this->expectPath($wallpaper->preview_image, $base.'.jpg');
                $rows[] = [$wallpaper, ['file_url' => self::NEW.$base.'.mp4',
                    'preview_video' => self::NEW.$base.'.mp4', 'preview_image' => self::NEW.$base.'.jpg']];
            }
        }

        return $rows;
    }

    private function expectPath(?string $value, string $file): void
    {
        if (! in_array($value, [self::OLD.$file, self::NEW.$file], true)) {
            throw new RuntimeException('A demo entry has been edited; refusing to replace its media: '.$file);
        }
    }
}
