{{--
    /admin/eve-donations

    Operator surface for the EVE donations character flow:

      - Token status panel: who is authorised, what scopes they granted,
        when the access token expires, who clicked Authorise.
      - Authorise CTA: links to /auth/eve/donations-redirect, which kicks
        off the wallet-read SSO round-trip locked to the configured
        character ID.
      - Recent donations ledger: most-recent rows from `eve_donations`,
        each with donor name (resolved via /universe/names/), ISK amount
        and the in-game reason text if the donor entered one.
      - Aggregate footer: total ISK received and unique donor count, both
        SUM'd in SQL to keep DECIMAL(20, 2) precision exact.

    No tokens (access or refresh) are sent to the view scope. The page
    constructs `$token` as a status snapshot in the controller side so
    an accidental @json($token) here can't dump bearer tokens.
--}}
<x-filament-panels::page>
    @if (! $sso_configured)
        <x-filament::section>
            <x-slot name="heading">EVE SSO not configured</x-slot>
            <x-slot name="description">
                Set <code>EVE_SSO_CLIENT_ID</code>, <code>EVE_SSO_CLIENT_SECRET</code>,
                and <code>EVE_SSO_CALLBACK_URL</code> in <code>.env</code>, then
                <code>php artisan config:clear</code> and reload.
            </x-slot>
        </x-filament::section>
    @elseif (! $donations_configured)
        <x-filament::section>
            <x-slot name="heading">Donations character not configured</x-slot>
            <x-slot name="description">
                Set <code>EVE_SSO_DONATIONS_CHARACTER_ID</code> (and optionally
                <code>EVE_SSO_DONATIONS_CHARACTER_NAME</code>) in <code>.env</code>,
                then <code>php artisan config:clear</code> and reload. The donations
                flow is locked to a single character ID so wrong-character
                authorisations bounce instead of leaking a token.
            </x-slot>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Donations character</x-slot>
            <x-slot name="description">
                Single in-game character that receives ISK donations. The
                wallet poller runs every 5 minutes (configurable via
                <code>EVE_DONATIONS_POLL_CRON</code>) and records new
                <code>player_donation</code> entries below. Donations remove
                future advertisements automatically — the donor's account is
                linked through their character ID when they next log in,
                no manual step needed.
            </x-slot>

            @if ($token === null)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No donations character authorised yet.
                    @if ($expected_character_name)
                        Expected character: <strong>{{ $expected_character_name }}</strong>
                        (#{{ $expected_character_id }}).
                    @else
                        Expected character ID: <strong>#{{ $expected_character_id }}</strong>.
                    @endif
                    Click the button below to start the SSO round-trip — you'll
                    need to be logged into EVE SSO as that exact character.
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
                                <x-filament::badge color="warning" class="ml-2">stale — poller will refresh</x-filament::badge>
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

                @if ($token['character_id'] !== $expected_character_id)
                    {{-- Token row exists but doesn't match the env-locked
                         character. SSO callback should never let this in
                         (it rejects mismatches), so this is a stale row
                         from before the env was changed. The poller
                         already refuses to use it; surface the warning so
                         the operator knows to re-authorise. --}}
                    <div class="mt-4 rounded-md border border-rose-300 bg-rose-50 p-3 dark:border-rose-700 dark:bg-rose-900/30">
                        <p class="text-xs font-semibold uppercase tracking-wider text-rose-700 dark:text-rose-300">
                            Stored token does not match configured character
                        </p>
                        <p class="mt-1 text-xs text-rose-700 dark:text-rose-200">
                            Stored: <strong>{{ $token['character_name'] }}</strong>
                            (#{{ $token['character_id'] }}). Configured:
                            #{{ $expected_character_id }}. The poller is refusing
                            to use this token. Re-authorise as the correct
                            character to continue.
                        </p>
                    </div>
                @endif
            @endif

            <div class="mt-6 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                <a
                    href="{{ $donations_redirect_url }}"
                    class="fi-btn fi-btn-color-primary fi-btn-size-md inline-flex items-center justify-center gap-2 rounded-lg border border-primary-600 bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-700 dark:border-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400"
                >
                    {{ $token === null ? 'Authorise donations character' : 'Re-authorise (rotate tokens)' }}
                </a>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    You'll be redirected to <code>login.eveonline.com</code> to
                    approve wallet-read access. The callback rejects any
                    character ID other than the configured one.
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Recent donations
                <span class="ml-2 text-xs font-normal text-gray-500">
                    showing latest {{ $donations->count() }}
                </span>
            </x-slot>
            <x-slot name="description">
                Captured from <code>player_donation</code> wallet journal
                entries. Donor names resolve via <code>/universe/names/</code>
                shortly after each donation appears.
            </x-slot>

            @if ($donations->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No donations recorded yet. The poller writes a row the
                    first time a <code>player_donation</code> appears in the
                    wallet journal.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4">When</th>
                                <th class="py-2 pr-4">Donor</th>
                                <th class="py-2 pr-4 text-right">Amount (ISK)</th>
                                <th class="py-2">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($donations as $donation)
                                <tr>
                                    <td class="py-2 pr-4 align-top text-gray-900 dark:text-gray-100">
                                        {{ $donation['donated_at']->toIso8601String() }}
                                        <div class="text-xs text-gray-500">
                                            {{ $donation['donated_at']->diffForHumans() }}
                                        </div>
                                    </td>
                                    <td class="py-2 pr-4 align-top text-gray-900 dark:text-gray-100">
                                        {{ $donation['donor_name'] ?? '— (resolving…)' }}
                                        <div class="font-mono text-xs text-gray-500">
                                            #{{ $donation['donor_character_id'] }}
                                        </div>
                                    </td>
                                    <td class="py-2 pr-4 text-right align-top font-mono text-gray-900 dark:text-gray-100">
                                        {{ number_format((float) $donation['amount'], 2, '.', ',') }}
                                    </td>
                                    <td class="py-2 align-top text-gray-700 dark:text-gray-300">
                                        @if ($donation['reason'])
                                            <span class="italic">{{ $donation['reason'] }}</span>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 text-sm font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">
                                <td class="py-2 pr-4">Totals</td>
                                <td class="py-2 pr-4">{{ $donor_count }} unique donor{{ $donor_count === 1 ? '' : 's' }}</td>
                                <td class="py-2 pr-4 text-right font-mono">
                                    {{ number_format((float) $donation_total, 2, '.', ',') }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
