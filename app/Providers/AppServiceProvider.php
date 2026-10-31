<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(max(1, (int) config('catalog.rate_limit')))
            ->by($request->ip())
        );

        // /up reports unhealthy when the database is unreachable, so monitors catch it.
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));
    }
}
