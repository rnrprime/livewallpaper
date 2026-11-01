<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Wallpaper;
use App\Support\CatalogCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Imports a wallpaper pack built by Tools/WallpaperForge (pack.json + media/).
 *
 * Wallpapers are identified by their stored video path, so re-running a pack is safe and
 * existing entries are updated in place. The first wallpaper listed ends up first in the
 * feed. Media is checksummed before anything is written and is never overwritten.
 */
class ImportCatalogPack extends Command
{
    protected $signature = 'catalog:import-pack {directory : Pack directory containing pack.json and media/}
        {--dry-run : Validate everything without changing files or the database}
        {--publish : Publish the pack\'s wallpapers (new ones are drafts otherwise)}
        {--retire-demo : Unpublish the placeholder demo catalog}';

    protected $description = 'Import a WallpaperForge pack of categories, videos and posters';

    private const MAX_BYTES = 50 * 1024 * 1024;

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $created = [];
        try {
            $source = realpath($this->argument('directory'));
            if (! $source || ! is_file($source.'/pack.json')) {
                throw new RuntimeException('No pack.json in that directory.');
            }
            $pack = $this->validatedPack($source);
            $prefix = 'catalog/'.$pack['version'].'/';

            $files = [];
            foreach ($pack['wallpapers'] as $wallpaper) {
                foreach (['video', 'preview', 'poster'] as $kind) {
                    $files[$wallpaper[$kind]] = $wallpaper['sha256'][$kind];
                }
            }
            foreach ($files as $relative => $sha) {
                $path = $source.'/'.$relative;
                if (! is_file($path) || filesize($path) === 0 || filesize($path) > self::MAX_BYTES) {
                    throw new RuntimeException('Missing, empty or oversized file: '.$relative);
                }
                if (! hash_equals($sha, hash_file('sha256', $path))) {
                    throw new RuntimeException('Checksum mismatch: '.$relative);
                }
                $target = $prefix.basename($relative);
                if ($disk->exists($target) && ! hash_equals($sha, hash('sha256', $disk->get($target)))) {
                    throw new RuntimeException('A different file already exists on the server: '.$target);
                }
            }

            $counts = ['wallpapers' => count($pack['wallpapers']), 'categories' => count($pack['categories']), 'files' => count($files)];
            if ($this->option('dry-run')) {
                $this->info(sprintf('Pack %s is valid: %d categories, %d wallpapers, %d files. No changes made.',
                    $pack['version'], $counts['categories'], $counts['wallpapers'], $counts['files']));

                return self::SUCCESS;
            }

            foreach (array_keys($files) as $relative) {
                $target = $prefix.basename($relative);
                if ($disk->exists($target)) {
                    continue;
                }
                // Tracked before writing: a write cut short (disk full, quota) leaves a partial file
                // that must be removed too, or it would block the next run as a "different file".
                $created[] = $target;
                $stream = fopen($source.'/'.$relative, 'rb');
                try {
                    $ok = $disk->put($target, $stream, ['visibility' => 'public']);
                } finally {
                    fclose($stream);
                }
                if (! $ok) {
                    throw new RuntimeException('Could not write '.$target.' (disk full or over quota?)');
                }
            }

            [$new, $updated, $retired] = DB::transaction(fn () => $this->writeCatalog($pack, $prefix));
        } catch (Throwable $error) {
            foreach ($created as $file) {
                $disk->delete($file);
            }
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        CatalogCache::flush();
        $this->info(sprintf('Imported pack %s: %d new and %d updated wallpapers in %d categories, %d new files.',
            $pack['version'], $new, $updated, $counts['categories'], count($created)));
        if ($retired > 0) {
            $this->info("Unpublished {$retired} demo wallpapers.");
        }
        if (! $this->option('publish') && $new > 0) {
            $this->warn('New wallpapers are drafts. Run again with --publish when ready.');
        }

        return self::SUCCESS;
    }

    /** @return array{int, int, int} new, updated and retired counts */
    private function writeCatalog(array $pack, string $prefix): array
    {
        $categories = [];
        foreach ($pack['categories'] as $slug => $name) {
            $firstPoster = collect($pack['wallpapers'])->firstWhere('category', $slug)['poster'] ?? null;
            $thumbnail = $firstPoster ? $prefix.basename($firstPoster) : null;
            $category = Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'thumbnail' => $thumbnail]);
            if (! $category->thumbnail && $thumbnail) {
                $category->update(['thumbnail' => $thumbnail]);
            }
            $categories[$slug] = $category;
        }

