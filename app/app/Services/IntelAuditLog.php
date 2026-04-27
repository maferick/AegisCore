<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * V1 §11 — write-side audit log for analyst-mutable surfaces.
 *
 * Single static entry point: IntelAuditLog::record(
 *     surface, refId, action, prior, new, metadata
 * ).
 *
 * Resolves actor user_id + alliance_id + bloc_id at write time so
 * historical rows remain accurate even if the actor's affiliation
 * changes later. Inline IP + user_agent capture from the current
 * request when available.
 *
 * Append-only by convention. No update / delete API.
 */
final class IntelAuditLog
{
    public const SURFACE_ALERT = 'strategic_alert';
    public const SURFACE_VERIFIED_ITEM = 'verified_intelligence_item';
    public const SURFACE_SUPPRESSION_RULE = 'suppression_rule';
    public const SURFACE_EXPORT = 'export_artifact';
    public const SURFACE_FEEDBACK = 'feedback_event';

    public static function record(
        string $surface,
        int $refId,
        string $action,
        array|object|null $prior = null,
        array|object|null $new = null,
        array $metadata = [],
    ): void {
        $userId = Auth::id();
        $allianceId = null;
        $blocId = null;

        $user = Auth::user();
        if ($user !== null) {
            $char = $user->characters()->first();
            if ($char !== null) {
                $allianceId = $char->alliance_id;
                if ($allianceId) {
                    $blocId = DB::table('coalition_entity_labels')
                        ->where('entity_type', 'alliance')
                        ->where('entity_id', $allianceId)
                        ->where('is_active', 1)
                        ->value('bloc_id');
                }
            }
        }

        $request = Request::instance();

        DB::table('intel_audit_log')->insert([
            'actor_user_id' => $userId,
            'actor_alliance_id' => $allianceId,
            'actor_bloc_id' => $blocId,
            'surface' => $surface,
            'surface_ref_id' => $refId,
            'action' => mb_substr($action, 0, 60),
            'prior_state_json' => $prior !== null ? json_encode(self::sanitize($prior)) : null,
            'new_state_json' => $new !== null ? json_encode(self::sanitize($new)) : null,
            'metadata_json' => $metadata !== [] ? json_encode($metadata) : null,
            'ip_address' => mb_substr((string) ($request?->ip() ?? ''), 0, 45) ?: null,
            'user_agent' => mb_substr((string) ($request?->userAgent() ?? ''), 0, 220) ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Coerce stdClass / model instances to plain arrays for JSON encode.
     * Drops any field whose name suggests a secret.
     */
    private static function sanitize(mixed $v): array
    {
        if (is_object($v)) {
            $v = json_decode(json_encode($v), true) ?: [];
        }
        if (! is_array($v)) {
            return [];
        }
        $blocked = ['password', 'token', 'secret', 'api_key'];
        foreach ($v as $k => $val) {
            $lk = strtolower((string) $k);
            foreach ($blocked as $b) {
                if (str_contains($lk, $b)) {
                    $v[$k] = '[REDACTED]';
                    break;
                }
            }
        }
        return $v;
    }
}
