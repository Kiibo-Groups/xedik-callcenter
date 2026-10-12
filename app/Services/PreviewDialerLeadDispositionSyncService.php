<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Conversation;
use App\Models\DialerDisposition;
use App\Models\LeadList;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Propagates the agent's commercial disposition into the Preview Dialer plane.
 *
 * Two surfaces, one event:
 *   - dl_{id}_leads.last_disposition_*  — the lead's CURRENT known outcome (critical)
 *   - dl_{id}_log.disposition           — the outcome of THAT SPECIFIC attempt (secondary history)
 *
 * The lead update keeps its original behaviour untouched. The attempt-log update is
 * secondary history: it is fully isolated and can never break the disposition flow,
 * the lead update, or the agent's Wrap-Up.
 */
class PreviewDialerLeadDispositionSyncService
{
    private const LOG_PREFIX = '[PreviewDialerLeadDispositionSync] ';

    public function sync(
        Conversation $conversation,
        DialerDisposition $disposition,
        int $agentId,
        ?Carbon $callbackAt = null,
    ): void {
        if ($conversation->channel !== 'voice'
            || !$conversation->lead_list_id
            || !$conversation->lead_list_row_id) {
            return;
        }

        $leadList = LeadList::withoutGlobalScopes()->find((int) $conversation->lead_list_id);
        if (!$leadList || !$leadList->table_name) {
            Log::warning('Preview disposition sync skipped: lead list missing', [
                'conversation_id' => $conversation->id,
                'lead_list_id' => $conversation->lead_list_id,
            ]);
            return;
        }

        $tableName = (string) $leadList->table_name;
        if (!preg_match('/^dl_[1-9][0-9]*_leads$/', $tableName) || !Schema::hasTable($tableName)) {
            Log::warning('Preview disposition sync skipped: dynamic lead table missing', [
                'conversation_id' => $conversation->id,
                'lead_list_id' => $leadList->id,
                'table_name' => $tableName,
            ]);
            return;
        }

        $requiredColumns = [
            'last_disposition_id',
            'last_disposition',
            'last_disposition_at',
            'last_disposition_by',
            'callback_at',
        ];

        foreach ($requiredColumns as $column) {
            if (!Schema::hasColumn($tableName, $column)) {
                Log::warning('Preview disposition sync skipped: dynamic lead column missing', [
                    'conversation_id' => $conversation->id,
                    'table_name' => $tableName,
                    'column' => $column,
                ]);
                return;
            }
        }

        $updates = [
            'last_disposition_id' => (int) $disposition->id,
            'last_disposition' => (string) $disposition->name,
            'last_disposition_at' => now(),
            'last_disposition_by' => $agentId,
            'callback_at' => $this->requiresCallbackAt($disposition)
                ? $callbackAt
                : null,
        ];

        $updated = DB::table($tableName)
            ->where('id', (int) $conversation->lead_list_row_id)
            ->update($updates);

        if ($updated === 0) {
            Log::warning('Preview disposition sync skipped: dynamic lead row missing', [
                'conversation_id' => $conversation->id,
                'table_name' => $tableName,
                'lead_list_row_id' => $conversation->lead_list_row_id,
            ]);
        }

        // Secondary history plane. Runs only after the lead update above has finished,
        // and is fully isolated — see syncAttemptLogDisposition().
        $this->syncAttemptLogDisposition($conversation, $disposition, $leadList);
    }

    /**
     * Mirror the commercial disposition onto the dl_{id}_log row of THIS attempt.
     *
     * Identity is call_uuid (UNIQUE index on the log table), read from
     * conversations.external_id — it addresses exactly one attempt, so a redisposition
     * can never rewrite an earlier attempt of the same lead. Phone / dialer_lead_id /
     * date matching are deliberately NOT supported: they resolve to many rows.
     *
     * Strict UPDATE, never updateOrInsert: a call that failed before ringing legitimately
     * has no log row (see PreviewDialerLifecycleService::finalizeAtEnd()), and inventing
     * one would pollute the Call Log and its stats. updated === 0 is a valid outcome.
     *
     * Never throws: the disposition, the lead update and Wrap-Up must all survive any
     * failure here.
     */
    private function syncAttemptLogDisposition(
        Conversation $conversation,
        DialerDisposition $disposition,
        LeadList $leadList,
    ): void {
        $logTable = (string) ($leadList->log_table_name ?? '');
        $callUuid = trim((string) $conversation->external_id);

        $context = [
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'call_uuid' => $callUuid === '' ? null : $callUuid,
            'lead_list_id' => $leadList->id,
            'lead_list_row_id' => $conversation->lead_list_row_id,
            'log_table' => $logTable === '' ? null : $logTable,
        ];

        try {
            if (!preg_match('/^dl_[1-9][0-9]*_log$/', $logTable)) {
                $this->skipAttemptLog($context, 'invalid_log_table');
                return;
            }

            if (!Schema::hasTable($logTable)) {
                $this->skipAttemptLog($context, 'log_table_missing');
                return;
            }

            if (!Schema::hasColumn($logTable, 'disposition')) {
                $this->skipAttemptLog($context, 'disposition_column_missing');
                return;
            }

            if ($callUuid === '') {
                $this->skipAttemptLog($context, 'missing_call_uuid');
                return;
            }

            $newDisposition = (string) $disposition->name;

            $previousDisposition = DB::table($logTable)
                ->where('call_uuid', $callUuid)
                ->value('disposition');

            // Absolute value, no counters, no derived timestamps — running this twice
            // leaves the same result, and a redisposition always lands the final value.
            $updated = DB::table($logTable)
                ->where('call_uuid', $callUuid)
                ->update(['disposition' => $newDisposition]);

            if ($updated === 0) {
                Log::info(self::LOG_PREFIX . 'attempt log row not found', $context);
                return;
            }

            Log::info(self::LOG_PREFIX . 'attempt log disposition synced', $context + [
                'previous_disposition' => $previousDisposition,
                'new_disposition' => $newDisposition,
                'updated_rows' => $updated,
            ]);
        } catch (\Throwable $e) {
            Log::warning(self::LOG_PREFIX . 'attempt log disposition skipped', $context + [
                'reason' => 'exception',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function skipAttemptLog(array $context, string $reason): void
    {
        Log::warning(self::LOG_PREFIX . 'attempt log disposition skipped', $context + [
            'reason' => $reason,
        ]);
    }

    public function isCallbackDisposition(DialerDisposition $disposition): bool
    {
        return $this->requiresCallbackAt($disposition);
    }

    public function requiresCallbackAt(DialerDisposition $disposition): bool
    {
        return (bool) $disposition->requires_callback_at;
    }
}
