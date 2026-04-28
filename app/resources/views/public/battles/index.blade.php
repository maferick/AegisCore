@extends('public.layout', [
    'battles_link' => isset($scoped_conflict) && $scoped_conflict
        ? '/battles/' . $scoped_conflict
        : '/battles',
    'battles_link_label' => isset($scoped_label) && $scoped_label
        ? 'Battles · ' . $scoped_label
        : 'Battles',
    'page_class' => isset($scoped_conflict) && $scoped_conflict ? $scoped_conflict : 'battles',
])

@section('title', isset($scoped_label) && $scoped_label
    ? 'Battles — WinterCo vs ' . $scoped_label
    : 'Battles')

@section('content')
    @php
        $formatIsk = function (float $v): string {
            if ($v >= 1e12) return number_format($v / 1e12, 2).' T';
            if ($v >= 1e9)  return number_format($v / 1e9, 2).' B';
            if ($v >= 1e6)  return number_format($v / 1e6, 2).' M';
            if ($v >= 1e3)  return number_format($v / 1e3, 1).' K';
            return number_format($v, 0);
        };
        $secClass = function (?float $s): string {
            if ($s === null) return '';
            return $s >= 0.5 ? 'sec-hi' : ($s >= 0.0 ? 'sec-lo' : 'sec-ns');
        };
    @endphp

    <h1 style="font-size: 1.4rem; margin: 0 0 0.4rem; font-family: 'JetBrains Mono', monospace;">
        @if (! empty($scoped_label))
            Battles · WinterCo vs {{ $scoped_label }}
        @else
            Recent battles
        @endif
        <span style="color: var(--muted); font-weight: 400; font-size: 0.8rem;">
            · {{ $battles->count() }} shown
        </span>
    </h1>
    <div style="margin-bottom: 1rem; font-size: 0.75rem; color: var(--muted);">
        @if (! empty($scoped_conflict))
            Filtered to theaters with war-attributable kills for this conflict.
            <a href="/war-report/{{ $scoped_conflict }}" style="color: var(--cyan);">← back to {{ $scoped_label }} report</a>
            · <a href="/battles" style="color: var(--cyan);">show all battles</a>
        @else
            All battles. Pick a conflict to filter:
            <a href="/battles/vs-imperium" style="color: var(--cyan);">vs Imperium</a>
            · <a href="/battles/vs-initiative" style="color: var(--cyan);">vs Initiative</a>
        @endif
    </div>

    <table class="public-table">
        <thead>
            <tr>
                <th>System</th>
                <th>Region</th>
                <th>Time</th>
                <th>Duration</th>
                <th style="text-align: right;">Kills</th>
                <th style="text-align: right;">Pilots</th>
                <th style="text-align: right;">ISK destroyed</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($battles as $b)
                @php
                    $sec = $b->primarySystem?->security_status;
                    $secText = $sec !== null ? number_format($sec, 1) : '—';
                    $dur = $b->durationSeconds();
                    $durFmt = sprintf('%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60));
                    $url = route('public.battles.show', ['record' => $b->public_slug ?: $b->id]);
                @endphp
                <tr class="link-row" onclick="location.href='{{ $url }}'">
                    <td>
                        <span class="mono {{ $secClass($sec) }}">{{ $secText }}</span>
                        <a href="{{ $url }}" style="color: var(--text); text-decoration: none; margin-left: 0.4rem;">
                            {{ $b->primarySystem?->name ?? '#'.$b->primary_system_id }}
                        </a>
                    </td>
                    <td style="color: var(--muted);">{{ $b->region?->name ?? '—' }}</td>
                    <td class="mono" style="color: var(--muted); font-size: 0.78rem;">
                        {{ $b->end_time?->format('M d H:i') }}
                    </td>
                    <td class="mono" style="color: var(--muted); font-size: 0.78rem;">{{ $durFmt }}</td>
                    <td class="mono" style="text-align: right;">{{ number_format((int) $b->total_kills) }}</td>
                    <td class="mono" style="text-align: right;">{{ number_format((int) $b->participant_count) }}</td>
                    <td class="mono isk" style="text-align: right;">{{ $formatIsk((float) $b->total_isk_lost) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
