<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class HomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'category' => [
                'id' => $this->id,
                'name' => $this->name,
                'slug' => $this->slug,
                'thumbnail' => $this->thumbnail ? Storage::disk('public')->url($this->thumbnail) : null,
            ],
            'wallpapers' => $this->whenLoaded('wallpapers', fn () => $this->wallpapers->map(fn ($wallpaper) => [
                'id' => $wallpaper->id,
                'title' => $wallpaper->title,
                'preview_image' => $wallpaper->preview_image ? Storage::disk('public')->url($wallpaper->preview_image) : null,
                'file_url' => $wallpaper->file_url ? Storage::disk('public')->url($wallpaper->file_url) : null,
                'is_premium' => (bool) $wallpaper->is_premium,
            ])->values()->all(), []),
        ];
    }
}
