{{--
    /admin/eve-donations

    Operator surface for the EVE donations character flow:

      - Token status panel: who is authorised, what scopes they granted,
        when the access token expires, who clicked Authorise.
      - Authorise CTA: links to /auth/eve/donations-redirect, which kicks
        off the wallet-read SSO round-trip locked to the configured
        character ID.
      - Active donors grid: one card per donor with an unexpired
        `ad_free_until`. Shows the CCP-CDN portrait, name + ID,
        accumulated ISK, donation count, and a "X days remaining"
        countdown. Sorted longest-coverage-first.
      - Expired donors section: fades donors whose window has elapsed.
        Their `isDonor()` flipped back to false automatically — no cron
        demoted them.
      - Recent donations ledger: raw rows from `eve_donations`, each with
        portrait, name (resolved via /universe/names/), ISK amount, and
        the in-game reason text if the donor entered one.
      - Aggregate footer: total ISK received and unique donor count, both
        SUM'd in SQL to keep DECIMAL(20, 2) precision exact.

    No tokens (access or refresh) are sent to the view scope. The page
    constructs `$token` as a status snapshot in the controller side so
    an accidental @json($token) here can't dump bearer tokens.

    Portrait images come from `images.evetech.net`, the public CCP CDN.
    No auth required; long cache; CORS-friendly. Donor cards display at
    64px (retina: 128px source).
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
        {{-- ========== Token / character status ========== --}}
        <x-filament::section>
            <x-slot name="heading">Donations character</x-slot>
            <x-slot name="description">
                Single in-game character that receives ISK donations. The
                wallet poller runs every 5 minutes (configurable via
                <code>EVE_DONATIONS_POLL_CRON</code>) and records new
                <code>player_donation</code> entries below. Donations grant
                ad-free time at <strong>{{ number_format($isk_per_day) }} ISK = 1 day</strong>
                (configurable via <code>EVE_DONATIONS_ISK_PER_DAY</code>). The
                donor's account links through their character ID when they
                next log in, no manual step needed.
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
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <img
                        src="https://images.evetech.net/characters/{{ $token['character_id'] }}/portrait?size=128"
                        alt="{{ $token['character_name'] }} portrait"
                        width="64"
                        height="64"
                        class="h-16 w-16 flex-none rounded-md ring-1 ring-gray-200 dark:ring-gray-700"
                        loading="lazy"
                    />

                    <dl class="grid flex-1 grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
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
                </div>

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

        {{-- ========== Active donors ========== --}}
        <x-filament::section>
            <x-slot name="heading">
                Active donors
                <span class="ml-2 text-xs font-normal text-gray-500">
                    {{ $active_donor_count }} currently ad-free
                </span>
            </x-slot>
            <x-slot name="description">
                Donors whose accumulated ad-free window has not expired yet.
                New donations stack forward from the current expiry, so
                donating while still covered extends the window without
                losing unused time.
            </x-slot>

            @if ($donors_active->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No active donors yet. Once a <code>player_donation</code>
                    lands in the wallet journal, the poller grants the donor
                    <em>{{ number_format($isk_per_day) }} ISK = 1 day</em> of
                    ad-free time.
                </p>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($donors_active as $donor)
                        @php
                            $adFreeUntil = $donor['ad_free_until'];
                            $secondsLeft = max(0, $adFreeUntil->getTimestamp() - now()->getTimestamp());
                            $daysLeft = (int) floor($secondsLeft / 86400);
                            $hoursLeft = (int) floor(($secondsLeft % 86400) / 3600);
                        @endphp
                        <div class="flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                            <div class="flex items-start gap-3">
                                <img
                                    src="{{ $donor['donor_portrait_url'] }}"
                                    alt="{{ $donor['donor_name'] ?? 'donor' }} portrait"
                                    width="56"
                                    height="56"
                                    class="h-14 w-14 flex-none rounded-md ring-1 ring-gray-200 dark:ring-gray-700"
                                    loading="lazy"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ $donor['donor_name'] ?? '— (resolving…)' }}
                                    </p>
                                    <p class="font-mono text-xs text-gray-500">
                                        #{{ $donor['donor_character_id'] }}
                                    </p>
                                    <x-filament::badge color="success" class="mt-1">
                                        ad-free
                                    </x-filament::badge>
                                </div>
                            </div>

                            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-gray-100 pt-3 text-xs dark:border-gray-800">
                                <div>
                                    <dt class="uppercase tracking-wider text-gray-500">Donated</dt>
                                    <dd class="font-mono text-sm text-gray-900 dark:text-gray-100">
                                        {{ number_format((float) $donor['total_isk_donated'], 2, '.', ',') }}
                                        <span class="text-[10px] font-normal text-gray-500">ISK</span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="uppercase tracking-wider text-gray-500">Transfers</dt>
                                    <dd class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ $donor['donations_count'] }}
                                    </dd>
                                </div>
                                <div class="col-span-2">
                                    <dt class="uppercase tracking-wider text-gray-500">Ad-free until</dt>
                                    <dd class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ $adFreeUntil->toIso8601String() }}
                                        <span class="ml-1 text-xs text-gray-500">
                                            ({{ $daysLeft }}d {{ $hoursLeft }}h left)
                                        </span>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- ========== Expired donors ========== --}}
        @if ($donors_expired->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">
                    Expired donors
                    <span class="ml-2 text-xs font-normal text-gray-500">
                        {{ $donors_expired->count() }} past ad-free window
                    </span>
                </x-slot>
                <x-slot name="description">
                    Donors whose accumulated ad-free time has elapsed. They
                    will re-enter the active list the next time they donate —
                    a fresh window starts from the new donation's arrival
                    time (past unused time isn't credited backward).
                    <code>User::isDonor()</code> returns <code>false</code>
                    for these donors until a new donation lands.
                </x-slot>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($donors_expired as $donor)
                        <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 opacity-75 dark:border-gray-800 dark:bg-gray-900/50">
                            <img
                                src="{{ $donor['donor_portrait_url'] }}"
                                alt="{{ $donor['donor_name'] ?? 'donor' }} portrait"
                                width="40"
                                height="40"
                                class="h-10 w-10 flex-none rounded-md grayscale ring-1 ring-gray-200 dark:ring-gray-700"
                                loading="lazy"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ $donor['donor_name'] ?? '— (resolving…)' }}
                                </p>
                                <p class="font-mono text-[10px] text-gray-500">
                                    #{{ $donor['donor_character_id'] }} · expired {{ $donor['ad_free_until']->diffForHumans() }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ========== Raw donations ledger ========== --}}
        <x-filament::section>
            <x-slot name="heading">
                Recent donations
                <span class="ml-2 text-xs font-normal text-gray-500">
                    showing latest {{ $donations->count() }}
                </span>
            </x-slot>
            <x-slot name="description">
                Raw <code>player_donation</code> wallet journal entries.
                Donor names resolve via <code>/universe/names/</code>
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
                                <th class="w-1/4 px-3 py-2">When</th>
                                <th class="w-1/3 px-3 py-2">Donor</th>
                                <th class="w-1/6 px-3 py-2 text-right">Amount (ISK)</th>
                                <th class="px-3 py-2">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($donations as $donation)
                                <tr>
                                    <td class="px-3 py-2 align-top text-gray-900 dark:text-gray-100">
                                        <div class="whitespace-nowrap">{{ $donation['donated_at']->toIso8601String() }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ $donation['donated_at']->diffForHumans() }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="flex items-center gap-2">
                                            <img
                                                src="{{ $donation['donor_portrait_url'] }}"
                                                alt=""
                                                width="32"
                                                height="32"
                                                class="h-8 w-8 flex-none rounded ring-1 ring-gray-200 dark:ring-gray-700"
                                                loading="lazy"
                                            />
                                            <div class="min-w-0">
                                                <div class="truncate text-gray-900 dark:text-gray-100">
                                                    {{ $donation['donor_name'] ?? '— (resolving…)' }}
                                                </div>
                                                <div class="font-mono text-xs text-gray-500">
                                                    #{{ $donation['donor_character_id'] }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right align-top font-mono tabular-nums text-gray-900 dark:text-gray-100">
                                        {{ number_format((float) $donation['amount'], 2, '.', ',') }}
                                    </td>
                                    <td class="px-3 py-2 align-top text-gray-700 dark:text-gray-300">
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
                                <td class="px-3 py-2">Totals</td>
                                <td class="px-3 py-2">{{ $donor_count }} unique donor{{ $donor_count === 1 ? '' : 's' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-mono tabular-nums">
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
