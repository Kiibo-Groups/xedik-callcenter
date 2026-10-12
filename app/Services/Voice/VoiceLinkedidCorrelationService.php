<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\VoiceCall;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single, conflict-safe writer for VoiceCall.asterisk_linkedid.
 *
 * Shared by the real-time correlation endpoint (SIP MESSAGE relay) and by
 * QueueLogImportService's outbound reconciliation, so the conflict rules live in
 * exactly one place. It NEVER touches telephony_calls — the existing machinery
 * (TelephonyCallWrapupService / QueueLogImportService) creates and enriches that row
 * once the VoiceCall carries the linkedid.
 */
class VoiceLinkedidCorrelationService
{
    public const RESULT_CORRELATED = 'correlated';
    public const RESULT_ALREADY = 'already_correlated';
    public const RESULT_CONFLICT = 'conflict';

    /**
     * Assign $linkedid to $voiceCall, honouring UNIQUE(tenant_id, asterisk_linkedid)
     * and never overwriting an existing value. Row-locked and race-safe.
     *
     * @return self::RESULT_* one of correlated | already_correlated | conflict
     */
    public function correlate(VoiceCall $voiceCall, string $linkedid, string $source): string
    {
        $linkedid = trim($linkedid);

        return DB::transaction(function () use ($voiceCall, $linkedid, $source): string {
            /** @var VoiceCall|null $fresh */
            $fresh = VoiceCall::withoutGlobalScopes()
                ->where('id', $voiceCall->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return self::RESULT_CONFLICT;
            }

            $existing = trim((string) ($fresh->asterisk_linkedid ?? ''));

            // Idempotent: same linkedid already stored.
            if ($existing === $linkedid) {
                return self::RESULT_ALREADY;
            }

            // A different linkedid already claims this VoiceCall → never overwrite.
            if ($existing !== '') {
                $this->warn('linkedid_conflict_existing_value', $fresh, $linkedid, $source, [
                    'existing_linkedid' => $existing,
                ]);
                return self::RESULT_CONFLICT;
            }

            // The linkedid already belongs to a different VoiceCall of the same tenant
            // (would also violate UNIQUE(tenant_id, asterisk_linkedid)).
            $ownerId = VoiceCall::withoutGlobalScopes()
                ->where('tenant_id', $fresh->tenant_id)
                ->where('asterisk_linkedid', $linkedid)
                ->where('id', '!=', $fresh->id)
                ->value('id');

            if ($ownerId !== null) {
                $this->warn('linkedid_conflict_other_voice_call', $fresh, $linkedid, $source, [
                    'owner_voice_call_id' => (int) $ownerId,
                ]);
                return self::RESULT_CONFLICT;
            }

            try {
                $fresh->forceFill(['asterisk_linkedid' => $linkedid])->save();
            } catch (QueryException $e) {
                // Lost the race to another writer for the same UNIQUE key.
                if ($this->isUniqueViolation($e)) {
                    $this->warn('linkedid_conflict_unique_race', $fresh, $linkedid, $source, [
                        'sql_state' => (string) ($e->errorInfo[0] ?? ''),
                    ]);
                    return self::RESULT_CONFLICT;
                }

                throw $e;
            }

            $voiceCall->setAttribute('asterisk_linkedid', $linkedid);

            return self::RESULT_CORRELATED;
        });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23000'
            || $driverCode === '1062'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /** @param array<string, mixed> $extra */
    private function warn(string $reason, VoiceCall $voiceCall, string $linkedid, string $source, array $extra = []): void
    {
        Log::warning('[voice][correlation] ' . $reason, array_merge([
            'tenant_id' => $voiceCall->tenant_id,
            'voice_call_id' => $voiceCall->id,
            'incoming_linkedid' => $linkedid,
            'source' => $source,
        ], $extra));
    }
}
