<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * /portal/intelligence/alerts — strategic alerts board.
 *
 * Reads strategic_alerts. Open ack/dismiss actions hit
 * acknowledged_at / dismissed_at via portal-scoped form posts.
 */
class StrategicAlerts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Strategic alerts';

    protected static string|UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Strategic alerts';

    protected static ?string $slug = 'intelligence/alerts';

    protected string $view = 'filament.portal.pages.strategic-alerts';

    public string $statusFilter = 'open';
    public string $kindFilter = '';

    public function mount(): void
    {
        $status = (string) request()->query('status', 'open');
        if (! in_array($status, ['open', 'all', 'dismissed'], true)) {
            $status = 'open';
        }
        $this->statusFilter = $status;
        $this->kindFilter = (string) request()->query('kind', '');
    }

    public function ack(int $alertId): void
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) return;
        DB::table('strategic_alerts')
            ->where('id', $alertId)
            ->where('viewer_bloc_id', $blocId)
            ->whereNull('acknowledged_at')
            ->update([
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => Auth::id(),
            ]);
    }

    public function dismiss(int $alertId): void
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) return;
        DB::table('strategic_alerts')
            ->where('id', $alertId)
            ->where('viewer_bloc_id', $blocId)
            ->update([
                'dismissed_at' => now(),
                'dismissed_by_user_id' => Auth::id(),
            ]);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $blocId = $this->resolveViewerBlocId();
        if ($blocId === null) {
            return ['no_bloc' => true];
        }

        $q = DB::table('strategic_alerts')->where('viewer_bloc_id', $blocId);
        if ($this->statusFilter === 'open') {
            $q->whereNull('dismissed_at');
        } elseif ($this->statusFilter === 'dismissed') {
            $q->whereNotNull('dismissed_at');
        }
        if ($this->kindFilter !== '') {
            $q->where('alert_kind', $this->kindFilter);
        }
        $alerts = $q->orderByRaw("FIELD(severity,'urgent','elevated','watch','info')")
            ->orderByDesc('detected_at')
            ->limit(150)
            ->get();

        $kindCounts = DB::table('strategic_alerts')
            ->where('viewer_bloc_id', $blocId)
            ->whereNull('dismissed_at')
            ->groupBy('alert_kind')
            ->selectRaw('alert_kind, COUNT(*) as n')
            ->pluck('n', 'alert_kind')
            ->all();

        $totals = [
            'open' => DB::table('strategic_alerts')->where('viewer_bloc_id', $blocId)->whereNull('dismissed_at')->count(),
            'acked' => DB::table('strategic_alerts')->where('viewer_bloc_id', $blocId)->whereNotNull('acknowledged_at')->whereNull('dismissed_at')->count(),
            'dismissed' => DB::table('strategic_alerts')->where('viewer_bloc_id', $blocId)->whereNotNull('dismissed_at')->count(),
        ];

        return [
            'no_bloc' => false,
            'alerts' => $alerts,
            'kind_counts' => $kindCounts,
            'totals' => $totals,
            'status' => $this->statusFilter,
            'kind' => $this->kindFilter,
        ];
    }

    private function resolveViewerBlocId(): ?int
    {
        $override = request()->query('bloc_id');
        if ($override !== null && ctype_digit((string) $override)) {
            return (int) $override;
        }
        $user = Auth::user();
        if ($user === null) return null;
        $char = $user->characters()->first();
        if ($char === null || ! $char->alliance_id) return null;
        $blocId = DB::table('coalition_entity_labels')
            ->where('entity_type', 'alliance')
            ->where('entity_id', $char->alliance_id)
            ->where('is_active', 1)
            ->value('bloc_id');
        return $blocId ? (int) $blocId : null;
    }
}
