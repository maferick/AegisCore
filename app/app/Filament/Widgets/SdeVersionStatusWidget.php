<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Reference\Models\SdeVersionCheck;
use Filament\Widgets\Widget;

/**
 * Dashboard widget: SDE drift status at a glance.
 *
 * Reads the latest row from `sde_version_checks` (written daily by the
 * `reference:check-sde-version` scheduled task). Surfaces three states:
 *
 *   - "not checked yet"  → scheduler hasn't run, or table is empty.
 *   - "up-to-date"       → pinned == upstream.
 *   - "bump available"   → pinned != upstream; the operator should bump
 *                          infra/sde/version.txt and re-run the importer.
 *
 * Deliberately read-only — the widget never triggers the check itself.
 * Use `make sde-check` for an ad-hoc run with visible output.
 */
class SdeVersionStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.sde-version-status';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var SdeVersionCheck|null $latest */
        $latest = SdeVersionCheck::query()->latest('checked_at')->first();

        if ($latest === null) {
            return [
                'state' => 'never',
                'label' => 'SDE version never checked',
                'description' => 'The scheduled check runs daily at 08:00 UTC. Run `make sde-check` to trigger one now.',
                'pinned' => null,
                'upstream' => null,
                'checked_at' => null,
                'notes' => null,
            ];
        }

        $state = match (true) {
            $latest->notes !== null && ! $latest->is_bump_available => 'stalled',
            $latest->is_bump_available => 'bump',
            default => 'ok',
        };

        $label = match ($state) {
            'ok' => 'SDE is up-to-date',
            'bump' => 'SDE bump available',
            'stalled' => 'SDE check stalled',
            default => 'SDE status unknown',
        };

        return [
            'state' => $state,
            'label' => $label,
            'description' => null,
            'pinned' => $latest->pinned_version,
            'upstream' => $latest->upstream_version,
            'checked_at' => $latest->checked_at,
            'notes' => $latest->notes,
        ];
    }
}
