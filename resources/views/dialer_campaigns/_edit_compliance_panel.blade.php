{{--
    Compliance panel for Preview Dialer campaigns — Federal Calling Hours Guard.
    Variables: $dc (DialerCampaign), $complianceConfig (array), $isRunning (bool)

    IMPORTANT: this is a calling-hours guard only — NOT full TCPA compliance.
    DNC, consent, holidays, and frequency caps are handled separately.
--}}
@php
    $cc               = $complianceConfig ?? [];
    $isEnabled        = !empty($cc['enabled']);
    $startTime        = $cc['start_time'] ?? '08:00';
    $endTime          = $cc['end_time'] ?? '21:00';
    $unknownPolicy    = $cc['unknown_timezone_policy'] ?? 'skip';
    // Legacy configs may have use_campaign_timezone saved — no longer exposed
    // as a normal option. Visually maps to "skip" (its default) until the
    // admin saves the form again, at which point it becomes explicit.
    $isLegacyUnknownPolicy = $unknownPolicy === 'use_campaign_timezone';
    $unknownPolicyDisplay  = $isLegacyUnknownPolicy ? 'skip' : $unknownPolicy;

    $ruleMode           = $cc['rule_mode'] ?? 'federal_baseline';
    $unknownStatePolicy = $cc['unknown_state_policy'] ?? 'federal_baseline';

    // 15-minute increments, 00:00–23:45, plus 23:59 as an explicit end-of-day option.
    $timeOptions = [];
    for ($h = 0; $h < 24; $h++) {
        foreach ([0, 15, 30, 45] as $m) {
            $timeOptions[] = sprintf('%02d:%02d', $h, $m);
        }
    }
    $timeOptions[] = '23:59';
@endphp

@push('styles')
<style>
/* ── Compliance tab — scoped additions, reuses .filters-* toggle/panel look ── */
.compliance-enable-row {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 14px 16px; background: var(--primary-light);
    border: 1px solid rgba(79, 70, 229, .20); border-radius: var(--radius-sm);
    margin-bottom: 18px;
}
.compliance-window-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px; max-width: 420px;
}
.compliance-test-result {
    margin-top: 12px; padding: 12px 14px; border-radius: var(--radius-sm);
    border: 1px dashed var(--border); background: var(--bg-stripe); font-size: 12px;
}
.compliance-test-row { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; }
</style>
@endpush

@if($isRunning)
<div class="tip-box" style="background:#fffbeb;border-color:#fde68a;color:#92400e;margin-bottom:20px;">
    <i class="bi bi-play-circle-fill tip-icon" style="color:#d97706;"></i>
    <div>
        <strong>Campaign is currently running.</strong>
        Pause this campaign to edit compliance settings.
    </div>
</div>
@endif

<div class="tip-box" style="margin-bottom:20px;">
    <i class="bi bi-info-circle tip-icon"></i>
    <div>
        Federal Calling Hours Guard uses the recipient's local time to determine whether a lead can be offered during the configured calling window.
    </div>
</div>

