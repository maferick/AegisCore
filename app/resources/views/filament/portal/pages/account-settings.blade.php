<x-filament-panels::page>
    {{-- Embed the full interactive Livewire AccountSettings component.
         This gives portal users the same coalition change, structure
         search, and standings sync features as the legacy /account/settings
         page, but inside the portal's Filament layout with sidebar nav. --}}
    <style>
        /* Match the Livewire component's styling to the portal's dark theme. */
        .fi-page .card { background: rgba(17,17,19,0.6); border: 1px solid #26262b; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
        .fi-page .card h2 { font-size: 1.1rem; font-weight: 700; color: #e5e5e7; margin-bottom: 0.75rem; }
        .fi-page .card.donor { border-color: rgba(229,169,0,0.2); }
        .fi-page .kv { display: grid; grid-template-columns: 10rem 1fr; gap: 0.5rem 1rem; }
        .fi-page .kv-label { font-size: 0.8rem; color: #7a7a82; }
        .fi-page .badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 3px; font-size: 0.7rem; font-family: 'JetBrains Mono', monospace; }
        .fi-page .badge.ok { background: rgba(74,222,128,0.15); color: #4ade80; }
        .fi-page .badge.muted { background: rgba(122,122,130,0.15); color: #7a7a82; }
        .fi-page .badge.warn { background: rgba(229,169,0,0.15); color: #e5a900; }
        .fi-page .mono { font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; }
        .fi-page .btn { display: inline-block; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.78rem; font-family: 'JetBrains Mono', monospace; cursor: pointer; border: 1px solid; transition: all 0.15s; }
        .fi-page .btn.primary { background: rgba(79,208,208,0.12); border-color: rgba(79,208,208,0.3); color: #4fd0d0; }
        .fi-page .btn.primary:hover { background: rgba(79,208,208,0.2); }
        .fi-page .btn.secondary { background: rgba(122,122,130,0.1); border-color: #3a3a42; color: #e5e5e7; }
        .fi-page .btn.danger { background: rgba(255,56,56,0.1); border-color: rgba(255,56,56,0.3); color: #ff3838; }
        .fi-page .flash { padding: 0.6rem 1rem; border-radius: 4px; font-size: 0.82rem; margin-bottom: 1rem; }
        .fi-page .flash.success { background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.2); color: #4ade80; }
        .fi-page .flash.error { background: rgba(255,56,56,0.1); border: 1px solid rgba(255,56,56,0.2); color: #ff3838; }
        .fi-page .subtitle { font-size: 0.85rem; color: #7a7a82; margin-bottom: 1.5rem; }
        .fi-page input[type="text"], .fi-page input[type="number"], .fi-page select {
            background: #111113; border: 1px solid #26262b; border-radius: 4px; padding: 0.4rem 0.6rem;
            color: #e5e5e7; font-family: 'JetBrains Mono', monospace; font-size: 0.82rem;
        }
        .fi-page table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        .fi-page th { text-align: left; color: #7a7a82; border-bottom: 1px solid #26262b; padding: 0.4rem 0.5rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; }
        .fi-page td { border-bottom: 1px solid #1a1a1e; padding: 0.5rem; }
    </style>

    @livewire('account.settings')
</x-filament-panels::page>
