<?php

namespace App\Models;

use App\Support\CatalogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallpaper extends Model
{
    protected $fillable = [
        'title',
        'category_id',
        'preview_image',
        'file_url',
        'is_premium',
        'is_published',
        'preview_video',
    ];

    protected function casts(): array
    {
        return ['is_premium' => 'boolean', 'is_published' => 'boolean'];
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_published', true)->whereNotNull('file_url')->where('file_url', '!=', '')
            ->whereNotNull('preview_image')->where('preview_image', '!=', '');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Any change makes every rendered catalog response stale. */
    protected static function booted(): void
    {
        static::saved(fn () => CatalogCache::flush());
        static::deleted(fn () => CatalogCache::flush());
    }
}
