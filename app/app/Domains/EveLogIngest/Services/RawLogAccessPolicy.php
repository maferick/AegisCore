<?php

declare(strict_types=1);

namespace App\Domains\EveLogIngest\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 hardening — raw-log access policy.
 *
 * Default deny on eve_log_events.raw_line / parsed_json for any user
 * who is not the file owner. Admins (User::isAdmin) can view across
 * users but every such access is audited.
 *
 * Read paths must call:
 *
 *   $policy->ensureCanView($user, $file)        — throws if denied
 *   $policy->recordCrossUserAccess($user, ...)  — after a cross-user
 *                                                 read returns rows
 *
 * Owner-self access does not write audit rows. Cross-user access
 * always does.
 */
final class RawLogAccessPolicy
{
    /**
     * Deny by default. Allow when:
     *   - $user is the file owner
     *   - $user->isAdmin() returns true
     *
     * `$file` may be a stdClass row from DB or an array — we only
     * read user_id off it.
     */
    public function canView(?User $user, object|array $file): bool
    {
        if ($user === null) return false;
        $ownerId = is_array($file) ? ($file['user_id'] ?? null) : ($file->user_id ?? null);
        if ($ownerId !== null && (int) $ownerId === (int) $user->id) return true;
        return (bool) $user->isAdmin();
    }

    /**
     * Throws if the user cannot view. Use at the top of any read
     * path that exposes raw_line / parsed_json.
     */
    public function ensureCanView(?User $user, object|array $file): void
    {
        if (! $this->canView($user, $file)) {
            abort(403, 'Raw log access denied.');
        }
    }

    /**
     * Returns true when the access is cross-user (i.e. needs to be
     * audited). Caller writes the audit row only when this is true.
     */
    public function isCrossUser(?User $user, object|array $file): bool
    {
        if ($user === null) return true;
        $ownerId = is_array($file) ? ($file['user_id'] ?? null) : ($file->user_id ?? null);
        return $ownerId !== null && (int) $ownerId !== (int) $user->id;
    }

    /**
     * Persist one audit row for a single-event or single-file view.
     */
    public function recordSingleAccess(
        User $user,
        ?int $targetUserId,
        string $accessKind,
        ?int $eveLogFileId,
        ?int $eveLogEventId,
    ): void {
        DB::table('eve_log_access_audit')->insert([
            'user_id' => $user->id,
            'target_user_id' => $targetUserId,
            'access_kind' => mb_substr($accessKind, 0, 40),
            'eve_log_file_id' => $eveLogFileId,
            'eve_log_event_id' => $eveLogEventId,
            'query_terms_json' => null,
            'row_count' => 1,
            'accessed_at' => now(),
            'ip' => mb_substr((string) request()->ip(), 0, 64),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    /**
     * Persist one audit row covering a list/search view across N rows
     * (potentially multiple owners). target_user_id stays NULL for
     * cross-cutting searches; the row_count reflects how many lines
     * were exposed to the viewer.
     *
     * @param  array<string, mixed>  $queryTerms
     */
    public function recordListAccess(
        User $user,
        string $accessKind,
        int $rowCount,
        array $queryTerms = [],
    ): void {
        DB::table('eve_log_access_audit')->insert([
            'user_id' => $user->id,
            'target_user_id' => null,
            'access_kind' => mb_substr($accessKind, 0, 40),
            'eve_log_file_id' => null,
            'eve_log_event_id' => null,
            'query_terms_json' => json_encode($queryTerms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'row_count' => $rowCount,
            'accessed_at' => now(),
            'ip' => mb_substr((string) request()->ip(), 0, 64),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
