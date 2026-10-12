<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DialerCampaign;
use App\Models\LeadList;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CampaignFilterService
{
    public const ELIGIBLE_STATUSES = ['NEW', 'NO_ANSWER', 'BUSY', 'FAILED', 'CONGESTION', 'REJECTED'];

    private const ACTIVE_OFFER_STATUSES = ['pending', 'accepted'];

    private const ALLOWED_OPERATORS = [
        'equals', 'not_equals',
        'contains', 'not_contains',
        'starts_with', 'ends_with',
        'in',
        'empty', 'not_empty',
        'greater_than', 'less_than',
    ];

    /**
     * Normalize and sanitize a raw filter config from the request or the database.
     * Always safe to call — invalid entries are silently dropped.
     */
    public function normalize(?array $raw): array
    {
        if (empty($raw) || !($raw['enabled'] ?? false)) {
            return ['enabled' => false];
        }

        // Intersect requested statuses with safe eligible set — filters can only NARROW
        $rawStatuses = is_array($raw['statuses'] ?? null) ? $raw['statuses'] : self::ELIGIBLE_STATUSES;
        $statuses    = array_values(array_intersect($rawStatuses, self::ELIGIBLE_STATUSES));

        $match = in_array($raw['match'] ?? 'all', ['all', 'any'], true)
            ? (string) ($raw['match'] ?? 'all')
            : 'all';

        $conditions = [];
        foreach (array_slice((array) ($raw['conditions'] ?? []), 0, 20) as $cond) {
            $field    = $this->sanitizeFieldName((string) ($cond['field']    ?? ''));
            $operator = in_array($cond['operator'] ?? '', self::ALLOWED_OPERATORS, true)
                ? (string) $cond['operator']
                : null;

            if ($field === '' || $operator === null) {
                continue;
            }

            $value = $cond['value'] ?? null;

            if (in_array($operator, ['empty', 'not_empty'], true)) {
                $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => null];
                continue;
            }

            if ($operator === 'in') {
                if (is_string($value)) {
                    $value = array_map('trim', explode(',', $value));
                }
                if (!is_array($value)) {
                    continue;
                }
                $value = array_values(array_filter(array_map('strval', $value), fn ($v) => $v !== ''));
                if (empty($value)) {
                    continue;
                }
                $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => (string) $value];
        }

        return [
            'enabled'    => true,
            'statuses'   => empty($statuses) ? self::ELIGIBLE_STATUSES : $statuses,
            'match'      => $match,
            'conditions' => $conditions,
        ];
    }

    /**
     * Apply a normalized filter config to an existing lead query builder.
     * Assumes the builder already restricts to ELIGIBLE_LEAD_STATUSES.
     * Returns the builder (modified in-place).
     */
    public function apply(Builder $query, DialerCampaign $campaign, array $config): Builder
    {
        if (!($config['enabled'] ?? false)) {
            return $query;
        }

        if (!empty($config['statuses'])) {
            $query->whereIn('status', $config['statuses']);
        }

        $conditions = $config['conditions'] ?? [];
        if (!empty($conditions)) {
            $this->applyConditions($query, $conditions, $config['match'] ?? 'all');
        }

        return $query;
    }

    /**
     * Dry-run a raw config against the campaign's lead list and return counts.
     * No mutations: no offers, no reservations, no status changes.
     */
    public function test(DialerCampaign $campaign, array $rawConfig): array
    {
        $config   = $this->normalize($rawConfig);
        $leadList = $campaign->leadList;

        if (!$leadList) {
            return $this->errorResult('No lead list assigned to this campaign.');
        }

        try {
            $total = (int) DB::table($leadList->table_name)->count();
        } catch (\Throwable) {
            return $this->errorResult('Lead list table not found.');
        }

        $baseQuery   = $this->baseCandidateQuery($campaign, $leadList);
        $eligibleNow = (int) (clone $baseQuery)->count();

        if (!($config['enabled'] ?? false)) {
            return [
                'ok'                   => true,
                'total_leads'          => $total,
                'eligible_now'         => $eligibleNow,
                'status_field_matches' => $total,
                'matching_filters'     => $eligibleNow,
                'status_breakdown'     => $this->statusBreakdown(clone $baseQuery),
            ];
        }

        $filtered = $this->apply(clone $baseQuery, $campaign, $config);
        $willDial = (int) (clone $filtered)->count();

        // Count leads matching selected statuses + field conditions on the raw table,
        // without core eligibility constraints (retries, reservations, active offers).
        // This answers "how many leads in my list fit the configured filter?"
        $rawFilterQuery = DB::table($leadList->table_name);
        $this->apply($rawFilterQuery, $campaign, $config);
        $statusFieldMatches = (int) $rawFilterQuery->count();

        return [
            'ok'                   => true,
            'total_leads'          => $total,
            'eligible_now'         => $eligibleNow,
            'status_field_matches' => $statusFieldMatches,
            'matching_filters'     => $willDial,
            'status_breakdown'     => $this->statusBreakdown(clone $filtered),
        ];
    }

    /**
     * Extract unique custom_field keys from a sample of leads in the list.
     */
    public function extractAvailableFields(LeadList $leadList): array
    {
        try {
            $samples = DB::table($leadList->table_name)
                ->whereNotNull('custom_fields')
                ->limit(100)
                ->pluck('custom_fields');
        } catch (\Throwable) {
            return [];
        }

        $keys = [];
        foreach ($samples as $json) {
            $decoded = is_string($json) ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                foreach (array_keys($decoded) as $key) {
                    $keys[$key] = true;
                }
            }
        }

        $result = array_keys($keys);
        sort($result);
        return $result;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function baseCandidateQuery(DialerCampaign $campaign, LeadList $leadList): Builder
    {
        return DB::table($leadList->table_name)
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->where('called_count', '<', (int) $campaign->max_retries)
            ->whereNull('reserved_by')
            ->where(function ($q): void {
                $q->whereNull('callback_at')->orWhere('callback_at', '<=', now());
            })
            ->whereNotExists(function ($q) use ($leadList): void {
                $q->selectRaw('1')
                    ->from('preview_dialer_offers')
                    ->whereColumn('preview_dialer_offers.lead_list_row_id', "{$leadList->table_name}.id")
                    ->where('preview_dialer_offers.lead_list_id', (int) $leadList->id)
                    ->where('preview_dialer_offers.active_slot', 1)
                    ->whereIn('preview_dialer_offers.status', self::ACTIVE_OFFER_STATUSES);
            });
    }

    private function applyConditions(Builder $query, array $conditions, string $match): void
    {
        if (empty($conditions)) {
            return;
        }

        $query->where(function (Builder $group) use ($conditions, $match): void {
            foreach ($conditions as $cond) {
                $this->applyOneCondition($group, $cond, $match);
            }
        });
    }

    private function applyOneCondition(Builder $group, array $cond, string $match): void
    {
        $field    = $cond['field'];
        $operator = $cond['operator'];
        $value    = $cond['value'] ?? null;
        $extract  = $this->jsonExtract('custom_fields', $field);
        $rawFn    = $match === 'any' ? 'orWhereRaw' : 'whereRaw';

        if ($operator === 'empty') {
            $group->{$rawFn}("({$extract} IS NULL OR {$extract} = '')");
            return;
        }

        if ($operator === 'not_empty') {
            $group->{$rawFn}("({$extract} IS NOT NULL AND {$extract} != '')");
            return;
        }

        if ($operator === 'in') {
            if (!is_array($value) || empty($value)) {
                return;
            }
            $placeholders = implode(',', array_fill(0, count($value), '?'));
            $group->{$rawFn}("{$extract} IN ({$placeholders})", array_values($value));
            return;
        }

        $sqlOp = match($operator) {
            'equals'       => '=',
            'not_equals'   => '!=',
            'greater_than' => '>',
            'less_than'    => '<',
            'contains'     => 'LIKE',
            'not_contains' => 'NOT LIKE',
            'starts_with'  => 'LIKE',
            'ends_with'    => 'LIKE',
            default        => null,
        };

        if ($sqlOp === null) {
            return;
        }

        $bindValue = match($operator) {
            'contains'     => '%' . $value . '%',
            'not_contains' => '%' . $value . '%',
            'starts_with'  => $value . '%',
            'ends_with'    => '%' . $value,
            default        => (string) $value,
        };

        $group->{$rawFn}("{$extract} {$sqlOp} ?", [$bindValue]);
    }

    private function jsonExtract(string $column, string $field): string
    {
        // Field is already sanitized by sanitizeFieldName — strip any residual quotes
        $safe = str_replace(["'", '\\', '"'], '', $field);

        return DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract({$column}, '$.{$safe}')"
            : "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$safe}'))";
    }

    private function sanitizeFieldName(string $field): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9 _\-]/', '', $field));
    }

    private function statusBreakdown(Builder $query): array
    {
        $rows = $query
            ->select([])
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $result = [];
        foreach ($rows as $status => $count) {
            $result[(string) $status] = (int) $count;
        }
        return $result;
    }

    private function errorResult(string $message): array
    {
        return [
            'ok'               => false,
            'error'            => $message,
            'total_leads'      => 0,
            'eligible_now'     => 0,
            'matching_filters' => 0,
            'status_breakdown' => [],
        ];
    }
}
