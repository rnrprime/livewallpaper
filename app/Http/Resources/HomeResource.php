<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'category' => [
                'id' => $this->id,
                'name' => $this->name,
                'slug' => $this->slug,
                'thumbnail' => MediaUrl::public($this->thumbnail),
            ],
            'wallpapers' => $this->whenLoaded('wallpapers', fn () => $this->wallpapers->map(fn ($wallpaper) => [
                'id' => $wallpaper->id,
                'title' => $wallpaper->title,
                'preview_image' => MediaUrl::public($wallpaper->preview_image),
                'file_url' => MediaUrl::public($wallpaper->file_url),
                'is_premium' => (bool) $wallpaper->is_premium,
            ])->values()->all(), []),
        ];
    }
}
