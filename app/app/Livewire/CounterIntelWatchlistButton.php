<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Embedded Livewire button for the character-card Counter-Intel
 * section. Manages a single ci_watchlist_entries row for the
 * (viewer_bloc, character) pair. The dossier card includes one
 * instance per character.
 *
 * Actions:
 *   - addOrEnsure(reason): create if absent, no-op if present
 *   - setStatus(status):   move through watching → escalated → cleared/archived
 *   - setNotes(notes):     update notes inline
 *   - remove():            delete the row
 *
 * No automation. Every change is explicit operator action. All writes
 * stamp last_status_change_by + last_status_change_at for audit.
 */
class CounterIntelWatchlistButton extends Component
{
    #[Locked]
    public int $characterId;

    #[Locked]
    public int $viewerBlocId;

    public ?int $entryId = null;
    public string $status = '';
    public ?string $reason = null;
    public ?string $notes = null;
    public bool $editing = false;

    public function mount(int $characterId, int $viewerBlocId): void
    {
        $this->characterId = $characterId;
        $this->viewerBlocId = $viewerBlocId;
        $this->loadCurrent();
    }

    private function loadCurrent(): void
    {
        $row = DB::table('ci_watchlist_entries')
            ->where('viewer_bloc_id', $this->viewerBlocId)
            ->where('character_id', $this->characterId)
            ->first();
        if ($row) {
            $this->entryId = (int) $row->id;
            $this->status = (string) $row->status;
            $this->reason = $row->reason ? (string) $row->reason : null;
            $this->notes = $row->notes ? (string) $row->notes : null;
        } else {
            $this->entryId = null;
            $this->status = '';
            $this->reason = null;
            $this->notes = null;
        }
    }

    public function addOrEnsure(?string $reason = null): void
    {
        if ($this->entryId !== null) {
            $this->editing = true;
            return;
        }
        $userId = Auth::id();
        $now = now();
        DB::table('ci_watchlist_entries')->updateOrInsert(
            ['viewer_bloc_id' => $this->viewerBlocId, 'character_id' => $this->characterId],
            [
                'added_by_user_id' => $userId,
                'status' => 'watching',
                'reason' => $reason ?: null,
                'notes' => null,
                'priority_override' => 'none',
                'created_at' => $now,
                'updated_at' => $now,
                'last_status_change_at' => $now,
                'last_status_change_by' => $userId,
            ],
        );
        $this->loadCurrent();
        $this->editing = true;
    }

    public function setStatus(string $status): void
    {
        $allowed = ['watching', 'escalated', 'cleared', 'archived'];
        if (! in_array($status, $allowed, true)) return;
        if ($this->entryId === null) return;
        $userId = Auth::id();
        $now = now();
        DB::table('ci_watchlist_entries')
            ->where('id', $this->entryId)
            ->update([
                'status' => $status,
                'updated_at' => $now,
                'last_status_change_at' => $now,
                'last_status_change_by' => $userId,
            ]);
        $this->loadCurrent();
    }

    public function setNotes(): void
    {
        if ($this->entryId === null) return;
        $userId = Auth::id();
        DB::table('ci_watchlist_entries')
            ->where('id', $this->entryId)
            ->update([
                'reason' => $this->reason ?: null,
                'notes' => $this->notes ?: null,
                'updated_at' => now(),
                'last_status_change_by' => $userId,
            ]);
        $this->editing = false;
    }

    public function remove(): void
    {
        if ($this->entryId === null) return;
        DB::table('ci_watchlist_entries')->where('id', $this->entryId)->delete();
        $this->loadCurrent();
    }

    public function render()
    {
        return view('livewire.counter-intel-watchlist-button');
    }
}
