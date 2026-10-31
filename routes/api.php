<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\WallpaperController;
use App\Http\Middleware\CatalogHeaders;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:catalog', CatalogHeaders::class])->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/home', [HomeController::class, 'index']);

    Route::get('/wallpapers', [WallpaperController::class, 'index']);
    Route::get('/wallpapers/{wallpaper}', [WallpaperController::class, 'show'])
        ->where('wallpaper', '[1-9][0-9]{0,17}');
});
