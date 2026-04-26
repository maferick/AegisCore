<div style="margin-top:0.6rem;">
    @if ($entryId === null)
        <button wire:click="addOrEnsure"
                style="font-size:0.65rem; padding:4px 10px; background:rgba(99,102,241,0.10); color:#c7d2fe; border:1px solid rgba(99,102,241,0.30); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
            + Add to bloc watchlist
        </button>
    @else
        @php
            $statusColors = [
                'watching'  => ['#fde68a', 'rgba(234,179,8,0.10)', 'rgba(234,179,8,0.30)'],
                'escalated' => ['#fca5a5', 'rgba(239,68,68,0.10)', 'rgba(239,68,68,0.30)'],
                'cleared'   => ['#86efac', 'rgba(34,197,94,0.10)', 'rgba(34,197,94,0.30)'],
                'archived'  => ['#9ca3af', 'rgba(255,255,255,0.04)', 'rgba(255,255,255,0.10)'],
            ];
            [$sFg, $sBg, $sBorder] = $statusColors[$status] ?? $statusColors['watching'];
        @endphp
        <div style="display:flex; gap:0.45rem; align-items:center; flex-wrap:wrap; padding:0.55rem 0.7rem; background:{{ $sBg }}; border:1px solid {{ $sBorder }}; border-radius:6px;">
            <span style="font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82;">
                Bloc watchlist
            </span>
            <span style="font-size:0.6rem; padding:2px 8px; border-radius:4px; text-transform:uppercase; letter-spacing:0.08em; background:{{ $sBg }}; color:{{ $sFg }}; border:1px solid {{ $sBorder }};">
                {{ $status }}
            </span>
            @foreach (['watching', 'escalated', 'cleared', 'archived'] as $st)
                @if ($st !== $status)
                    <button wire:click="setStatus('{{ $st }}')"
                            style="font-size:0.55rem; padding:2px 8px; background:transparent; color:#9ca3af; border:1px solid rgba(255,255,255,0.10); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                        → {{ $st }}
                    </button>
                @endif
            @endforeach
            <button wire:click="$toggle('editing')"
                    style="font-size:0.55rem; padding:2px 8px; background:transparent; color:#9ca3af; border:1px solid rgba(255,255,255,0.10); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                {{ $editing ? 'cancel' : 'edit' }}
            </button>
            <button wire:click="remove"
                    wire:confirm="Remove this character from the bloc watchlist?"
                    style="font-size:0.55rem; padding:2px 8px; background:rgba(239,68,68,0.08); color:#fca5a5; border:1px solid rgba(239,68,68,0.20); border-radius:4px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                remove
            </button>
        </div>

        @if ($editing)
            <div style="margin-top:0.5rem; padding:0.6rem 0.7rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:6px;">
                <label style="display:block; font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82; margin-bottom:0.2rem;">Reason</label>
                <input type="text" wire:model="reason" placeholder="Short reason (optional)"
                       style="width:100%; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.08); color:#e5e5e7; padding:0.35rem 0.5rem; border-radius:4px; font-size:0.78rem; margin-bottom:0.5rem;">
                <label style="display:block; font-size:0.55rem; text-transform:uppercase; letter-spacing:0.08em; color:#7a7a82; margin-bottom:0.2rem;">Notes</label>
                <textarea wire:model="notes" rows="3" placeholder="Context, observations, links to evidence…"
                          style="width:100%; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.08); color:#e5e5e7; padding:0.35rem 0.5rem; border-radius:4px; font-size:0.78rem; resize:vertical;"></textarea>
                <div style="margin-top:0.4rem; display:flex; gap:0.35rem;">
                    <button wire:click="setNotes"
                            style="font-size:0.65rem; padding:4px 10px; background:rgba(34,197,94,0.10); color:#86efac; border:1px solid rgba(34,197,94,0.30); border-radius:4px; cursor:pointer;">
                        Save
                    </button>
                </div>
            </div>
        @elseif ($reason || $notes)
            <div style="margin-top:0.4rem; padding:0.5rem 0.65rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:6px; font-size:0.75rem; color:#cbd5e1;">
                @if ($reason)<div><strong style="color:#9ca3af;">reason:</strong> {{ $reason }}</div>@endif
                @if ($notes)<div style="margin-top:0.25rem; white-space:pre-wrap;">{{ $notes }}</div>@endif
            </div>
        @endif
    @endif
</div>
