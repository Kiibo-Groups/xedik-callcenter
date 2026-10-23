<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Helpers\PhoneHelper;
use App\Models\TelephonyCall;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallsReportService
{
    private const SELECT_COLUMNS = [
        'id', 'pbx_id', 'tenant_id', 'user_id', 'conversation_id', 'call_uuid',
        'linkedid', 'did', 'queue_name', 'caller_number', 'agent', 'direction',
        'call_mode', 'dial_prefix', 'hangup_cause', 'started_at',
        'entered_queue_at', 'answered_at', 'ended_at', 'exit_reason',
        'disposition', 'wrapup_started_at', 'wrapup_ended_at', 'wrapup_seconds',
        'ring_seconds', 'wait_seconds', 'talk_seconds', 'call_duration_seconds',
        'created_at', 'updated_at',
    ];

    /** @param array<string, mixed> $filters */
    public function calls(User $user, array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($user, $filters, true)
            ->with(['user:id,name', 'conversation:id,tenant_id,campaign_id,omni_queue_id,subject,from_name,from_email', 'conversation.campaign:id,name', 'conversation.omniQueue:id,name'])
            ->select($this->qualifiedCallColumns())
            ->addSelect($this->resolvedCampaignSelects())
            ->orderByDesc('telephony_calls.started_at')
            ->orderByDesc('telephony_calls.id')
            ->paginate(20)
            ->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    public function groupedByAgent(User $user, array $filters): Collection
    {
        $callRows = $this->filteredQuery($user, $filters, true)
            ->where(function (Builder $q): void {
                $q->where(function (Builder $inbound): void {
                    $inbound->whereRaw("UPPER(COALESCE(telephony_calls.direction, '')) = 'INBOUND'")
                        ->whereNotNull('telephony_calls.answered_at')
                        ->where($this->hasAttributableAgentCondition());
                })->orWhere(function (Builder $outbound): void {
                    $outbound->whereRaw("UPPER(COALESCE(telephony_calls.direction, '')) = 'OUTBOUND'")
                        ->where($this->hasAttributableAgentCondition());
                });
            })
            ->leftJoin('users', 'users.id', '=', 'telephony_calls.user_id')
            ->selectRaw("
                CASE WHEN telephony_calls.user_id IS NOT NULL THEN telephony_calls.user_id ELSE NULL END as agent_user_id,
                CASE WHEN telephony_calls.user_id IS NULL THEN NULLIF(telephony_calls.agent, '') ELSE NULL END as agent_fallback,
                COALESCE(users.name, NULLIF(telephony_calls.agent, '')) as agent_name,
                " . $this->resolvedCampaignIdExpression() . " as campaign_id,
                " . $this->resolvedCampaignNameExpression() . " as campaign_name,
                telephony_calls.direction,
                telephony_calls.answered_at,
                telephony_calls.talk_seconds
            ");

        $query = DB::query()
            ->fromSub($callRows->toBase(), 'call_rows')
            ->selectRaw("
                agent_user_id,
                agent_fallback,
                agent_name,
                campaign_id,
                campaign_name,
                SUM(CASE WHEN UPPER(COALESCE(direction, '')) = 'INBOUND' AND answered_at IS NOT NULL THEN 1 ELSE 0 END) as inbound_answered,
                SUM(CASE WHEN UPPER(COALESCE(direction, '')) = 'OUTBOUND' THEN 1 ELSE 0 END) as outbound_attempts,
                SUM(CASE WHEN UPPER(COALESCE(direction, '')) = 'OUTBOUND' AND answered_at IS NOT NULL THEN 1 ELSE 0 END) as outbound_answered,
                SUM(CASE WHEN UPPER(COALESCE(direction, '')) = 'OUTBOUND' AND answered_at IS NULL THEN 1 ELSE 0 END) as outbound_no_answer,
                COALESCE(SUM(talk_seconds), 0) as total_talk_time,
                COUNT(talk_seconds) as talk_sample_count,
                AVG(talk_seconds) as avg_talk_time
            ")
            ->groupBy('agent_user_id', 'agent_fallback', 'agent_name', 'campaign_id', 'campaign_name');

        return $query
            ->orderBy('agent_name')
            ->orderBy('campaign_name')
            ->get()
            ->map(function ($row): object {
                $inboundAnswered = (int) $row->inbound_answered;
                $outboundAttempts = (int) $row->outbound_attempts;
                $row->agent_name = $this->displayText($row->agent_name);
                $row->campaign_name = $row->campaign_name ?: 'No Campaign';
                $row->inbound_answered = $inboundAnswered;
                $row->outbound_attempts = $outboundAttempts;
                $row->outbound_answered = (int) $row->outbound_answered;
                $row->outbound_no_answer = (int) $row->outbound_no_answer;
                $row->total_answered_calls = $inboundAnswered + $row->outbound_answered;
                $row->total_talk_time = (int) $row->total_talk_time;
                $row->talk_sample_count = (int) $row->talk_sample_count;
                $row->avg_talk_time = $row->avg_talk_time !== null ? (int) round((float) $row->avg_talk_time) : null;

                return $row;
            });
    }

    /** @param array<string, mixed> $filters */
    public function metrics(User $user, array $filters): array
    {
        $row = $this->filteredQuery($user, $filters)
            ->selectRaw('
                COUNT(*) as total_calls,
                SUM(CASE WHEN telephony_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN telephony_calls.answered_at IS NULL AND NOT (UPPER(COALESCE(telephony_calls.direction, \'\')) = \'INBOUND\' AND UPPER(COALESCE(telephony_calls.exit_reason, \'\')) = \'ABANDON\') THEN 1 ELSE 0 END) as no_answer,
                SUM(CASE WHEN UPPER(COALESCE(telephony_calls.direction, \'\')) = \'INBOUND\' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN UPPER(COALESCE(telephony_calls.direction, \'\')) = \'OUTBOUND\' THEN 1 ELSE 0 END) as outbound,
                SUM(CASE WHEN UPPER(COALESCE(telephony_calls.direction, \'\')) = \'INBOUND\' AND UPPER(COALESCE(telephony_calls.exit_reason, \'\')) = \'ABANDON\' THEN 1 ELSE 0 END) as abandoned,
                COALESCE(SUM(telephony_calls.talk_seconds), 0) as total_talk_time,
                AVG(telephony_calls.talk_seconds) as avg_talk_time,
                AVG(telephony_calls.ring_seconds) as avg_ring_time,
                AVG(CASE WHEN UPPER(COALESCE(telephony_calls.direction, \'\')) = \'INBOUND\' AND telephony_calls.entered_queue_at IS NOT NULL AND telephony_calls.queue_name IS NOT NULL AND telephony_calls.queue_name <> \'\' THEN telephony_calls.wait_seconds ELSE NULL END) as avg_queue_wait_time,
                AVG(telephony_calls.call_duration_seconds) as avg_call_duration
            ')
            ->first();

        $total = (int) ($row->total_calls ?? 0);
        $answered = (int) ($row->answered ?? 0);

        return [
            'total_calls' => $total,
            'answered' => $answered,
            'no_answer' => (int) ($row->no_answer ?? 0),
            'answer_rate' => $total > 0 ? round(($answered / $total) * 100, 1) : 0.0,
            'inbound' => (int) ($row->inbound ?? 0),
            'outbound' => (int) ($row->outbound ?? 0),
            'abandoned' => (int) ($row->abandoned ?? 0),
            'total_talk_time' => (int) ($row->total_talk_time ?? 0),
            'avg_talk_time' => $row->avg_talk_time !== null ? (int) round((float) $row->avg_talk_time) : null,
            'avg_ring_time' => $row->avg_ring_time !== null ? (int) round((float) $row->avg_ring_time) : null,
            'avg_queue_wait_time' => $row->avg_queue_wait_time !== null ? (int) round((float) $row->avg_queue_wait_time) : null,
            'avg_call_duration' => $row->avg_call_duration !== null ? (int) round((float) $row->avg_call_duration) : null,
        ];
    }

    public function queueOptions(User $user): Collection
    {
        $tenantId = $user->tenant_id ?: 2;
        return $this->applyCampaignResolutionJoins(
            TelephonyCall::withoutGlobalScopes()
                ->where('telephony_calls.tenant_id', $tenantId)
                ->whereNotNull('telephony_calls.queue_name')
                ->where('telephony_calls.queue_name', '<>', '')
        )
            ->selectRaw('
                telephony_calls.queue_name as value,
                COALESCE(oq_report.name, telephony_calls.queue_name) as label,
                oq_report.id as resolved_queue_id
            ')
            ->groupBy('telephony_calls.queue_name', 'oq_report.name', 'oq_report.id')
            ->orderBy('label')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function groupMetrics(User $user, array $filters): array
    {
        $rows = $this->groupedByAgent($user, $filters);
        $totalTalkTime = (int) $rows->sum('total_talk_time');
        $talkSampleCount = (int) $rows->sum('talk_sample_count');

        return [
            'total_agents' => $rows->pluck('agent_name')->unique()->count(),
            'inbound_answered' => (int) $rows->sum('inbound_answered'),
            'outbound_attempts' => (int) $rows->sum('outbound_attempts'),
            'outbound_answered' => (int) $rows->sum('outbound_answered'),
            'outbound_no_answer' => (int) $rows->sum('outbound_no_answer'),
            'total_answered_calls' => (int) $rows->sum('total_answered_calls'),
            'total_talk_time' => $totalTalkTime,
            'avg_talk_time' => $talkSampleCount > 0 ? (int) round($totalTalkTime / $talkSampleCount) : null,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function exportCsv(User $user, array $filters): StreamedResponse
    {
        $from = str_replace([':', ' '], ['', '_'], (string) $filters['from']);
        $to = str_replace([':', ' '], ['', '_'], (string) $filters['to']);
        $viewType = (string) ($filters['view_type'] ?? 'detailed');
        $filename = $viewType === 'agent_summary'
            ? "calls_report_agent_summary_{$from}_{$to}.csv"
            : "calls_report_{$from}_{$to}.csv";
        $timezone = $this->timezone();

        if ($viewType === 'agent_summary') {
            return $this->exportGroupedCsv($user, $filters, $filename);
        }

        $query = $this->filteredQuery($user, $filters, true)
            ->with(['user:id,name', 'conversation:id,tenant_id,campaign_id,omni_queue_id,subject,from_name,from_email', 'conversation.campaign:id,name', 'conversation.omniQueue:id,name'])
            ->select($this->qualifiedCallColumns())
            ->addSelect($this->resolvedCampaignSelects())
            ->orderByDesc('telephony_calls.started_at')
            ->orderByDesc('telephony_calls.id');

        return response()->stream(function () use ($query, $timezone): void {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Date', 'Direction', 'Mode', 'ANI', 'DNIS / Caller ID',
                'Campaign', 'Queue', 'Agent', 'Outcome', 'Disposition',
                'Ring Time', 'Queue Wait', 'Talk Time', 'Wrap-Up Time', 'Duration',
                'Started At', 'Entered Queue At', 'Answered At', 'Ended At',
                'Call UUID', 'LinkedID',
            ]);

            foreach ($query->lazy(500) as $call) {
                fputcsv($handle, array_map(fn ($value) => $this->sanitizeCsvValue($value), [
                    $this->formatDate($this->callStartAt($call), $timezone),
                    $this->directionLabel($call),
                    $this->modeLabel($call),
                    $this->customerNumber($call),
                    $this->campaignNumber($call),
                    $this->campaignName($call),
                    $this->queueName($call),
                    $this->agentName($call),
                    $this->resultLabel($call),
                    $call->disposition,
                    $this->formatSeconds($call->ring_seconds),
                    $this->formatSeconds($this->queueWaitSeconds($call)),
                    $this->formatSeconds($call->talk_seconds),
                    $this->formatSeconds($call->wrapup_seconds),
                    $this->formatSeconds($call->call_duration_seconds),
                    $this->formatDate($call->started_at, $timezone),
                    $this->formatDate($this->queueEnteredAt($call), $timezone),
                    $this->formatDate($call->answered_at, $timezone),
                    $this->formatDate($call->ended_at, $timezone),
                    $call->call_uuid,
                    $call->linkedid,
                ]));
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function exportGroupedCsv(User $user, array $filters, string $filename): StreamedResponse
    {
        $rows = $this->groupedByAgent($user, $filters);

        return response()->stream(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Agent', 'Campaign', 'Inbound Answered', 'Outbound Attempts',
                'Outbound Answered', 'Outbound No Answer', 'Total Answered Calls',
                'Total Talk Time', 'Average Talk Time',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($value) => $this->sanitizeCsvValue($value), [
                    $row->agent_name,
                    $row->campaign_name,
                    $row->inbound_answered,
                    $row->outbound_attempts,
                    $row->outbound_answered,
                    $row->outbound_no_answer,
                    $row->total_answered_calls,
                    $this->formatSeconds($row->total_talk_time),
                    $this->formatSeconds($row->avg_talk_time),
                ]));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function detail(TelephonyCall $call, User $user): array
    {
        $this->assertTenant($call, $user);
        $call = $this->queryWithCampaignResolution()
            ->where('telephony_calls.id', $call->id)
            ->select($this->qualifiedCallColumns())
            ->addSelect($this->resolvedCampaignSelects())
            ->firstOrFail();
        $call->load(['user:id,name,email', 'conversation:id,tenant_id,campaign_id,omni_queue_id,subject,from_name,from_email,status', 'conversation.campaign:id,name', 'conversation.omniQueue:id,name']);

        return [
            'call' => $this->present($call),
        ];
    }

    public function present(TelephonyCall $call): array
    {
        return [
            'id' => $call->id,
            'tenant_id' => $call->tenant_id,
            'pbx_id' => $call->pbx_id,
            'linkedid' => $call->linkedid,
            'call_uuid' => $call->call_uuid,
            'conversation_id' => $call->conversation_id,
            'conversation_url' => $call->conversation_id ? route('console.index', ['open' => $call->conversation_id]) : null,
            'call_start' => $this->formatDate($this->callStartAt($call)),
            'direction' => $this->directionLabel($call),
            'mode' => $this->modeLabel($call),
            'call_mode' => $this->modeLabel($call),
            'customer_number' => $this->customerNumber($call),
            'campaign_number' => $this->campaignNumber($call),
            'campaign_number_role' => $this->campaignNumberRole($call),
            'ani' => $this->isInbound($call) ? $call->caller_number : null,
            'dnis' => $this->isInbound($call) ? $call->did : null,
            'outbound_caller_id' => $this->isOutbound($call) ? $call->caller_number : null,
            'dial_prefix' => $call->dial_prefix,
            'queue' => $this->queueName($call),
            'agent' => $this->agentName($call),
            'campaign' => $this->campaignName($call),
            'result' => $this->resultLabel($call),
            'started_at' => $this->formatDate($call->started_at),
            'entered_queue_at' => $this->formatDate($this->queueEnteredAt($call)),
            'answered_at' => $this->formatDate($call->answered_at),
            'ended_at' => $this->formatDate($call->ended_at),
            'wrapup_started_at' => $this->formatDate($call->wrapup_started_at),
            'wrapup_ended_at' => $this->formatDate($call->wrapup_ended_at),
            'ring_seconds' => $call->ring_seconds,
            'wait_seconds' => $this->queueWaitSeconds($call),
            'talk_seconds' => $call->talk_seconds,
            'call_duration_seconds' => $call->call_duration_seconds,
            'wrapup_seconds' => $call->wrapup_seconds,
            'ring_time' => $this->formatSeconds($call->ring_seconds),
            'wait_time' => $this->formatSeconds($this->queueWaitSeconds($call)),
            'talk_time' => $this->formatSeconds($call->talk_seconds),
            'duration' => $this->formatSeconds($call->call_duration_seconds),
            'wrapup_time' => $this->formatSeconds($call->wrapup_seconds),
            'exit_reason' => $call->exit_reason,
            'disposition' => $call->disposition,
            'recording_available' => $this->recordingAvailable($call),
        ];
    }

    public function timezone(): string
    {
        return config('app.timezone', 'UTC');
    }

    public function callStartAt(TelephonyCall $call): mixed
    {
        return $call->started_at;
    }

    public function customerNumber(TelephonyCall $call): string
    {
        if ($this->isOutbound($call)) {
            return $this->displayText($call->did);
        }

        return $this->displayText($call->caller_number);
    }

    public function campaignNumber(TelephonyCall $call): string
    {
        if ($this->isOutbound($call)) {
            return $this->displayText($call->caller_number);
        }

        return $this->displayText($call->did);
    }

    public function campaignNumberRole(TelephonyCall $call): string
    {
        if ($this->isOutbound($call)) {
            return '';
        }

        if ($this->isInbound($call)) {
            return '';
        }

        return 'DNIS / Caller ID';
    }

    public function modeLabel(TelephonyCall $call): string
    {
        if (!$this->isOutbound($call)) {
            return 'N/A';
        }

        $raw = trim((string) $call->call_mode);
        if ($raw === '') {
            return 'Unknown';
        }

        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $raw) ?? '');
        $normalized = trim($normalized, '_');

        return match ($normalized) {
            'manual', 'outbound_manual' => 'Manual',
            'preview', 'outbound_preview' => 'Preview',
            default => ucwords(strtolower(str_replace('_', ' ', $normalized ?: $raw))),
        };
    }

    public function recordingAvailable(TelephonyCall $call): bool
    {
        $linkedid = trim((string) $call->linkedid);

        return $linkedid !== ''
            && preg_match('/\A[0-9]+(?:[.-][0-9]+)*\z/', $linkedid) === 1
            && $call->started_at !== null
            && ($this->isInbound($call) || $this->isOutbound($call))
            && !($this->isInbound($call) && strtoupper((string) $call->exit_reason) === 'ABANDON');
    }

    public function queueEnteredAt(TelephonyCall $call): mixed
    {
        return $this->isInbound($call) ? $call->entered_queue_at : null;
    }

    public function queueWaitSeconds(TelephonyCall $call): ?int
    {
        if (!$this->isInbound($call) || $call->entered_queue_at === null || blank($call->queue_name) || $call->wait_seconds === null) {
            return null;
        }

        return (int) $call->wait_seconds;
    }

    public function formatDate(mixed $value, ?string $timezone = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return CarbonImmutable::parse($value)->setTimezone($timezone ?? $this->timezone())->format('Y-m-d H:i:s');
    }

    public function formatSeconds(mixed $seconds): string
    {
        if ($seconds === null || $seconds === '') {
            return '—';
        }

        $seconds = max(0, (int) $seconds);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    public function directionLabel(TelephonyCall $call): string
    {
        return match (strtoupper((string) $call->direction)) {
            'INBOUND' => 'Inbound',
            'OUTBOUND' => 'Outbound',
            default => '—',
        };
    }

    public function isInbound(TelephonyCall $call): bool
    {
        return strtoupper((string) $call->direction) === 'INBOUND';
    }

    public function isOutbound(TelephonyCall $call): bool
    {
        return strtoupper((string) $call->direction) === 'OUTBOUND';
    }

    public function resultLabel(TelephonyCall $call): string
    {
        $exitReason = strtoupper((string) ($call->exit_reason ?? ''));
        $hangupCause = strtoupper((string) ($call->hangup_cause ?? ''));
        $state = $exitReason !== '' ? $exitReason : $hangupCause;

        return match ($state) {
            'COMPLETEAGENT' => 'Agent Hung Up',
            'COMPLETECALLER' => 'Caller Hung Up',
            'ABANDON' => 'Abandoned',
            'EXITWITHTIMEOUT' => 'Timeout',
            'EXITEMPTY', 'EXITWITHKEY', 'NOANSWER', 'NO_ANSWER' => 'No Answer',
            'TRANSFER' => 'Answered',
            'BUSY' => 'Busy',
            'CANCEL', 'CANCELLED' => 'Cancel',
            'FAILED', 'FAILURE', 'CHANUNAVAIL', 'UNAVAILABLE' => 'Failed',
            'CONGESTION' => 'Congestion',
            default => $call->answered_at !== null ? 'Answered' : 'Unknown',
        };
    }

    public function agentName(TelephonyCall $call): string
    {
        if ($call->user?->name) {
            return $call->user->name;
        }

        $agent = trim((string) $call->agent);

        return $agent === '' || strtoupper($agent) === 'NONE' ? '—' : $agent;
    }

    public function queueName(TelephonyCall $call): string
    {
        return $call->resolved_queue_name
            ? $this->displayText($call->resolved_queue_name)
            : $this->displayText($call->queue_name);
    }

    public function campaignName(TelephonyCall $call): string
    {
        return $call->resolved_campaign_name ?: 'No Campaign';
    }

    /** @param array<string, mixed> $filters */
    private function filteredQuery(User $user, array $filters, bool $withCampaignResolution = false): Builder
    {
        $tenantId = $user->tenant_id ?: 2;
        $query = TelephonyCall::withoutGlobalScopes()
            ->where('telephony_calls.tenant_id', $tenantId);
        $campaignFilterActive = !empty($filters['campaign_id']);

        if ($withCampaignResolution || $campaignFilterActive) {
            $this->applyCampaignResolutionJoins($query);
        }

        $this->applyDateFilter($query, $filters);

        if (($filters['direction'] ?? 'ALL') !== 'ALL') {
            $query->where('telephony_calls.direction', strtoupper((string) $filters['direction']));
        }

        if (!empty($filters['result']) && $filters['result'] !== 'all') {
            $this->applyResultFilter($query, (string) $filters['result']);
        }

        if (!empty($filters['disposition'])) {
            $query->where('telephony_calls.disposition', (string) $filters['disposition']);
        }

        if ($campaignFilterActive) {
            $campaignId = (int) $filters['campaign_id'];
            $query->where(function (Builder $q) use ($campaignId): void {
                $q->where('queue_campaigns.id', $campaignId)
                    ->orWhere(function (Builder $fallback) use ($campaignId): void {
                        $fallback->where(function (Builder $queue): void {
                            $queue->whereNull('telephony_calls.queue_name')
                                ->orWhere('telephony_calls.queue_name', '')
                                ->orWhereNull('queue_campaigns.id');
                        })->where('fallback_campaigns.id', $campaignId);
                    });
            });
        }

        if (!empty($filters['queue'])) {
            $query->where('telephony_calls.queue_name', (string) $filters['queue']);
        }

        if (!empty($filters['agent_id'])) {
            $query->where('telephony_calls.user_id', (int) $filters['agent_id']);
        }

        if (!empty($filters['phone'])) {
            $this->applyDirectionAwarePhoneFilter($query, 'customer', (string) $filters['phone']);
        }

        if (!empty($filters['ani'])) {
            $this->applyDirectionAwarePhoneFilter($query, 'customer', (string) $filters['ani']);
        }

        if (!empty($filters['dnis'])) {
            $this->applyDirectionAwarePhoneFilter($query, 'campaign', (string) $filters['dnis']);
        }

        foreach (['linkedid', 'call_uuid', 'call_mode'] as $field) {
            if (!empty($filters[$field])) {
                $query->where('telephony_calls.' . $field, 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters[$field]) . '%');
            }
        }

        if (!empty($filters['conversation_id'])) {
            $query->where('telephony_calls.conversation_id', (int) $filters['conversation_id']);
        }

        if (($filters['answered'] ?? 'all') === 'answered') {
            $query->whereNotNull('telephony_calls.answered_at');
        } elseif (($filters['answered'] ?? 'all') === 'unanswered') {
            $query->whereNull('telephony_calls.answered_at');
        }

        if ($filters['duration_min'] !== null) {
            $query->where('telephony_calls.call_duration_seconds', '>=', (int) $filters['duration_min']);
        }

        if ($filters['duration_max'] !== null) {
            $query->where('telephony_calls.call_duration_seconds', '<=', (int) $filters['duration_max']);
        }

        return $query;
    }

    private function queryWithCampaignResolution(): Builder
    {
        return $this->applyCampaignResolutionJoins(TelephonyCall::withoutGlobalScopes());
    }

    private function applyCampaignResolutionJoins(Builder $query): Builder
    {
        return $query
            ->leftJoin('omni_queue_voice_settings as qvs_report', function (JoinClause $join): void {
                $join->on('qvs_report.tenant_id', '=', 'telephony_calls.tenant_id')
                    ->on('qvs_report.asterisk_queue_name', '=', 'telephony_calls.queue_name');
            })
            ->leftJoin('omni_queues as oq_report', function (JoinClause $join): void {
                $join->on('oq_report.id', '=', 'qvs_report.omni_queue_id')
                    ->on('oq_report.tenant_id', '=', 'telephony_calls.tenant_id');
            })
            ->leftJoin('campaigns as queue_campaigns', function (JoinClause $join): void {
                $join->on('queue_campaigns.id', '=', 'oq_report.campaign_id')
                    ->on('queue_campaigns.tenant_id', '=', 'telephony_calls.tenant_id');
            })
            ->leftJoin('conversations as report_conversations', function (JoinClause $join): void {
                $join->on('report_conversations.id', '=', 'telephony_calls.conversation_id')
                    ->on('report_conversations.tenant_id', '=', 'telephony_calls.tenant_id');
            })
            ->leftJoin('campaigns as fallback_campaigns', function (JoinClause $join): void {
                $join->on('fallback_campaigns.id', '=', 'report_conversations.campaign_id')
                    ->on('fallback_campaigns.tenant_id', '=', 'telephony_calls.tenant_id');
            });
    }

    /** @return array<int, string> */
    private function qualifiedCallColumns(): array
    {
        return array_map(
            static fn (string $column): string => 'telephony_calls.' . $column,
            self::SELECT_COLUMNS
        );
    }

    /** @return array<int, \Illuminate\Database\Query\Expression> */
    private function resolvedCampaignSelects(): array
    {
        return [
            DB::raw('oq_report.id as resolved_queue_id'),
            DB::raw('oq_report.name as resolved_queue_name'),
            DB::raw($this->resolvedCampaignIdExpression() . ' as resolved_campaign_id'),
            DB::raw($this->resolvedCampaignNameExpression() . ' as resolved_campaign_name'),
        ];
    }

    private function resolvedCampaignIdExpression(): string
    {
        return "COALESCE(queue_campaigns.id, CASE WHEN telephony_calls.queue_name IS NULL OR telephony_calls.queue_name = '' OR queue_campaigns.id IS NULL THEN fallback_campaigns.id ELSE NULL END)";
    }

    private function resolvedCampaignNameExpression(): string
    {
        return "COALESCE(queue_campaigns.name, CASE WHEN telephony_calls.queue_name IS NULL OR telephony_calls.queue_name = '' OR queue_campaigns.id IS NULL THEN fallback_campaigns.name ELSE NULL END)";
    }

    /** @param array<string, mixed> $filters */
    private function applyDateFilter(Builder $query, array $filters): void
    {
        $from = $this->parseBoundary($filters['from'] ?? null, false);
        $to = $this->parseBoundary($filters['to'] ?? null, true);

        $query->whereBetween('telephony_calls.started_at', [$from, $to]);
    }

    private function parseBoundary(mixed $value, bool $endOfDay): string
    {
        $raw = trim((string) ($value ?? now()->toDateString()));
        $hasTime = str_contains($raw, ':');
        $date = CarbonImmutable::parse($raw, $this->timezone());

        if (!$hasTime) {
            $date = $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function applyDirectionAwarePhoneFilter(Builder $query, string $semantic, string $value): void
    {
        $normalized = PhoneHelper::normalizeTelephonyReportingNumber($value);
        $needle = $normalized ?: preg_replace('/\D/', '', $value);
        if ($needle === '') {
            $needle = trim($value);
        }

        $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], $needle) . '%';

        $query->where(function (Builder $q) use ($semantic, $needle): void {
            if ($semantic === 'customer') {
                $q->where(function (Builder $inbound) use ($needle): void {
                    $inbound->whereRaw("UPPER(COALESCE(telephony_calls.direction, '')) = 'INBOUND'")
                        ->where('telephony_calls.caller_number', 'like', $needle);
                })->orWhere(function (Builder $outbound) use ($needle): void {
                    $outbound->whereRaw("UPPER(COALESCE(telephony_calls.direction, '')) = 'OUTBOUND'")
                        ->where('telephony_calls.did', 'like', $needle);
                });

                return;
            }

            $q->where(function (Builder $inbound) use ($needle): void {
                $inbound->whereRaw("UPPER(COALESCE(telephony_calls.direction, '')) = 'INBOUND'")
                    ->where('telephony_calls.did', 'like', $needle);
            })->orWhere(function (Builder $outbound) use ($needle): void {
                $outbound->whereRaw("UPPER(COALESCE(telephony_calls.direction, '')) = 'OUTBOUND'")
                    ->where('telephony_calls.caller_number', 'like', $needle);
            });
        });
    }

    private function hasAttributableAgentCondition(): \Closure
    {
        return function (Builder $q): void {
            $q->whereNotNull('telephony_calls.user_id')
                ->orWhere(function (Builder $agent): void {
                    $agent->whereNotNull('telephony_calls.agent')
                        ->where('telephony_calls.agent', '<>', '')
                        ->whereRaw("UPPER(telephony_calls.agent) <> 'NONE'");
                });
        };
    }

    private function applyResultFilter(Builder $query, string $result): void
    {
        match ($result) {
            'answered' => $query->whereNotNull('telephony_calls.answered_at'),
            'no_answer' => $query->whereNull('telephony_calls.answered_at')->where(function (Builder $q): void {
                $q->whereNull('telephony_calls.exit_reason')
                    ->orWhereIn('telephony_calls.exit_reason', ['NOANSWER', 'NO_ANSWER', 'EXITEMPTY', 'EXITWITHKEY']);
            }),
            'abandoned' => $query->where('telephony_calls.exit_reason', 'ABANDON'),
            'caller_hung_up' => $query->where('telephony_calls.exit_reason', 'COMPLETECALLER'),
            'agent_hung_up' => $query->where('telephony_calls.exit_reason', 'COMPLETEAGENT'),
            'timeout' => $query->where('telephony_calls.exit_reason', 'EXITWITHTIMEOUT'),
            'busy' => $query->whereNull('telephony_calls.answered_at')->where('telephony_calls.exit_reason', 'BUSY'),
            'cancel' => $query->whereNull('telephony_calls.answered_at')->whereIn('telephony_calls.exit_reason', ['CANCEL', 'CANCELLED']),
            'failed' => $query->whereNull('telephony_calls.answered_at')->whereIn('telephony_calls.exit_reason', ['FAILED', 'FAILURE', 'CHANUNAVAIL', 'UNAVAILABLE']),
            'congestion' => $query->whereNull('telephony_calls.answered_at')->where('telephony_calls.exit_reason', 'CONGESTION'),
            'unknown' => $query->whereNull('telephony_calls.answered_at')->where(function (Builder $q): void {
                $q->whereNull('telephony_calls.exit_reason')
                    ->orWhereNotIn('telephony_calls.exit_reason', ['NOANSWER', 'NO_ANSWER', 'ABANDON', 'EXITWITHTIMEOUT', 'EXITEMPTY', 'EXITWITHKEY', 'COMPLETEAGENT', 'COMPLETECALLER', 'TRANSFER', 'BUSY', 'CANCEL', 'CANCELLED', 'FAILED', 'FAILURE', 'CHANUNAVAIL', 'UNAVAILABLE', 'CONGESTION']);
            }),
            default => null,
        };
    }

    private function sanitizeCsvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }

    private function displayText(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? '—' : $value;
    }

    private function assertTenant(TelephonyCall $call, User $user): void
    {
        if ((int) $call->tenant_id !== (int) $user->tenant_id) {
            abort(404);
        }
    }
}
