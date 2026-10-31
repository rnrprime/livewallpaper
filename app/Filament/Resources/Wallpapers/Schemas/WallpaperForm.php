<?php

namespace App\Filament\Resources\Wallpapers\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WallpaperForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Select::make('category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                FileUpload::make('preview_image')
                    ->image()
                    ->imagePreviewHeight('160')
                    ->disk('public')
                    ->visibility('public')
                    ->directory('wallpapers/images')
                    ->acceptedFileTypes(['image/jpeg', 'image/png'])
                    ->rules(['image', 'mimes:jpg,jpeg,png'])
                    ->required(),
                FileUpload::make('file_url')
                    ->disk('public')
                    ->visibility('public')
                    ->directory('wallpapers/videos')
                    ->acceptedFileTypes(['video/mp4', 'video/quicktime'])
                    ->rules(['file', 'mimetypes:video/mp4,video/quicktime', 'mimes:mp4,mov'])
                    ->maxSize(51200)
                    ->required(),
                FileUpload::make('preview_video')
                    ->label('Lightweight preview video (optional)')
                    ->helperText('Upload a short silent MP4, ideally under 3 MB, for efficient feed playback.')
                    ->disk('public')->visibility('public')->directory('wallpapers/previews')
                    ->acceptedFileTypes(['video/mp4'])->rules(['file', 'mimetypes:video/mp4', 'mimes:mp4'])
                    ->maxSize(5120),
                Toggle::make('is_published')->default(true)->inline(false),
                Toggle::make('is_premium')
                    ->default(false)
                    ->inline(false),
            ]);
    }
}
