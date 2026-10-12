@extends('layouts.app')

@section('title', $template->exists ? 'Edit Template' : 'New Template')
@section('page-title', $template->exists ? 'Edit Email Template' : 'New Email Template')
@section('page-subtitle', 'Reusable email body with auto-fill variables for leads and agents')

@push('styles')
<style>
.var-tab-btn {
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--bg-card);
    color: var(--text);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    line-height: 1.6;
}
.var-tab-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.var-tab-btn:hover:not(.active) {
    background: var(--bg-th);
}
.var-chip-group-label {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 4px;
}
</style>
@endpush

@section('content')

@if($errors->any())
<div class="alert alert-danger" style="margin-bottom:16px;">
    <ul style="margin:0;padding-left:16px;">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

@php
$leadVars = [
    '{{lead.first_name}}' => 'First Name',
    '{{lead.last_name}}'  => 'Last Name',
    '{{lead.email}}'      => 'Email',
    '{{lead.phone}}'      => 'Phone',
    '{{lead.phone_2}}'    => 'Phone 2',
    '{{lead.address}}'    => 'Address',
    '{{lead.city}}'       => 'City',
    '{{lead.state}}'      => 'State',
    '{{lead.zip}}'        => 'ZIP',
    '{{lead.country}}'    => 'Country',
    '{{lead.status}}'     => 'Status',
    '{{lead.source}}'     => 'Source',
    '{{lead.value}}'      => 'Value',
    '{{lead.notes}}'      => 'Notes',
];
$systemVars = [
    '{{agent.name}}'     => 'Agent Name',
    '{{agent.email}}'    => 'Agent Email',
    '{{agent.phone}}'    => 'Agent Phone',
    '{{agent.campaign}}' => 'Agent Campaign',
    '{{date.today}}'     => 'Today\'s Date',
];
$currentSource = old('variable_source', $template->variable_source ?? 'leads');
$currentLlId   = old('leadlist_id', $template->leadlist_id);
@endphp

<form method="POST"
      action="{{ $template->exists ? route('email-templates.update', $template) : route('email-templates.store') }}"
      style="max-width:900px;">
    @csrf
    @if($template->exists) @method('PUT') @endif

    <div class="card" style="padding:24px;margin-bottom:16px;">

        {{-- Tenant (superadmin only) --}}
        @if($isSuperadmin)
        <div class="form-group">
            <label class="form-label">Tenant <span class="required-star">*</span></label>
            <select name="tenant_id" class="form-control" style="max-width:320px;" required>
                <option value="">— Select tenant —</option>
                @foreach($tenants as $t)
                <option value="{{ $t->id }}"
                    {{ old('tenant_id', $template->tenant_id) == $t->id ? 'selected' : '' }}>
                    {{ $t->name }}
                </option>
                @endforeach
            </select>
            @error('tenant_id')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
        </div>
        @endif

        {{-- Name --}}
        <div class="form-group">
            <label class="form-label">Template Name <span class="required-star">*</span></label>
            <input type="text" name="name" class="form-control" style="max-width:480px;"
                   value="{{ old('name', $template->name) }}"
                   placeholder="e.g. Welcome Email, Follow-up, Quote" required>
            @error('name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
        </div>

        {{-- Subject --}}
        <div class="form-group">
            <label class="form-label">Subject <span class="required-star">*</span></label>
            <input type="text" name="subject" id="tplSubject" class="form-control"
                   value="{{ old('subject', $template->subject) }}"
                   placeholder="e.g. Hello @{{lead.first_name}}, your quote is ready"
                   onfocus="window._tplActiveField='tplSubject'" required>
            @error('subject')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
        </div>

        {{-- Variable picker with tabs --}}
        <div style="background:var(--bg-th);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;margin-bottom:16px;">
            {{-- Header: label + tab buttons --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <span style="font-size:11px;font-weight:600;color:var(--text-muted);letter-spacing:.5px;text-transform:uppercase;">
                    Insert Variable — click to insert at cursor
                </span>
                <div style="display:flex;gap:4px;">
                    <button type="button" class="var-tab-btn {{ $currentSource !== 'leadlist' ? 'active' : '' }}"
                            data-tab="leads" id="varTabLeads">Leads (CRM)</button>
                    <button type="button" class="var-tab-btn"
                            data-tab="system" id="varTabSystem">System</button>
                    <button type="button" class="var-tab-btn {{ $currentSource === 'leadlist' ? 'active' : '' }}"
                            data-tab="leadlist" id="varTabLeadList">Lead List</button>
                </div>
            </div>

            {{-- Pane: Leads --}}
            <div id="varPane-leads" class="var-pane" style="{{ $currentSource === 'leadlist' ? 'display:none;' : '' }}">
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    @foreach($leadVars as $var => $label)
                    <button type="button" class="btn btn-xs btn-outline var-chip" data-var="{{ $var }}"
                            style="font-size:11px;font-family:monospace;">{{ $var }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Pane: System --}}
            <div id="varPane-system" class="var-pane" style="display:none;">
                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    @foreach($systemVars as $var => $label)
                    <button type="button" class="btn btn-xs btn-outline var-chip" data-var="{{ $var }}"
                            style="font-size:11px;font-family:monospace;">{{ $var }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Pane: Lead List --}}
            <div id="varPane-leadlist" class="var-pane" style="{{ $currentSource !== 'leadlist' ? 'display:none;' : '' }}">
                <div style="margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                    <select id="varLeadListSelect" class="form-control"
                            style="max-width:300px;height:32px;padding:3px 8px;font-size:13px;">
                        <option value="">— Select a Lead List —</option>
                        @foreach($leadLists as $ll)
                        <option value="{{ $ll->id }}"
                            {{ $currentLlId == $ll->id ? 'selected' : '' }}>
                            {{ $ll->name }}
                        </option>
                        @endforeach
                    </select>
                    <span id="varLeadListLoading"
                          style="display:none;font-size:12px;color:var(--text-muted);">Loading…</span>
                </div>
                <div id="varLeadListChips">
                    <span style="font-size:11px;color:var(--text-muted);">Select a list above to see available variables.</span>
                </div>
            </div>

            {{-- Hidden: variable_source and leadlist_id --}}
            <input type="hidden" name="variable_source" id="tplVariableSource" value="{{ $currentSource }}">
            <input type="hidden" name="leadlist_id"     id="tplLeadlistId"     value="{{ $currentLlId ?? '' }}">
        </div>

        {{-- Body --}}
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Body <span class="required-star">*</span></label>
            <textarea name="body" id="tplBody">{{ old('body', $template->body) }}</textarea>
            @error('body')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
        </div>

    </div>

    <div style="display:flex;gap:8px;align-items:center;">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> {{ $template->exists ? 'Save Changes' : 'Create Template' }}
        </button>
        <a href="{{ route('email-templates.index') }}" class="btn btn-secondary">Cancel</a>
        @if($template->exists)
        <span style="margin-left:8px;font-size:12px;color:var(--text-muted);">
            Last updated {{ $template->updated_at->diffForHumans() }}
        </span>
        @endif
    </div>
</form>

@endsection

@push('scripts')
<script>
window.EmailTemplateFormConfig = {
    leadlistFieldsUrl: '{{ route('email-templates.leadlist-fields') }}',
    initialSource:     '{{ $currentSource }}',
    initialLlId:       '{{ $currentLlId ?? '' }}',
};
</script>
<script src="{{ asset('js/email-templates-form.js') }}"></script>
@endpush
