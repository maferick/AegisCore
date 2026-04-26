<x-filament-panels::page>
    @if (! empty($no_admin))
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p style="font-size:0.85rem; color:#fca5a5;">Admin only.</p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3" style="background:rgba(239,68,68,0.04); border:1px solid rgba(239,68,68,0.20);">
            <h2 style="margin:0; font-size:1rem; color:#fca5a5;">⚠ Audited cross-user view</h2>
            <p style="font-size:0.78rem; color:#fde68a; margin-top:0.4rem; margin-bottom:0;">
                Every search you run here is recorded in <code>eve_log_access_audit</code> with your user_id, the row count exposed, the filters used, your IP, and timestamp. Coalition leadership can review the audit log. Do not browse without operational reason.
            </p>
        </div>

        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-3">
            <form method="get" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:0.5rem; align-items:end;">
                <div>
                    <label style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">actor</label>
                    <input type="text" name="actor" value="{{ $actor }}" placeholder="character name (substring)"
                           style="width:100%; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.10); color:#e5e5e7; padding:0.35rem 0.5rem; border-radius:4px; font-size:0.78rem; margin-top:0.2rem;">
                </div>
                <div>
                    <label style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">channel</label>
                    <input type="text" name="channel" value="{{ $channel }}" placeholder="channel name (substring)"
                           style="width:100%; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.10); color:#e5e5e7; padding:0.35rem 0.5rem; border-radius:4px; font-size:0.78rem; margin-top:0.2rem;">
                </div>
                <div>
                    <label style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">event type</label>
                    <select name="type"
                            style="width:100%; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.10); color:#e5e5e7; padding:0.35rem 0.5rem; border-radius:4px; font-size:0.78rem; margin-top:0.2rem;">
                        <option value="">any</option>
                        @foreach (['chat_message','local_message','fleet_message','intel_report','combat_event','notify_event','session_event','unknown'] as $opt)
                            <option value="{{ $opt }}" {{ $type === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="font-size:0.55rem; color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em;">window (hours)</label>
                    <input type="number" name="since_hours" value="{{ $since_hours }}" min="1" max="720"
                           style="width:100%; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.10); color:#e5e5e7; padding:0.35rem 0.5rem; border-radius:4px; font-size:0.78rem; margin-top:0.2rem;">
                </div>
                <div>
                    <button type="submit"
                            style="width:100%; font-size:0.7rem; padding:0.4rem 0.7rem; background:rgba(239,68,68,0.10); color:#fca5a5; border:1px solid rgba(239,68,68,0.30); border-radius:5px; cursor:pointer; text-transform:uppercase; letter-spacing:0.06em;">
                        Audited search
                    </button>
                </div>
            </form>
        </div>

        @if (count($rows) === 0)
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p style="font-size:0.8rem; color:#9ca3af;">No rows match. Refine the filters or widen the window.</p>
            </div>
        @else
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="overflow:auto; max-height:75vh;">
                <table style="width:100%; border-collapse:collapse; font-size:0.75rem;">
                    <thead>
                        <tr style="position:sticky; top:0; background:rgba(0,0,0,0.5); color:#7a7a82; text-transform:uppercase; letter-spacing:0.06em; font-size:0.55rem;">
                            <th style="text-align:left; padding:0.5rem 0.6rem;">Time</th>
                            <th style="text-align:left; padding:0.5rem 0.6rem;">Type</th>
                            <th style="text-align:left; padding:0.5rem 0.6rem;">Actor</th>
                            <th style="text-align:left; padding:0.5rem 0.6rem;">Channel</th>
                            <th style="text-align:left; padding:0.5rem 0.6rem;">Raw line</th>
                            <th style="text-align:left; padding:0.5rem 0.6rem;">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                <td style="padding:0.4rem 0.6rem; color:#9ca3af; font-family:ui-monospace,monospace; font-size:0.65rem; white-space:nowrap;">
                                    {{ $r->event_timestamp ?? '—' }}
                                </td>
                                <td style="padding:0.4rem 0.6rem; color:#cbd5e1; text-transform:uppercase; letter-spacing:0.04em; font-size:0.6rem; white-space:nowrap;">
                                    {{ $r->event_type }}
                                </td>
                                <td style="padding:0.4rem 0.6rem; color:#e5e5e7; white-space:nowrap;">
                                    {{ $r->actor_name ?? '—' }}
                                </td>
                                <td style="padding:0.4rem 0.6rem; color:#cbd5e1; white-space:nowrap;">
                                    {{ $r->channel_name ?? '—' }}
                                </td>
                                <td style="padding:0.4rem 0.6rem; color:#e5e5e7; font-family:ui-monospace,monospace; font-size:0.7rem; white-space:pre-wrap; word-break:break-word; max-width:600px;">
                                    {{ $r->raw_line }}
                                </td>
                                <td style="padding:0.4rem 0.6rem; color:#7a7a82; font-size:0.6rem;">
                                    user {{ $r->user_id }} · {{ $r->log_type }}<br>
                                    <span style="font-family:ui-monospace,monospace;">{{ $r->filename }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
