{{--
    Edit tabs partial for DialerCampaign.
    Variables: $dialerCampaign, $isSuperAdmin, $tenants,
               $crmCampaigns, $leadLists, $scripts,
               $eligibleCallerIds, $assignedIds
--}}

@push('styles')
<style>
.form-page-card    { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); }
.tab-nav           { display:flex; border-bottom:2px solid var(--border); gap:0; padding:0 24px; border-radius:var(--radius) var(--radius) 0 0; }
.tab-btn           { background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px;
                     padding:12px 18px; font-size:14px; font-weight:500; color:var(--text-muted); cursor:pointer;
                     display:flex; align-items:center; gap:6px; transition:color .15s, border-color .15s; white-space:nowrap; }
.tab-btn:hover     { color:var(--text-strong); }
.tab-btn.active    { color:var(--primary); border-bottom-color:var(--primary); }
.tab-count-badge   { display:inline-flex; align-items:center; justify-content:center;
                     background:var(--primary); color:#fff; border-radius:99px;
                     min-width:18px; height:18px; padding:0 5px; font-size:11px; font-weight:700; }
.tab-panels        { padding:24px 24px 0; }
.tab-panel         { display:none; }
.tab-panel.active  { display:block; }
.form-actions      { display:flex; justify-content:flex-end; gap:8px; padding:20px 24px; border-top:1px solid var(--border); margin-top:24px; }

.caller-id-grid    { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:10px; margin-top:16px; }
.caller-id-card    { border:2px solid var(--border); border-radius:var(--radius-sm); padding:13px 15px;
                     display:flex; align-items:center; gap:11px; cursor:pointer;
                     transition:border-color .15s, background .15s; background:var(--bg-card); }
