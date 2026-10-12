@extends('layouts.app')

@section('title', 'Voice — Extension Ranges')
@section('page-title', 'Voice Administration')
@section('page-subtitle', 'Extension number ranges per tenant')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-lg"></i> Add Range
    </button>
@endsection

@section('content')

@include('admin.voice._nav')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-123"></i></div>
        <div><div class="stat-label">Total Ranges</div><div class="stat-value">{{ $ranges->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Active Ranges</div><div class="stat-value">{{ $ranges->where('is_active', true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-headset"></i></div>
        <div><div class="stat-label">Total Capacity</div><div class="stat-value">{{ $ranges->where('is_active', true)->sum('capacity') }}</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-123 icon-primary"></i> Extension Ranges</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Range</th>
                    <th>Label</th>
                    <th>Capacity</th>
                    <th>Assigned</th>
                    <th title="Released or deprovision_failed — number blocked until Free Extension is run">Cleanup Pending</th>
                    <th>Available</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($ranges as $range)
                @php
                    $assigned = $range->assignedCount();
                    $cleanup  = $range->pendingCleanupCount();
                    $available = $range->availableCount();
                @endphp
                <tr>
                    <td class="cell-strong">{{ $range->tenant?->name ?? '—' }}</td>
                    <td><code class="cell-code">{{ $range->range_start }} – {{ $range->range_end }}</code></td>
                    <td>{{ $range->label ?: '—' }}</td>
                    <td class="cell-muted">{{ $range->capacity() }}</td>
                    <td>
                        <span class="{{ $assigned > 0 ? 'cell-strong' : 'cell-muted' }}">{{ $assigned }}</span>
                    </td>
                    <td>
                        @if($cleanup > 0)
                            <span class="badge badge-warning" title="Released numbers blocked until Free Extension action">{{ $cleanup }}</span>
                        @else
                            <span class="cell-muted">0</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $available > 0 ? 'badge-success' : 'badge-danger' }}">{{ $available }}</span>
                    </td>
                    <td>
                        <button class="toggle-btn {{ $range->is_active ? 'active' : '' }}"
                            onclick="toggleRange({{ $range->id }}, this)"
                            title="{{ $range->is_active ? 'Deactivate' : 'Activate' }}">
                            <span></span>
                        </button>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs"
                                onclick="openEdit({{ $range->id }}, {{ $range->range_start }}, {{ $range->range_end }}, '{{ addslashes($range->label ?? '') }}')">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('admin.voice.extension-ranges.destroy', $range) }}"
                                  class="delete-form" data-name="range {{ $range->range_start }}–{{ $range->range_end }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No ranges defined yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- CREATE MODAL --}}
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Extension Range</span>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.voice.extension-ranges.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tenant <span class="required-star">*</span></label>
                    <select class="form-control" name="tenant_id" required>
                        <option value="">Select tenant…</option>
                        @foreach($tenants as $t)
                        <option value="{{ $t->id }}" {{ old('tenant_id') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Start <span class="required-star">*</span></label>
                        <input type="number" class="form-control" name="range_start" min="100" max="9999"
                               value="{{ old('range_start', 3000) }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End <span class="required-star">*</span></label>
                        <input type="number" class="form-control" name="range_end" min="100" max="9999"
                               value="{{ old('range_end', 3099) }}" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Label <span class="cell-muted">(optional)</span></label>
                    <input type="text" class="form-control" name="label" maxlength="120"
                           placeholder="e.g. Agents Floor 1" value="{{ old('label') }}">
                </div>
                <div class="tip-box">
                    <i class="bi bi-info-circle-fill tip-icon"></i>
                    <div>Ranges from different tenants may overlap — isolation is enforced by Asterisk context, not by range number.
                         Ranges within the <strong>same tenant</strong> may not overlap.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Range</button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Range</span>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm" action="">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Start <span class="required-star">*</span></label>
                        <input type="number" class="form-control" name="range_start" id="edit_start" min="100" max="9999" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End <span class="required-star">*</span></label>
                        <input type="number" class="form-control" name="range_end" id="edit_end" min="100" max="9999" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Label</label>
                    <input type="text" class="form-control" name="label" id="edit_label" maxlength="120">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/voice-admin.js') }}"></script>
<script>
window.VoiceAdminConfig = {
    rangeToggleUrl: '/admin/voice/extension-ranges/{id}/toggle',
    rangeUpdateUrl: '/admin/voice/extension-ranges/{id}',
    csrf: '{{ csrf_token() }}'
};
</script>
@endpush

@endsection
