<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Wallpaper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit opt-in fixtures; never part of DatabaseSeeder or a production deployment. */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $database = config('database.connections.sqlite.database');
        if (! app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite'
            || ($database !== ':memory:' && basename((string) $database) !== 'liper-demo.sqlite')) {
            throw new RuntimeException('Demo content requires local/testing and a dedicated liper-demo.sqlite database.');
        }

        $collections = [
            'aurora' => ['Aurora', ['Northern Light', 'Polar Drift', 'Emerald Sky', 'Arctic Glow', 'Midnight Ribbon', 'Boreal Haze', 'Quiet Horizon', 'After Dark']],
            'ocean' => ['Ocean', ['Blue Hour', 'Deep Current', 'Tidal Light', 'Still Water', 'Coastal Drift', 'Lagoon', 'Sea Glass', 'Moon Tide']],
            'prism' => ['Prism', ['Iridescence', 'Soft Spectrum', 'Colour Study', 'Refraction', 'Violet Hour', 'Chromatic', 'Prism Shift', 'Afterimage']],
            'ember' => ['Ember', ['Golden Hour', 'Copper Light', 'Desert Sun', 'Warm Current', 'Amber Glow', 'Sunset Study', 'Slow Burn', 'Terracotta']],
            'fluid' => ['Fluid', ['Liquid Silk', 'Soft Focus', 'Sage Flow', 'Mint Drift', 'Ebb & Flow', 'Still Motion', 'Satin', 'Olive Light']],
            'noir' => ['Noir', ['Silver Lining', 'Graphite', 'Low Light', 'Carbon', 'Monochrome', 'Shadow Play', 'Obsidian', 'Nightfall']],
        ];
        DB::transaction(function () use ($collections): void {
            $categories = [];
            foreach ($collections as $slug => [$name]) {
                $categories[$slug] = Category::updateOrCreate(['slug' => 'demo-'.$slug], [
                    'name' => $name, 'thumbnail' => "https://localhost:9443/media/{$slug}-0.jpg",
                ]);
            }
            // Interleave categories so the initial feed exercises a realistic mixed catalog.
            for ($edition = 7; $edition >= 0; $edition--) {
                foreach (array_reverse($collections, true) as $slug => [$name, $titles]) {
                    $media = $slug.'-'.($edition % 2);
                    Wallpaper::updateOrCreate([
                        'category_id' => $categories[$slug]->id, 'title' => $titles[$edition],
                    ], [
                        'preview_image' => "https://localhost:9443/media/{$media}.jpg",
                        'preview_video' => "https://localhost:9443/media/{$media}.mp4",
                        'file_url' => "https://localhost:9443/media/{$media}.mp4",
                        'is_premium' => $edition % 3 === 1, 'is_published' => true,
                    ]);
                }
            }
        });
        Cache::forget('api.v1.categories');
    }
}
