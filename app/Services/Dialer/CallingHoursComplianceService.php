<?php

declare(strict_types=1);

namespace App\Services\Dialer;

use App\Models\CallingStateRule;
use App\Models\DialerCampaign;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Federal + State Calling Hours Guard.
 *
 * Phase 1 (rule_mode=federal_baseline): enforces only the federal baseline
 * calling window (default 08:00-21:00) using the called party's local time,
 * derived from their NANP area code.
 *
 * Phase 2 (rule_mode=federal_plus_state): additionally narrows the window
 * using an imported, per-state calling-hour rule when one exists and is
 * active for the resolved state — a state rule can only make the window
 * stricter, never wider than the federal baseline.
 *
 * NOT full TCPA compliance: DNC, consent, holidays, and frequency caps are
 * out of scope. State-specific coverage is limited to whatever calling-hour
 * rules have been imported into calling_state_rules — nothing is hardcoded.
 */
class CallingHoursComplianceService
{
    private const RULE_TYPE = 'telemarketing_calling_hours';

    // Request-level caches: this service instance is shared across every
    // candidate evaluated within a single claim()/eligibilityFor() call.
    // - $configCache avoids re-decoding compliance_config's array cast (JSON)
    //   for the same campaign on every candidate in the scan loop.
    // - $stateRuleCache avoids re-querying calling_state_rules for the same
    //   state_code when many candidates share a state.
    // Both are cleared automatically when the request ends (not a singleton).
    private array $configCache = [];
    private array $stateRuleCache = [];

    public function __construct(private AreaCodeTimezoneResolver $resolver) {}

    /**
     * @return array{
     *     allowed: bool,
     *     reason: 'allowed'|'outside_calling_hours'|'unknown_timezone'|'unknown_state'|'disabled',
     *     area_code: ?string,
     *     timezone: ?string,
     *     local_time: ?string,
     *     start_time: ?string,
     *     end_time: ?string,
     *     rule_mode: ?string,
     *     state_code: ?string,
     *     state_rule_applied: bool,
     *     effective_start_time: ?string,
     *     effective_end_time: ?string,
     *     state_rule_source: ?string,
     *     local_day_of_week: ?string,
     *     state_rule_days: ?array,
     *     state_day_matched: bool,
     * }
     */
    public function evaluate(DialerCampaign $campaign, string $phone, ?CarbonInterface $now = null): array
    {
        $config = $this->callingHoursConfig($campaign);

        if (!($config['enabled'] ?? false)) {
            return $this->decision(true, 'disabled', null, null, null, null, null, $config['rule_mode'] ?? null, null, false, null, null, null);
        }

        $startTime          = $config['start_time'] ?? '08:00';
        $endTime             = $config['end_time'] ?? '21:00';
        $unknownTzPolicy     = $config['unknown_timezone_policy'] ?? 'skip';
        $campaignTimezone    = $config['campaign_timezone'] ?? null;
        $ruleMode            = $config['rule_mode'] ?? 'federal_baseline';
        $unknownStatePolicy  = $config['unknown_state_policy'] ?? 'federal_baseline';

        $areaCode = AreaCodeTimezoneResolver::parseAreaCode($phone);
        $row      = $areaCode ? $this->resolver->rowForAreaCode($areaCode) : null;
        $timezone = $row?->time_zone;
        $stateCode = $row?->state_code;

        if ($timezone === null) {
            return match ($unknownTzPolicy) {
                'allow' => $this->decision(true, 'unknown_timezone', $areaCode, null, null, $startTime, $endTime, $ruleMode, null, false, $startTime, $endTime, null),
                'use_campaign_timezone' => $campaignTimezone
                    ? $this->evaluateEffectiveWindow($areaCode, null, $campaignTimezone, $startTime, $endTime, $ruleMode, $unknownStatePolicy, $now)
                    : $this->decision(false, 'unknown_timezone', $areaCode, null, null, $startTime, $endTime, $ruleMode, null, false, $startTime, $endTime, null),
                default => $this->decision(false, 'unknown_timezone', $areaCode, null, null, $startTime, $endTime, $ruleMode, null, false, $startTime, $endTime, null),
            };
        }

        return $this->evaluateEffectiveWindow($areaCode, $stateCode, $timezone, $startTime, $endTime, $ruleMode, $unknownStatePolicy, $now);
    }

    /**
     * Whether the campaign's calling_hours guard is enabled at all — used by
     * PreviewDialerOfferService to skip the per-candidate check entirely when
     * the guard is off (the default for every existing campaign).
     */
    public function isEnabled(DialerCampaign $campaign): bool
    {
        return (bool) ($this->callingHoursConfig($campaign)['enabled'] ?? false);
    }

    private function callingHoursConfig(DialerCampaign $campaign): array
    {
        // Keyed by object identity, not campaign->id: testCompliance() builds an
        // in-memory, non-persisted probe DialerCampaign (id is null) to evaluate
        // an unsaved config — id-based keys would collide across distinct probes.
        $cacheKey = spl_object_id($campaign);

        if (array_key_exists($cacheKey, $this->configCache)) {
            return $this->configCache[$cacheKey];
        }

        $config = $campaign->compliance_config['calling_hours'] ?? null;

        return $this->configCache[$cacheKey] = is_array($config) ? $config : [];
    }

