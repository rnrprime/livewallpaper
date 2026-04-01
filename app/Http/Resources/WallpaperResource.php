<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class WallpaperResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'preview_image' => $this->preview_image ? Storage::disk('public')->url($this->preview_image) : null,
            'file_url' => $this->file_url ? Storage::disk('public')->url($this->file_url) : null,
            'is_premium' => (bool) $this->is_premium,
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ],
        ];
    }
}
