<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Reference\Models\SdeVersionCheck;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

/**
 * Dashboard widget: SDE drift status at a glance.
 *
 * Reads the latest row from `sde_version_checks` (written daily by the
 * `reference:check-sde-version` scheduled task) and renders three stats:
 * status, pinned version, upstream version.
 *
 * Extends Filament's {@see StatsOverviewWidget} rather than a custom Blade
 * view so the card uses Filament's bundled styling — we deliberately don't
 * ship a Tailwind build step in phase 1, so raw utility classes in custom
 * views don't get compiled and render unstyled.
 *
 * Deliberately read-only — the widget never triggers the check itself.
 * Use `make sde-check` for an ad-hoc run with visible output.
 */
class SdeVersionStatusWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'EVE Static Data';

    protected ?string $description = "Daily drift check against CCP's pinned SDE tarball.";

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        /** @var SdeVersionCheck|null $latest */
        $latest = SdeVersionCheck::query()
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return [
                Stat::make('Status', 'Never checked')
                    ->description('Scheduled daily at 08:00 UTC')
                    ->descriptionIcon('heroicon-m-clock')
                    ->color('gray'),
                Stat::make('Pinned', '—'),
                Stat::make('Upstream', '—'),
            ];
        }

        // State precedence, most specific first:
        //
        //   1. pinned=null + HEAD failed → nothing loaded AND can't reach CCP.
        //   2. pinned=null               → nothing loaded yet (pre-import).
        //      "Up to date" would lie here — we're not up to date with
        //      *anything*, because there's no local snapshot.
        //   3. HEAD failed (with pinned set) → drift check stalled.
        //   4. is_bump_available         → pinned != upstream, import pending.
        //   5. default                   → pinned == upstream, all good.
        [$statusLabel, $color, $icon] = match (true) {
            $latest->pinned_version === null && $latest->notes !== null => [
                'No SDE loaded · upstream unreachable',
                'danger',
                'heroicon-m-exclamation-triangle',
            ],
            $latest->pinned_version === null => [
                'No SDE loaded',
                'gray',
                'heroicon-m-inbox',
            ],
            $latest->notes !== null && ! $latest->is_bump_available => [
                'Check stalled',
                'danger',
                'heroicon-m-exclamation-triangle',
            ],
            $latest->is_bump_available => [
                'Bump available',
                'warning',
                'heroicon-m-arrow-up-circle',
            ],
            default => [
                'Up to date',
                'success',
                'heroicon-m-check-circle',
            ],
        };

        return [
            Stat::make('Status', $statusLabel)
                ->description('Checked '.$latest->checked_at->diffForHumans())
                ->descriptionIcon($icon)
                ->color($color),

            Stat::make('Pinned', $latest->pinned_version ?? '—')
                ->description('infra/sde/version.txt'),

            Stat::make('Upstream', Str::limit($latest->upstream_version ?? '—', 32))
                ->description($latest->http_status ? 'HTTP '.$latest->http_status : null),
        ];
    }
}
