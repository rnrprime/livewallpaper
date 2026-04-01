<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WallpaperResource;
use App\Models\Category;
use App\Models\Wallpaper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WallpaperController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer'],
        ]);

        if (filled($validated['category_id'] ?? null)) {
            $categoryExists = Category::query()
                ->whereKey($validated['category_id'])
                ->exists();

            if (! $categoryExists) {
                return response()->json([
                    'data' => null,
                    'message' => 'Category not found',
                ], 404);
            }
        }

        $wallpapers = Wallpaper::query()
            ->select(['id', 'title', 'category_id', 'preview_image', 'file_url', 'is_premium', 'created_at'])
            ->with(['category:id,name'])
            ->when(
                filled($validated['category_id'] ?? null),
                fn ($query) => $query->where('category_id', $validated['category_id'])
            )
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return WallpaperResource::collection($wallpapers);
    }

    public function show(Wallpaper $wallpaper): WallpaperResource
    {
        $wallpaper->loadMissing('category:id,name');

        return new WallpaperResource($wallpaper);
    }
}
