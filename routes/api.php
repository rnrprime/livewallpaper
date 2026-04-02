<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\WallpaperController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/home', [HomeController::class, 'index']);

    Route::get('/wallpapers', [WallpaperController::class, 'index']);
    Route::get('/wallpapers/{wallpaper}', [WallpaperController::class, 'show'])
        ->whereNumber('wallpaper');
});
