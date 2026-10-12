<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Helpers\PhoneHelper;
use App\Models\Conversation;
use App\Models\TelephonyCall;
use App\Models\TelephonyQueueLog;
use App\Models\VoiceCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueueLogImportService
{
    /**
     * @param array{pbx_id:int, tenant_id:int, linkedid:string, events:array<int,array>} $payload
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
            $row = $this->reconcileOutboundCrm($payload, $events, $row);

            TelephonyCall::withoutGlobalScopes()->upsert(
                [$row],
                ['pbx_id', 'linkedid'],
                $this->asteriskCallUpdateColumns()
            );

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
            $row = $this->reconcileOutboundCrm($payload, $events, $row);

            TelephonyCall::withoutGlobalScopes()->upsert(
                [$row],
                ['pbx_id', 'linkedid'],
                $this->asteriskCallUpdateColumns()
            );

            return true;
        });
    }

    /**
     * @param array{pbx_id:int, tenant_id:int, linkedid:string, events:array<int,array>} $payload
     * @param Collection<int, TelephonyQueueLog> $events
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
     * @param array{pbx_id:int, tenant_id:int, linkedid:string, events:array<int,array>} $payload
     * @param Collection<int, TelephonyQueueLog> $events
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
     * @param array{pbx_id:int, tenant_id:int, linkedid:string} $payload
     * @param Collection<int, TelephonyQueueLog> $events
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function reconcileOutboundCrm(array $payload, Collection $events, array $row): array
    {
        if (($row['direction'] ?? null) !== 'OUTBOUND') {
            return $row;
        }

        $tenantId = (int) $payload['tenant_id'];
        $voiceCall = $this->resolveVoiceCallForOutbound(
            $tenantId,
            $row['call_uuid'] ?? null,
            $row['conversation_id'] ?? null
        );

        if (!$voiceCall) {
            return $row;
        }

        // Never associate incorrectly: a conflicting linkedid stops reconciliation.
        if (!$this->linkVoiceCallLinkedid($voiceCall, (string) $payload['linkedid'])) {
            return $row;
        }

        $conversation = Conversation::withoutGlobalScopes()
            ->where('id', $voiceCall->conversation_id)
            ->where('tenant_id', $tenantId)
            ->first();

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

    private function resolveVoiceCallForOutbound(int $tenantId, ?string $callUuid, ?int $conversationId): ?VoiceCall
    {
        if ($callUuid !== null && $callUuid !== '') {
            $byUuid = VoiceCall::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('call_uuid', $callUuid)
                ->get();

            if ($byUuid->count() === 1) {
                return $byUuid->first();
            }
            if ($byUuid->count() > 1) {
                return null; // ambiguous (should be impossible: UNIQUE tenant_id+call_uuid)
            }
        }

        // Fallback by conversation_id — only when it resolves unambiguously.
        if ($conversationId !== null && $conversationId > 0) {
            $byConversation = VoiceCall::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->get();

            if ($byConversation->count() === 1) {
                return $byConversation->first();
            }
        }

        return null;
    }

    /**
     * @return bool true when the VoiceCall is (now) linked to $linkedid; false on a
     *              conflicting existing linkedid (reconciliation must stop).
     */
    private function linkVoiceCallLinkedid(VoiceCall $voiceCall, string $linkedid): bool
    {
        $existing = trim((string) ($voiceCall->asterisk_linkedid ?? ''));

        if ($existing === '') {
            $voiceCall->forceFill(['asterisk_linkedid' => $linkedid])->save();
            return true;
        }

        if ($existing === $linkedid) {
            return true;
        }

        Log::warning('[telephony][import] outbound_linkedid_conflict', [
            'tenant_id' => $voiceCall->tenant_id,
            'voice_call_id' => $voiceCall->id,
            'existing_linkedid' => $existing,
            'incoming_linkedid' => $linkedid,
        ]);

        return false;
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
        if (!$event) {
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
