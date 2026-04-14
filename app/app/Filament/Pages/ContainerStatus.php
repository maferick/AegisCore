<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\ContainerStatusWidget;
use App\System\DockerContainer;
use App\System\DockerStatusService;
use App\System\SystemStatusLevel;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * /admin/container-status — operator view of the full docker stack.
 *
 * Sibling of {@see SystemStatus} (which surfaces backend-level health)
 * and of Laravel Horizon (which surfaces queue health). This one
 * surfaces *container* health: is the scheduler up, is the market
 * poller looping, did nginx restart.
 *
 * Data comes from the `docker_socket_proxy` sidecar declared in
 * infra/docker-compose.yml — we never touch /var/run/docker.sock
 * directly. See {@see \App\System\DockerStatusService} for the
 * probe + caching shape.
 *
 * The header widget is the {@see ContainerStatusWidget} summary
 * (total / running / unhealthy / stopped). The page body is a
 * simple table rendered directly in Blade — Filament's Table
 * builder expects an Eloquent query builder, and this data is
 * ephemeral JSON from an HTTP peer, so we skip the ceremony and
 * render rows straight from the snapshot.
 */
class ContainerStatus extends Page
{
    protected string $view = 'filament.pages.container-status';

    protected static ?string $title = 'Containers';

    protected static ?string $navigationLabel = 'Containers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube-transparent';

    protected static string|\UnitEnum|null $navigationGroup = 'Monitoring';

    // Between System Status (10) and Horizon (100).
    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'container-status';

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            ContainerStatusWidget::class,
        ];
    }

    /**
     * Full-width so the widget's four-stat grid lays out properly.
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * View data — the Blade template reads `$snapshot` directly.
     * We expose {@see self::snapshot()} as a Livewire computed
     * property rather than a `mount()` field so the polling re-render
     * picks up fresh state (Livewire re-runs public methods on poll;
     * mounted properties are one-shot).
     */
    public function snapshot(): \App\System\DockerSnapshot
    {
        return app(DockerStatusService::class)->snapshot();
    }

    /**
     * Render an "Up N minutes/hours/days" string from the container's
     * creation timestamp. Docker's list endpoint doesn't hand us the
     * exact start time (it's only on the per-container detail
     * endpoint), so this is approximate for containers that have
     * restarted — close enough for an admin dashboard.
     */
    public function formatUptime(DockerContainer $container): string
    {
        if ($container->startedAtUnix === null) {
            return '—';
        }

        return Carbon::createFromTimestamp($container->startedAtUnix)->diffForHumans([
            'parts' => 2,
            'short' => true,
            'syntax' => Carbon::DIFF_ABSOLUTE,
        ]);
    }

    /**
     * Traffic-light colour for the status column. Maps our shared
     * {@see SystemStatusLevel} onto Filament's stat / badge palette.
     */
    public function levelColor(DockerContainer $container): string
    {
        return $container->level()->color();
    }

    /**
     * Short label (e.g. "Running", "Exited") + any health suffix for
     * the status column — `Running (healthy)`, `Running (unhealthy)`,
     * `Restarting`, etc.
     */
    public function stateLabel(DockerContainer $container): string
    {
        $label = $container->state->label();
        if ($container->healthStatus !== null) {
            $label .= ' ('.$container->healthStatus.')';
        }

        return $label;
    }
}
