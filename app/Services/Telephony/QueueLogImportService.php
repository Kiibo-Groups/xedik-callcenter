<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Helpers\PhoneHelper;
use App\Models\Conversation;
use App\Models\DialerCampaign;
use App\Models\LeadList;
use App\Models\TelephonyCall;
use App\Models\TelephonyQueueLog;
use App\Models\VoiceCall;
use App\Services\DialerTableService;
use App\Services\Voice\VoiceLinkedidCorrelationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueLogImportService
{
    public function __construct(
        private readonly VoiceLinkedidCorrelationService $linkedidCorrelation,
        private readonly DialerTableService $dialerTables,
    ) {}

    /**
     * @param  array{pbx_id:int, tenant_id:int, linkedid:string, events:array<int,array>}  $payload
     * @return array{events_imported:int, call_upserted:bool}
     */
    public function import(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $now = now();
            $rows = [];

            foreach ($payload['events'] as $event) {
                $rows[] = [
                    'pbx_id' => $payload['pbx_id'],
                    'tenant_id' => $payload['tenant_id'],
                    'asterisk_queue_log_id' => (int) $event['id'],
                    'event_time' => $this->parseEventTime($event['time']),
                    'linkedid' => $payload['linkedid'],
                    'queuename' => $this->nullableString($event['queuename'] ?? null),
                    'agent' => $this->nullableString($event['agent'] ?? null),
                    'event' => strtoupper(trim((string) $event['event'])),
                    'data1' => $this->nullableString($event['data1'] ?? null),
                    'data2' => $this->nullableString($event['data2'] ?? null),
                    'data3' => $this->nullableString($event['data3'] ?? null),
                    'data4' => $this->nullableString($event['data4'] ?? null),
                    'data5' => $this->nullableString($event['data5'] ?? null),
                    'created_at' => $now,
                ];
            }

            TelephonyQueueLog::withoutGlobalScopes()->upsert(
                $rows,
                ['pbx_id', 'asterisk_queue_log_id'],
                [
                    'tenant_id',
                    'event_time',
                    'linkedid',
                    'queuename',
                    'agent',
                    'event',
                    'data1',
                    'data2',
                    'data3',
                    'data4',
                    'data5',
                ]
            );

            $events = TelephonyQueueLog::withoutGlobalScopes()
                ->where('pbx_id', $payload['pbx_id'])
                ->where('linkedid', $payload['linkedid'])
                ->orderBy('event_time')
                ->orderBy('asterisk_queue_log_id')
                ->get();

            $row = $this->buildCallRow($payload, $events, $now);
            $outboundCrmContext = null;
            $row = $this->reconcileOutboundCrm($payload, $events, $row, $outboundCrmContext);
            $row = $this->reconcileInboundCrm($payload, $row);

            TelephonyCall::withoutGlobalScopes()->upsert(
                [$row],
                ['pbx_id', 'linkedid'],
                $this->asteriskCallUpdateColumns()
            );

            $this->reconcilePreviewMetricsAfterUpsert($payload, $outboundCrmContext);

            return [
                'events_imported' => count($rows),
                'call_upserted' => true,
            ];
        });
    }

    public function rebuildCallFromStoredEvents(int $pbxId, string $linkedid): bool
    {
        return DB::transaction(function () use ($pbxId, $linkedid): bool {
            $events = $this->storedEventsQuery($pbxId, $linkedid)->get();
            if ($events->isEmpty()) {
                return false;
            }

            $firstEvent = $events->first();
            $payload = [
                'pbx_id' => $pbxId,
                'tenant_id' => (int) $firstEvent->tenant_id,
                'linkedid' => $linkedid,
                'events' => [],
            ];

            $row = $this->buildCallRow($payload, $events, now());
            $outboundCrmContext = null;
            $row = $this->reconcileOutboundCrm($payload, $events, $row, $outboundCrmContext);
            $row = $this->reconcileInboundCrm($payload, $row);

            TelephonyCall::withoutGlobalScopes()->upsert(
                [$row],
                ['pbx_id', 'linkedid'],
                $this->asteriskCallUpdateColumns()
            );

            $this->reconcilePreviewMetricsAfterUpsert($payload, $outboundCrmContext);

            return true;
        });
    }

    /**
     * @param  array{pbx_id:int, tenant_id:int, linkedid:string, events:array<int,array>}  $payload
     * @param  Collection<int, TelephonyQueueLog>  $events
     * @return array<string, mixed>
     */
    private function buildCallRow(array $payload, Collection $events, mixed $now): array
    {
        // Manual Dial outbound calls carry OUTBOUND_* events (no ENTERQUEUE/CONNECT).
        // They consolidate from a different event set than native inbound Queue()
        // events. Inbound and outbound never share a linkedid, so this branch never
        // touches an inbound call's consolidation.
        if ($events->contains(fn (TelephonyQueueLog $event): bool => str_starts_with((string) $event->event, 'OUTBOUND_'))) {
            return $this->buildOutboundCallRow($payload, $events, $now);
        }

        $entered = $events->firstWhere('event', 'ENTERQUEUE');
        $connect = $events->firstWhere('event', 'CONNECT');
        $did = $events->firstWhere('event', 'DID');
        $terminal = $events
            ->filter(fn (TelephonyQueueLog $event): bool => in_array($event->event, TelephonyCall::TERMINAL_EVENTS, true))
            ->last();

        $endedAt = $terminal?->event_time;
        $enteredAt = $entered?->event_time;
        $answeredAt = $connect?->event_time;
        $didRaw = $did?->data1;
        $callerNumberRaw = $entered?->data2;

        return [
            'pbx_id' => $payload['pbx_id'],
            'tenant_id' => $payload['tenant_id'],
            'linkedid' => $payload['linkedid'],
            // Correlation/mode columns do not apply to native inbound Queue() events.
            'conversation_id' => null,
            'call_uuid' => null,
            'direction' => 'INBOUND',
            'call_mode' => null,
            'did' => PhoneHelper::normalizeTelephonyReportingNumber($didRaw),
            'queue_name' => $entered?->queuename ?? $events->first()?->queuename,
            'caller_number' => PhoneHelper::normalizeTelephonyReportingNumber($callerNumberRaw),
            'dial_prefix' => null,
            'agent' => $this->resolveAgent($events),
            'started_at' => $enteredAt,
            'entered_queue_at' => $enteredAt,
            'answered_at' => $answeredAt,
            'ended_at' => $endedAt,
            'exit_reason' => $terminal?->event,
            'hangup_cause' => null,
            'wait_seconds' => $this->resolveWaitSeconds($terminal, $connect),
            // ring_seconds = time before answer (entered_queue_at → answered_at).
            // Unchanged wait_seconds semantics; only computed when the call was answered.
            'ring_seconds' => $answeredAt !== null
                ? $this->diffInSeconds($enteredAt, $answeredAt)
                : null,
            'talk_seconds' => $this->resolveTalkSeconds($terminal),
            'call_duration_seconds' => $this->diffInSeconds($enteredAt, $endedAt),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Manual Dial outbound consolidation. Event contract (data slots agreed with the
     * dialplan):
     *   OUTBOUND_START  data1=conversation_id data2=call_uuid data3=destination
     *                   data4=caller_id data5=dial_prefix ; queuename=logical queue
     *   OUTBOUND_CONNECT (written only when answered) — no data
     *   OUTBOUND_END    data1=DIALSTATUS data2=ANSWEREDTIME data3=DIALEDTIME
     *                   data4=HANGUPCAUSE
     * caller_number holds the DIALED destination for outbound (there is no ANI).
     *
     * @param  array{pbx_id:int, tenant_id:int, linkedid:string, events:array<int,array>}  $payload
     * @param  Collection<int, TelephonyQueueLog>  $events
     * @return array<string, mixed>
     */
    private function buildOutboundCallRow(array $payload, Collection $events, mixed $now): array
    {
        $context = $events->firstWhere('event', 'XEDIK_CONTEXT');
        $start = $events->firstWhere('event', 'OUTBOUND_START');
        $connect = $events->firstWhere('event', 'OUTBOUND_CONNECT');
        $end = $events
            ->filter(fn (TelephonyQueueLog $event): bool => $event->event === 'OUTBOUND_END')
            ->last();

        $startFields = $this->outboundDataFields($start);
        $endFields = $this->outboundDataFields($end);
        $contextFields = $this->outboundDataFields($context);

        // OUTBOUND_START: data1=conversation_id, data2=call_uuid, data3=DNIS/destination,
        //                 data4=ANI/caller_id, data5=dial_prefix
        $conversationId = $this->intOrNull($startFields[1] ?? null);
        $callUuid = $this->trimmedOrNull($startFields[2] ?? null);
        $destination = $startFields[3] ?? null;   // DNIS
        $ani = $startFields[4] ?? null;           // ANI / caller id
        $dialPrefix = $this->trimmedOrNull($startFields[5] ?? null);

        // OUTBOUND_END: data1=DIALSTATUS, data2=ANSWEREDTIME, data3=DIALEDTIME, data4=HANGUPCAUSE
        $dialStatus = $this->trimmedOrNull($endFields[1] ?? null);
        $answeredTime = $this->intOrNull($endFields[2] ?? null);
        $dialedTime = $this->intOrNull($endFields[3] ?? null);
        $hangupCause = $this->trimmedOrNull($endFields[4] ?? null);

        $callMode = $this->trimmedOrNull($contextFields[5] ?? null) ?? 'MANUAL';
        $isAnswered = $dialStatus !== null && strtoupper($dialStatus) === 'ANSWER';

        $startedAt = $start?->event_time;
        $endedAt = $end?->event_time;

        // OUTBOUND_CONNECT is written from the hangup handler, so its event_time is NOT
        // the real answer time. When answered, derive answered_at = ended_at - ANSWEREDTIME.
        $answeredAt = null;
        if ($isAnswered && $endedAt !== null) {
            $answeredAt = CarbonImmutable::parse($endedAt)->subSeconds((int) ($answeredTime ?? 0));
        }

        // Outbound timing comes straight from Dial() metrics, not from timestamps:
        //   talk_seconds = ANSWEREDTIME, call_duration_seconds = DIALEDTIME,
        //   ring_seconds = DIALEDTIME - ANSWEREDTIME, wait_seconds = NULL (no queue wait).
        $talkSeconds = $answeredTime;
        $callDuration = $dialedTime;
        $ringSeconds = ($dialedTime !== null)
            ? max(0, $dialedTime - (int) ($answeredTime ?? 0))
            : null;

        $exitReason = $dialStatus !== null ? strtoupper($dialStatus) : null;

        return [
            'pbx_id' => $payload['pbx_id'],
            'tenant_id' => $payload['tenant_id'],
            'linkedid' => $payload['linkedid'],
            'conversation_id' => $conversationId,
            'call_uuid' => $callUuid,
            'direction' => 'OUTBOUND',
            'call_mode' => $callMode,
            'did' => PhoneHelper::normalizeTelephonyReportingNumber($destination),      // DNIS
            'queue_name' => $start?->queuename ?? $events->first()?->queuename,
            'caller_number' => PhoneHelper::normalizeTelephonyReportingNumber($ani),    // ANI
            'dial_prefix' => $dialPrefix,
            'agent' => $start?->agent ?? $end?->agent,
            'started_at' => $startedAt,
            // entered_queue_at mirrors started_at so date-range reports (indexed on
            // entered_queue_at) include outbound calls too. There is no queue wait.
            'entered_queue_at' => $startedAt,
            'answered_at' => $answeredAt,
            'ended_at' => $endedAt,
            'exit_reason' => $exitReason,
            'hangup_cause' => $hangupCause,
            // disposition is the HUMAN selection (exit_reason already holds the technical
            // DIALSTATUS). It is reconciled from the Conversation in reconcileOutboundCrm()
            // when the VoiceCall can be resolved by call_uuid/conversation_id — never
            // filled with DIALSTATUS here.
            'wait_seconds' => null,
            'ring_seconds' => $ringSeconds,
            'talk_seconds' => $talkSeconds,
            'call_duration_seconds' => $callDuration,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Outbound reconciliation bridge (until real-time SIP MESSAGE delivers the
     * linkedid). Using conversation_id/call_uuid from OUTBOUND_START it:
     *   1. resolves the CRM VoiceCall,
     *   2. links voice_calls.asterisk_linkedid (only if NULL or already matching),
     *   3. overlays CRM-owned columns (user_id, disposition, wrap-up) into the row.
     * CRM columns are insert-only in the upsert, so an existing human disposition or
     * wrap-up is never overwritten with NULL/DIALSTATUS on reimport.
     *
     * @param  array{pbx_id:int, tenant_id:int, linkedid:string}  $payload
     * @param  Collection<int, TelephonyQueueLog>  $events
     * @param  array<string, mixed>  $row
     * @param array{
     *   call_uuid:?string,
     *   voice_call:?VoiceCall,
     *   conversation:?Conversation,
     *   resolved_by_call_uuid:bool,
     *   skip_reason:?string
     * }|null $outboundCrmContext
     * @return array<string, mixed>
     */
    private function reconcileOutboundCrm(
        array $payload,
        Collection $events,
        array $row,
        ?array &$outboundCrmContext = null
    ): array {
        if (($row['direction'] ?? null) !== 'OUTBOUND') {
            return $row;
        }

        $tenantId = (int) $payload['tenant_id'];
        $callUuid = $this->trimmedOrNull($row['call_uuid'] ?? null);
        [$voiceCall, $resolvedByCallUuid] = $this->resolveVoiceCallForOutbound(
            $tenantId,
            $callUuid,
            $row['conversation_id'] ?? null
        );

        $outboundCrmContext = [
            'call_uuid' => $callUuid,
            'voice_call' => $resolvedByCallUuid ? $voiceCall : null,
            'conversation' => null,
            'resolved_by_call_uuid' => $resolvedByCallUuid,
            'skip_reason' => $callUuid === null
                ? 'call_uuid_missing'
                : ($resolvedByCallUuid ? null : 'voice_call_not_found_by_call_uuid'),
        ];

        if (! $voiceCall) {
            return $row;
        }

        // Never associate incorrectly: a conflicting linkedid stops reconciliation.
        if (! $this->linkVoiceCallLinkedid($voiceCall, (string) $payload['linkedid'])) {
            $outboundCrmContext['skip_reason'] = 'linkedid_conflict';

            return $row;
        }

        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $voiceCall->conversation_id)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($resolvedByCallUuid) {
            $outboundCrmContext['conversation'] = $conversation;
            if ($conversation === null) {
                $outboundCrmContext['skip_reason'] = 'conversation_not_found';
            }
        }

        $userId = $voiceCall->agent_user_id ?: $conversation?->assigned_to;
        if ($userId !== null && (int) $userId > 0) {
            $row['user_id'] = (int) $userId;
        }

        // disposition is the HUMAN selection, from the Conversation. Never DIALSTATUS.
        $disposition = $conversation?->disposition;
        if ($disposition !== null && $disposition !== '') {
            $row['disposition'] = $disposition;
        }

        // Wrap-up timing from the canonical VoiceCall store — never overwrite with null.
        if ($voiceCall->wrapup_started_at !== null) {
            $row['wrapup_started_at'] = $voiceCall->wrapup_started_at;
        }
        if ($voiceCall->wrapup_ended_at !== null) {
            $row['wrapup_ended_at'] = $voiceCall->wrapup_ended_at;
        }
        if ($voiceCall->wrapup_seconds !== null) {
            $row['wrapup_seconds'] = (int) $voiceCall->wrapup_seconds;
        }

        return $row;
    }

    /**
     * Reconcile provisional Preview Dialer metrics only after telephony_calls has been
     * upserted, so the structured audit log always has the consolidated call id. The
     * nested transaction is a savepoint inside import/rebuild: a dynamic-table failure
     * rolls back these metric writes without aborting the global QueueLog import.
     *
     * @param  array{pbx_id:int, tenant_id:int, linkedid:string}  $payload
     * @param array{
     *   call_uuid:?string,
     *   voice_call:?VoiceCall,
     *   conversation:?Conversation,
     *   resolved_by_call_uuid:bool,
     *   skip_reason:?string
     * }|null $context
     */
    private function reconcilePreviewMetricsAfterUpsert(array $payload, ?array $context): void
    {
        if ($context === null) {
            return;
        }

        $telephonyCall = TelephonyCall::withoutGlobalScopes()
            ->where('pbx_id', (int) $payload['pbx_id'])
            ->where('linkedid', (string) $payload['linkedid'])
            ->first();

        try {
            DB::transaction(function () use ($telephonyCall, $context): void {
                $this->reconcilePreviewMetrics($telephonyCall, $context);
            });
        } catch (Throwable $e) {
            $this->logPreviewMetricsSkipped('exception', $telephonyCall, $context, [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{
     *   call_uuid:?string,
     *   voice_call:?VoiceCall,
     *   conversation:?Conversation,
     *   resolved_by_call_uuid:bool,
     *   skip_reason:?string
     * } $context
     */
    private function reconcilePreviewMetrics(?TelephonyCall $telephonyCall, array $context): void
    {
        if ($telephonyCall === null) {
            $this->logPreviewMetricsSkipped('telephony_call_not_found', null, $context);

            return;
        }

        $callUuid = $this->trimmedOrNull($telephonyCall->call_uuid);
        if ($callUuid === null) {
            $this->logPreviewMetricsSkipped('call_uuid_missing', $telephonyCall, $context);

            return;
        }

        if (($context['skip_reason'] ?? null) !== null) {
            $this->logPreviewMetricsSkipped((string) $context['skip_reason'], $telephonyCall, $context);

            return;
        }

        $voiceCall = $context['voice_call'] ?? null;
        if (! $voiceCall instanceof VoiceCall
            || ! ($context['resolved_by_call_uuid'] ?? false)
            || $this->trimmedOrNull($voiceCall->call_uuid) !== $callUuid) {
            $this->logPreviewMetricsSkipped('voice_call_uuid_mismatch', $telephonyCall, $context);

            return;
        }

        $conversation = $context['conversation'] ?? null;
        if (! $conversation instanceof Conversation) {
            $this->logPreviewMetricsSkipped('conversation_not_found', $telephonyCall, $context);

            return;
        }

        $tenantId = (int) $telephonyCall->tenant_id;
        if ($tenantId <= 0
            || (int) $voiceCall->tenant_id !== $tenantId
            || (int) $conversation->tenant_id !== $tenantId) {
            $this->logPreviewMetricsSkipped('tenant_mismatch', $telephonyCall, $context);

            return;
        }

        if ((string) $telephonyCall->direction !== 'OUTBOUND'
            || (string) $voiceCall->direction !== 'outbound'
            || ! $conversation->isVoice()
            || (string) $conversation->direction !== 'outbound') {
            $this->logPreviewMetricsSkipped('not_outbound_voice', $telephonyCall, $context);

            return;
        }

        if ((int) $voiceCall->conversation_id !== (int) $conversation->id
            || (int) $telephonyCall->conversation_id !== (int) $conversation->id
            || $this->trimmedOrNull($conversation->external_id) !== $callUuid) {
            $this->logPreviewMetricsSkipped('conversation_correlation_mismatch', $telephonyCall, $context);

            return;
        }

        $leadListId = (int) ($conversation->lead_list_id ?? 0);
        if ($leadListId <= 0) {
            $this->logPreviewMetricsSkipped('invalid_lead_list_id', $telephonyCall, $context);

            return;
        }

        $leadListRowId = (int) ($conversation->lead_list_row_id ?? 0);
        if ($leadListRowId <= 0) {
            $this->logPreviewMetricsSkipped('invalid_lead_list_row_id', $telephonyCall, $context);

            return;
        }

        $dialerCampaignId = (int) ($conversation->dialer_campaign_id ?? 0);
        $dialerCampaign = $dialerCampaignId > 0
            ? DialerCampaign::withoutGlobalScopes()->find($dialerCampaignId)
            : null;

        if ($dialerCampaign === null
            || (string) $dialerCampaign->dialer_type !== 'preview'
            || (int) $dialerCampaign->tenant_id !== $tenantId
            || (int) $dialerCampaign->lead_list_id !== $leadListId) {
            $this->logPreviewMetricsSkipped('conversation_not_preview', $telephonyCall, $context);

            return;
        }

        $leadList = LeadList::withoutGlobalScopes()->find($leadListId);
        if ($leadList === null) {
            $this->logPreviewMetricsSkipped('lead_list_not_found', $telephonyCall, $context);

            return;
        }

        if ((int) $leadList->tenant_id !== $tenantId) {
            $this->logPreviewMetricsSkipped('tenant_mismatch', $telephonyCall, $context);

            return;
        }

        $leadTable = "dl_{$leadListId}_leads";
        $logTable = "dl_{$leadListId}_log";

        if ((string) $leadList->table_name !== $leadTable
            || (string) $leadList->log_table_name !== $logTable) {
            $this->logPreviewMetricsSkipped('dynamic_table_mapping_mismatch', $telephonyCall, $context);

            return;
        }

        // tableExists() applies DialerTableService's canonical identifier allowlist.
        if (! $this->dialerTables->tableExists($leadTable)
            || ! $this->dialerTables->tableExists($logTable)) {
            $this->logPreviewMetricsSkipped('dynamic_tables_missing', $telephonyCall, $context);

            return;
        }

        $lead = DB::table($leadTable)
            ->where('id', $leadListRowId)
            ->lockForUpdate()
            ->first();
        if ($lead === null) {
            $this->logPreviewMetricsSkipped('lead_row_not_found', $telephonyCall, $context);

            return;
        }

        $logRows = DB::table($logTable)
            ->where('call_uuid', $callUuid)
            ->lockForUpdate()
            ->get();
        if ($logRows->count() !== 1) {
            $this->logPreviewMetricsSkipped(
                $logRows->isEmpty() ? 'log_row_not_found' : 'log_row_ambiguous',
                $telephonyCall,
                $context
            );

            return;
        }

        $logRow = $logRows->first();
        $duration = $telephonyCall->call_duration_seconds;
        $talkSeconds = $telephonyCall->talk_seconds;
        $linkedid = $this->trimmedOrNull($telephonyCall->linkedid);

        $previousLastDuration = $this->nullableInt($lead->last_duration ?? null);
        $previousLastBillsec = $this->nullableInt($lead->last_billsec ?? null);
        $previousLogDuration = $this->nullableInt($logRow->duration ?? null);
        $previousLogBillsec = $this->nullableInt($logRow->billsec ?? null);
        $previousUniqueid = $this->trimmedOrNull($logRow->uniqueid ?? null);

        $leadUpdates = [];
        $logUpdates = [];

        if ($duration !== null) {
            $leadUpdates['last_duration'] = (int) $duration;
            $logUpdates['duration'] = (int) $duration;
        }
        if ($talkSeconds !== null) {
            $leadUpdates['last_billsec'] = (int) $talkSeconds;
            $logUpdates['billsec'] = (int) $talkSeconds;
        }
        if ($linkedid !== null) {
            $logUpdates['uniqueid'] = $linkedid;
        }

        if ($leadUpdates !== []) {
            DB::table($leadTable)->where('id', $leadListRowId)->update($leadUpdates);
        }
        if ($logUpdates !== []) {
            DB::table($logTable)->where('call_uuid', $callUuid)->update($logUpdates);
        }

        Log::info('[PreviewMetricsReconciliation] reconciled', [
            'tenant_id' => $tenantId,
            'telephony_call_id' => (int) $telephonyCall->id,
            'call_uuid' => $callUuid,
            'linkedid' => $linkedid,
            'conversation_id' => (int) $conversation->id,
            'voice_call_id' => (int) $voiceCall->id,
            'lead_list_id' => $leadListId,
            'lead_list_row_id' => $leadListRowId,
            'previous_last_duration' => $previousLastDuration,
            'new_last_duration' => $duration !== null ? (int) $duration : $previousLastDuration,
            'previous_last_billsec' => $previousLastBillsec,
            'new_last_billsec' => $talkSeconds !== null ? (int) $talkSeconds : $previousLastBillsec,
            'previous_log_duration' => $previousLogDuration,
            'new_log_duration' => $duration !== null ? (int) $duration : $previousLogDuration,
            'previous_log_billsec' => $previousLogBillsec,
            'new_log_billsec' => $talkSeconds !== null ? (int) $talkSeconds : $previousLogBillsec,
            'previous_uniqueid' => $previousUniqueid,
            'new_uniqueid' => $linkedid ?? $previousUniqueid,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function logPreviewMetricsSkipped(
        string $reason,
        ?TelephonyCall $telephonyCall,
        array $context,
        array $extra = []
    ): void {
        $conversation = ($context['conversation'] ?? null) instanceof Conversation
            ? $context['conversation']
            : null;

        Log::warning('[PreviewMetricsReconciliation] skipped', array_merge([
            'reason' => $reason,
            'call_uuid' => $this->trimmedOrNull($telephonyCall?->call_uuid)
                ?? $this->trimmedOrNull($context['call_uuid'] ?? null),
            'linkedid' => $this->trimmedOrNull($telephonyCall?->linkedid),
            'conversation_id' => $conversation?->id ?? $telephonyCall?->conversation_id,
            'lead_list_id' => $conversation?->lead_list_id,
            'lead_list_row_id' => $conversation?->lead_list_row_id,
        ], $extra));
    }

    /**
     * Inbound reconciliation: fill telephony_calls.conversation_id / call_uuid from
     * the canonical VoiceCall (resolved by tenant_id + asterisk_linkedid — the same
     * linkedid the row is keyed on). Race-safe:
     *   - completes NULLs from the VoiceCall,
     *   - never nulls an already-valid value (preserves the existing row's value when
     *     the VoiceCall does not exist yet — normal QueueLog-first race),
     *   - never changes a valid value to a different one (logs a conflict instead).
     * call_mode stays NULL and direction stays INBOUND — inbound has no call mode.
     *
     * @param  array{pbx_id:int, tenant_id:int, linkedid:string}  $payload
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function reconcileInboundCrm(array $payload, array $row): array
    {
        if (($row['direction'] ?? null) !== 'INBOUND') {
            return $row;
        }

        $tenantId = (int) $payload['tenant_id'];
        $pbxId = (int) $payload['pbx_id'];
        $linkedid = (string) $payload['linkedid'];

        $existing = TelephonyCall::withoutGlobalScopes()
            ->where('pbx_id', $pbxId)
            ->where('linkedid', $linkedid)
            ->first();

        // Start from what the row/existing already holds so we never overwrite a valid
        // value with NULL.
        $conversationId = $row['conversation_id'] ?? $existing?->conversation_id;
        $callUuid = $row['call_uuid'] ?? $existing?->call_uuid;

        $voiceCall = VoiceCall::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('asterisk_linkedid', $linkedid)
            ->first();

        if ($voiceCall === null) {
            // Normal QueueLog-first race — not an error. Preserve whatever exists.
            Log::debug('[telephony][import] inbound_reconciliation_voice_call_not_found', [
                'tenant_id' => $tenantId,
                'pbx_id' => $pbxId,
                'linkedid' => $linkedid,
                'telephony_call_id' => $existing?->id,
            ]);

            $row['conversation_id'] = $conversationId;
            $row['call_uuid'] = $callUuid;

            return $row;
        }

        $vcConversationId = $voiceCall->conversation_id !== null ? (int) $voiceCall->conversation_id : null;
        $vcCallUuid = $this->trimmedOrNull($voiceCall->call_uuid);

        if ($vcConversationId !== null) {
            if ($conversationId !== null && (int) $conversationId !== $vcConversationId) {
                $this->logInboundConflict('conversation_id', $tenantId, $pbxId, $linkedid, $existing?->id, $voiceCall->id, (string) $conversationId, (string) $vcConversationId);
            } else {
                $conversationId = $vcConversationId;
            }
        }

        if ($vcCallUuid !== null) {
            if ($callUuid !== null && $callUuid !== '' && $callUuid !== $vcCallUuid) {
                $this->logInboundConflict('call_uuid', $tenantId, $pbxId, $linkedid, $existing?->id, $voiceCall->id, (string) $callUuid, $vcCallUuid);
            } else {
                $callUuid = $vcCallUuid;
            }
        }

        Log::info('[telephony][import] inbound_reconciliation_completed', [
            'tenant_id' => $tenantId,
            'pbx_id' => $pbxId,
            'linkedid' => $linkedid,
            'telephony_call_id' => $existing?->id,
            'voice_call_id' => $voiceCall->id,
            'conversation_id' => $conversationId,
            'call_uuid' => $callUuid,
        ]);

        $row['conversation_id'] = $conversationId;
        $row['call_uuid'] = $callUuid;

        return $row;
    }

    private function logInboundConflict(
        string $field,
        int $tenantId,
        int $pbxId,
        string $linkedid,
        ?int $telephonyCallId,
        int $voiceCallId,
        string $existingValue,
        string $incomingValue
    ): void {
        Log::warning('[telephony][import] inbound_reconciliation_conflict', [
            'field' => $field,
            'tenant_id' => $tenantId,
            'pbx_id' => $pbxId,
            'linkedid' => $linkedid,
            'telephony_call_id' => $telephonyCallId,
            'voice_call_id' => $voiceCallId,
            'existing_value' => $existingValue,
            'incoming_value' => $incomingValue,
        ]);
    }

    /** @return array{0:?VoiceCall, 1:bool} call and whether it was resolved by call_uuid */
    private function resolveVoiceCallForOutbound(int $tenantId, ?string $callUuid, ?int $conversationId): array
    {
        if ($callUuid !== null && $callUuid !== '') {
            $byUuid = VoiceCall::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('call_uuid', $callUuid)
                ->get();

            if ($byUuid->count() === 1) {
                return [$byUuid->first(), true];
            }
            if ($byUuid->count() > 1) {
                return [null, false]; // ambiguous (should be impossible: UNIQUE tenant_id+call_uuid)
            }
        }

        // Fallback by conversation_id — only when it resolves unambiguously.
        if ($conversationId !== null && $conversationId > 0) {
            $byConversation = VoiceCall::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->get();

            if ($byConversation->count() === 1) {
                return [$byConversation->first(), false];
            }
        }

        return [null, false];
    }

    /**
     * @return bool true when the VoiceCall is (now) linked to $linkedid; false on a
     *              conflicting linkedid (reconciliation must stop). Delegates to the
     *              single conflict-safe writer so the rules live in one place.
     */
    private function linkVoiceCallLinkedid(VoiceCall $voiceCall, string $linkedid): bool
    {
        return $this->linkedidCorrelation->correlate($voiceCall, $linkedid, 'queue_log_import')
            !== VoiceLinkedidCorrelationService::RESULT_CONFLICT;
    }

    /**
     * Return the 1-indexed data1..data5 of a QueueLog event. If the realtime queue_log
     * backend did NOT split the '|'-delimited additionalinfo into separate columns
     * (everything landed in data1), fall back to splitting data1 on '|'. This makes
     * consolidation correct regardless of how the PBX stores multi-field QueueLog rows.
     *
     * @return array<int, ?string>
     */
    private function outboundDataFields(?TelephonyQueueLog $event): array
    {
        if (! $event) {
            return [];
        }

        $cols = [
            1 => $event->data1,
            2 => $event->data2,
            3 => $event->data3,
            4 => $event->data4,
            5 => $event->data5,
        ];

        $populated = array_filter($cols, fn ($v): bool => $v !== null && $v !== '');
        if (count($populated) <= 1
            && is_string($event->data1)
            && str_contains($event->data1, '|')) {
            $cols = [];
            foreach (explode('|', $event->data1) as $i => $part) {
                $cols[$i + 1] = $part === '' ? null : $part;
            }
        }

        return $cols;
    }

    /** @param Collection<int, TelephonyQueueLog> $events */
    private function resolveAgent(Collection $events): ?string
    {
        $event = $events
            ->filter(fn (TelephonyQueueLog $event): bool => $event->agent !== null && $event->agent !== '')
            ->filter(fn (TelephonyQueueLog $event): bool => $event->event === 'CONNECT'
                || str_starts_with($event->event, 'COMPLETE')
                || $event->event === 'TRANSFER')
            ->last();

        return $event?->agent;
    }

    private function resolveWaitSeconds(?TelephonyQueueLog $terminal, ?TelephonyQueueLog $connect): ?int
    {
        if ($terminal?->event === 'ABANDON') {
            return $this->intOrNull($terminal->data3);
        }

        if (in_array($terminal?->event, ['COMPLETEAGENT', 'COMPLETECALLER'], true)) {
            return $this->intOrNull($terminal->data1);
        }

        if ($terminal?->event === 'TRANSFER') {
            return $this->intOrNull($terminal->data3) ?? $this->intOrNull($terminal->data1);
        }

        return $this->intOrNull($connect?->data1);
    }

    private function resolveTalkSeconds(?TelephonyQueueLog $terminal): ?int
    {
        if (in_array($terminal?->event, ['COMPLETEAGENT', 'COMPLETECALLER'], true)) {
            return $this->intOrNull($terminal->data2);
        }

        if ($terminal?->event === 'TRANSFER') {
            return $this->intOrNull($terminal->data4) ?? $this->intOrNull($terminal->data2);
        }

        return null;
    }

    private function parseEventTime(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return CarbonImmutable::parse($raw)->format('Y-m-d H:i:s');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function diffInSeconds(mixed $start, mixed $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }

        return (int) max(0, CarbonImmutable::parse($start)->diffInSeconds(CarbonImmutable::parse($end), false));
    }

    /**
     * Columns the Asterisk import owns and may overwrite on reimport/rebuild.
     * CRM-owned columns (user_id, disposition, wrapup_*) are deliberately excluded so
     * a human/CRM disposition and wrap-up survive re-consolidation. `disposition` is
     * therefore insert-only for outbound (a technical DIALSTATUS default) and is never
     * updated here.
     *
     * @return array<int, string>
     */
    private function asteriskCallUpdateColumns(): array
    {
        return [
            'tenant_id',
            'conversation_id',
            'call_uuid',
            'direction',
            'call_mode',
            'did',
            'queue_name',
            'caller_number',
            'dial_prefix',
            'agent',
            'started_at',
            'entered_queue_at',
            'answered_at',
            'ended_at',
            'exit_reason',
            'hangup_cause',
            'wait_seconds',
            'ring_seconds',
            'talk_seconds',
            'call_duration_seconds',
            'updated_at',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<TelephonyQueueLog> */
    private function storedEventsQuery(int $pbxId, string $linkedid)
    {
        return TelephonyQueueLog::withoutGlobalScopes()
            ->where('pbx_id', $pbxId)
            ->where('linkedid', $linkedid)
            ->orderBy('event_time')
            ->orderBy('asterisk_queue_log_id');
    }
}
