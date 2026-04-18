@php
    $showControl = $this->canAttest;
@endphp

<div class="fc-attest-wrap" style="margin-top:.5rem;">
    @if (! $showControl)
        {{-- Non-donors see nothing; keep DOM empty so the sub-fleet
             card layout stays clean. --}}
    @elseif ($flashMessage)
        <div role="status" class="fc-attest-flash fc-attest-flash-{{ $flashKind === 'ok' ? 'ok' : 'err' }}"
             style="font-size:.85em; padding:.25rem .5rem; border-radius:4px;
                    background:{{ $flashKind === 'ok' ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.15)' }};
                    color:{{ $flashKind === 'ok' ? '#16a34a' : '#dc2626' }};">
            {{ $flashMessage }}
        </div>
    @elseif (! $open)
        <button type="button" wire:click="togglePicker"
                class="fc-attest-btn"
                style="font-size:.8em; padding:.2rem .6rem; border:1px solid #7dd3fc;
                       background:rgba(14,165,233,0.08); color:#0ea5e9; border-radius:4px; cursor:pointer;">
            Mark FC for this sub-fleet
        </button>
    @else
        <div class="fc-attest-picker" style="padding:.5rem; border:1px solid #7dd3fc; border-radius:4px; background:rgba(14,165,233,0.04);">
            <div style="font-size:.85em; margin-bottom:.3rem; color:#0ea5e9;">
                Who was calling on this sub-fleet?
            </div>
            <select wire:model.live="selectedCharacterId" style="width:100%; padding:.2rem; margin-bottom:.3rem;">
                <option value="">— select pilot —</option>
                @foreach ($candidates as $c)
                    <option value="{{ $c['character_id'] }}">
                        {{ $c['character_name'] }}
                        @if (! empty($c['ship_name'])) ({{ $c['ship_name'] }}) @endif
                    </option>
                @endforeach
            </select>
            <input type="text" wire:model="userNote"
                   placeholder="Optional context (heard on comms, etc.)" maxlength="255"
                   style="width:100%; padding:.2rem; margin-bottom:.3rem; font-size:.85em;">
            <div style="display:flex; gap:.3rem;">
                <button type="button" wire:click="submit"
                        style="flex:1; font-size:.8em; padding:.2rem .6rem; border:1px solid #0ea5e9;
                               background:#0ea5e9; color:white; border-radius:4px; cursor:pointer;">
                    Submit
                </button>
                <button type="button" wire:click="togglePicker"
                        style="font-size:.8em; padding:.2rem .6rem; border:1px solid #9ca3af;
                               background:transparent; color:#9ca3af; border-radius:4px; cursor:pointer;">
                    Cancel
                </button>
            </div>
        </div>
    @endif
</div>
