<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WallpaperResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'preview_image' => MediaUrl::public($this->preview_image),
            'preview_video' => MediaUrl::public($this->preview_video),
            'file_url' => MediaUrl::public($this->file_url),
            'is_premium' => (bool) $this->is_premium,
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ],
        ];
    }
}
