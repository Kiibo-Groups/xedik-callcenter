{{--
    Filter panel partial for Preview Dialer campaigns.
    Variables: $dc (DialerCampaign), $filterConfig (array), $availableFields (array), $isRunning (bool)
--}}
@php
    $fc              = $filterConfig ?? [];
    $isEnabled       = !empty($fc['enabled']);
    $selStatuses     = $fc['statuses'] ?? ['NEW', 'NO_ANSWER', 'BUSY', 'FAILED', 'CONGESTION', 'REJECTED'];
    $selMatch        = $fc['match'] ?? 'all';
    $savedConditions = $fc['conditions'] ?? [];

    $eligibleStatuses = [
        'NEW'        => 'Not dialed',
        'NO_ANSWER'  => 'Retryable',
        'BUSY'       => 'Retryable',
        'FAILED'     => 'Optional',
        'CONGESTION' => 'Optional',
        'REJECTED'   => 'Optional',
    ];
@endphp

@push('styles')
<style>
/* ── Filters tab — scoped additions ─────────────────────────────────────────
   All new classes prefixed "filters-". Reuses existing CRM variables and
   global classes (form-label, form-hint, form-group, tip-box, btn-*, badge).
   ────────────────────────────────────────────────────────────────────────── */

/* Two-column layout */
.filters-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) 300px;
    gap: 20px;
    align-items: start;
}
.filters-sidebar { display: flex; flex-direction: column; gap: 14px; }

/* Enable-toggle row */
.filters-enable-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 16px;
    background: var(--primary-light);
    border: 1px solid rgba(79, 70, 229, .20);
    border-radius: var(--radius-sm);
    margin-bottom: 18px;
}
.filters-toggle-wrap { position: relative; width: 42px; height: 24px; flex-shrink: 0; cursor: pointer; }
.filters-toggle-wrap input { position: absolute; opacity: 0; width: 0; height: 0; }
.filters-toggle-track {
    position: absolute; inset: 0;
    background: var(--border);
    border-radius: 12px;
    transition: background .2s;
}
.filters-toggle-track::after {
    content: '';
    position: absolute;
    width: 18px; height: 18px;
    top: 3px; left: 3px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.15);
    transition: transform .2s;
}
.filters-toggle-wrap input:checked ~ .filters-toggle-track { background: var(--primary); }
.filters-toggle-wrap input:checked ~ .filters-toggle-track::after { transform: translateX(18px); }
.filters-toggle-wrap input:disabled ~ .filters-toggle-track { opacity: .5; cursor: default; }

/* Status pills — mirrors caller-id-card style from Caller ID tab */
.filters-status-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-top: 8px;
}
.filters-status-pill {
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 10px 12px;
    display: flex;
    align-items: center;
    gap: 9px;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    background: var(--bg-card);
}
.filters-status-pill:hover { border-color: var(--primary); }
.filters-status-pill:has(input:checked) { border-color: var(--primary); background: var(--primary-light); }
.filters-status-pill input[type="checkbox"] { width: 15px; height: 15px; accent-color: var(--primary); flex-shrink: 0; cursor: pointer; }
.filters-status-pill:has(input:disabled) { opacity: .55; cursor: default; pointer-events: none; }
.filters-pill-name { font-size: 12px; font-weight: 600; color: var(--text-strong); display: block; }
.filters-pill-hint { font-size: 11px; color: var(--text-muted); display: block; }

