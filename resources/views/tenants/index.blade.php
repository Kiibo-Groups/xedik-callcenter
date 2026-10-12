@extends('layouts.app')

@section('title', 'Tenants')
@section('page-title', 'Tenant Management')
@section('page-subtitle', $tenants->count() . ' tenants registered')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-lg"></i> Add Tenant
    </button>
@endsection

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-building"></i></div>
        <div><div class="stat-label">Total Tenants</div><div class="stat-value">{{ $tenants->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-building-check"></i></div>
        <div><div class="stat-label">Active</div><div class="stat-value">{{ $tenants->where('is_active', true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Users</div><div class="stat-value">{{ $tenants->sum('users_count') }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-building icon-primary"></i> Tenants</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Slug</th>
                    <th>Status</th>
                    <th>Users</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($tenants as $tenant)
                <tr>
                    <td><span class="cell-strong">{{ $tenant->name }}</span></td>
                    <td><code class="cell-code">{{ $tenant->slug }}</code></td>
                    <td>
                        <span class="badge {{ $tenant->is_active ? 'badge-success' : 'badge-danger' }}">
                            <i class="bi {{ $tenant->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $tenant->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td><span class="cell-strong">{{ $tenant->users_count }}</span></td>
                    <td class="cell-muted">{{ $tenant->created_at->format('M d, Y') }}</td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs edit-tenant-btn"
                                data-id="{{ $tenant->id }}"
                                data-name="{{ $tenant->name }}"
                                data-slug="{{ $tenant->slug }}"
                                data-is-active="{{ $tenant->is_active ? '1' : '0' }}"
                                data-users-count="{{ $tenant->users_count }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <a href="{{ route('tenants.permissions', $tenant) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-shield-lock"></i> Permissions
                            </a>
                            @if($tenant->users_count === 0)
                            <form method="POST" action="{{ route('tenants.destroy', $tenant) }}"
                                  class="delete-form" data-name="{{ $tenant->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @else
                            <button class="btn btn-xs btn-disabled" disabled title="{{ $tenant->users_count }} users assigned">
                                <i class="bi bi-trash"></i>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">
                        <i class="bi bi-building" style="font-size:24px;display:block;margin-bottom:8px"></i>
                        No tenants yet. Create your first tenant.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- CREATE MODAL --}}
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-building modal-icon"></i> Add New Tenant</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('tenants.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tenant Name <span class="required-star">*</span></label>
                    <input type="text" name="name" id="createName" class="form-control" required
                           value="{{ old('name') }}" placeholder="Acme Corp">
                </div>
                <div class="form-group">
                    <label class="form-label">Slug <span class="required-star">*</span></label>
                    <input type="text" name="slug" id="createSlug" class="form-control" required
                           value="{{ old('slug') }}" placeholder="acme-corp"
                           pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only">
                    <div class="form-hint">Auto-generated from name. Lowercase letters, numbers and hyphens only.</div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Permissions granted to this tenant</label>
                    <div class="form-hint" style="margin-bottom:8px;">Only what you select here will be available for this tenant's admin to build roles with. Nothing is granted by default.</div>
                    @foreach($permissions as $module => $perms)
                    <div style="margin-bottom:10px;">
                        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
                            {{ ucfirst($module) }}
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            @foreach($perms as $perm)
                            <label style="display:flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:12px;">
                                <input type="checkbox" name="permission_ids[]" value="{{ $perm->id }}">
                                <i class="bi {{ $perm->icon }}"></i> {{ $perm->label }}
                            </label>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Tenant</button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Tenant</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tenant Name <span class="required-star">*</span></label>
                    <input type="text" name="name" id="editName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Slug <span class="required-star">*</span></label>
                    <input type="text" name="slug" id="editSlug" class="form-control" required
                           pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only">
                    <div class="form-hint">Lowercase letters, numbers and hyphens only.</div>
                </div>
                <div class="form-group mb-0">
                    <div class="check-row">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="editIsActive" value="1" class="form-check">
                        <label for="editIsActive" class="form-label mb-0">Active</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.TenantsConfig = {
    hasErrors:    @json($errors->any()),
    isEditMethod: @json(old('_method') === 'PUT'),
    old: {
        name:     @json(old('name', '')),
        slug:     @json(old('slug', '')),
        isActive: @json(old('is_active', '1')),
    }
};
</script>
<script src="{{ asset('js/tenants.js') }}"></script>
@endpush
