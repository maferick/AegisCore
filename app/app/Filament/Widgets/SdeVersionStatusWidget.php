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

            // ETags are 32+ chars of hex — they overflow the stat card and
            // wrap ugly when shown raw. Show a 12-char prefix (git short-SHA
            // territory) and surface the full value in the description so
            // ops can copy it without leaving the dashboard. Full history
            // with un-truncated values lives at /admin/sde-status.
            Stat::make('Pinned', $this->shortVersion($latest->pinned_version))
                ->description($latest->pinned_version ?? 'infra/sde/version.txt'),

            Stat::make('Upstream', $this->shortVersion($latest->upstream_version))
                ->description($this->upstreamDescription($latest->upstream_version, $latest->http_status)),
        ];
    }

    /**
     * Render an ETag-shaped value in ~12 chars + ellipsis.
     *
     * Mirrors `git log --oneline` short-SHA semantics: the full value lives
     * elsewhere (here: the Stat description), so the headline only needs to
     * be enough to eyeball-compare two adjacent values.
     */
    private function shortVersion(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Str::limit($value, 12);
    }

    /**
     * Pair the full upstream value (so ops can copy it) with the HTTP status
     * (so a half-broken HEAD still surfaces a clue). Falls back to one or
     * the other when only half the row populated.
     */
    private function upstreamDescription(?string $value, ?int $status): ?string
    {
        $statusLabel = $status !== null ? 'HTTP '.$status : null;

        if ($value === null || $value === '') {
            return $statusLabel;
        }

        return $statusLabel === null ? $value : $value.' · '.$statusLabel;
    }
}