/* Builder toolbar (match toggle + Add button) */
.filters-builder-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--bg-stripe);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
}
.filters-match-control {
    display: inline-flex;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.filters-match-btn {
    padding: 5px 13px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--bg-card);
    border: none;
    cursor: pointer;
    transition: all .15s;
    line-height: 1.4;
}
.filters-match-btn + .filters-match-btn { border-left: 1px solid var(--border); }
.filters-match-btn.active { background: var(--primary); color: #fff; }
.filters-match-btn:hover:not(.active):not(:disabled) { background: #e8eaf6; color: var(--text-strong); }
.filters-match-btn:disabled { opacity: .5; cursor: default; }

/* Rules list — sits directly below the toolbar */
.filters-rules-list {
    display: flex;
    flex-direction: column;
    gap: 0;
    border: 1px solid var(--border);
    border-top: 0;
    border-radius: 0 0 var(--radius-sm) var(--radius-sm);
    overflow: hidden;
}
.filters-rule-empty {
    padding: 16px;
    text-align: center;
    font-size: 12px;
    color: var(--text-muted);
    background: var(--bg-card);
}

/* Rule row — grid: prefix | field | operator | value | remove */
.filters-rule-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1.3fr 32px;
    gap: 8px;
    align-items: center;
    padding: 10px 12px;
    background: var(--bg-card);
    border-top: 1px solid var(--border);
}
.filters-rule-row:first-child { border-top: 0; }
.filters-rule-prefix {
    grid-column: 1 / -1;
    display: none;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-muted);
    margin-bottom: 2px;
}
.filters-rule-row .cond-field,
.filters-rule-row .cond-operator,
.filters-rule-row .cond-value {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 6px 9px;
    font-size: 12px;
    background: var(--bg-card);
    color: var(--text-strong);
    outline: none;
    transition: border-color .15s;
    min-height: 34px;
}
.filters-rule-row .cond-field:focus,
.filters-rule-row .cond-operator:focus,
.filters-rule-row .cond-value:focus { border-color: var(--primary); }
.filters-rule-row .cond-value-wrap { min-width: 0; }
.filters-rule-row .remove-condition-btn {
    width: 32px; height: 32px;
    border-radius: var(--radius-sm);
    border: 1px solid #fecaca;
    background: #fff7f7;
    color: var(--danger);
    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, border-color .15s;
    flex-shrink: 0;
}
.filters-rule-row .remove-condition-btn:hover { background: #fee2e2; border-color: #fca5a5; }

/* Human-readable preview — reuses .preview-box concept */
.filters-preview-box {
    margin-top: 12px;
    padding: 12px 14px;
    background: var(--bg-stripe);
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
}
.filters-preview-query {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
    line-height: 1.65;
    color: var(--text-strong);
    white-space: pre-wrap;
    margin: 0;
}

/* Sidebar panels */
.filters-sidebar-panel {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.filters-sidebar-head {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}
.filters-sidebar-title { font-size: 13px; font-weight: 700; color: var(--text-strong); }
.filters-sidebar-desc  { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.filters-sidebar-body  { padding: 14px 16px; }

/* Metric grid inside sidebar */
.filters-metric-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.filters-metric {
    background: var(--bg-stripe);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 10px 8px;
    text-align: center;
}
.filters-metric-value { font-size: 20px; font-weight: 700; color: var(--text-strong); line-height: 1.1; }
.filters-metric-label { font-size: 10px; color: var(--text-muted); margin-top: 3px; text-transform: uppercase; letter-spacing: .04em; }

/* Summary table */
.filters-summary-list { display: flex; flex-direction: column; }
.filters-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}
.filters-summary-row:last-child { border-bottom: 0; padding-bottom: 0; }

/* Responsive */
@media (max-width: 880px) {
    .filters-layout { grid-template-columns: 1fr; }
    .filters-sidebar { order: -1; }
    .filters-status-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 540px) {
    .filters-rule-row { grid-template-columns: 1fr; }
}
</style>
@endpush

{{-- Running alert — uses tip-box (existing CRM class) with warning overrides --}}
@if($isRunning)
<div class="tip-box" style="background:#fffbeb;border-color:#fde68a;color:#92400e;margin-bottom:20px;">
    <i class="bi bi-play-circle-fill tip-icon" style="color:#d97706;"></i>
    <div>
        <strong>Campaign is currently running.</strong>
        Pause this campaign to edit filters. You can still run <em>Test Filters</em> without stopping.
    </div>
</div>
@endif

<form method="POST" action="{{ route('dialer-campaigns.filters', $dc) }}" id="filtersForm">
    @csrf @method('PUT')

    {{-- Hidden fields updated by JS; submitted with the form --}}
    <input type="hidden" id="filtersMatch"   name="match"           value="{{ $selMatch }}">
    <input type="hidden" id="conditionsJson" name="conditions_json" value="">

    <div class="filters-layout">

        {{-- ── Main column ─────────────────────────────────────────────────── --}}
        <div>

            {{-- Enable toggle --}}
            <div class="filters-enable-row">
                <div>
                    <div style="font-size:14px;font-weight:700;color:var(--text-strong);">Enable lead filters</div>
                    <div class="form-hint" style="margin-top:3px;">When off, the dialer uses normal eligibility without extra filtering.</div>
                </div>
                <label class="filters-toggle-wrap" title="{{ $isRunning ? 'Pause the campaign to change this' : 'Toggle filters' }}">
                    <input type="checkbox" id="filtersEnabled" name="enabled" value="1"
                           {{ $isEnabled ? 'checked' : '' }}
                           {{ $isRunning ? 'disabled' : '' }}>
                    <span class="filters-toggle-track"></span>
                </label>
            </div>

            {{-- Body — hidden when disabled --}}
            <div id="filtersBody" style="{{ $isEnabled ? '' : 'display:none;' }}">

                {{-- Section 1: Status --}}
                <div class="form-group">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:4px;">
                        <div>
                            <label class="form-label" style="margin-bottom:2px;">1. Dialing status</label>
                            <div class="form-hint">Select which lead statuses are eligible for offers in this campaign.</div>
                        </div>
                        @if(!$isRunning)
                        <button type="button" id="defaultStatusesBtn" class="btn btn-secondary btn-sm" style="flex-shrink:0;">Default</button>
                        @endif
                    </div>

                    <div class="filters-status-grid" id="statusChecks">
                        @foreach($eligibleStatuses as $st => $sub)
                        <label class="filters-status-pill">
                            <input type="checkbox"
                                   name="statuses[]"
                                   value="{{ $st }}"
                                   {{ in_array($st, $selStatuses, true) ? 'checked' : '' }}
                                   {{ $isRunning ? 'disabled' : '' }}>
                            <span>
                                <span class="filters-pill-name">{{ $st }}</span>
                                <span class="filters-pill-hint">{{ $sub }}</span>
                            </span>
                        </label>
                        @endforeach
                    </div>

                    <div class="form-hint" style="margin-top:10px;">
                        <i class="bi bi-info-circle"></i>
                        <strong>ANSWERED</strong> is always terminal and cannot be offered again.
                    </div>
                </div>

                {{-- Section 2: Field conditions --}}
                <div class="form-group" style="margin-top:24px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px;">
                        <div>
                            <label class="form-label" style="margin-bottom:2px;">2. Lead field conditions</label>
                            <div class="form-hint">Example: State is one of AZ, NY AND Zip equals 83296.</div>
                        </div>
                        @if($dc->leadList)
                        <span class="badge" style="background:#fffbeb;color:#92400e;white-space:nowrap;flex-shrink:0;font-size:11px;">
                            {{ $dc->leadList->name }}
                        </span>
                        @endif
                    </div>

                    @if(empty($availableFields))
                    <div class="tip-box" style="margin:0;">
                        <i class="bi bi-table tip-icon"></i>
                        No custom fields detected in this lead list. Import leads first to enable field conditions.
                    </div>
                    @else

                    {{-- Builder: toolbar + rules list --}}
                    <div class="filters-builder-toolbar">
                        <div class="filters-match-control" id="matchControl">
                            <button type="button"
                                    class="filters-match-btn {{ $selMatch === 'all' ? 'active' : '' }}"
                                    data-match="all"
                                    {{ $isRunning ? 'disabled' : '' }}>Match ALL</button>
                            <button type="button"
                                    class="filters-match-btn {{ $selMatch === 'any' ? 'active' : '' }}"
                                    data-match="any"
                                    {{ $isRunning ? 'disabled' : '' }}>Match ANY</button>
                        </div>
                        @if(!$isRunning)
                        <button type="button" id="addConditionBtn" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-circle"></i> Add condition
                        </button>
                        @endif
                    </div>

                    <div class="filters-rules-list" id="conditionsList">
                        <div class="filters-rule-empty" id="conditionsEmptyHint" style="{{ !empty($savedConditions) ? 'display:none;' : '' }}">
                            No conditions yet — click <em>Add condition</em> to narrow leads by field value.
                        </div>
                    </div>

                    {{-- Human-readable preview --}}
                    <div class="filters-preview-box" id="filterPreview" style="{{ empty($savedConditions) ? 'display:none;' : '' }}">
                        <div class="form-hint" style="margin-bottom:6px;font-weight:600;">
                            <i class="bi bi-eye" style="margin-right:4px;"></i>Human preview
                        </div>
                        <pre class="filters-preview-query" id="filterPreviewText"></pre>
                    </div>

                    @endif
                </div>

            </div>{{-- #filtersBody --}}

        </div>{{-- main column --}}

        {{-- ── Right sidebar ───────────────────────────────────────────────── --}}
        <aside class="filters-sidebar">

            {{-- Test Filters panel --}}
            <div class="filters-sidebar-panel">
                <div class="filters-sidebar-head">
                    <div class="filters-sidebar-title"><i class="bi bi-search icon-primary"></i> Test Filters</div>
                    <div class="filters-sidebar-desc">Simulate how many leads match current settings.</div>
                </div>
                <div class="filters-sidebar-body">

                    <div class="filters-metric-grid">
                        <div class="filters-metric">
                            <div class="filters-metric-value" id="metricTotal">—</div>
                            <div class="filters-metric-label">Total Leads</div>
                        </div>
                        <div class="filters-metric">
                            <div class="filters-metric-value" id="metricCalled">—</div>
                            <div class="filters-metric-label">Eligible Status</div>
                        </div>
                        <div class="filters-metric">
                            <div class="filters-metric-value" id="metricMatching">—</div>
                            <div class="filters-metric-label">Matching Filters</div>
                        </div>
                        <div class="filters-metric">
                            <div class="filters-metric-value" id="metricEligible" style="color:var(--primary);">—</div>
                            <div class="filters-metric-label">Will Dial</div>
                        </div>
                    </div>

                    {{-- Error / status area --}}
                    <div id="testFiltersResult" style="display:none;margin-top:10px;"></div>

                    <div style="margin-top:12px;">
                        <button type="button" id="testFiltersBtn" class="btn btn-primary btn-sm js-test-filters-btn">
                            <i class="bi bi-search"></i> Test Filters
                        </button>
                    </div>

                </div>
            </div>

            {{-- Current rule summary panel --}}
            <div class="filters-sidebar-panel">
                <div class="filters-sidebar-head">
                    <div class="filters-sidebar-title"><i class="bi bi-list-check icon-primary"></i> Rule summary</div>
                    <div class="filters-sidebar-desc">How the current settings would be saved.</div>
                </div>
                <div class="filters-sidebar-body">
                    <div class="filters-summary-list">
                        <div class="filters-summary-row">
                            <span class="cell-muted">Mode</span>
                            <span class="cell-strong" id="summaryEnabled">{{ $isEnabled ? 'Enabled' : 'Disabled' }}</span>
                        </div>
                        <div class="filters-summary-row">
                            <span class="cell-muted">Status filter</span>
                            <span class="cell-strong" id="summaryStatuses">{{ count($selStatuses) }} selected</span>
                        </div>
                        <div class="filters-summary-row">
                            <span class="cell-muted">Field rules</span>
                            <span class="cell-strong" id="summaryRules">{{ count($savedConditions) }} rules</span>
                        </div>
                        <div class="filters-summary-row">
                            <span class="cell-muted">Match</span>
                            <span class="cell-strong" id="summaryMatch">{{ strtoupper($selMatch) }}</span>
                        </div>
                    </div>
                </div>
            </div>

        </aside>

    </div>{{-- .filters-layout --}}

</form>

{{-- Condition row template (cloned by JS; class "condition-row" required by collectConditions()) --}}
<template id="conditionRowTemplate">
    <div class="filters-rule-row condition-row">
        <div class="filters-rule-prefix">AND</div>
        <select class="cond-field" title="Field">
            <option value="">— Field —</option>
        </select>
        <select class="cond-operator" title="Operator">
            <option value="equals">equals</option>
            <option value="not_equals">does not equal</option>
            <option value="contains">contains</option>
            <option value="not_contains">does not contain</option>
            <option value="starts_with">starts with</option>
            <option value="ends_with">ends with</option>
            <option value="in">is one of</option>
            <option value="empty">is empty</option>
            <option value="not_empty">is not empty</option>
            <option value="greater_than">greater than</option>
            <option value="less_than">less than</option>
        </select>
        <div class="cond-value-wrap">
            <input type="text" class="cond-value" placeholder="Value">
        </div>
        <button type="button" class="remove-condition-btn" title="Remove">&times;</button>
    </div>
</template>

@push('scripts')
<script>
window.CampaignFiltersConfig = {
    fields:            @json($availableFields),
    currentConditions: @json($savedConditions),
    routes: {
        test: '{{ route('dialer-campaigns.filters.test', $dc) }}',
    },
    csrf:            '{{ csrf_token() }}',
    isRunning:       @json($isRunning),
    defaultStatuses: ['NEW', 'NO_ANSWER', 'BUSY', 'FAILED', 'CONGESTION', 'REJECTED'],
};
</script>
<script src="{{ asset('js/dialer-campaign-filters.js') }}"></script>
@endpush
