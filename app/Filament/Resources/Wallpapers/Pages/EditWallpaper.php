<?php

namespace App\Filament\Resources\Wallpapers\Pages;

use App\Filament\Resources\Wallpapers\WallpaperResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWallpaper extends EditRecord
{
    protected static string $resource = WallpaperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
