<?php

namespace App\Providers;

use App\Domains\UsersCharacters\Services\DonorBenefitCalculator;
use App\Services\Eve\Esi\CachedEsiClient;
use App\Services\Eve\Esi\EsiClient;
use App\Services\Eve\Esi\EsiClientInterface;
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

        // EsiClientInterface resolves to the payload-caching decorator by
        // default: fresh hits skip the network, 304s replay a usable body,
        // transient upstream failures serve the last-good body. Caller code
        // should type-hint the interface, not either concrete, so the
        // decorator applies transparently. The kill switch flips the
        // binding back to the bare transport without a deploy if a cache
        // correctness issue ever needs isolating.
        $this->app->singleton(EsiClientInterface::class, function ($app) {
            $inner = $app->make(EsiClient::class);

            if (! (bool) config('eve.esi.payload_cache_enabled', true)) {
                return $inner;
            }

            return CachedEsiClient::fromConfig($inner);
        });

        // Same pattern for the donor-benefits calculator: its rate comes
        // from config('eve.donations.isk_per_day'), which the container
        // can't autowire into a primitive `int $iskPerDay` parameter.
        // Not a singleton because the config value may change between
        // requests in tests (singleton would cache the first value).
        $this->app->bind(DonorBenefitCalculator::class, fn () => DonorBenefitCalculator::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
