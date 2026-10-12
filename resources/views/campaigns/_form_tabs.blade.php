{{--
  Shared create/edit form for Campaigns.

  Required variables (passed from create.blade.php / edit.blade.php):
    $action    (string)          — form POST URL
    $campaign  (Campaign|null)   — null for create, Campaign model for edit

  Variables injected by CampaignController::formData():
    $programs, $agents, $voiceGateways, $tenantVoiceEnabled,
    $isSuperAdmin, $tenants, $selectedTenantId
--}}

@push('styles')
<style>
/* ── Form page tabs ──────────────────────────────────────────────────────── */
.form-page-card   { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); }
.tab-nav          { display:flex; border-bottom:2px solid var(--border); gap:0; padding:0 24px; border-radius:var(--radius) var(--radius) 0 0; }
.tab-btn          { background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px;
                    padding:12px 18px; font-size:14px; font-weight:500; color:var(--text-muted); cursor:pointer;
                    display:flex; align-items:center; gap:6px; transition:color .15s, border-color .15s; white-space:nowrap; }
.tab-btn:hover    { color:var(--text-strong); }
.tab-btn.active   { color:var(--primary); border-bottom-color:var(--primary); }
.tab-err-dot      { width:7px; height:7px; border-radius:50%; background:#ef4444; display:inline-block; flex-shrink:0; }
.tab-panels       { padding:24px 24px 0; }
.tab-panel        { display:none; }
.tab-panel.active { display:block; }
.form-actions     { display:flex; justify-content:flex-end; gap:8px; padding:20px 24px; border-top:1px solid var(--border); margin-top:8px; }
.form-hint        { font-size:11px; color:var(--text-muted); margin-top:4px; line-height:1.4; }

/* ── Agents dual-panel ───────────────────────────────────────────────────── */
.ca-toolbar          { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.ca-toolbar .form-control { flex:1; min-width:180px; max-width:340px; }
.ca-summary          { font-size:12px; color:var(--text-muted); white-space:nowrap; }
.ca-summary strong   { color:var(--text-strong); }
.ca-toolbar-btns     { display:flex; gap:6px; margin-left:auto; flex-shrink:0; }
.ca-dual             { display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:stretch; }
.ca-panel            { border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg-card);
                       display:flex; flex-direction:column;
                       min-height:420px; height:calc(100vh - 330px); max-height:620px; }
.ca-panel-hd         { flex-shrink:0; padding:9px 13px; border-bottom:1px solid var(--border); border-radius:var(--radius-sm) var(--radius-sm) 0 0; }
.ca-panel-hd--assigned  { background:#f0fdf4; }
.ca-panel-hd--available { background:var(--bg-th); }
.ca-panel-hd strong  { display:block; font-size:12px; font-weight:700; color:var(--text-strong); }
.ca-panel-hd p       { font-size:11px; color:var(--text-muted); margin:2px 0 0; }
.ca-panel-body       { flex:1; min-height:0; overflow-y:auto; }
.ca-panel-ft         { flex-shrink:0; padding:7px 11px; border-top:1px solid var(--border); background:var(--bg-th); border-radius:0 0 var(--radius-sm) var(--radius-sm); }
.ca-empty            { padding:22px 14px; text-align:center; color:var(--text-muted); font-size:12px; }
.ca-row              { display:flex; align-items:center; gap:8px; padding:11px 12px; border-bottom:1px solid var(--border); cursor:default; transition:background .1s; }
@media (max-width:680px) {
    .ca-dual  { grid-template-columns:1fr; }
    .ca-panel { height:auto; min-height:300px; max-height:400px; }
}
.ca-row:last-child   { border-bottom:none; }
.ca-row:hover        { background:var(--bg-th); }
.ca-row.ca-hidden    { display:none !important; }
.ca-chk              { width:14px; height:14px; flex-shrink:0; cursor:pointer; accent-color:var(--primary); }
.ca-avatar           { width:26px; height:26px; border-radius:50%; flex-shrink:0; background:var(--primary); color:#fff;
                        display:inline-flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; text-transform:uppercase; }
.ca-info             { flex:1; min-width:0; }
.ca-name             { display:block; font-size:12px; font-weight:600; color:var(--text-strong); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ca-email            { display:block; font-size:11px; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ca-btn              { flex-shrink:0; width:22px; height:22px; border-radius:50%; border:1px solid var(--border); background:var(--bg-card);
                        color:var(--text-muted); font-size:14px; line-height:1; display:inline-flex; align-items:center; justify-content:center;
                        cursor:pointer; padding:0; transition:all .15s; }
.ca-btn-add:hover    { background:#dcfce7; border-color:#16a34a; color:#16a34a; }
.ca-btn-remove:hover { background:#fef2f2; border-color:#dc2626; color:#dc2626; }
</style>
@endpush

@php
    $isEdit = isset($campaign) && $campaign !== null;

    // Error-driven tab routing: general takes highest priority
    $initialTab = 'general';
    if ($errors->hasAny(['outbound_caller_id', 'voice_gateway_id'])) $initialTab = 'voice';
    if ($errors->hasAny(['agent_ids']))                               $initialTab = 'agents';
    if ($errors->hasAny(['tenant_id', 'name', 'program_id', 'dialer_pattern', 'color', 'bg_color', 'description', 'is_default', 'is_active'])) $initialTab = 'general';

    // Assigned agent IDs — integers, respecting old() on validation errors
    $assignedAgentIds = $isEdit ? $campaign->users->pluck('id')->toArray() : [];
    $rawSel           = old('agent_ids', $assignedAgentIds);
    $selectedAgentIds = array_values(array_map('intval', (array) $rawSel));

    // Agent data for the dual-panel JS
    $agentJson = $agents->map(fn($a) => [
        'id'    => $a->id,
        'name'  => $a->name,
        'email' => $a->email ?? '',
    ])->values();
@endphp

<div class="form-page-card">

    {{-- Tab Navigation --}}
    <div class="tab-nav" role="tablist">
        <button type="button" class="tab-btn {{ $initialTab === 'general' ? 'active' : '' }}" data-tab="general">
            <i class="bi bi-megaphone"></i> General
            @if($errors->hasAny(['tenant_id', 'name', 'program_id', 'dialer_pattern', 'color', 'bg_color', 'description', 'is_default', 'is_active']))
                <span class="tab-err-dot" title="This tab has errors"></span>
            @endif
        </button>
        <button type="button" class="tab-btn {{ $initialTab === 'agents' ? 'active' : '' }}" data-tab="agents">
            <i class="bi bi-people"></i> Agents
            @if($errors->hasAny(['agent_ids']))
                <span class="tab-err-dot" title="This tab has errors"></span>
            @endif
        </button>
        @if($tenantVoiceEnabled || $voiceGateways->isNotEmpty())
        <button type="button" class="tab-btn {{ $initialTab === 'voice' ? 'active' : '' }}" data-tab="voice">
            <i class="bi bi-telephone-fill"></i> Voice
            @if($errors->hasAny(['outbound_caller_id', 'voice_gateway_id']))
                <span class="tab-err-dot" title="This tab has errors"></span>
            @endif
        </button>
        @endif
    </div>

    <form method="POST" action="{{ $action }}" id="campaignForm">
        @csrf
        @if($isEdit) @method('PUT') @endif

        <div class="tab-panels">

            {{-- ── TAB: GENERAL ────────────────────────────────────────────── --}}
            <div class="tab-panel {{ $initialTab === 'general' ? 'active' : '' }}" id="tab-general">

                @if($errors->any())
                    <div class="alert alert-danger" style="margin-bottom:16px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-sm);color:#dc2626;font-size:13px;">
                        @foreach($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger" style="margin-bottom:16px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius-sm);color:#dc2626;font-size:13px;">
                        {{ session('error') }}
                    </div>
                @endif

                @if($isSuperAdmin)
                <div class="form-group">
                    <label class="form-label">Tenant <span class="required-star">*</span></label>
                    @if($isEdit)
                        <input type="hidden" name="tenant_id" value="{{ $selectedTenantId }}">
                    @endif
                    <select name="{{ $isEdit ? '_tenant_display' : 'tenant_id' }}"
                            id="campaignTenantId"
                            class="form-control {{ $errors->has('tenant_id') ? 'is-invalid' : '' }}"
                            {{ $isEdit ? 'disabled' : 'required' }}>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}"
                                {{ (int) old('tenant_id', $selectedTenantId) === (int) $tenant->id ? 'selected' : '' }}>
                                {{ $tenant->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        Campaign gateways are filtered to global gateways and this tenant's gateways.
                    </div>
                    @error('tenant_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endif

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Campaign Name <span class="required-star">*</span></label>
                        <input type="text" name="name" id="campaignName" required
                               class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}"
                               placeholder="e.g. GBPO Sales"
                               value="{{ old('name', $isEdit ? $campaign->name : '') }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Dialer Pattern</label>
                        <input type="text" name="dialer_pattern"
                               class="form-control {{ $errors->has('dialer_pattern') ? 'is-invalid' : '' }}"
                               placeholder="e.g. sales"
                               value="{{ old('dialer_pattern', $isEdit ? $campaign->dialer_pattern : '') }}">
                        <div class="form-hint">Keyword matched against incoming dialer campaign names.</div>
                        @error('dialer_pattern')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Program</label>
                    <select name="program_id" class="form-control {{ $errors->has('program_id') ? 'is-invalid' : '' }}">
                        <option value="">— No program —</option>
                        @foreach($programs as $program)
                            <option value="{{ $program->id }}"
                                {{ old('program_id', $isEdit ? $campaign->program_id : '') == $program->id ? 'selected' : '' }}>
                                {{ $program->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('program_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-row cols-2 mb-16">
                    <div class="form-group mb-0">
                        <label class="form-label">Text Color <span class="required-star">*</span></label>
                        <div class="color-row">
                            <input type="color" name="color" id="campaignColor" class="color-picker"
                                   value="{{ old('color', $isEdit ? $campaign->color : '#4f46e5') }}">
                            <input type="text" id="campaignColorHex" class="form-control color-hex"
                                   value="{{ old('color', $isEdit ? $campaign->color : '#4f46e5') }}">
                        </div>
                        @error('color')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Background Color <span class="required-star">*</span></label>
                        <div class="color-row">
                            <input type="color" name="bg_color" id="campaignBg" class="color-picker"
                                   value="{{ old('bg_color', $isEdit ? $campaign->bg_color : '#e0e7ff') }}">
                            <input type="text" id="campaignBgHex" class="form-control color-hex"
                                   value="{{ old('bg_color', $isEdit ? $campaign->bg_color : '#e0e7ff') }}">
                        </div>
                        @error('bg_color')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="preview-box mb-16">
                    <span class="cell-muted">Preview:</span>
                    <span class="badge" id="campaignPreview"
                          style="background:{{ old('bg_color', $isEdit ? $campaign->bg_color : '#e0e7ff') }};color:{{ old('color', $isEdit ? $campaign->color : '#4f46e5') }};">
                        {{ old('name', $isEdit ? $campaign->name : 'Campaign') }}
                    </span>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description"
                           class="form-control {{ $errors->has('description') ? 'is-invalid' : '' }}"
                           placeholder="Brief description (optional)"
                           value="{{ old('description', $isEdit ? $campaign->description : '') }}">
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group {{ $isEdit ? '' : 'mb-0' }}">
                    <label class="check-row">
                        <input type="checkbox" name="is_default" value="1"
                               {{ old('is_default', $isEdit ? $campaign->is_default : false) ? 'checked' : '' }}>
                        <span>Default campaign — receives leads that don't match any dialer pattern</span>
                    </label>
                </div>

                @if($isEdit)
                <div class="form-group mb-0">
                    <label class="check-row">
                        <input type="checkbox" name="is_active" value="1"
                               {{ old('is_active', $campaign->is_active) ? 'checked' : '' }}>
                        <span>Active — agents can use this campaign</span>
                    </label>
                </div>
                @endif

            </div>{{-- /tab-general --}}

            {{-- ── TAB: AGENTS ─────────────────────────────────────────────── --}}
            <div class="tab-panel {{ $initialTab === 'agents' ? 'active' : '' }}" id="tab-agents">

                {{-- Hidden inputs populated by JS on every change --}}
                <div id="agentInputs"></div>

                @if($agents->isEmpty())
                    <p class="cell-muted" style="padding:4px 0 12px;">No active agents available in this tenant.</p>
                @else

                {{-- Toolbar: search + counters only --}}
                <div class="ca-toolbar">
                    <input type="text" id="agentSearch" class="form-control"
                           placeholder="Search agents by name or email…"
                           autocomplete="off">
                    <div class="ca-summary">
                        Available:&nbsp;<strong id="countAvailable">0</strong>&emsp;
                        Assigned:&nbsp;<strong id="countAssigned">0</strong>&emsp;
                        Total:&nbsp;<strong id="countTotal">{{ $agents->count() }}</strong>
                    </div>
                </div>

                {{-- Dual panel --}}
                <div class="ca-dual">

                    {{-- Left: Available --}}
                    <div class="ca-panel">
                        <div class="ca-panel-hd ca-panel-hd--available">
                            <strong>Available Agents (<span id="countAvailablePanel">0</span>)</strong>
                            <p>Add agents from this list to include them in this campaign.</p>
                        </div>
                        <div class="ca-panel-body" id="availableList"></div>
                        <div class="ca-panel-ft" style="display:flex;gap:6px;">
                            <button type="button" class="btn btn-secondary btn-xs" id="selectAllVisible">
                                <i class="bi bi-check2-square"></i> Select all visible
                            </button>
                            <button type="button" class="btn btn-primary btn-xs" id="addSelected">
                                <i class="bi bi-plus-circle"></i> Add selected
                            </button>
                        </div>
                    </div>

                    {{-- Right: Assigned --}}
                    <div class="ca-panel">
                        <div class="ca-panel-hd ca-panel-hd--assigned">
                            <strong>Assigned Agents (<span id="countAssignedPanel">0</span>)</strong>
                            <p>Agents who will be included in this campaign.</p>
                        </div>
                        <div class="ca-panel-body" id="assignedList"></div>
                        <div class="ca-panel-ft" style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                            <button type="button" class="btn btn-danger btn-xs" id="removeSelected">
                                <i class="bi bi-dash-circle"></i> Remove selected
                            </button>
                            <button type="button" class="btn btn-outline btn-xs" id="removeAll">
                                <i class="bi bi-x-square"></i> Remove all
                            </button>
                        </div>
                    </div>

                </div>{{-- /ca-dual --}}
                @endif

            </div>{{-- /tab-agents --}}

            {{-- ── TAB: VOICE ───────────────────────────────────────────────── --}}
            @if($tenantVoiceEnabled || $voiceGateways->isNotEmpty())
            <div class="tab-panel {{ $initialTab === 'voice' ? 'active' : '' }}" id="tab-voice">

                <div class="form-group">
                    <label class="form-label">Outbound Caller ID</label>
                    <input type="text" name="outbound_caller_id" maxlength="40"
                           class="form-control {{ $errors->has('outbound_caller_id') ? 'is-invalid' : '' }}"
                           placeholder="+15551234567"
                           value="{{ old('outbound_caller_id', $isEdit ? $campaign->outbound_caller_id : '') }}">
                    <div class="form-hint">
                        Campaign Caller ID is an override.<br>
                        Leave empty to use the agent Caller ID, then the tenant default.
                    </div>
                    @error('outbound_caller_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Gateway</label>
                    <select name="voice_gateway_id"
                            class="form-control {{ $errors->has('voice_gateway_id') ? 'is-invalid' : '' }}">
                        <option value="">— Inherit from tenant —</option>
                        @foreach($voiceGateways as $gw)
                            <option value="{{ $gw->id }}"
                                data-tenant-id="{{ $gw->tenant_id ?? '' }}"
                                {{ old('voice_gateway_id', $isEdit ? $campaign->voice_gateway_id : '') == $gw->id ? 'selected' : '' }}>
                                {{ $gw->name }}@if($gw->isGlobal()) (global)@endif
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">
                        Campaign Gateway is an override.<br>
                        Leave empty to inherit the tenant gateway.
                    </div>
                    @error('voice_gateway_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

            </div>{{-- /tab-voice --}}
            @endif

        </div>{{-- .tab-panels --}}

        {{-- Form Actions --}}
        <div class="form-actions">
            <a href="{{ route('campaigns.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary" id="campaignSubmitBtn">
                <i class="bi bi-save"></i> {{ $isEdit ? 'Save Changes' : 'Create Campaign' }}
            </button>
        </div>

    </form>
</div>

@push('scripts')
<script>
window.CampaignsFormConfig = {
    isEdit:           @json($isEdit),
    initialTab:       @json($initialTab),
    allAgents:        @json($agentJson),
    assignedAgentIds: @json($selectedAgentIds),
    isSuperAdmin:     @json($isSuperAdmin),
    selectedTenantId: @json((string) $selectedTenantId),
};

document.addEventListener('DOMContentLoaded', () => {
    const tenantSelect = document.getElementById('campaignTenantId');
    const gatewaySelect = document.querySelector('select[name="voice_gateway_id"]');
    if (!gatewaySelect) return;

    const syncGatewayOptions = () => {
        const tenantId = tenantSelect?.value || window.CampaignsFormConfig.selectedTenantId || '';
        let selectedOptionVisible = gatewaySelect.value === '';

        gatewaySelect.querySelectorAll('option[data-tenant-id]').forEach(option => {
            const optionTenantId = option.dataset.tenantId || '';
            const visible = optionTenantId === '' || optionTenantId === tenantId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible && option.selected) selectedOptionVisible = true;
        });

        if (!selectedOptionVisible) {
            gatewaySelect.value = '';
        }
    };

    tenantSelect?.addEventListener('change', syncGatewayOptions);
    syncGatewayOptions();
});
</script>
<script src="{{ asset('js/campaigns-form.js') }}"></script>
@endpush