        $new = $updated = 0;
        // Insert from the end so the first wallpaper listed gets the highest id (top of the feed).
        foreach (array_reverse($pack['wallpapers']) as $entry) {
            $wallpaper = Wallpaper::firstOrNew(['file_url' => $prefix.basename($entry['video'])]);
            $isNew = ! $wallpaper->exists;
            $wallpaper->fill([
                'title' => $entry['title'],
                'category_id' => $categories[$entry['category']]->id,
                'preview_image' => $prefix.basename($entry['poster']),
                'preview_video' => $prefix.basename($entry['preview']),
                'is_premium' => false,
            ]);
            if ($isNew) {
                $wallpaper->is_published = (bool) $this->option('publish');
            } elseif ($this->option('publish')) {
                $wallpaper->is_published = true;
            }
            if ($isNew || $wallpaper->isDirty()) {
                $wallpaper->save();
                $isNew ? $new++ : $updated++;
            }
        }

        $retired = 0;
        if ($this->option('retire-demo')) {
            $retired = Wallpaper::where('file_url', 'like', 'demo-catalog/%')->where('is_published', true)
                ->update(['is_published' => false]);
        }

        return [$new, $updated, $retired];
    }

    private function validatedPack(string $source): array
    {
        $pack = json_decode((string) file_get_contents($source.'/pack.json'), true);
        $fail = fn (string $why) => throw new RuntimeException('Invalid pack.json: '.$why);

        if (! is_array($pack) || ($pack['format'] ?? null) !== 1) {
            $fail('unsupported format');
        }
        if (! is_string($pack['version'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $pack['version'])) {
            $fail('version must be lowercase letters, digits and dashes');
        }
        $categories = $pack['categories'] ?? null;
        if (! is_array($categories) || $categories === []) {
            $fail('no categories');
        }
        foreach ($categories as $slug => $name) {
            if (! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', (string) $slug) || ! is_string($name) || $name === '' || mb_strlen($name) > 60) {
                $fail('bad category '.$slug);
            }
        }
        $wallpapers = $pack['wallpapers'] ?? null;
        if (! is_array($wallpapers) || $wallpapers === [] || count($wallpapers) > 5000) {
            $fail('wallpapers must be a list of 1 to 5000 entries');
        }
        $seen = [];
        foreach ($wallpapers as $i => $entry) {
            $key = $entry['key'] ?? null;
            if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $key) || isset($seen[$key])) {
                $fail("wallpaper #{$i} has a missing, invalid or duplicate key");
            }
            $seen[$key] = true;
            if (! is_string($entry['title'] ?? null) || $entry['title'] === '' || mb_strlen($entry['title']) > 120) {
                $fail("{$key}: bad title");
            }
            if (! isset($categories[$entry['category'] ?? ''])) {
                $fail("{$key}: unknown category");
            }
            foreach (['video' => 'mp4', 'preview' => 'mp4', 'poster' => 'jpg'] as $kind => $extension) {
                // Only plain files directly inside media/: no traversal, no surprises on disk.
                if (! is_string($entry[$kind] ?? null) || ! preg_match('/^media\/[a-z0-9][a-z0-9-]{0,95}\.'.$extension.'$/', $entry[$kind])) {
                    $fail("{$key}: bad {$kind} path");
                }
                if (! is_string($entry['sha256'][$kind] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $entry['sha256'][$kind])) {
                    $fail("{$key}: bad {$kind} checksum");
                }
            }
        }

        return $pack;
    }
}
