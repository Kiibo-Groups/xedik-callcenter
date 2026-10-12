@extends('layouts.app')

@section('title', 'Lead Statuses')
@section('page-title', 'Lead Status Catalog')
@section('page-subtitle', 'Manage pipeline stages')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-circle-fill"></i> Add Status
    </button>
@endsection

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-tags-fill"></i></div>
        <div><div class="stat-label">Total Statuses</div><div class="stat-value">{{ $statuses->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Active</div><div class="stat-value">{{ $statuses->where('is_active',true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Leads Assigned</div><div class="stat-value">{{ $statuses->sum('leads_count') }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-tags icon-primary"></i> Pipeline Statuses
        </span>
        <span class="cell-muted">
            <i class="bi bi-info-circle"></i> Drag rows to reorder
        </span>
    </div>

    <div class="table-wrapper">
        <table id="statusTable">
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Slug</th>
                    <th>Preview</th>
                    <th>Description</th>
                    <th>Leads</th>
                    <th>Default</th>
                    <th>Active</th>
                    <th>Pipeline</th>
                    <th>Won</th>
                    <th>Lost</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody id="sortableBody">
                @foreach($statuses as $status)
                <tr data-id="{{ $status->id }}">
                    <td class="drag-handle">
                        <i class="bi bi-grip-vertical" style="font-size:16px"></i>
                    </td>
                    <td>
                        <span class="cell-code" data-role="order-value">{{ $status->order }}</span>
                    </td>
                    <td>
                        <div class="cell-strong">{{ $status->name }}</div>
                    </td>
                    <td>
                        <code class="cell-code">{{ $status->slug }}</code>
                    </td>
                    <td>
                        <span class="badge" style="background:{{ $status->bg_color }};color:{{ $status->color }}">
                            {{ $status->name }}
                        </span>
                    </td>
                    <td class="cell-muted" style="max-width:200px;font-size:12px">
                        {{ $status->description ?: '—' }}
                    </td>
                    <td>
                        <span class="cell-strong">{{ $status->leads_count }}</span>
                        @if($status->leads_count > 0)
                            <span class="cell-muted"> lead{{ $status->leads_count != 1 ? 's' : '' }}</span>
                        @endif
                    </td>
                    <td>
                        @if($status->is_default)
                            <span class="badge badge-primary">
                                <i class="bi bi-star-fill badge-icon"></i> Default
                            </span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $status->is_active ? 'badge-success' : 'badge-danger' }} toggle-status"
                              data-status-id="{{ $status->id }}"
                              style="cursor:pointer" title="Click to toggle">
                            {{ $status->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        @if($status->show_in_pipeline)
                            <span class="badge badge-success"><i class="bi bi-check-circle-fill badge-icon"></i> Yes</span>
                        @else
                            <span class="badge badge-secondary"><i class="bi bi-eye-slash badge-icon"></i> No</span>
                        @endif
                    </td>
                    <td>
                        @if($status->is_won)
                            <span class="badge badge-success"><i class="bi bi-trophy-fill badge-icon"></i> Won</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($status->is_lost)
                            <span class="badge badge-danger"><i class="bi bi-x-circle-fill badge-icon"></i> Lost</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $status->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs edit-status-btn"
                                data-id="{{ $status->id }}"
                                data-name="{{ $status->name }}"
                                data-color="{{ $status->color }}"
                                data-bg="{{ $status->bg_color }}"
                                data-description="{{ $status->description }}"
                                data-default="{{ $status->is_default ? '1' : '0' }}"
                                data-show-in-pipeline="{{ $status->show_in_pipeline ? '1' : '0' }}"
                                data-won="{{ $status->is_won ? '1' : '0' }}"
                                data-lost="{{ $status->is_lost ? '1' : '0' }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            @if($status->leads_count === 0)
                            <form method="POST" action="{{ route('statuses.destroy', $status) }}"
                                  class="delete-form" data-name="{{ $status->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @else
                            <button class="btn btn-xs btn-disabled" title="Cannot delete — has leads">
                                <i class="bi bi-trash"></i>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- COLOR PREVIEW TIP --}}
<div class="tip-box">
    <i class="bi bi-lightbulb tip-icon"></i>
    <div>
        <strong>Tips:</strong>
        The <strong>Slug</strong> is auto-generated from the name and is used internally — you can't change it after creation.
        The <strong>Default</strong> status is assigned automatically to new leads.
        You <strong>cannot delete</strong> a status that has leads — reassign them first.
        Drag the <i class="bi bi-grip-vertical"></i> handle to reorder how statuses appear in dropdowns.
    </div>
</div>

{{-- ── CREATE MODAL ── --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add New Status</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('statuses.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Status Name *</label>
                    <input type="text" name="name" class="form-control" required
                           placeholder="e.g. Hot Lead, Follow Up, Demo Scheduled"
                           id="createName">
                    <div class="form-hint">
                        Slug will be auto-generated: <code id="slugPreview" style="background:var(--bg-th);padding:1px 5px;border-radius:3px">—</code>
                    </div>
                </div>

                <div class="form-row cols-2 mb-16">
                    <div class="form-group mb-0">
                        <label class="form-label">Text Color *</label>
                        <div class="color-row">
                            <input type="color" name="color" id="createColor" value="#4f46e5" class="color-picker">
                            <input type="text" id="createColorHex" value="#4f46e5" class="form-control color-hex">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Background Color *</label>
                        <div class="color-row">
                            <input type="color" name="bg_color" id="createBg" value="#e0e7ff" class="color-picker">
                            <input type="text" id="createBgHex" value="#e0e7ff" class="form-control color-hex">
                        </div>
                    </div>
                </div>

                {{-- LIVE PREVIEW --}}
                <div class="preview-box mb-16">
                    <span class="cell-muted">Preview:</span>
                    <span class="badge" id="createPreview" style="background:#e0e7ff;color:#4f46e5">New Status</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control"
                           placeholder="Brief description of this stage (optional)">
                </div>

                <label class="agent-label mb-12" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="is_default" value="1" id="createDefault" class="check-row">
                    <span>Set as default status for new leads</span>
                </label>

                <label class="agent-label mb-12" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="show_in_pipeline" value="1" id="createShowInPipeline" class="check-row" checked>
                    <span>Show as a column in the Pipeline</span>
                </label>

                <label class="agent-label mb-12" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="is_won" value="1" id="createIsWon" class="check-row">
                    <span>Mark as Won (counts in Pipeline summary)</span>
                </label>

                <label class="agent-label" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="is_lost" value="1" id="createIsLost" class="check-row">
                    <span>Mark as Lost (counts in Pipeline summary)</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Status</button>
            </div>
        </form>
    </div>
</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Status</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Status Name *</label>
                    <input type="text" name="name" id="editName" class="form-control" required>
                </div>

                <div class="form-row cols-2 mb-16">
                    <div class="form-group mb-0">
                        <label class="form-label">Text Color *</label>
                        <div class="color-row">
                            <input type="color" name="color" id="editColor" class="color-picker">
                            <input type="text" id="editColorHex" class="form-control color-hex">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Background Color *</label>
                        <div class="color-row">
                            <input type="color" name="bg_color" id="editBg" class="color-picker">
                            <input type="text" id="editBgHex" class="form-control color-hex">
                        </div>
                    </div>
                </div>

                <div class="preview-box mb-16">
                    <span class="cell-muted">Preview:</span>
                    <span class="badge" id="editPreview">Status</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" id="editDescription" class="form-control"
                           placeholder="Brief description (optional)">
                </div>

                <label class="agent-label mb-12" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="is_default" value="1" id="editDefault" class="check-row">
                    <span>Set as default status for new leads</span>
                </label>

                <label class="agent-label mb-12" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="show_in_pipeline" value="1" id="editShowInPipeline" class="check-row">
                    <span>Show as a column in the Pipeline</span>
                </label>

                <label class="agent-label mb-12" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="is_won" value="1" id="editIsWon" class="check-row">
                    <span>Mark as Won (counts in Pipeline summary)</span>
                </label>

                <label class="agent-label" style="border:none;padding:0;gap:8px;">
                    <input type="checkbox" name="is_lost" value="1" id="editIsLost" class="check-row">
                    <span>Mark as Lost (counts in Pipeline summary)</span>
                </label>
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
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
window.StatusesConfig = {
    hasErrors:  @json($errors->any()),
    reorderUrl: '{{ route("statuses.reorder") }}'
};
</script>
<script src="{{ asset('js/statuses.js') }}"></script>
@endpush
