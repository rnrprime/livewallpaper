<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

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
}
