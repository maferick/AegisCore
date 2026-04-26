<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-4">
        <h2 style="margin:0; font-size:1.05rem; color:#e5e5e7;">Parser failure queue</h2>
        <p style="font-size:0.78rem; color:#9ca3af; margin-top:0.4rem; margin-bottom:0;">
            Lines the parser could not classify. Retry runs the current parser on the line; if it now classifies cleanly, the original event row is upgraded and the error is marked <code>reparsed_ok</code>. Dismiss when the line is genuine garbage.
        </p>
    </div>

    {{-- Filter strip --}}
    <div class="fi-section rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
        <form method="get" style="display:flex; gap:0.4rem; align-items:center; flex-wrap:wrap;">
            <span style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">status</span>
            @foreach (['open' => 'open', 'retried' => 'retried', 'reparsed_ok' => 'reparsed_ok', 'dismissed' => 'dismissed', '' => 'all'] as $val => $label)
                <a href="?status={{ $val }}{{ $reason_filter ? '&reason='.$reason_filter : '' }}{{ $client_filter ? '&client_id='.$client_filter : '' }}"
                   style="font-size:0.6rem; padding:3px 8px; border-radius:4px; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em;
                          background:{{ (string) $status_filter === (string) $val ? 'rgba(99,102,241,0.20)' : 'rgba(255,255,255,0.04)' }};
                          color:{{ (string) $status_filter === (string) $val ? '#c7d2fe' : '#9ca3af' }};
                          border:1px solid {{ (string) $status_filter === (string) $val ? 'rgba(99,102,241,0.40)' : 'rgba(255,255,255,0.10)' }};">
                    {{ $label }}
                </a>
            @endforeach

            @if (! empty($reason_counts))
                <span style="font-size:0.6rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; margin-left:0.6rem;">reason</span>
                @foreach ($reason_counts as $reason => $n)
                    @php $isAct = (string) $reason_filter === (string) $reason; @endphp
                    <a href="?status={{ $status_filter }}&reason={{ $reason }}{{ $client_filter ? '&client_id='.$client_filter : '' }}"
                       style="font-size:0.6rem; padding:3px 8px; border-radius:4px; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em;
                              background:{{ $isAct ? 'rgba(234,179,8,0.15)' : 'rgba(255,255,255,0.04)' }};
                              color:{{ $isAct ? '#fde68a' : '#9ca3af' }};
                              border:1px solid {{ $isAct ? 'rgba(234,179,8,0.30)' : 'rgba(255,255,255,0.10)' }};">
                        {{ str_replace('_', ' ', $reason) }} <span style="opacity:0.7; margin-left:3px;">{{ $n }}</span>
                    </a>
                @endforeach
                @if ($reason_filter)
                    <a href="?status={{ $status_filter }}{{ $client_filter ? '&client_id='.$client_filter : '' }}"
                       style="font-size:0.6rem; padding:3px 8px; color:#7a7a82; text-decoration:none;">
                        clear
                    </a>
                @endif
            @endif

            @if ($client_filter)
                <span style="font-size:0.6rem; color:#7a7a82; margin-left:0.6rem;">
                    client: <code style="color:#cbd5e1;">{{ $client_filter }}</code>
                    <a href="?status={{ $status_filter }}{{ $reason_filter ? '&reason='.$reason_filter : '' }}" style="color:#7a7a82; margin-left:0.3rem;">×</a>
                </span>
            @endif
        </form>
    </div>

    {{-- Rows --}}
    @if (count($rows) === 0)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p style="font-size:0.8rem; color:#9ca3af; margin:0;">No parse errors match this filter.</p>
        </div>
    @else
        <div style="display:grid; gap:0.5rem;">
            @foreach ($rows as $r)
                @php
                    $statusColors = [
                        'open' => ['#fca5a5', 'rgba(239,68,68,0.08)', 'rgba(239,68,68,0.30)'],
                        'retried' => ['#fde68a', 'rgba(234,179,8,0.08)', 'rgba(234,179,8,0.30)'],
                        'reparsed_ok' => ['#86efac', 'rgba(34,197,94,0.08)', 'rgba(34,197,94,0.30)'],
                        'dismissed' => ['#9ca3af', 'rgba(255,255,255,0.04)', 'rgba(255,255,255,0.10)'],
                    ];
                    [$sFg, $sBg, $sBorder] = $statusColors[$r->status] ?? $statusColors['open'];
                @endphp
                <div class="fi-section rounded-lg shadow-sm" style="background:{{ $sBg }}; border:1px solid {{ $sBorder }}; padding:0.7rem 0.9rem;">
                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:0.4rem;">
                        <span style="font-size:0.55rem; padding:2px 6px; border-radius:3px; background:rgba(255,255,255,0.04); color:{{ $sFg }}; text-transform:uppercase; letter-spacing:0.06em;">
                            {{ $r->status }}
                        </span>
                        <span style="font-size:0.55rem; color:#cbd5e1; text-transform:uppercase; letter-spacing:0.06em;">
                            {{ str_replace('_', ' ', $r->reason) }}
                        </span>
                        <span style="font-size:0.6rem; color:#9ca3af;">
                            {{ $r->filename }} <span style="color:#6b7280;">· {{ $r->log_type }}{{ $r->channel_name ? ' · '.$r->channel_name : '' }}</span>
                        </span>
                        @if ($r->retry_count > 0)
                            <span style="font-size:0.55rem; color:#7a7a82;">retried {{ $r->retry_count }}×</span>
                        @endif
                        <span style="font-size:0.55rem; color:#7a7a82; margin-left:auto;">
                            {{ \Carbon\Carbon::parse($r->created_at)->diffForHumans() }}
                            @if ($r->line_offset !== null)
                                · offset {{ number_format((int) $r->line_offset) }}
                            @endif
                        </span>
                    </div>
                    <pre style="background:rgba(0,0,0,0.30); border:1px solid rgba(255,255,255,0.05); border-radius:4px; padding:0.5rem 0.7rem; font-family:ui-monospace,monospace; font-size:0.72rem; color:#e5e5e7; white-space:pre-wrap; word-break:break-all; margin:0 0 0.4rem;">{{ $r->raw_line }}</pre>
                    @if ($r->status === 'open' || $r->status === 'retried')
                        <div style="display:flex; gap:0.3rem;">
                            <button wire:click="retry({{ $r->id }})"
                                    style="font-size:0.55rem; padding:3px 8px; background:rgba(99,102,241,0.10); color:#c7d2fe; border:1px solid rgba(99,102,241,0.30); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                                retry
                            </button>
                            <button wire:click="dismiss({{ $r->id }})"
                                    wire:confirm="Mark as dismissed? Use this when the line is genuine garbage and not parser-fixable."
                                    style="font-size:0.55rem; padding:3px 8px; background:rgba(255,255,255,0.04); color:#9ca3af; border:1px solid rgba(255,255,255,0.10); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                                dismiss
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
