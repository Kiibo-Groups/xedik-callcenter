@extends('layouts.app')

@section('title', 'Lead Sources')
@section('page-title', 'Lead Sources')
@section('page-subtitle', 'Manage lead origin sources')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-circle-fill"></i> Add Source
    </button>
@endsection

@section('content')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-diagram-3-fill"></i></div>
        <div><div class="stat-label">Total Sources</div><div class="stat-value">{{ $sources->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Active</div><div class="stat-value">{{ $sources->where('is_active',true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Leads</div><div class="stat-value">{{ $sources->sum('leads_count') }}</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-diagram-3 icon-primary"></i> Sources</span>
        <span class="cell-muted"><i class="bi bi-info-circle"></i> Drag to reorder</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th>Source Name</th>
                    <th>Leads</th>
                    <th>Active</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody id="sortableBody">
                @foreach($sources as $source)
                <tr data-id="{{ $source->id }}">
                    <td class="drag-handle">
                        <i class="bi bi-grip-vertical" style="font-size:16px"></i>
                    </td>
                    <td>
                        <span class="cell-strong">{{ $source->name }}</span>
                    </td>
                    <td>
                        <span class="cell-strong">{{ $source->leads_count }}</span>
                        @if($source->leads_count > 0)
                            <span class="cell-muted"> lead{{ $source->leads_count != 1 ? 's' : '' }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $source->is_active ? 'badge-success' : 'badge-danger' }} toggle-source"
                              data-source-id="{{ $source->id }}" style="cursor:pointer">
                            {{ $source->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $source->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs edit-source-btn"
                                    data-id="{{ $source->id }}"
                                    data-name="{{ $source->name }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            @if($source->leads_count === 0)
                                <form method="POST" action="{{ route('sources.destroy', $source) }}"
                                      class="delete-form" data-name="{{ $source->name }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-xs">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @else
                                <button class="btn btn-xs btn-disabled" title="Has leads assigned">
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

{{-- CREATE MODAL --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Source</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('sources.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group mb-0">
                    <label class="form-label">Source Name *</label>
                    <input type="text" name="name" class="form-control" required
                           placeholder="e.g. LinkedIn, Trade Show, Cold Call">
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
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Source</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group mb-0">
                    <label class="form-label">Source Name *</label>
                    <input type="text" name="name" id="editName" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
window.SourcesConfig = {
    hasErrors:  @json($errors->any()),
    reorderUrl: '{{ route("sources.reorder") }}'
};
</script>
<script src="{{ asset('js/sources.js') }}"></script>
@endpush
