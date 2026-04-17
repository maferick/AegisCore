@php
    $formatValue = function ($v): string {
        if (is_float($v)) {
            if (abs($v) >= 1e9) return number_format($v / 1e9, 2).' B';
            if (abs($v) >= 1e6) return number_format($v / 1e6, 2).' M';
            if (abs($v) >= 1e3) return number_format($v / 1e3, 1).' k';
            return number_format($v, 2);
        }
        return (string) $v;
    };
@endphp

<style>
    .ic-shell { display: flex; flex-direction: column; gap: 1rem; }
    .ic-log { display: flex; flex-direction: column; gap: 0.75rem; max-height: 70vh; overflow-y: auto; padding-right: 0.5rem; }
    .ic-msg { border: 1px solid #26262b; border-radius: 8px; padding: 0.9rem 1rem; background: rgba(17,17,19,0.6); }
    .ic-msg.user { border-color: rgba(79,208,208,0.3); background: rgba(79,208,208,0.05); }
    .ic-msg.assistant { border-color: #26262b; }
    .ic-msg.system { border-color: rgba(229,169,0,0.3); background: rgba(229,169,0,0.05); font-size: 0.82rem; color: #e5e5e7; }
    .ic-msg-role { font-family: 'JetBrains Mono', monospace; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.15em; color: #7a7a82; margin-bottom: 0.25rem; }
    .ic-msg.user .ic-msg-role { color: #4fd0d0; }
    .ic-msg-text { color: #e5e5e7; font-size: 0.92rem; line-height: 1.4; }
    .ic-rows { margin-top: 0.75rem; display: flex; flex-direction: column; gap: 0.35rem; }
    .ic-row { display: flex; justify-content: space-between; align-items: center; padding: 0.35rem 0.5rem; border-bottom: 1px solid #1a1a1e; font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; }
    .ic-row-label { color: #e5e5e7; }
    .ic-row-value { color: #4fd0d0; font-weight: 700; }
    .ic-meta { margin-top: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap; font-family: 'JetBrains Mono', monospace; font-size: 0.65rem; color: #7a7a82; }
    .ic-badge { display: inline-block; padding: 0.1rem 0.45rem; border-radius: 3px; font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.1em; }
    .ic-badge-cyan { background: rgba(79,208,208,0.12); color: #4fd0d0; }
    .ic-badge-amber { background: rgba(229,169,0,0.15); color: #e5a900; }
    .ic-badge-red { background: rgba(255,56,56,0.15); color: #ff3838; }
    .ic-plan-details summary { cursor: pointer; color: #7a7a82; font-size: 0.72rem; font-family: 'JetBrains Mono', monospace; margin-top: 0.5rem; }
    .ic-plan-details pre { background: #0c0c0e; border: 1px solid #1a1a1e; border-radius: 4px; padding: 0.6rem; font-size: 0.7rem; color: #b5b5b8; overflow-x: auto; margin-top: 0.4rem; }
    .ic-form { display: flex; gap: 0.5rem; align-items: stretch; }
    .ic-input { flex: 1; padding: 0.65rem 0.9rem; background: rgba(17,17,19,0.8); border: 1px solid #26262b; border-radius: 6px; color: #e5e5e7; font-size: 0.9rem; }
    .ic-input:focus { outline: none; border-color: #4fd0d0; }
    .ic-submit { padding: 0 1.25rem; background: #4fd0d0; color: #0a0a0b; font-weight: 700; border: none; border-radius: 6px; cursor: pointer; font-family: 'JetBrains Mono', monospace; letter-spacing: 0.05em; text-transform: uppercase; font-size: 0.78rem; }
    .ic-submit[disabled] { opacity: 0.5; cursor: not-allowed; }
    .ic-toggle { display: flex; align-items: center; gap: 0.4rem; font-size: 0.72rem; color: #7a7a82; font-family: 'JetBrains Mono', monospace; }
    .ic-busy { font-size: 0.78rem; color: #7a7a82; font-style: italic; }
    .ic-error { color: #ff3838; font-size: 0.72rem; margin-top: 0.25rem; word-break: break-word; }
</style>

<div class="ic-shell" wire:poll.keep-alive.30s>

    <div class="ic-log">
        @foreach ($messages as $m)
            <div class="ic-msg {{ $m['role'] }}">
                <div class="ic-msg-role">{{ $m['role'] }}</div>
                <div class="ic-msg-text">{{ $m['text'] ?? '' }}</div>

                @if (! empty($m['rows']))
                    <div class="ic-rows">
                        @foreach ($m['rows'] as $row)
                            <div class="ic-row">
                                <span class="ic-row-label">{{ $row['label'] ?? '—' }}</span>
                                <span class="ic-row-value">{{ $formatValue($row['value'] ?? 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! empty($m['backend']) || ! empty($m['parser']) || ! empty($m['took_ms']) || isset($m['total']))
                    <div class="ic-meta">
                        @if (! empty($m['backend']))
                            <span class="ic-badge ic-badge-cyan">{{ $m['backend'] }}</span>
                        @endif
                        @if (! empty($m['parser']))
                            <span class="ic-badge ic-badge-amber">parser: {{ $m['parser'] }}</span>
                        @endif
                        @if (! empty($m['took_ms']))
                            <span>{{ $m['took_ms'] }} ms</span>
                        @endif
                        @if (isset($m['total']))
                            <span>total: {{ $m['total'] }}</span>
                        @endif
                    </div>
                @endif

                @if (! empty($m['plan']))
                    <details class="ic-plan-details">
                        <summary>Show plan</summary>
                        <pre>{{ json_encode($m['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif

                @if (! empty($m['error']))
                    <div class="ic-error">{{ $m['error'] }}</div>
                @endif
            </div>
        @endforeach

        @if ($busy)
            <div class="ic-busy">Thinking…</div>
        @endif
    </div>

    <form wire:submit.prevent="ask" class="ic-form">
        <input
            type="text"
            wire:model.defer="draft"
            placeholder='Ask the broker — e.g. "most used ship to kill freighters last 30 days"'
            class="ic-input"
            {{ $busy ? 'disabled' : '' }}
            autocomplete="off"
        />
        <button type="submit" class="ic-submit" {{ $busy ? 'disabled' : '' }}>Send</button>
    </form>

    <label class="ic-toggle">
        <input type="checkbox" wire:model.live="useLlm" />
        Use LLM when heuristic doesn't match (requires ANTHROPIC_API_KEY on broker)
    </label>

</div>
