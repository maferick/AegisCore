{{--
    /admin/eve-service-character

    Operator surface for the EVE service-character SSO flow:

      - Status panel: who is authorised, what scopes they granted, when
        the access token expires, who clicked Authorise.
      - One CTA: "Authorise / Re-authorise service character" — links to
        /auth/eve/service-redirect, which kicks off the elevated-scope
        SSO round-trip.

    No tokens (access or refresh) are ever sent to the view scope. The
    page constructs `$token` as a status snapshot in the controller
    side, deliberately omitting the encrypted columns, so an accidental
    @json($token) here can't dump bearer tokens into the page.

    Stylistically: stock Filament classes only — no Tailwind build step
    runs in phase 1, just the framework's compiled CSS bundle.
--}}
<x-filament-panels::page>
    @if (! $sso_configured)
        {{-- Same condition that hides the nav entry; defensive in case
             someone deep-links to the page after EVE_SSO_* got cleared. --}}
        <x-filament::section>
            <x-slot name="heading">EVE SSO not configured</x-slot>
            <x-slot name="description">
                Set <code>EVE_SSO_CLIENT_ID</code>, <code>EVE_SSO_CLIENT_SECRET</code>,
                and <code>EVE_SSO_CALLBACK_URL</code> in <code>.env</code>, then
                <code>php artisan config:clear</code> and reload.
            </x-slot>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Service character</x-slot>
            <x-slot name="description">
                One stored EVE token per AegisCore stack. The Python
                execution plane (and any phase-1 Laravel callers needing
                authed ESI) consumes this token to poll character /
                corp / alliance / market endpoints. Re-auth at any time
                to rotate the access + refresh tokens or grant additional
                scopes.
            </x-slot>

            @if ($token === null)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No service character authorised yet.
                    Click the button below to start the SSO round-trip.
                </p>
            @else
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Character
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ $token['character_name'] }}
                            <span class="ml-1 font-mono text-xs text-gray-500">
                                #{{ $token['character_id'] }}
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Access token expires
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ $token['expires_at']->toIso8601String() }}
                            <span class="ml-1 text-xs text-gray-500">
                                ({{ $token['expires_at']->diffForHumans() }})
                            </span>
                            @if ($token['is_fresh'])
                                <x-filament::badge color="success" class="ml-2">fresh</x-filament::badge>
                            @else
                                <x-filament::badge color="warning" class="ml-2">stale — needs refresh</x-filament::badge>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Authorised by
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ $token['authorized_by'] ?? '— (no audit trail)' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Last updated
                        </dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ $token['updated_at']->toIso8601String() }}
                            <span class="ml-1 text-xs text-gray-500">
                                ({{ $token['updated_at']->diffForHumans() }})
                            </span>
                        </dd>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Granted scopes ({{ count($token['scopes']) }})
                        </dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            @forelse ($token['scopes'] as $scope)
                                <x-filament::badge color="info">{{ $scope }}</x-filament::badge>
                            @empty
                                <span class="text-sm text-gray-500">none granted</span>
                            @endforelse
                        </dd>
                    </div>
                </dl>

                @php
                    $missing = array_values(array_diff($service_scopes, $token['scopes']));
                @endphp
                @if (! empty($missing))
                    <div class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/30">
                        <p class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-300">
                            Configured scopes not yet granted
                        </p>
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-200">
                            <code>EVE_SSO_SERVICE_SCOPES</code> requests scopes that
                            this token doesn't grant. Re-authorise to ask the
                            character for the missing ones:
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($missing as $scope)
                                <x-filament::badge color="warning">{{ $scope }}</x-filament::badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif

            <div class="mt-6 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                <a
                    href="{{ $service_redirect_url }}"
                    class="fi-btn fi-btn-color-primary fi-btn-size-md inline-flex items-center justify-center gap-2 rounded-lg border border-primary-600 bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-700 dark:border-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400"
                >
                    {{ $token === null ? 'Authorise service character' : 'Re-authorise (rotate tokens / extend scopes)' }}
                </a>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    You'll be redirected to <code>login.eveonline.com</code> to pick a
                    character and approve the requested scope set, then sent back here.
                </p>
            </div>
        </x-filament::section>

        @if (empty($service_scopes))
            <x-filament::section>
                <x-slot name="heading">No service scopes configured</x-slot>
                <x-slot name="description">
                    <code>EVE_SSO_SERVICE_SCOPES</code> is empty in <code>.env</code>,
                    so re-authorisation would request no scopes — no useful ESI
                    access. Set the variable to the scope list you want
                    (see <code>.env.example</code> for a sensible default).
                </x-slot>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Configured scope set</x-slot>
                <x-slot name="description">
                    Reflects <code>EVE_SSO_SERVICE_SCOPES</code> from the running
                    container. Re-authorisation will request exactly this set.
                </x-slot>

                <div class="flex flex-wrap gap-1.5">
                    @foreach ($service_scopes as $scope)
                        <x-filament::badge color="info">{{ $scope }}</x-filament::badge>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
