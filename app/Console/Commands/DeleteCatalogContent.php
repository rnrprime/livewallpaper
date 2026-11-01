<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Wallpaper;
use App\Support\CatalogCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently deletes named categories with all their wallpapers and media files.
 *
 * Only files stored on the public disk are removed, and never one that a remaining
 * wallpaper or category still uses. --directory also removes a whole media folder,
 * but only when nothing remaining references a file inside it.
 */
class DeleteCatalogContent extends Command
{
    protected $signature = 'catalog:delete
        {--category=* : Slug of a category to delete with all of its wallpapers}
        {--directory=* : Media folder on the public disk to remove once nothing uses it}
        {--force : Delete; without it the command only lists what would be deleted}';

    protected $description = 'Permanently delete categories, their wallpapers and their media files';

    public function handle(): int
    {
        $slugs = array_values(array_unique($this->option('category')));
        $directories = array_values(array_unique(array_map(fn ($d) => trim($d, '/'), $this->option('directory'))));
        foreach ($directories as $directory) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*(\/[A-Za-z0-9][A-Za-z0-9._-]*)*$/', $directory)) {
                $this->error("Refusing unsafe directory: {$directory}");

                return self::FAILURE;
            }
        }
        $categories = Category::whereIn('slug', $slugs)->get();
        $missing = array_diff($slugs, $categories->pluck('slug')->all());
        if ($missing !== []) {
            $this->error('Unknown categories: '.implode(', ', $missing).'. Nothing deleted.');

            return self::FAILURE;
        }
        if ($categories->isEmpty() && $directories === []) {
            $this->error('Name at least one --category or --directory.');

            return self::FAILURE;
        }

        $wallpapers = Wallpaper::whereIn('category_id', $categories->pluck('id'))->get();
        $paths = $wallpapers->flatMap(fn ($w) => [$w->preview_image, $w->preview_video, $w->file_url])
            ->merge($categories->pluck('thumbnail'));
        $stillUsed = $this->referencedPaths($categories->pluck('id')->all());
        $files = $paths->filter(fn ($p) => is_string($p) && $p !== '' && ! preg_match('#^[a-z]+://#i', $p))
            ->unique()->reject(fn ($p) => isset($stillUsed[$p]))->values();
        foreach ($directories as $directory) {
            $used = collect(array_keys($stillUsed))->first(fn ($p) => str_starts_with($p, $directory.'/'));
            if ($used !== null) {
                $this->error("{$directory} is still used by remaining content ({$used}). Nothing deleted.");

                return self::FAILURE;
            }
        }

        foreach ($categories as $category) {
            $count = $wallpapers->where('category_id', $category->id)->count();
            $this->line("category {$category->slug} ({$category->name}): {$count} wallpapers");
        }
        $this->line(count($files).' media files'.($directories ? ', folders: '.implode(', ', $directories) : ''));

        if (! $this->option('force')) {
            $this->warn('Dry run: nothing deleted. Add --force to delete permanently.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($wallpapers, $categories): void {
            Wallpaper::whereKey($wallpapers->modelKeys())->delete();
            Category::whereKey($categories->modelKeys())->delete();
        });
        CatalogCache::flush();

        $disk = Storage::disk('public');
        $deleted = 0;
        foreach ($files as $file) {
            if ($disk->exists($file) && $disk->delete($file)) {
                $deleted++;
            }
        }
        foreach ($directories as $directory) {
            if ($disk->exists($directory)) {
                $disk->deleteDirectory($directory);
            }
        }
        $this->info(sprintf('Deleted %d categories, %d wallpapers and %d media files%s.',
            $categories->count(), $wallpapers->count(), $deleted, $directories ? ' plus '.implode(', ', $directories) : ''));

        return self::SUCCESS;
    }

    /** Media paths used by everything that is not being deleted. */
    private function referencedPaths(array $deletedCategoryIds): array
    {
        $used = [];
        Wallpaper::whereNotIn('category_id', $deletedCategoryIds)
            ->select(['preview_image', 'preview_video', 'file_url'])
            ->each(function ($w) use (&$used): void {
                foreach ([$w->preview_image, $w->preview_video, $w->file_url] as $p) {
                    if ($p) {
                        $used[$p] = true;
                    }
                }
            });
        Category::whereNotIn('id', $deletedCategoryIds)->whereNotNull('thumbnail')->pluck('thumbnail')
            ->each(function ($p) use (&$used): void {
                $used[$p] = true;
            });

        return $used;
    }
}
