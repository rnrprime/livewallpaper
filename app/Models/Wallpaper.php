<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Wallpaper extends Model
{
    protected $fillable = [
        'title',
        'category_id',
        'preview_image',
        'file_url',
        'is_premium',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
