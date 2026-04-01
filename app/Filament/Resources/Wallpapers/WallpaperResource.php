<?php

namespace App\Filament\Resources\Wallpapers;

use App\Filament\Resources\Wallpapers\Pages\CreateWallpaper;
use App\Filament\Resources\Wallpapers\Pages\EditWallpaper;
use App\Filament\Resources\Wallpapers\Pages\ListWallpapers;
use App\Filament\Resources\Wallpapers\Schemas\WallpaperForm;
use App\Filament\Resources\Wallpapers\Tables\WallpapersTable;
use App\Models\Wallpaper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WallpaperResource extends Resource
{
    protected static ?string $model = Wallpaper::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return WallpaperForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WallpapersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWallpapers::route('/'),
            'create' => CreateWallpaper::route('/create'),
            'edit' => EditWallpaper::route('/{record}/edit'),
        ];
    }
}
