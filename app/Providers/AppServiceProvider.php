<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
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
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?? $r->ip()));
        Paginator::defaultView('vendor.pagination.skladex');
        Paginator::defaultSimpleView('vendor.pagination.skladex-simple');
    }
}
