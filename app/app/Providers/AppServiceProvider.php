<?php

namespace App\Providers;

use App\Services\Eve\Esi\EsiClient;
use App\Services\Eve\Esi\EsiRateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ESI client + rate limiter both have primitive constructor deps
        // (base URL, user agent, timeouts, safety margin…) pulled from
        // config('eve.esi'). The container can't autowire those, so type-
        // hinted callers — e.g. `handle(EsiClient $esi)` on the donations
        // poller — need an explicit binding or they die with
        // "Unresolvable dependency resolving [Parameter #0 <required> string $baseUrl]".
        // Singleton because both objects are stateless wrt. the current
        // request and their underlying deps (Cache, Http) are already
        // Laravel singletons. Tests can override with `->instance()`.
        $this->app->singleton(EsiRateLimiter::class, fn () => EsiRateLimiter::fromConfig());
        $this->app->singleton(EsiClient::class, fn () => EsiClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
