<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\Campaign;
use App\Models\OmniQueue;
use App\Models\TelephonyCall;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class VoiceDashboardService
{
    public function filterOptionsForUser(User $user): array
    {
        $tenantId = $this->tenantIdFor($user);

        $queues = OmniQueue::withoutGlobalScopes()
            ->with('campaign:id,name')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereHas('voiceSettings', fn ($query) => $query->where('is_enabled', true))
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'campaign_id']);

        return [
            'campaigns' => $queues
                ->filter(fn (OmniQueue $queue): bool => $queue->campaign !== null)
                ->map(fn (OmniQueue $queue): array => [
                    'id' => (int) $queue->campaign->id,
                    'name' => $queue->campaign->name,
                ])
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->all(),
            'queues' => $queues
                ->map(fn (OmniQueue $queue): array => [
                    'id' => (int) $queue->id,
                    'name' => $queue->name,
                    'campaign_id' => $queue->campaign_id === null ? null : (int) $queue->campaign_id,
                ])
                ->values()
                ->all(),
        ];
    }

    public function snapshotForUser(User $user, array $filters = []): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $tenantId = $this->tenantIdFor($user);
        $resolved = $this->resolveFilters($tenantId, $filters, $now, $timezone);
        $utcNow = $now->utc();

        if (($resolved['authorized'] ?? true) === false) {
            return $this->emptySnapshot($resolved, $timezone, $utcNow);
        }

        $summary = $this->summary($tenantId, $resolved);
        $applicability = $this->applicability($summary);

        return [
            'generated_at' => $utcNow->toIso8601String(),
            'generated_at_epoch' => $utcNow->getTimestamp(),
            'timezone' => $timezone,
            'filters' => $this->publicFilters($resolved),
            'applicability' => $applicability,
            'kpis' => $this->kpis($summary, $applicability),
            'series' => [
                'calls_by_time' => $this->callsByTime($tenantId, $resolved),
            ],
            'breakdowns' => [
                'direction' => $this->directionBreakdown($summary),
                'outcome' => $this->outcomeBreakdown($summary),
                'dispositions' => $this->dispositions($tenantId, $resolved),
            ],
            'top_queues' => $this->topQueues($tenantId, $resolved),
            'top_agents' => $this->topAgents($tenantId, $resolved),
            'recent_calls' => $this->recentCalls($tenantId, $resolved),
            'calls_in_progress' => $this->callsInProgress($tenantId, $resolved),
            'definitions' => $this->definitions(),
        ];
    }

    private function tenantIdFor(User $user): ?int
    {
        if ($user->isSuperAdmin()) {
            return $user->tenant_id === null ? 2 : (int) $user->tenant_id;
        }

        return (int) $user->tenant_id;
    }

    private function resolveFilters(?int $tenantId, array $filters, CarbonImmutable $now, string $timezone): array
    {
        $preset = (string) ($filters['preset'] ?? 'today');
        if (!in_array($preset, ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'custom'], true)) {
            $preset = 'today';
        }

        [$start, $end] = $this->dateRange($preset, $filters, $now, $timezone);
        $campaignId = $this->positiveInt($filters['campaign_id'] ?? null);
        $queueId = $this->positiveInt($filters['queue_id'] ?? null);

        if ($queueId !== null) {
            $queue = OmniQueue::withoutGlobalScopes()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('id', $queueId)
                ->whereHas('voiceSettings', fn ($query) => $query->where('is_enabled', true))
                ->first(['id', 'campaign_id']);

            if ($queue === null || ($campaignId !== null && (int) $queue->campaign_id !== $campaignId)) {
                return ['authorized' => false, 'preset' => $preset, 'campaign_id' => null, 'queue_id' => null, 'start' => $start, 'end' => $end];
            }
        }

        if ($campaignId !== null) {
            $exists = Campaign::withoutGlobalScopes()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('id', $campaignId)
                ->exists();

            if (!$exists) {
                return ['authorized' => false, 'preset' => $preset, 'campaign_id' => null, 'queue_id' => null, 'start' => $start, 'end' => $end];
            }
        }

        return [
            'authorized' => true,
            'preset' => $preset,
            'campaign_id' => $campaignId,
            'queue_id' => $queueId,
            'start' => $start,
            'end' => $end,
        ];
    }

    private function dateRange(string $preset, array $filters, CarbonImmutable $now, string $timezone): array
    {
        return match ($preset) {
            'yesterday' => [$now->subDay()->startOfDay(), $now->startOfDay()],
            'last_7_days' => [$now->subDays(6)->startOfDay(), $now->addDay()->startOfDay()],
            'last_30_days' => [$now->subDays(29)->startOfDay(), $now->addDay()->startOfDay()],
            'this_month' => [$now->startOfMonth(), $now->addDay()->startOfDay()],
            'custom' => $this->customDateRange($filters, $now, $timezone),
            default => [$now->startOfDay(), $now->addDay()->startOfDay()],
        };
    }

    private function customDateRange(array $filters, CarbonImmutable $now, string $timezone): array
    {
        try {
            $start = !empty($filters['date_from'])
                ? CarbonImmutable::parse((string) $filters['date_from'], $timezone)->startOfDay()
                : $now->startOfDay();
            $end = !empty($filters['date_to'])
                ? CarbonImmutable::parse((string) $filters['date_to'], $timezone)->addDay()->startOfDay()
                : $start->addDay();
        } catch (\Throwable) {
            return [$now->startOfDay(), $now->addDay()->startOfDay()];
        }

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addDay();
        }

        return [$start, $end];
    }

    private function baseQuery(?int $tenantId, array $filters): Builder
    {
        $voiceSettings = DB::table('omni_queue_voice_settings')
            ->select([
                'tenant_id',
                'asterisk_queue_name',
                DB::raw('MAX(omni_queue_id) as omni_queue_id'),
                DB::raw('MAX(service_level) as service_level'),
            ])
            ->where('is_enabled', true)
            ->groupBy('tenant_id', 'asterisk_queue_name');

        $query = TelephonyCall::withoutGlobalScopes()
            ->from('telephony_calls')
            ->leftJoinSub($voiceSettings, 'dashboard_qvs', function (JoinClause $join): void {
                $join->on('dashboard_qvs.tenant_id', '=', 'telephony_calls.tenant_id')
                    ->on('dashboard_qvs.asterisk_queue_name', '=', 'telephony_calls.queue_name');
            })
            ->leftJoin('omni_queues as dashboard_queues', function (JoinClause $join): void {
                $join->on('dashboard_queues.id', '=', 'dashboard_qvs.omni_queue_id')
                    ->on('dashboard_queues.tenant_id', '=', 'telephony_calls.tenant_id');
            })
            ->leftJoin('campaigns as dashboard_queue_campaigns', function (JoinClause $join): void {
                $join->on('dashboard_queue_campaigns.id', '=', 'dashboard_queues.campaign_id')
                    ->on('dashboard_queue_campaigns.tenant_id', '=', 'telephony_calls.tenant_id');
            })
            ->leftJoin('conversations as dashboard_conversations', function (JoinClause $join): void {
                $join->on('dashboard_conversations.id', '=', 'telephony_calls.conversation_id')
                    ->on('dashboard_conversations.tenant_id', '=', 'telephony_calls.tenant_id');
            })
            ->leftJoin('omni_queues as dashboard_fallback_queues', function (JoinClause $join): void {
                $join->on('dashboard_fallback_queues.id', '=', 'dashboard_conversations.omni_queue_id')
                    ->on('dashboard_fallback_queues.tenant_id', '=', 'telephony_calls.tenant_id');
            })
            ->leftJoin('campaigns as dashboard_fallback_campaigns', function (JoinClause $join): void {
                $join->on('dashboard_fallback_campaigns.id', '=', 'dashboard_conversations.campaign_id')
                    ->on('dashboard_fallback_campaigns.tenant_id', '=', 'telephony_calls.tenant_id');
            });

        if ($tenantId !== null) {
            $query->where('telephony_calls.tenant_id', $tenantId);
        }

        $query->whereNotNull('telephony_calls.started_at')
            ->where('telephony_calls.started_at', '>=', $this->dateForSql($filters['start']))
            ->where('telephony_calls.started_at', '<', $this->dateForSql($filters['end']));

        if ($filters['campaign_id'] !== null) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->where('dashboard_queue_campaigns.id', $filters['campaign_id'])
                    ->orWhere(function (Builder $fallback) use ($filters): void {
                        $fallback->whereNull('dashboard_queue_campaigns.id')
                            ->where('dashboard_fallback_campaigns.id', $filters['campaign_id']);
                    });
            });
        }

        if ($filters['queue_id'] !== null) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->where('dashboard_queues.id', $filters['queue_id'])
                    ->orWhere(function (Builder $fallback) use ($filters): void {
                        $fallback->whereNull('dashboard_queues.id')
                            ->where('dashboard_fallback_queues.id', $filters['queue_id']);
                    });
            });
        }

        return $query;
    }

    private function dashboardRows(?int $tenantId, array $filters, bool $includeAgent = false): Builder
    {
        $queueIdExpr = 'COALESCE(dashboard_queues.id, dashboard_fallback_queues.id)';
        $queueLabelExpr = "COALESCE(dashboard_queues.name, dashboard_fallback_queues.name, NULLIF(telephony_calls.queue_name, ''), 'No Queue')";
        $query = $this->baseQuery($tenantId, $filters)
            ->selectRaw('telephony_calls.id as call_id')
            ->selectRaw("{$queueIdExpr} as queue_id")
            ->selectRaw("{$queueLabelExpr} as queue_label")
            ->selectRaw("{$this->directionExpr()} as direction_normalized")
            ->selectRaw('CASE WHEN telephony_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END as answered_flag')
            ->selectRaw("CASE WHEN {$this->abandonedExpr()} THEN 1 ELSE 0 END as abandoned_flag")
            ->selectRaw('telephony_calls.wait_seconds')
            ->selectRaw('telephony_calls.talk_seconds')
            ->selectRaw('telephony_calls.wrapup_seconds')
            ->selectRaw('COALESCE(dashboard_qvs.service_level, 20) as service_level_seconds');

        if ($includeAgent) {
            $agentExpr = "COALESCE(users.name, NULLIF(telephony_calls.agent, ''), 'Unassigned')";
            $query->leftJoin('users', 'users.id', '=', 'telephony_calls.user_id')
                ->selectRaw('telephony_calls.user_id as agent_id')
                ->selectRaw("{$agentExpr} as agent_label");
        }

        return $query;
    }

    private function summary(?int $tenantId, array $filters): array
    {
        $serviceLevelExpr = 'COALESCE(dashboard_qvs.service_level, 20)';

        $row = $this->baseQuery($tenantId, $filters)
            ->selectRaw('COUNT(telephony_calls.id) as total_calls')
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'INBOUND' THEN 1 ELSE 0 END) as inbound_calls")
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'OUTBOUND' THEN 1 ELSE 0 END) as outbound_calls")
            ->selectRaw('SUM(CASE WHEN telephony_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered_calls')
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END) as inbound_answered")
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'OUTBOUND' AND telephony_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END) as outbound_answered")
            ->selectRaw("SUM(CASE WHEN {$this->abandonedExpr()} THEN 1 ELSE 0 END) as abandoned_calls")
            ->selectRaw("SUM(CASE WHEN {$this->queueTimeoutExpr()} THEN 1 ELSE 0 END) as queue_timeouts")
            ->selectRaw("SUM(CASE WHEN telephony_calls.ended_at IS NULL THEN 1 ELSE 0 END) as calls_in_progress")
            ->selectRaw("SUM(CASE WHEN telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NULL AND NOT ({$this->abandonedExpr()}) AND NOT ({$this->queueTimeoutExpr()}) THEN 1 ELSE 0 END) as unanswered_calls")
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.ended_at IS NOT NULL THEN 1 ELSE 0 END) as inbound_calls_eligible")
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'OUTBOUND' AND telephony_calls.ended_at IS NOT NULL THEN 1 ELSE 0 END) as outbound_calls_eligible")
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'OUTBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NULL THEN 1 ELSE 0 END) as outbound_unanswered")
            ->selectRaw("AVG(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN telephony_calls.wait_seconds END) as asa_seconds")
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL AND telephony_calls.wait_seconds IS NOT NULL AND telephony_calls.wait_seconds <= {$serviceLevelExpr} THEN 1 ELSE 0 END) as service_level_met")
            ->selectRaw("SUM(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.wait_seconds IS NOT NULL AND (telephony_calls.answered_at IS NOT NULL OR {$this->abandonedExpr()}) THEN 1 ELSE 0 END) as service_level_eligible")
            ->selectRaw('AVG(CASE WHEN telephony_calls.answered_at IS NOT NULL THEN telephony_calls.talk_seconds END) as avg_talk_seconds')
            ->selectRaw('AVG(CASE WHEN telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN telephony_calls.wrapup_seconds END) as avg_wrapup_seconds')
            ->selectRaw('AVG(CASE WHEN telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN COALESCE(telephony_calls.talk_seconds, 0) + COALESCE(telephony_calls.wrapup_seconds, 0) END) as aht_seconds')
            ->selectRaw("AVG(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN telephony_calls.talk_seconds END) as inbound_avg_talk_seconds")
            ->selectRaw("AVG(CASE WHEN {$this->directionExpr()} = 'OUTBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN telephony_calls.talk_seconds END) as outbound_avg_talk_seconds")
            ->selectRaw("AVG(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN telephony_calls.wrapup_seconds END) as inbound_avg_wrapup_seconds")
            ->selectRaw("AVG(CASE WHEN {$this->directionExpr()} = 'OUTBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN telephony_calls.wrapup_seconds END) as outbound_avg_wrapup_seconds")
            ->selectRaw("AVG(CASE WHEN {$this->directionExpr()} = 'INBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN COALESCE(telephony_calls.talk_seconds, 0) + COALESCE(telephony_calls.wrapup_seconds, 0) END) as inbound_aht_seconds")
            ->selectRaw("AVG(CASE WHEN {$this->directionExpr()} = 'OUTBOUND' AND telephony_calls.ended_at IS NOT NULL AND telephony_calls.answered_at IS NOT NULL THEN COALESCE(telephony_calls.talk_seconds, 0) + COALESCE(telephony_calls.wrapup_seconds, 0) END) as outbound_aht_seconds")
            ->first();

        return collect((array) $row?->getAttributes())
            ->map(fn ($value) => $value === null ? null : (float) $value)
            ->all();
    }

    private function kpis(array $summary, array $applicability): array
    {
        $total = (int) ($summary['total_calls'] ?? 0);
        $answered = (int) ($summary['answered_calls'] ?? 0);
        $abandoned = (int) ($summary['abandoned_calls'] ?? 0);
        $queueTimeouts = (int) ($summary['queue_timeouts'] ?? 0);
        $inbound = (int) ($summary['inbound_calls'] ?? 0);
        $outbound = (int) ($summary['outbound_calls'] ?? 0);
        $inboundAnswered = (int) ($summary['inbound_answered'] ?? 0);
        $outboundAnswered = (int) ($summary['outbound_answered'] ?? 0);
        $inboundEligible = (int) ($summary['inbound_calls_eligible'] ?? 0);
        $outboundEligible = (int) ($summary['outbound_calls_eligible'] ?? 0);
        $eligibleService = (int) ($summary['service_level_eligible'] ?? 0);

        return [
            'total_calls' => $this->numberKpi($total, 'calls'),
            'calls_in_progress' => $this->numberKpi((int) ($summary['calls_in_progress'] ?? 0), 'calls'),
            'total_answered' => $this->numberKpi($answered, 'calls'),
            'overall_aht' => $this->durationKpi($summary['aht_seconds'] ?? null, $applicability['has_handle_time']),
            'inbound_calls' => $this->numberKpi($inbound, 'calls', $applicability['has_inbound']),
            'inbound_answered' => $this->numberKpi($inboundAnswered, 'calls', $applicability['has_inbound']),
            'queue_timeouts' => $this->numberKpi($queueTimeouts, 'calls', $applicability['has_inbound']),
            'abandoned_calls' => $this->numberKpi($abandoned, 'calls', $applicability['has_inbound']),
            'inbound_answer_rate' => $this->percentKpi($inboundAnswered, $inboundEligible, $applicability['has_inbound'] && $inboundEligible > 0),
            'asa' => $this->durationKpi($summary['asa_seconds'] ?? null, $applicability['has_inbound'] && $inboundAnswered > 0),
            'service_level' => $this->percentKpi($summary['service_level_met'] ?? null, $eligibleService, $applicability['has_inbound'] && $eligibleService > 0),
            'abandon_rate' => $this->percentKpi($abandoned, $inboundEligible, $applicability['has_inbound'] && $inboundEligible > 0),
            'queue_timeout_rate' => $this->percentKpi($queueTimeouts, $inboundEligible, $applicability['has_inbound'] && $inboundEligible > 0),
            'inbound_aht' => $this->durationKpi($summary['inbound_aht_seconds'] ?? null, $applicability['has_inbound_handle_time']),
            'inbound_average_talk_time' => $this->durationKpi($summary['inbound_avg_talk_seconds'] ?? null, $applicability['has_inbound_talk_time']),
            'inbound_average_wrap_up' => $this->durationKpi($summary['inbound_avg_wrapup_seconds'] ?? null, $applicability['has_inbound_wrap_up']),
            'outbound_calls' => $this->numberKpi($outbound, 'calls', $applicability['has_outbound']),
            'outbound_answered' => $this->numberKpi($outboundAnswered, 'calls', $applicability['has_outbound']),
            'outbound_unanswered' => $this->numberKpi((int) ($summary['outbound_unanswered'] ?? 0), 'calls', $applicability['has_outbound']),
            'outbound_answer_rate' => $this->percentKpi($outboundAnswered, $outboundEligible, $applicability['has_outbound'] && $outboundEligible > 0),
            'outbound_contact_rate' => $this->naKpi('percent'),
            'outbound_aht' => $this->durationKpi($summary['outbound_aht_seconds'] ?? null, $applicability['has_outbound_handle_time']),
            'outbound_average_talk_time' => $this->durationKpi($summary['outbound_avg_talk_seconds'] ?? null, $applicability['has_outbound_talk_time']),
            'outbound_average_wrap_up' => $this->durationKpi($summary['outbound_avg_wrapup_seconds'] ?? null, $applicability['has_outbound_wrap_up']),
        ];
    }

    private function callsByTime(?int $tenantId, array $filters): array
    {
        $granularity = $filters['start']->diffInDays($filters['end']) > 1 ? 'day' : 'hour';
        $bucket = $this->timeBucketExpression($granularity);

        $callRows = $this->baseQuery($tenantId, $filters)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw("{$this->directionExpr()} as direction_normalized")
            ->selectRaw('CASE WHEN telephony_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END as answered_flag')
            ->selectRaw("CASE WHEN {$this->abandonedExpr()} THEN 1 ELSE 0 END as abandoned_flag");

        $rows = DB::query()
            ->fromSub($callRows->toBase(), 'dashboard_time_rows')
            ->selectRaw('bucket')
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'INBOUND' THEN 1 ELSE 0 END) as inbound")
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'OUTBOUND' THEN 1 ELSE 0 END) as outbound")
            ->selectRaw('SUM(answered_flag) as answered')
            ->selectRaw('SUM(abandoned_flag) as abandoned')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return [
            'granularity' => $granularity,
            'labels' => $rows->pluck('bucket')->map(fn ($value) => (string) $value)->all(),
            'inbound' => $rows->pluck('inbound')->map(fn ($value) => (int) $value)->all(),
            'outbound' => $rows->pluck('outbound')->map(fn ($value) => (int) $value)->all(),
            'answered' => $rows->pluck('answered')->map(fn ($value) => (int) $value)->all(),
            'abandoned' => $rows->pluck('abandoned')->map(fn ($value) => (int) $value)->all(),
        ];
    }

    private function topQueues(?int $tenantId, array $filters): array
    {
        $callRows = $this->dashboardRows($tenantId, $filters);

        return DB::query()
            ->fromSub($callRows->toBase(), 'dashboard_queue_rows')
            ->selectRaw('queue_id, queue_label as queue')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'INBOUND' THEN 1 ELSE 0 END) as inbound")
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'OUTBOUND' THEN 1 ELSE 0 END) as outbound")
            ->selectRaw('SUM(answered_flag) as answered')
            ->selectRaw('SUM(abandoned_flag) as abandoned')
            ->selectRaw("AVG(CASE WHEN direction_normalized = 'INBOUND' AND answered_flag = 1 THEN wait_seconds END) as asa_seconds")
            ->selectRaw('AVG(CASE WHEN answered_flag = 1 THEN COALESCE(talk_seconds, 0) + COALESCE(wrapup_seconds, 0) END) as aht_seconds')
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'INBOUND' AND answered_flag = 1 AND wait_seconds IS NOT NULL AND wait_seconds <= service_level_seconds THEN 1 ELSE 0 END) as service_level_met")
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'INBOUND' AND wait_seconds IS NOT NULL AND (answered_flag = 1 OR abandoned_flag = 1) THEN 1 ELSE 0 END) as service_level_eligible")
            ->groupBy('queue_id', 'queue_label')
            ->orderByDesc('total_calls')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'queue' => (string) $row->queue,
                'total_calls' => (int) $row->total_calls,
                'inbound' => (int) $row->inbound,
                'outbound' => (int) $row->outbound,
                'answered' => (int) $row->answered,
                'abandoned' => (int) $row->abandoned,
                'asa' => $this->formatDuration($row->asa_seconds),
                'aht' => $this->formatDuration($row->aht_seconds),
                'service_level' => $this->formatPercent($row->service_level_met, $row->service_level_eligible),
            ])
            ->all();
    }

    private function topAgents(?int $tenantId, array $filters): array
    {
        $callRows = $this->dashboardRows($tenantId, $filters, true);

        return DB::query()
            ->fromSub($callRows->toBase(), 'dashboard_agent_rows')
            ->selectRaw('agent_id, agent_label as agent')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw('SUM(answered_flag) as handled')
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'INBOUND' THEN 1 ELSE 0 END) as inbound")
            ->selectRaw("SUM(CASE WHEN direction_normalized = 'OUTBOUND' THEN 1 ELSE 0 END) as outbound")
            ->selectRaw('AVG(CASE WHEN answered_flag = 1 THEN talk_seconds END) as avg_talk_seconds')
            ->selectRaw('AVG(CASE WHEN answered_flag = 1 THEN COALESCE(talk_seconds, 0) + COALESCE(wrapup_seconds, 0) END) as aht_seconds')
            ->selectRaw('AVG(CASE WHEN wrapup_seconds IS NOT NULL THEN wrapup_seconds END) as avg_wrapup_seconds')
            ->whereNotNull('agent_id')
            ->where('agent_label', '<>', 'Unassigned')
            ->groupBy('agent_id', 'agent_label')
            ->orderByDesc('total_calls')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'agent' => (string) $row->agent,
                'total_calls' => (int) $row->total_calls,
                'handled' => (int) $row->handled,
                'inbound' => (int) $row->inbound,
                'outbound' => (int) $row->outbound,
                'average_talk_time' => $this->formatDuration($row->avg_talk_seconds),
                'aht' => $this->formatDuration($row->aht_seconds),
                'average_wrap_up' => $this->formatDuration($row->avg_wrapup_seconds),
            ])
            ->all();
    }

    private function dispositions(?int $tenantId, array $filters): array
    {
        $dispositionExpr = "COALESCE(NULLIF(TRIM(telephony_calls.disposition), ''), ?)";
        $total = (int) $this->baseQuery($tenantId, $filters)->count('telephony_calls.id');
        $callRows = $this->baseQuery($tenantId, $filters)
            ->selectRaw("{$dispositionExpr} as disposition_label", ['Without disposition']);

        return DB::query()
            ->fromSub($callRows->toBase(), 'dashboard_disposition_rows')
            ->selectRaw('disposition_label, COUNT(*) as total')
            ->groupBy('disposition_label')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) $row->disposition_label,
                'total' => (int) $row->total,
                'percentage' => $total > 0 ? round(((int) $row->total / $total) * 100, 1) : 0.0,
            ])
            ->all();
    }

    private function recentCalls(?int $tenantId, array $filters): array
    {
        return $this->baseQuery($tenantId, $filters)
            ->leftJoin('users', 'users.id', '=', 'telephony_calls.user_id')
            ->select([
                'telephony_calls.id',
                'telephony_calls.direction',
                'telephony_calls.did',
                'telephony_calls.caller_number',
                'telephony_calls.exit_reason',
                'telephony_calls.answered_at',
                'telephony_calls.ended_at',
                'telephony_calls.started_at',
                'telephony_calls.call_duration_seconds',
                'telephony_calls.disposition',
                DB::raw("COALESCE(users.name, NULLIF(telephony_calls.agent, ''), 'Unassigned') as agent"),
                DB::raw("COALESCE(dashboard_queue_campaigns.name, dashboard_fallback_campaigns.name, 'No Campaign') as campaign"),
                DB::raw("COALESCE(dashboard_queues.name, dashboard_fallback_queues.name, NULLIF(telephony_calls.queue_name, ''), 'No Queue') as queue"),
            ])
            ->orderByDesc('telephony_calls.started_at')
            ->orderByDesc('telephony_calls.id')
            ->limit(20)
            ->get()
            ->map(fn ($call): array => [
                'datetime' => $this->iso($call->started_at),
                'direction' => $this->label($call->direction),
                'phone' => $this->customerNumber($call),
                'agent' => (string) $call->agent,
                'campaign' => (string) $call->campaign,
                'queue' => (string) $call->queue,
                'status' => $this->callResult($call),
                'duration' => $this->formatDuration($call->call_duration_seconds),
                'disposition' => $call->disposition ?: 'Without disposition',
            ])
            ->all();
    }

    private function callsInProgress(?int $tenantId, array $filters): array
    {
        return $this->baseQuery($tenantId, $filters)
            ->leftJoin('users', 'users.id', '=', 'telephony_calls.user_id')
            ->whereNull('telephony_calls.ended_at')
            ->select([
                'telephony_calls.id',
                'telephony_calls.direction',
                'telephony_calls.did',
                'telephony_calls.caller_number',
                'telephony_calls.exit_reason',
                'telephony_calls.started_at',
                'telephony_calls.answered_at',
                DB::raw("COALESCE(users.name, NULLIF(telephony_calls.agent, ''), 'Unassigned') as agent"),
                DB::raw("COALESCE(dashboard_queues.name, dashboard_fallback_queues.name, NULLIF(telephony_calls.queue_name, ''), 'No Queue') as queue"),
            ])
            ->orderByDesc('telephony_calls.started_at')
            ->limit(20)
            ->get()
            ->map(fn ($call): array => [
                'direction' => $this->label($call->direction),
                'phone' => $this->customerNumber($call),
                'agent' => (string) $call->agent,
                'queue' => (string) $call->queue,
                'state' => 'In Progress',
                'started_at' => $this->iso($call->answered_at ?? $call->started_at),
                'started_at_epoch' => $this->epoch($call->answered_at ?? $call->started_at),
            ])
            ->all();
    }

    private function applicability(array $summary): array
    {
        return [
            'has_inbound' => (int) ($summary['inbound_calls'] ?? 0) > 0,
            'has_outbound' => (int) ($summary['outbound_calls'] ?? 0) > 0,
            'has_dispositions' => true,
            'has_talk_time' => ($summary['avg_talk_seconds'] ?? null) !== null,
            'has_wrap_up' => ($summary['avg_wrapup_seconds'] ?? null) !== null,
            'has_handle_time' => ($summary['aht_seconds'] ?? null) !== null,
            'has_inbound_talk_time' => ($summary['inbound_avg_talk_seconds'] ?? null) !== null,
            'has_outbound_talk_time' => ($summary['outbound_avg_talk_seconds'] ?? null) !== null,
            'has_inbound_wrap_up' => ($summary['inbound_avg_wrapup_seconds'] ?? null) !== null,
            'has_outbound_wrap_up' => ($summary['outbound_avg_wrapup_seconds'] ?? null) !== null,
            'has_inbound_handle_time' => ($summary['inbound_aht_seconds'] ?? null) !== null,
            'has_outbound_handle_time' => ($summary['outbound_aht_seconds'] ?? null) !== null,
            'can_calculate_occupancy' => false,
            'can_calculate_hold_time' => false,
        ];
    }

    private function directionBreakdown(array $summary): array
    {
        return [
            ['label' => 'Inbound', 'value' => (int) ($summary['inbound_calls'] ?? 0)],
            ['label' => 'Outbound', 'value' => (int) ($summary['outbound_calls'] ?? 0)],
        ];
    }

    private function outcomeBreakdown(array $summary): array
    {
        return [
            ['label' => 'Answered', 'value' => (int) ($summary['answered_calls'] ?? 0)],
            ['label' => 'Unanswered', 'value' => (int) ($summary['unanswered_calls'] ?? 0)],
            ['label' => 'Abandoned', 'value' => (int) ($summary['abandoned_calls'] ?? 0)],
            ['label' => 'Queue Timeout', 'value' => (int) ($summary['queue_timeouts'] ?? 0)],
        ];
    }

    private function emptySnapshot(array $filters, string $timezone, CarbonImmutable $now): array
    {
        $summary = [
            'total_calls' => 0,
            'inbound_calls' => 0,
            'outbound_calls' => 0,
            'answered_calls' => 0,
            'unanswered_calls' => 0,
            'inbound_answered' => 0,
            'outbound_answered' => 0,
            'queue_timeouts' => 0,
            'outbound_unanswered' => 0,
            'inbound_calls_eligible' => 0,
            'outbound_calls_eligible' => 0,
            'abandoned_calls' => 0,
            'calls_in_progress' => 0,
        ];
        $applicability = $this->applicability($summary);

        return [
            'generated_at' => $now->toIso8601String(),
            'generated_at_epoch' => $now->getTimestamp(),
            'timezone' => $timezone,
            'filters' => $this->publicFilters($filters),
            'applicability' => $applicability,
            'kpis' => $this->kpis($summary, $applicability),
            'series' => ['calls_by_time' => ['granularity' => 'hour', 'labels' => [], 'inbound' => [], 'outbound' => [], 'answered' => [], 'abandoned' => []]],
            'breakdowns' => ['direction' => [], 'outcome' => [], 'dispositions' => []],
            'top_queues' => [],
            'top_agents' => [],
            'recent_calls' => [],
            'calls_in_progress' => [],
            'definitions' => $this->definitions(),
        ];
    }

    private function publicFilters(array $filters): array
    {
        return [
            'campaign_id' => $filters['campaign_id'] ?? null,
            'queue_id' => $filters['queue_id'] ?? null,
            'preset' => $filters['preset'] ?? 'today',
            'date_from' => isset($filters['start']) ? $filters['start']->toDateString() : null,
            'date_to' => isset($filters['end']) ? $filters['end']->subDay()->toDateString() : null,
        ];
    }

    private function numberKpi(int $value, string $unit, bool $applicable = true): array
    {
        return [
            'value' => $applicable ? $value : null,
            'formatted' => $applicable ? number_format($value) : 'N/A',
            'unit' => $unit,
            'applicable' => $applicable,
        ];
    }

    private function durationKpi(mixed $seconds, bool $applicable = true): array
    {
        return [
            'value' => $applicable && $seconds !== null ? (int) round((float) $seconds) : null,
            'formatted' => $applicable && $seconds !== null ? $this->formatDuration($seconds) : 'N/A',
            'unit' => 'time',
            'applicable' => $applicable && $seconds !== null,
        ];
    }

    private function percentKpi(mixed $numerator, int $denominator, bool $applicable = true): array
    {
        $value = $applicable && $denominator > 0 ? round(((float) $numerator / $denominator) * 100, 1) : null;

        return [
            'value' => $value,
            'formatted' => $value === null ? 'N/A' : number_format($value, 1) . '%',
            'unit' => 'percent',
            'applicable' => $value !== null,
        ];
    }

    private function naKpi(string $unit): array
    {
        return ['value' => null, 'formatted' => 'N/A', 'unit' => $unit, 'applicable' => false];
    }

    private function formatPercent(mixed $numerator, mixed $denominator): string
    {
        $denominator = (int) $denominator;

        return $denominator > 0 ? number_format(((float) $numerator / $denominator) * 100, 1) . '%' : 'N/A';
    }

    private function formatDuration(mixed $seconds): string
    {
        if ($seconds === null || $seconds === '') {
            return 'N/A';
        }

        $seconds = max(0, (int) round((float) $seconds));

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function directionExpr(): string
    {
        return "UPPER(COALESCE(telephony_calls.direction, ''))";
    }

    private function abandonedExpr(): string
    {
        return "{$this->directionExpr()} = 'INBOUND' AND UPPER(COALESCE(telephony_calls.exit_reason, '')) = 'ABANDON'";
    }

    private function timeBucketExpression(string $granularity): string
    {
        $driver = DB::connection()->getDriverName();
        $start = 'telephony_calls.started_at';

        if ($driver === 'sqlite') {
            return $granularity === 'day'
                ? "strftime('%Y-%m-%d', {$start})"
                : "strftime('%Y-%m-%d %H:00', {$start})";
        }

        return $granularity === 'day'
            ? "DATE_FORMAT({$start}, '%Y-%m-%d')"
            : "DATE_FORMAT({$start}, '%Y-%m-%d %H:00')";
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : (int) $int;
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }

    private function epoch(mixed $value): ?int
    {
        return $value === null ? null : CarbonImmutable::parse($value)->getTimestamp();
    }

    private function dateForSql(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }

    private function label(?string $value): string
    {
        return $value === null || $value === '' ? 'N/A' : ucwords(strtolower(str_replace(['_', '-'], ' ', $value)));
    }

    private function customerNumber(mixed $call): string
    {
        $direction = strtoupper((string) ($call->direction ?? ''));
        $value = $direction === 'OUTBOUND' ? $call->did : $call->caller_number;

        return $value ?: 'N/A';
    }

    private function callResult(mixed $call): string
    {
        if ($call->answered_at !== null) {
            return 'Answered';
        }

        if ($this->isAbandonedCall($call)) {
            return 'Abandoned';
        }

        if ($this->isQueueTimeoutCall($call)) {
            return 'Queue Timeout';
        }

        if ($call->ended_at === null) {
            return 'In Progress';
        }

        return 'Unanswered';
    }

    private function isAbandonedCall(mixed $call): bool
    {
        return strtoupper((string) ($call->direction ?? '')) === 'INBOUND'
            && strtoupper((string) ($call->exit_reason ?? '')) === 'ABANDON';
    }

    private function isQueueTimeoutCall(mixed $call): bool
    {
        return strtoupper((string) ($call->direction ?? '')) === 'INBOUND'
            && $call->answered_at === null
            && $call->ended_at !== null
            && strtoupper((string) ($call->exit_reason ?? '')) === 'EXITWITHTIMEOUT';
    }

    private function queueTimeoutExpr(): string
    {
        return "{$this->directionExpr()} = 'INBOUND' AND telephony_calls.answered_at IS NULL AND telephony_calls.ended_at IS NOT NULL AND UPPER(COALESCE(telephony_calls.exit_reason, '')) = 'EXITWITHTIMEOUT'";
    }

    private function definitions(): array
    {
        return [
            'asa' => 'Average Speed of Answer for answered inbound calls using telephony_calls.wait_seconds.',
            'overall_aht' => 'Overall Average Handle Time using telephony_calls talk time plus wrap-up time for answered terminal calls. Hold time is not present in telephony_calls.',
            'inbound_aht' => 'Inbound Average Handle Time using talk_seconds plus wrapup_seconds for answered terminal inbound calls.',
            'outbound_aht' => 'Outbound Average Handle Time using talk_seconds plus wrapup_seconds for answered terminal outbound calls.',
            'service_level' => 'Inbound answered calls within telephony_calls.wait_seconds and the queue service-level threshold, defaulting to 20 seconds when no queue threshold is present.',
            'abandoned_calls' => 'Inbound telephony_calls rows whose exit_reason is ABANDON.',
            'queue_timeouts' => 'Inbound calls that reached the queue timeout before an agent answered.',
            'inbound_answer_rate' => 'Inbound answered calls divided by terminal inbound calls in the selected range.',
            'outbound_answer_rate' => 'Outbound answered calls divided by terminal outbound calls in the selected range.',
            'abandon_rate' => 'Abandoned inbound telephony_calls divided by terminal inbound telephony_calls in the selected range.',
            'queue_timeout_rate' => 'Percentage of eligible inbound calls that ended because the queue timeout was reached.',
            'outbound_contact_rate' => 'N/A until telephony_calls has a reliable contact taxonomy separate from answered_at.',
        ];
    }
}
