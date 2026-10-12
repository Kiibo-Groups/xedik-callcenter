@extends('layouts.app')

@section('title', 'Pause Reasons')
@section('page-title', 'Pause Reasons')
@section('page-subtitle', 'Manage agent pause reasons and time limits')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-circle-fill"></i> Add Reason
    </button>
@endsection

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-pause-circle-fill"></i></div>
        <div><div class="stat-label">Total Reasons</div><div class="stat-value">{{ $reasons->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Active</div><div class="stat-value">{{ $reasons->where('is_active',true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-clock-fill"></i></div>
        <div><div class="stat-label">With Time Limit</div><div class="stat-value">{{ $reasons->whereNotNull('max_minutes')->count() }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-pause-circle icon-primary"></i> Pause Reasons
        </span>
        <span class="cell-muted">
            <i class="bi bi-info-circle"></i> Drag rows to reorder
        </span>
    </div>

    <div class="table-wrapper">
        <table id="reasonsTable">
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th>Name</th>
                    <th>Preview</th>
                    <th>Max Time</th>
                    <th>Active</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody id="sortableBody">
                @foreach($reasons as $reason)
                <tr data-id="{{ $reason->id }}">
                    <td class="drag-handle">
                        <i class="bi bi-grip-vertical" style="font-size:16px"></i>
                    </td>
                    <td>
                        <div class="cell-strong">{{ $reason->name }}</div>
                    </td>
                    <td>
                        <span class="badge" style="background:{{ $reason->color }}20;color:{{ $reason->color }};border:1px solid {{ $reason->color }}40;">
                            {{ $reason->name }}
                        </span>
                    </td>
                    <td>
                        @if($reason->max_minutes)
                            <span class="cell-strong">{{ $reason->max_minutes }} min</span>
                        @else
                            <span class="cell-muted">No limit</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $reason->is_active ? 'badge-success' : 'badge-danger' }} toggle-reason"
                              data-reason-id="{{ $reason->id }}"
                              style="cursor:pointer" title="Click to toggle">
                            {{ $reason->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $reason->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs edit-reason-btn"
                                data-id="{{ $reason->id }}"
                                data-name="{{ $reason->name }}"
                                data-color="{{ $reason->color }}"
                                data-max="{{ $reason->max_minutes }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('pause-reasons.destroy', $reason) }}"
                                  class="delete-form" data-name="{{ $reason->name }}">
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
    </div>
</div>

<div class="tip-box">
    <i class="bi bi-lightbulb tip-icon"></i>
    <div>
        <strong>Tips:</strong>
        Set a <strong>Max Time</strong> to alert agents when they exceed their allowed pause duration — the timer will turn red.
        Leave <strong>Max Time</strong> empty for unlimited pauses like <em>Personal</em>.
        Drag the <i class="bi bi-grip-vertical"></i> handle to reorder how reasons appear to agents.
    </div>
</div>

{{-- CREATE MODAL --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Pause Reason</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('pause-reasons.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" class="form-control" required
                           placeholder="e.g. Lunch, Break 1, Personal"
                           id="createName">
                </div>
                <div class="form-group">
                    <label class="form-label">Color <span class="required-star">*</span></label>
                    <div class="color-row">
                        <input type="color" name="color" id="createColor"
                               value="#f59e0b" class="color-picker">
                        <input type="text" id="createColorHex" value="#f59e0b"
                               class="form-control color-hex">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Time (minutes)</label>
                    <input type="number" name="max_minutes" class="form-control"
                           placeholder="Leave empty for no limit" min="1" max="480">
                    <div class="form-hint">Timer turns red when agent exceeds this limit.</div>
                </div>
                <div class="preview-box">
                    <span class="cell-muted">Preview:</span>
                    <span class="badge" id="createPreview" style="background:#f59e0b20;color:#f59e0b;border:1px solid #f59e0b40;">
                        Pause Reason
                    </span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create</button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Pause Reason</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" id="editName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Color <span class="required-star">*</span></label>
                    <div class="color-row">
                        <input type="color" name="color" id="editColor" class="color-picker">
                        <input type="text" id="editColorHex" class="form-control color-hex">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Max Time (minutes)</label>
                    <input type="number" name="max_minutes" id="editMaxMinutes" class="form-control"
                           placeholder="Leave empty for no limit" min="1" max="480">
                </div>
                <div class="preview-box">
                    <span class="cell-muted">Preview:</span>
                    <span class="badge" id="editPreview" style="border:1px solid transparent;">Reason</span>
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
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
window.PauseReasonsConfig = {
    hasErrors:  @json($errors->any()),
    reorderUrl: '{{ route("pause-reasons.reorder") }}'
};
</script>
<script src="{{ asset('js/pause-reasons.js') }}"></script>
@endpush