<?php

namespace App\Filament\Resources\Wallpapers\Pages;

use App\Filament\Resources\Wallpapers\WallpaperResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWallpapers extends ListRecords
{
    protected static string $resource = WallpaperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
