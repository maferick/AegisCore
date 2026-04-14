<?php

namespace App\Providers;

use App\Domains\UsersCharacters\Services\DonorBenefitCalculator;
use App\Livewire\AccountSettings;
use App\Services\Eve\Esi\CachedEsiClient;
use App\Services\Eve\Esi\EsiClient;
use App\Services\Eve\Esi\EsiClientInterface;
use App\Services\Eve\Esi\EsiRateLimiter;
use App\Services\Eve\Sso\EveSsoClient;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // EveSsoClient: four primitive deps (client_id, secret, redirect
        // URI, default scopes) pulled from config('eve.sso'). Every
        // caller today does EveSsoClient::fromConfig() by hand — that
        // works for the controller + donations poller, but MarketTokenAuthorizer
        // (and by extension the Livewire AccountSettings search action)
        // gets auto-wired via the container, and died with
        // "Unresolvable dependency resolving [Parameter #0 <required>
        // string $clientId]" the first time it was invoked.
        //
        // Singleton: EveSsoClient is stateless except for its config,
        // and the config is itself fronted by Laravel's config repo
        // which is already a singleton. A singleton here is what
        // `EsiClient` above uses for the same reason.
        $this->app->singleton(EveSsoClient::class, fn () => EveSsoClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicit alias for the /account/settings Livewire component.
        //
        // The class lives at `App\Livewire\AccountSettings` (single
        // segment), and Livewire's auto-discovery derives the dash-case
        // alias `account-settings`. The blade view mounts it as
        // `account.settings` (dot-case, which Livewire interprets as a
        // subdirectory `App\Livewire\Account\Settings`) — so the
        // auto-discovery miss fires a ComponentNotFoundException on
        // every page render and the whole route 500s.
        //
        // Registering the alias manually fixes the mismatch without
        // moving the class or touching the view. If the component later
        // moves to `App\Livewire\Account\Settings` the alias becomes
        // redundant and can be dropped — until then this line is the
        // chokepoint that keeps /account/settings reachable.
        Livewire::component('account.settings', AccountSettings::class);
    }
}