.caller-id-card:hover    { border-color:var(--primary); }
.caller-id-card.selected { border-color:var(--primary); background:var(--primary-light); }
.caller-id-card input[type="checkbox"] { width:16px; height:16px; accent-color:var(--primary); flex-shrink:0; }
.caller-id-info    { flex:1; min-width:0; }
.caller-id-number  { font-size:13px; font-weight:600; color:var(--text-strong); font-family:monospace; }
.caller-id-name    { font-size:11px; color:var(--text-muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cid-count         { font-size:13px; color:var(--text-muted); margin-right:auto; }
</style>
@endpush

@php
    $assignedCount = count($assignedIds);
    $initialTab    = request('tab', 'general');
    $isRunning     = $dialerCampaign->isRunning();
@endphp

<div class="form-page-card">

    {{-- Tab Navigation --}}
    <div class="tab-nav" role="tablist">
        <button type="button" class="tab-btn {{ $initialTab === 'general' ? 'active' : '' }}" data-tab="general">
            <i class="bi bi-gear"></i> General
        </button>
        @if($dialerCampaign->dialer_type === 'preview')
        <button type="button" class="tab-btn {{ $initialTab === 'caller-id' ? 'active' : '' }}" data-tab="caller-id">
            <i class="bi bi-telephone-outbound"></i> Caller ID
            @if($assignedCount > 0)
            <span class="tab-count-badge">{{ $assignedCount }}</span>
            @endif
        </button>
        <button type="button" class="tab-btn {{ $initialTab === 'filters' ? 'active' : '' }}" data-tab="filters">
            <i class="bi bi-funnel"></i> Filters
            @if(!empty($filterConfig['enabled']))
            <span class="tab-count-badge" style="background:var(--success);">ON</span>
            @endif
        </button>
        <button type="button" class="tab-btn {{ $initialTab === 'compliance' ? 'active' : '' }}" data-tab="compliance">
            <i class="bi bi-shield-check"></i> Compliance
            @if(!empty($complianceConfig['enabled']))
            <span class="tab-count-badge" style="background:var(--success);">ON</span>
            @endif
        </button>
        @endif
    </div>

    <div class="tab-panels">

        {{-- ── Tab: General ─────────────────────────────────────────────────── --}}
        <div class="tab-panel {{ $initialTab === 'general' ? 'active' : '' }}" id="tab-general">

            @if($isRunning)
            <div class="tip-box" style="margin-bottom:20px;margin-top:0;">
                <i class="bi bi-play-circle-fill tip-icon" style="color:var(--success);"></i>
                This campaign is currently <strong>running</strong>. Stop it before editing general settings.
            </div>
            @endif

            <form method="POST" action="{{ route('dialer-campaigns.update', $dialerCampaign) }}" id="generalForm">
                @csrf @method('PUT')
                <input type="hidden" name="_from_edit_page" value="1">
                @if($isSuperAdmin)
                <input type="hidden" name="tenant_id" value="{{ $dialerCampaign->tenant_id }}">
                @endif

                @include('dialer_campaigns._edit_form', [
                    'dc'           => $dialerCampaign,
                    'isSuperAdmin' => $isSuperAdmin,
                    'tenants'      => $tenants,
                    'crmCampaigns' => $crmCampaigns,
                    'leadLists'    => $leadLists,
                    'scripts'      => $scripts,
                ])
            </form>

            <div class="form-actions">
                <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Cancel
                </a>
                <button type="submit" form="generalForm" class="btn btn-primary" {{ $isRunning ? 'disabled' : '' }}>
                    <i class="bi bi-save"></i> Save General Settings
                </button>
            </div>
        </div>

        {{-- ── Tab: Caller ID ───────────────────────────────────────────────── --}}
        @if($dialerCampaign->dialer_type === 'preview')
        <div class="tab-panel {{ $initialTab === 'caller-id' ? 'active' : '' }}" id="tab-caller-id">

            @if($eligibleCallerIds->isEmpty())

            <div class="tip-box" style="margin-top:0;">
                <i class="bi bi-info-circle tip-icon"></i>
                No eligible Caller IDs found. Add active, verified Caller IDs in
                <a href="{{ route('admin.voice.caller-ids.index') }}">Voice → Caller IDs</a>
                that are not assigned to any agent.
            </div>

            <div class="form-actions">
                <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            @else

            @if($isRunning)
            <div class="tip-box" style="margin-top:0;">
                <i class="bi bi-play-circle-fill tip-icon" style="color:var(--success);"></i>
                This campaign is currently <strong>running</strong>. Stop it before changing Caller ID assignments.
            </div>
            @else
            <div class="tip-box" style="margin-top:0;">
                <i class="bi bi-info-circle tip-icon"></i>
                Only active, verified Caller IDs that are not assigned to any agent are available.
                Numbers rotate in LRU order when the Preview Dialer accepts offers.
            </div>
            @endif

            <form method="POST" action="{{ route('dialer-campaigns.caller-ids', $dialerCampaign) }}" id="callerIdForm">
                @csrf @method('PUT')

                <div class="caller-id-grid" id="callerIdGrid">
                    @foreach($eligibleCallerIds as $cid)
                    @php $isAssigned = in_array($cid->id, $assignedIds, true); @endphp
                    <label class="caller-id-card {{ $isAssigned ? 'selected' : '' }}" style="{{ $isRunning ? 'pointer-events:none;opacity:.6;' : '' }}">
                        <input type="checkbox"
                               name="caller_id_ids[]"
                               value="{{ $cid->id }}"
                               {{ $isAssigned ? 'checked' : '' }}
                               {{ $isRunning ? 'disabled' : '' }}>
                        <div class="caller-id-info">
                            <div class="caller-id-number">{{ $cid->number }}</div>
                            @if($cid->name)
                            <div class="caller-id-name">{{ $cid->name }}</div>
                            @endif
                        </div>
                    </label>
                    @endforeach
                </div>
            </form>

            <div class="form-actions">
                <span class="cid-count" id="cidSelectedCount">{{ $assignedCount }} selected</span>
                @if(!$isRunning)
                <button type="button" class="btn btn-secondary" id="cidClearAll">Clear all</button>
                @endif
                <button type="submit" form="callerIdForm" class="btn btn-primary" {{ $isRunning ? 'disabled' : '' }}>
                    <i class="bi bi-save"></i> Save Caller ID Pool
                </button>
            </div>

            @endif

        </div>
        @endif

        {{-- ── Tab: Filters ─────────────────────────────────────────────────── --}}
        @if($dialerCampaign->dialer_type === 'preview')
        <div class="tab-panel {{ $initialTab === 'filters' ? 'active' : '' }}" id="tab-filters">
            @include('dialer_campaigns._edit_filter_panel', [
                'dc'              => $dialerCampaign,
                'filterConfig'    => $filterConfig ?? [],
                'availableFields' => $availableFields ?? [],
                'isRunning'       => $isRunning,
            ])

            <div class="form-actions">
                <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Cancel
                </a>
<button type="submit" form="filtersForm" class="btn btn-primary" {{ $isRunning ? 'disabled' : '' }}>
                    <i class="bi bi-save"></i> Save Filters
                </button>
            </div>
        </div>
        @endif

        {{-- ── Tab: Compliance ──────────────────────────────────────────────── --}}
        @if($dialerCampaign->dialer_type === 'preview')
        <div class="tab-panel {{ $initialTab === 'compliance' ? 'active' : '' }}" id="tab-compliance">
            @include('dialer_campaigns._edit_compliance_panel', [
                'dc'               => $dialerCampaign,
                'complianceConfig' => $complianceConfig ?? [],
                'isRunning'        => $isRunning,
            ])

            <div class="form-actions">
                <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Cancel
                </a>
                <button type="submit" form="complianceForm" class="btn btn-primary" {{ $isRunning ? 'disabled' : '' }}>
                    <i class="bi bi-save"></i> Save Compliance Settings
                </button>
            </div>
        </div>
        @endif

    </div>{{-- .tab-panels --}}

</div>{{-- .form-page-card --}}

@push('scripts')
<script>
window.DialerCampaignEditConfig = {
    csrf:        '{{ csrf_token() }}',
    hasErrors:   @json($errors->any()),
    initialTab:  '{{ $initialTab }}',
    tenantId:    {{ $dialerCampaign->tenant_id }},
    isSuperAdmin: @json($isSuperAdmin),
};
</script>
<script src="{{ asset('js/dialer-campaign-edit.js') }}"></script>
@endpush
