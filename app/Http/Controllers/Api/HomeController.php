<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HomeResource;
use App\Models\Category;
use App\Support\CatalogCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'between:1,10000']]);
        $page = (int) ($validated['page'] ?? 1);

        return CatalogCache::respond($request, 'home', ['page' => $page], function () use ($request, $page) {
            $categories = Category::query()
                ->select(['id', 'name', 'slug', 'thumbnail'])
                // Empty or fully unpublished categories would only show blank sections.
                ->whereHas('wallpapers', fn ($query) => $query->available())
                ->with([
                    'wallpapers' => fn ($query) => $query
                        ->select(['id', 'title', 'category_id', 'preview_image', 'file_url', 'is_premium'])
                        ->available()
                        ->orderByDesc('id')
                        ->limit(3),
                ])
                ->orderBy('name')
                ->paginate(10, ['*'], 'page', $page);

            return [
                'data' => HomeResource::collection($categories->getCollection())->resolve($request),
                'meta' => [
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                ],
            ];
        });
    }
}
