<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domains\KillmailsBattleTheaters\Services\BattleFcAttestationRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Spec 6 — donor-gated "Mark FC" control for one sub-fleet on a
 * battle report. Embedded inside the sub-fleet role card.
 *
 * Mode A: on successful submit the UI shows an inline "Recorded"
 * state for ~4 seconds, then collapses back to the unchanged view.
 * Nothing about the attestation is displayed to other users.
 *
 * Server-side enforcement of donor-tier lives in
 * BattleFcAttestationRecorder::record(); this component additionally
 * refuses to render the picker for non-donors as a UX guard.
 */
class BattleFcAttest extends Component
{
    public int $battleId;
    public int $allianceId;
    public int $subFleetId;
    public int $partitionAlgoVersion;

    /** @var array<int, array{character_id:int, character_name:string, ship_name:?string, ship_class_category:?string}> */
    public array $candidates = [];

    public bool $open = false;
    public ?int $selectedCharacterId = null;
    public string $userNote = '';

    public ?string $flashMessage = null;
    public ?string $flashKind = null; // 'ok' | 'err'

    public function mount(
        int $battleId,
        int $allianceId,
        int $subFleetId,
        int $partitionAlgoVersion,
        array $candidates,
    ): void {
        $this->battleId = $battleId;
        $this->allianceId = $allianceId;
        $this->subFleetId = $subFleetId;
        $this->partitionAlgoVersion = $partitionAlgoVersion;
        $this->candidates = $candidates;
    }

    #[Computed]
    public function canAttest(): bool
    {
        $user = Auth::user();
        return $user !== null && method_exists($user, 'isDonor') && $user->isDonor();
    }

    public function togglePicker(): void
    {
        if (! $this->canAttest()) {
            return;
        }
        $this->open = ! $this->open;
        $this->flashMessage = null;
    }

    public function submit(BattleFcAttestationRecorder $recorder): void
    {
        $user = Auth::user();
        if ($user === null || ! $this->canAttest()) {
            $this->flashKind = 'err';
            $this->flashMessage = 'Donor tier required.';
            return;
        }
        if ($this->selectedCharacterId === null) {
            $this->flashKind = 'err';
            $this->flashMessage = 'Pick a pilot first.';
            return;
        }

        try {
            $recorder->record(
                user: $user,
                battleId: $this->battleId,
                allianceId: $this->allianceId,
                subFleetId: $this->subFleetId,
                partitionAlgoVersion: $this->partitionAlgoVersion,
                attestedCharacterId: $this->selectedCharacterId,
                userNote: $this->userNote !== '' ? $this->userNote : null,
            );
        } catch (Throwable $e) {
            $this->flashKind = 'err';
            $this->flashMessage = $e->getMessage();
            return;
        }

        $this->flashKind = 'ok';
        $this->flashMessage = 'Recorded. Thanks.';
        $this->open = false;
        $this->selectedCharacterId = null;
        $this->userNote = '';
    }

    public function render()
    {
        return view('livewire.battle-fc-attest');
    }
}
