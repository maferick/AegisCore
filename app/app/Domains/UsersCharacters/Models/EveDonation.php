<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-game ISK donation event captured from the donations
 * character's wallet journal.
 *
 * `journal_ref_id` is CCP's primary key for the journal entry — unique
 * so the 5-minute poller can re-insert the same page idempotently
 * (the poll command uses `upsert(['journal_ref_id'])`). `donor_*`
 * fields describe who sent the ISK; `amount` + `donated_at` are the
 * durable facts of the donation itself.
 *
 * Linkage to AegisCore users is by character_id only — see the `donor`
 * relation below + the User::isDonor() PHPDoc. We deliberately do NOT
 * store a donor_user_id snapshot at insert time:
 *
 *   - Donors don't need an account to donate (they're EVE players,
 *     not necessarily AegisCore users).
 *   - When the donor later logs in via SSO, the existing flow creates
 *     a `characters` row keyed on the same character_id, and the join
 *     starts returning matches automatically — no migration needed.
 *   - The "is this user a donor?" lookup is the predicate that future
 *     ad-removal logic will gate on; pre-computing donor_user_id at
 *     insert time would only matter at scale, and donor count is
 *     small (dozens).
 *
 * @property int             $id
 * @property int             $journal_ref_id     CCP's wallet journal entry ID.
 * @property int             $donor_character_id EVE character ID of the donor.
 * @property string|null     $donor_name         Last-resolved EVE name; nullable until first resolve.
 * @property string          $amount             Decimal ISK amount (string for precision).
 * @property string|null     $reason             Free-text reason from the in-game send-money dialog.
 * @property CarbonInterface $donated_at         When CCP recorded the journal entry.
 * @property CarbonInterface $created_at         When our poller saw it.
 * @property CarbonInterface $updated_at
 */
class EveDonation extends Model
{
    protected $table = 'eve_donations';

    protected $fillable = [
        'journal_ref_id',
        'donor_character_id',
        'donor_name',
        'amount',
        'reason',
        'donated_at',
    ];

    protected function casts(): array
    {
        return [
            'journal_ref_id' => 'integer',
            'donor_character_id' => 'integer',
            // amount stays a string — DECIMAL(20,2) loses precision if
            // we cast through float. Callers that need to add up
            // donations should use bcadd() or aggregate in SQL.
            'donated_at' => 'datetime',
        ];
    }

    /**
     * The donor's `Character` row, when AegisCore knows about them.
     *
     * Donors don't need an AegisCore account, so this relation can be
     * absent. When the donor later logs in via SSO, the link
     * materialises retroactively (the upsertCharacterAndUser flow keys
     * on the same character_id we already stored here).
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(
            Character::class,
            'donor_character_id',
            'character_id',
        );
    }
}
