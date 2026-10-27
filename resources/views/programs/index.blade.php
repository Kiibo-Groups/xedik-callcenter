@extends('layouts.app')

@section('title', 'Programs')
@section('page-title', 'Programs')
@section('page-subtitle', $programs->count() . ' programs configured')

@push('styles')
<style>
.prog-name    { font-weight: 600; color: var(--text-strong); }
.prog-slug    { font-size: 11px; background: var(--bg-th); padding: 2px 8px; border-radius: 4px; color: var(--text-mid); }
.prog-actions { display: flex; gap: 6px; }
.prog-empty   { text-align: center; color: var(--text-muted); padding: 32px; }
.prog-empty i { font-size: 24px; display: block; margin-bottom: 8px; }
</style>
@endpush

@section('content')

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-building icon-primary"></i> Programs</span>
        <button class="btn btn-primary" onclick="openModal('createModal')">
            <i class="bi bi-plus-circle-fill"></i> Add Program
        </button>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Campaigns</th>
                    <th>Status</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($programs as $program)
                <tr>
                    <td><div class="prog-name">{{ $program->name }}</div></td>
                    <td><code class="prog-slug">{{ $program->slug }}</code></td>
                    <td><span class="prog-name">{{ $program->campaigns_count }}</span></td>
                    <td>
                        <span class="badge {{ $program->is_active ? 'badge-success' : 'badge-danger' }}">
                            <i class="bi {{ $program->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $program->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $program->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="prog-actions">
                            <button class="btn btn-outline btn-xs edit-program-btn"
                                data-id="{{ $program->id }}"
                                data-name="{{ $program->name }}"
                                data-slug="{{ $program->slug }}"
                                data-tenant-id="{{ $program->tenant_id }}"
                                data-is-active="{{ $program->is_active ? '1' : '0' }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('programs.destroy', $program) }}" class="delete-form">
                                @csrf @method('DELETE')
                                <button type="button" class="btn btn-danger btn-xs btn-delete"
                                    data-name="{{ $program->name }}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $isSuperAdmin ? 6 : 5 }}" class="prog-empty">
                        <i class="bi bi-building"></i>
                        No programs yet. Create your first one.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ── CREATE MODAL ── --}}
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-building icon-primary-mr"></i> Add New Program</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('programs.store') }}" id="createForm">
            @csrf
            <div class="modal-body">
                @if($isSuperAdmin)
                <div class="form-group">
                    <label class="form-label">Tenant <span style="color:#dc2626;">*</span></label>
                    <select name="tenant_id" id="createTenantId" class="form-control" required>
                        <option value="">Select Tenant...</option>
                        @foreach($tenants as $t)
                            <option value="{{ $t->id }}" {{ old('tenant_id') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                        @endforeach
                    </select>
                    @error('tenant_id')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>
                @endif
                <div class="form-group">
                    <label class="form-label">Name *</label>
                    <input type="text" name="name" id="createName" class="form-control" required
                           placeholder="e.g. Sales Program" value="{{ old('name') }}">
                    @error('name')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Slug <span class="form-hint">(auto-generated)</span></label>
                    <input type="text" id="createSlugPreview" class="form-control input-readonly" readonly
                           placeholder="auto-generated">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="createSubmitBtn">
                    <i class="bi bi-save"></i> Create Program
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil icon-primary-mr"></i> Edit Program</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                @if($isSuperAdmin)
                <div class="form-group">
                    <label class="form-label">Tenant <span style="color:#dc2626;">*</span></label>
                    <select name="tenant_id" id="editTenantId" class="form-control" required>
                        <option value="">Select Tenant...</option>
                        @foreach($tenants as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                    @error('tenant_id')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>
                @endif
                <div class="form-group">
                    <label class="form-label">Name *</label>
                    <input type="text" name="name" id="editName" class="form-control" required>
                    @error('name')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Slug <span class="form-hint">(auto-generated)</span></label>
                    <input type="text" id="editSlugPreview" class="form-control input-readonly" readonly>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label check-label">
                        <input type="checkbox" name="is_active" id="editIsActive" value="1">
                        Active
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" id="editSubmitBtn" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.ProgramsConfig = {
    hasErrors : {{ $errors->any() ? 'true' : 'false' }},
    oldMethod : @json(old('_method', '')),
    oldName   : @json(old('name', '')),
};
</script>
<script src="{{ asset('js/programs.js') }}"></script>
@endpush
