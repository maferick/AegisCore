<x-filament-panels::page>
    {{-- Account settings component styles — scoped to .acct-settings so
         they don't bleed into the portal's own Filament elements. --}}
    <style>
        .acct-settings {
            --bg: #0a0a0b;
            --bg-elev: #111113;
            --border: #26262b;
            --border-hot: #3a3a42;
            --text: #e5e5e7;
            --muted: #7a7a82;
            --accent: #4fd0d0;
            --accent-dim: #3aa8a8;
            --gold: #e5a900;
            --danger: #ff3838;
            --success: #4ade80;
        }
        .acct-settings .mono { font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace; }
        .acct-settings h2 { font-size: 1.15rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--accent); }
        .acct-settings .subtitle { color: var(--muted); margin-bottom: 2rem; }

        .acct-settings .card { background: var(--bg-elev); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .acct-settings .card.donor { border-color: var(--gold); background: linear-gradient(135deg, rgba(229, 169, 0, 0.05), transparent 40%), var(--bg-elev); }

        .acct-settings .kv { display: grid; grid-template-columns: 160px 1fr; gap: 0.5rem 1rem; margin-bottom: 0.25rem; }
        .acct-settings .kv-label { color: var(--muted); }

        .acct-settings .flash { padding: 0.85rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid; }
        .acct-settings .flash.error { border-color: var(--danger); background: rgba(255, 56, 56, 0.08); }
        .acct-settings .flash.success { border-color: var(--success); background: rgba(74, 222, 128, 0.08); }

        .acct-settings .badge { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500; border: 1px solid var(--border-hot); }
        .acct-settings .badge.ok { color: var(--success); border-color: var(--success); }
        .acct-settings .badge.warn { color: var(--gold); border-color: var(--gold); }
        .acct-settings .badge.bad { color: var(--danger); border-color: var(--danger); }
        .acct-settings .badge.muted { color: var(--muted); }

        .acct-settings .btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1rem; border-radius: 6px; background: var(--accent); color: var(--bg); text-decoration: none; font-weight: 600; border: 0; cursor: pointer; font: inherit; font-weight: 600; }
        .acct-settings .btn:hover { background: var(--accent-dim); }
        .acct-settings .btn:disabled { opacity: 0.6; cursor: wait; }
        .acct-settings .btn.secondary { background: transparent; color: var(--text); border: 1px solid var(--border-hot); }
        .acct-settings .btn.secondary:hover { border-color: var(--accent); color: var(--accent); }

        .acct-settings table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .acct-settings th, .acct-settings td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .acct-settings th { color: var(--muted); font-weight: 500; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .acct-settings .empty { color: var(--muted); font-style: italic; text-align: center; padding: 1.5rem; }

        .acct-settings input[type="text"], .acct-settings input[type="number"], .acct-settings select { background: var(--bg); border: 1px solid var(--border); border-radius: 4px; padding: 0.5rem 0.7rem; color: var(--text); font: inherit; font-size: 0.9rem; }
        .acct-settings input:focus, .acct-settings select:focus { outline: none; border-color: var(--accent); }

        /* Standings grid */
        .acct-settings .standings-owner { margin-top: 1.5rem; }
        .acct-settings .standings-owner-head { display: flex; align-items: baseline; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
        .acct-settings .standings-owner-head h3 { font-size: 1rem; font-weight: 600; text-transform: capitalize; margin: 0; }
        .acct-settings .standings-owner-meta { color: var(--muted); font-size: 0.82rem; margin-left: auto; }

        .acct-settings .standings-group { margin-top: 1rem; }
        .acct-settings .standings-group-head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; padding-bottom: 0.3rem; border-bottom: 1px solid var(--border); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .acct-settings .standings-group-head .count { color: var(--muted); font-family: 'JetBrains Mono', monospace; }

        .acct-settings .standings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 0.5rem; }

        .acct-settings .standing-cell { border: 1px solid var(--border); border-left: 3px solid var(--border-hot); border-radius: 4px; padding: 0.5rem 0.7rem; background: var(--bg); display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; }
        .acct-settings .standing-cell.friendly { border-left-color: var(--success); }
        .acct-settings .standing-cell.enemy { border-left-color: var(--danger); }
        .acct-settings .standing-cell.neutral { border-left-color: var(--border-hot); }

        .acct-settings .standing-cell-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; min-width: 0; }
        .acct-settings .standing-cell-name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1; }
        .acct-settings .standing-cell-standing { font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; font-weight: 600; flex-shrink: 0; color: var(--muted); }
        .acct-settings .standing-cell.friendly .standing-cell-standing { color: var(--success); }
        .acct-settings .standing-cell.enemy .standing-cell-standing { color: var(--danger); }

        .acct-settings .standing-cell-meta { display: flex; flex-wrap: wrap; gap: 0.3rem; align-items: center; font-size: 0.72rem; color: var(--muted); min-width: 0; }
        .acct-settings .standing-cell-meta .type-tag { font-family: 'JetBrains Mono', monospace; font-size: 0.7rem; color: var(--muted); }
        .acct-settings .standing-cell-meta .badge { font-size: 0.7rem; padding: 0.02rem 0.4rem; }
        .acct-settings .standing-cell-meta .id-tag { font-family: 'JetBrains Mono', monospace; font-size: 0.68rem; color: var(--muted); opacity: 0.6; margin-left: auto; }
    </style>

    <div class="acct-settings">
        @livewire('account.settings')
    </div>
</x-filament-panels::page>
