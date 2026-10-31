<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\CatalogCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        return CatalogCache::respond($request, 'categories', [], fn () => [
            'data' => CategoryResource::collection(
                Category::query()->select(['id', 'name', 'slug', 'thumbnail'])->orderBy('name')->get()
            )->resolve($request),
        ]);
    }
}
