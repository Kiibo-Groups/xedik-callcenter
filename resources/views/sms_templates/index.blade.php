@extends('layouts.app')

@section('title', 'SMS Templates')
@section('page-title', 'SMS Templates')
@section('page-subtitle', 'Reusable SMS message templates, optionally scoped to a queue')

@section('header-actions')
@php $__canCreate = !($isSuperAdmin ?? false) || !empty($selectedTenantId ?? null); @endphp
<button class="btn btn-primary btn-sm" onclick="SmsTemplates.openCreate()" {{ $__canCreate ? '' : 'disabled title="Select a tenant first"' }}>
    <i class="bi bi-plus-lg"></i> New Template
</button>
@endsection

@section('content')

{{-- Defensivo: nunca confiar en que el controller haya pasado estas variables. --}}
@php
    $isSuperAdmin     = $isSuperAdmin     ?? auth()->user()->isSuperAdmin();
    $tenants          = $tenants          ?? collect();
    $selectedTenantId = $selectedTenantId ?? null;
    $selectedTenant   = $selectedTenant   ?? ($selectedTenantId ? $tenants->firstWhere('id', $selectedTenantId) : null);
    $templates        = $templates        ?? collect();
    $queues           = $queues           ?? collect();
@endphp

@if($isSuperAdmin)
    @include('admin.sms._nav')
@else
    @include('sms._nav')
@endif

@if($isSuperAdmin)
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="display:flex;align-items:center;gap:10px;">
        <label class="form-label" style="margin:0;white-space:nowrap;">Acting as tenant:</label>
        <form method="GET" action="{{ route('sms-templates.index') }}" style="display:flex;gap:8px;">
            <select name="tenant_id" class="form-control" onchange="this.form.submit()" style="min-width:260px;">
                <option value="">— Select tenant —</option>
                @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}" {{ (string) $selectedTenantId === (string) $tenant->id ? 'selected' : '' }}>{{ $tenant->name }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-chat-square-text icon-primary"></i> SMS Templates</span>
    </div>
    <div class="table-wrapper">
        @if($isSuperAdmin && !$selectedTenantId)
        <div class="empty-state">
            <i class="bi bi-building"></i>
            <h3>Select a tenant</h3>
            <p>Pick a tenant above to view and manage its SMS templates.</p>
        </div>
        @elseif($templates->count())
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Body</th>
                    <th>Queue</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($templates as $tpl)
                <tr>
                    <td class="cell-strong">{{ $tpl->name }}</td>
                    <td style="font-size:13px;color:var(--text-muted);max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ Str::limit($tpl->body, 70) }}</td>
                    <td class="cell-muted">{{ $tpl->omniQueue?->name ?? 'Any queue' }}</td>
                    <td>
                        <span class="badge {{ $tpl->is_active ? 'badge-success' : 'badge-danger' }} tpl-toggle"
                              data-id="{{ $tpl->id }}"
                              data-url="{{ route('sms-templates.toggle', $tpl) }}"
                              style="cursor:pointer;">
                            <i class="bi {{ $tpl->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $tpl->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <button class="btn btn-outline btn-xs"
                                    onclick="SmsTemplates.openEdit({{ $tpl->id }}, {{ json_encode($tpl->name) }}, {{ json_encode($tpl->omni_queue_id) }}, {{ json_encode($tpl->body) }})">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('sms-templates.destroy', $tpl) }}"
                                  class="delete-form" data-name="{{ $tpl->name }}" data-entity="template"
                                  style="display:inline;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-chat-square-text"></i>
            <h3>No templates yet</h3>
            <p>Create your first SMS template to speed up P2P outreach.</p>
        </div>
        @endif
    </div>
</div>

{{-- Create Modal --}}
<div id="modal-create" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-plus-lg modal-icon"></i> New SMS Template</h3>
            <button class="modal-close" onclick="closeModal('modal-create')">&times;</button>
        </div>
        <form method="POST" action="{{ route('sms-templates.store') }}">
            @csrf
            @if($isSuperAdmin)
                <input type="hidden" name="tenant_id" value="{{ $selectedTenantId }}">
            @endif
            <div class="modal-body">
                <div style="margin-bottom:14px;">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Follow-up">
                    @error('name') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Queue <span style="font-weight:400;">(optional — leave blank for any queue)</span></label>
                    <select name="omni_queue_id" class="form-control">
                        <option value="">— Any queue —</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->id }}" {{ old('omni_queue_id') == $queue->id ? 'selected' : '' }}>{{ $queue->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Body <span class="required-star">*</span></label>
                    <textarea name="body" id="create-body" class="form-control" rows="4" maxlength="1600"
                              oninput="SmsTemplates.updateCounter('create-body','create-counter')"
                              placeholder="Message text — editable by the agent before sending (P2P)">{{ old('body') }}</textarea>
                    <div id="create-counter" class="form-hint"></div>
                    @error('body') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>

{{-- Edit Modal --}}
<div id="modal-edit" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-pencil modal-icon"></i> Edit SMS Template</h3>
            <button class="modal-close" onclick="closeModal('modal-edit')">&times;</button>
        </div>
        <form method="POST" id="edit-form" action="">
            @csrf @method('PUT')
            <div class="modal-body">
                <div style="margin-bottom:14px;">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" id="edit-name" class="form-control">
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Queue <span style="font-weight:400;">(optional)</span></label>
                    <select name="omni_queue_id" id="edit-queue" class="form-control">
                        <option value="">— Any queue —</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->id }}">{{ $queue->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Body <span class="required-star">*</span></label>
                    <textarea name="body" id="edit-body" class="form-control" rows="6" maxlength="1600"
                              oninput="SmsTemplates.updateCounter('edit-body','edit-counter')"></textarea>
                    <div id="edit-counter" class="form-hint"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Update</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.SmsTemplatesConfig = {
    csrf:       '{{ csrf_token() }}',
    hasErrors:  {{ $errors->any() ? 'true' : 'false' }},
    updateBase: '{{ url('sms-templates') }}',
};
</script>
<script src="{{ asset('js/sms-segment-counter.js') }}"></script>
<script src="{{ asset('js/sms-templates.js') }}"></script>
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