    private function stateRuleForStateCode(string $stateCode): ?CallingStateRule
    {
        $cacheKey = $stateCode . '|' . self::RULE_TYPE;

        if (array_key_exists($cacheKey, $this->stateRuleCache)) {
            return $this->stateRuleCache[$cacheKey];
        }

        return $this->stateRuleCache[$cacheKey] = CallingStateRule::query()
            ->where('state_code', $stateCode)
            ->where('rule_type', self::RULE_TYPE)
            ->where('is_active', true)
            ->first();
    }

    private function evaluateEffectiveWindow(
        ?string $areaCode,
        ?string $stateCode,
        string $timezone,
        string $federalStart,
        string $federalEnd,
        string $ruleMode,
        string $unknownStatePolicy,
        ?CarbonInterface $now
    ): array {
        $localNow       = ($now ?? Carbon::now())->copy()->setTimezone($timezone);
        $localTimeStr   = $localNow->format('H:i');
        $localDayOfWeek = strtolower($localNow->format('D'));

        if ($ruleMode !== 'federal_plus_state') {
            $allowed = $this->withinWindow($localTimeStr, $federalStart, $federalEnd);

            return $this->decision(
                $allowed,
                $allowed ? 'allowed' : 'outside_calling_hours',
                $areaCode, $timezone, $localTimeStr, $federalStart, $federalEnd,
                $ruleMode, $stateCode, false, $federalStart, $federalEnd, null,
                $localDayOfWeek
            );
        }

        $stateRule = $stateCode ? $this->stateRuleForStateCode($stateCode) : null;

        if ($stateRule) {
            $stateRuleDays = $stateRule->days_of_week;
            $dayMatched    = $this->stateRuleDayMatches($stateRuleDays, $localDayOfWeek);

            if ($dayMatched) {
                // A state rule can only narrow the federal window, never widen it.
                $effectiveStart = max($federalStart, $stateRule->start_time);
                $effectiveEnd   = min($federalEnd, $stateRule->end_time);
                $allowed        = $this->withinWindow($localTimeStr, $effectiveStart, $effectiveEnd);

                return $this->decision(
                    $allowed,
                    $allowed ? 'allowed' : 'outside_calling_hours',
                    $areaCode, $timezone, $localTimeStr, $federalStart, $federalEnd,
                    $ruleMode, $stateCode, true, $effectiveStart, $effectiveEnd, $stateRule->source,
                    $localDayOfWeek, $stateRuleDays, true
                );
            }

            // The state is known and the rule exists, but it does not restrict
            // today's local day — it simply does not apply for this evaluation.
            // This is NOT an unknown_state case: fall back straight to the
            // federal baseline window, bypassing unknown_state_policy entirely.
            $allowed = $this->withinWindow($localTimeStr, $federalStart, $federalEnd);

            return $this->decision(
                $allowed,
                $allowed ? 'allowed' : 'outside_calling_hours',
                $areaCode, $timezone, $localTimeStr, $federalStart, $federalEnd,
                $ruleMode, $stateCode, false, $federalStart, $federalEnd, null,
                $localDayOfWeek, $stateRuleDays, false
            );
        }

        if ($unknownStatePolicy === 'skip') {
            return $this->decision(
                false, 'unknown_state',
                $areaCode, $timezone, $localTimeStr, $federalStart, $federalEnd,
                $ruleMode, $stateCode, false, $federalStart, $federalEnd, null,
                $localDayOfWeek
            );
        }

        // unknown_state_policy = federal_baseline: no state rule found, fall back to federal window only.
        $allowed = $this->withinWindow($localTimeStr, $federalStart, $federalEnd);

        return $this->decision(
            $allowed,
            $allowed ? 'allowed' : 'outside_calling_hours',
            $areaCode, $timezone, $localTimeStr, $federalStart, $federalEnd,
            $ruleMode, $stateCode, false, $federalStart, $federalEnd, null,
            $localDayOfWeek
        );
    }

    /**
     * True when a state rule applies for the given local day: unrestricted
     * (null/empty days_of_week) always matches; otherwise the local day must
     * be present in the rule's configured days.
     */
    private function stateRuleDayMatches(?array $days, string $localDayOfWeek): bool
    {
        if ($days === null || $days === []) {
            return true;
        }

        $normalized = array_map(static fn ($day) => strtolower((string) $day), $days);

        return in_array($localDayOfWeek, $normalized, true);
    }

    private function withinWindow(string $localTime, string $start, string $end): bool
    {
        return $localTime >= $start && $localTime < $end;
    }

    private function decision(
        bool $allowed,
        string $reason,
        ?string $areaCode,
        ?string $timezone,
        ?string $localTime,
        ?string $startTime,
        ?string $endTime,
        ?string $ruleMode,
        ?string $stateCode,
        bool $stateRuleApplied,
        ?string $effectiveStartTime,
        ?string $effectiveEndTime,
        ?string $stateRuleSource,
        ?string $localDayOfWeek = null,
        ?array $stateRuleDays = null,
        bool $stateDayMatched = false
    ): array {
        return [
            'allowed'              => $allowed,
            'reason'               => $reason,
            'area_code'            => $areaCode,
            'timezone'             => $timezone,
            'local_time'           => $localTime,
            'start_time'           => $startTime,
            'end_time'             => $endTime,
            'rule_mode'            => $ruleMode,
            'state_code'           => $stateCode,
            'state_rule_applied'   => $stateRuleApplied,
            'effective_start_time' => $effectiveStartTime,
            'effective_end_time'   => $effectiveEndTime,
            'state_rule_source'    => $stateRuleSource,
            'local_day_of_week'    => $localDayOfWeek,
            'state_rule_days'      => $stateRuleDays,
            'state_day_matched'    => $stateDayMatched,
        ];
    }
}
