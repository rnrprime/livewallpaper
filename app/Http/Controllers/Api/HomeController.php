<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HomeResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'thumbnail'])
            ->with([
                'wallpapers' => fn ($query) => $query
                    ->select(['id', 'title', 'category_id', 'preview_image', 'file_url', 'is_premium', 'created_at'])
                    ->latest('created_at')
                    ->limit(3),
            ])
            ->orderBy('name')
            ->paginate(10);

        return response()->json([
            'data' => HomeResource::collection($categories->getCollection())->resolve(),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
            ],
        ]);
    }
}
