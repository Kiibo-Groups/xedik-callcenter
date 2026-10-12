@extends('layouts.app')

@section('title', 'WhatsApp Templates')
@section('page-title', 'WhatsApp Templates')
@section('page-subtitle', 'Manage approved message templates sent via WhatsApp')

@section('header-actions')
<button class="btn btn-primary btn-sm" onclick="WaTemplates.openCreate()">
    <i class="bi bi-plus-lg"></i> New Template
</button>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-whatsapp icon-primary"></i> WhatsApp Templates</span>
    </div>
    <div class="table-wrapper">
        @if($templates->count())
        <table>
            <thead>
                <tr>
                    <th>Display Name</th>
                    <th>Template Name</th>
                    <th>Language</th>
                    <th>Body Preview</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($templates as $tpl)
                <tr>
                    <td class="cell-strong">{{ $tpl->display_name }}</td>
                    <td class="cell-code">{{ $tpl->name }}</td>
                    <td>
                        <span class="badge" style="background:#e0e7ff;color:#3730a3;">{{ strtoupper($tpl->language) }}</span>
                    </td>
                    <td style="font-size:13px;color:var(--text-muted);max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ Str::limit($tpl->body_preview ?? '—', 60) }}</td>
                    @if($isSuperAdmin)
                    <td class="cell-muted">{{ $tpl->tenant?->name ?? '—' }}</td>
                    @endif
                    <td>
                        <span class="badge {{ $tpl->is_active ? 'badge-success' : 'badge-danger' }} tpl-toggle"
                              data-id="{{ $tpl->id }}"
                              data-url="{{ route('whatsapp-templates.toggle', $tpl) }}"
                              style="cursor:pointer;">
                            <i class="bi {{ $tpl->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $tpl->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <button class="btn btn-outline btn-xs"
                                    onclick="WaTemplates.openEdit({{ $tpl->id }}, {{ json_encode($tpl->name) }}, {{ json_encode($tpl->display_name) }}, {{ json_encode($tpl->language) }}, {{ json_encode($tpl->body_preview ?? '') }})">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('whatsapp-templates.destroy', $tpl) }}"
                                  class="delete-form" data-name="{{ $tpl->display_name }}" data-entity="template"
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
            <i class="bi bi-whatsapp"></i>
            <h3>No templates yet</h3>
            <p>Create your first WhatsApp template to start sending messages.</p>
        </div>
        @endif
    </div>
</div>

{{-- Create Modal --}}
<div id="modal-create" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-plus-lg modal-icon"></i> New WhatsApp Template</h3>
            <button class="modal-close" onclick="closeModal('modal-create')">&times;</button>
        </div>
        <form method="POST" action="{{ route('whatsapp-templates.store') }}">
            @csrf
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="form-label">Display Name <span class="required-star">*</span></label>
                        <input type="text" name="display_name" class="form-control"
                               value="{{ old('display_name') }}"
                               placeholder="e.g. Welcome Message">
                        @error('display_name') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="form-label">Template Name <span class="required-star">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="{{ old('name') }}"
                               placeholder="e.g. welcome_message">
                        @error('name') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Language <span class="required-star">*</span></label>
                    <select name="language" class="form-control" style="max-width:180px;">
                        <option value="en" {{ old('language', 'en') === 'en' ? 'selected' : '' }}>English (en)</option>
                        <option value="es" {{ old('language', 'en') === 'es' ? 'selected' : '' }}>Spanish (es)</option>
                    </select>
                    @error('language') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">Body Preview</label>
                    <textarea name="body_preview" class="form-control" rows="4"
                              placeholder="Text that the agent will see as a preview...">{{ old('body_preview') }}</textarea>
                    @error('body_preview') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
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
            <h3><i class="bi bi-pencil modal-icon"></i> Edit WhatsApp Template</h3>
            <button class="modal-close" onclick="closeModal('modal-edit')">&times;</button>
        </div>
        <form method="POST" id="edit-form" action="">
            @csrf @method('PUT')
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="form-label">Display Name <span class="required-star">*</span></label>
                        <input type="text" name="display_name" id="edit-display-name" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Template Name <span class="required-star">*</span></label>
                        <input type="text" name="name" id="edit-name" class="form-control">
                    </div>
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Language <span class="required-star">*</span></label>
                    <select name="language" id="edit-language" class="form-control" style="max-width:180px;">
                        <option value="en">English (en)</option>
                        <option value="es">Spanish (es)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Body Preview</label>
                    <textarea name="body_preview" id="edit-body-preview" class="form-control" rows="6"></textarea>
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
window.WaTemplatesConfig = {
    csrf:       '{{ csrf_token() }}',
    hasErrors:  {{ $errors->any() ? 'true' : 'false' }},
    updateBase: '{{ url('whatsapp-templates') }}',
};
</script>
<script src="{{ asset('js/whatsapp-templates.js') }}"></script>
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