<form method="POST" action="{{ route('dialer-campaigns.compliance', $dc) }}" id="complianceForm">
    @csrf @method('PUT')

    <div class="compliance-enable-row">
        <div>
            <div style="font-size:14px;font-weight:700;color:var(--text-strong);">Enable Federal Calling Hours Guard</div>
            <div class="form-hint" style="margin-top:3px;">When off, the dialer offers leads with no time-of-day restriction (current behavior).</div>
        </div>
        <label class="filters-toggle-wrap" title="{{ $isRunning ? 'Pause the campaign to change this' : 'Toggle guard' }}">
            <input type="checkbox" id="complianceEnabled" name="enabled" value="1"
                   {{ $isEnabled ? 'checked' : '' }}
                   {{ $isRunning ? 'disabled' : '' }}>
            <span class="filters-toggle-track"></span>
        </label>
    </div>

    <div id="complianceBody" style="{{ $isEnabled ? '' : 'display:none;' }}">

        <div class="form-group">
            <label class="form-label">Calling window (recipient's local time, 24-hour)</label>
            <div class="compliance-window-grid">
                <div>
                    <label class="form-hint" for="complianceStart">Start time</label>
                    <select id="complianceStart" name="start_time" class="form-control" {{ $isRunning ? 'disabled' : '' }}>
                        @foreach($timeOptions as $t)
                        <option value="{{ $t }}" {{ $t === $startTime ? 'selected' : '' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-hint" for="complianceEnd">End time</label>
                    <select id="complianceEnd" name="end_time" class="form-control" {{ $isRunning ? 'disabled' : '' }}>
                        @foreach($timeOptions as $t)
                        <option value="{{ $t }}" {{ $t === $endTime ? 'selected' : '' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-hint" style="margin-top:6px;">Federal baseline default: 08:00–21:00. Values are always 24-hour HH:mm, regardless of browser locale.</div>
        </div>

        <div class="form-group" style="margin-top:20px;">
            <label class="form-label">Timezone strategy</label>
            <input type="text" class="form-control" value="Phone area code (US NANP)" disabled style="max-width:320px;">
            <div class="form-hint" style="margin-top:6px;">Resolves the recipient's timezone from their phone's area code only.</div>
        </div>

        <div class="form-group" style="margin-top:20px;">
            <label class="form-label" for="complianceUnknownPolicy">Unknown timezone policy</label>
            <select id="complianceUnknownPolicy" name="unknown_timezone_policy" class="form-control" style="max-width:320px;" {{ $isRunning ? 'disabled' : '' }}>
                <option value="skip" {{ $unknownPolicyDisplay === 'skip' ? 'selected' : '' }}>Skip — do not offer the lead</option>
                <option value="allow" {{ $unknownPolicyDisplay === 'allow' ? 'selected' : '' }}>Allow — offer the lead anyway</option>
            </select>
            <div class="form-hint" style="margin-top:6px;" id="unknownPolicyHint">
                @if($unknownPolicyDisplay === 'allow')
                If the area code cannot be matched to a known timezone, the lead may still be offered. Use only when your campaign has confirmed this is acceptable.
                @else
                If the area code cannot be matched to a known timezone, the lead will not be offered.
                @endif
            </div>
            @if($isLegacyUnknownPolicy)
            <div class="form-hint" style="margin-top:6px;color:#92400e;">
                <i class="bi bi-info-circle"></i>
                Legacy note: this campaign previously used an internal timezone-based policy, shown here as "Skip". Saving this form will switch it to whichever option is selected above.
            </div>
            @endif
        </div>

        <div class="form-group" style="margin-top:20px;">
            <label class="form-label" for="complianceRuleMode">Rule mode</label>
            <select id="complianceRuleMode" name="rule_mode" class="form-control" style="max-width:360px;" {{ $isRunning ? 'disabled' : '' }}>
                <option value="federal_baseline" {{ $ruleMode === 'federal_baseline' ? 'selected' : '' }}>Federal baseline only</option>
                <option value="federal_plus_state" {{ $ruleMode === 'federal_plus_state' ? 'selected' : '' }}>Federal + state-specific stricter rules</option>
            </select>
        </div>

        <div class="form-group" id="unknownStatePolicyGroup" style="margin-top:20px;{{ $ruleMode === 'federal_plus_state' ? '' : 'display:none;' }}">
            <label class="form-label" for="complianceUnknownStatePolicy">Unknown state policy</label>
            <select id="complianceUnknownStatePolicy" name="unknown_state_policy" class="form-control" style="max-width:360px;" {{ $isRunning ? 'disabled' : '' }}>
                <option value="federal_baseline" {{ $unknownStatePolicy === 'federal_baseline' ? 'selected' : '' }}>Use federal baseline</option>
                <option value="skip" {{ $unknownStatePolicy === 'skip' ? 'selected' : '' }}>Skip lead</option>
            </select>
            <div class="form-hint" style="margin-top:6px;">
                State-specific mode applies globally configured state calling-hour rules when available.
                If no state rule exists, the selected unknown-state policy is used.
            </div>
            <div class="form-hint" style="margin-top:6px;">
                Additional compliance controls such as DNC, consent, holidays, and frequency caps are managed separately.
            </div>
        </div>

    </div>{{-- #complianceBody --}}
</form>

{{-- Compliance test box --}}
<div class="filters-sidebar-panel" style="margin-top:24px;max-width:420px;">
    <div class="filters-sidebar-head">
        <div class="filters-sidebar-title"><i class="bi bi-search icon-primary"></i> Test a phone number</div>
        <div class="filters-sidebar-desc">Checks a number against the settings currently on this form (unsaved changes included).</div>
    </div>
    <div class="filters-sidebar-body">
        <input type="text" id="complianceTestPhone" class="form-control" placeholder="e.g. 6021234567" style="margin-bottom:10px;">
        <button type="button" id="complianceTestBtn" class="btn btn-primary btn-sm">
            <i class="bi bi-search"></i> Test
        </button>
        <div class="compliance-test-result" id="complianceTestResult" style="display:none;"></div>
    </div>
</div>

@push('scripts')
<script>
window.DialerCampaignComplianceConfig = {
    csrf:       '{{ csrf_token() }}',
    isRunning:  @json($isRunning),
    routes: {
        test: '{{ route('dialer-campaigns.compliance.test', $dc) }}',
    },
};
</script>
<script src="{{ asset('js/dialer-campaign-compliance.js') }}"></script>
@endpush
