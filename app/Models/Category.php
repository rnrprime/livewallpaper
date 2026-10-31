<?php

namespace App\Models;

use App\Support\CatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'thumbnail',
    ];

    public function wallpapers(): HasMany
    {
        return $this->hasMany(Wallpaper::class);
    }

    /** Any change makes every rendered catalog response stale. */
    protected static function booted(): void
    {
        static::saved(fn () => CatalogCache::flush());
        static::deleted(fn () => CatalogCache::flush());
    }
}
