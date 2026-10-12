@extends('layouts.app')

@section('title', 'Lead Lists')
@section('page-title', 'Lead Lists')
@section('page-subtitle', 'Contact lists for the Power Dialer')

@section('content')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-list-ul"></i></div>
        <div><div class="stat-label">Total Lists</div><div class="stat-value">{{ $lists->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Ready</div><div class="stat-value">{{ $lists->where('status','ready')->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Leads</div><div class="stat-value">{{ number_format($lists->sum('total_leads')) }}</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-list-ul icon-primary"></i> Lead Lists</span>
        <button class="btn btn-primary" onclick="openModal('createModal')">
            <i class="bi bi-plus-circle-fill"></i> New Lead List
        </button>
    </div>
    <div class="table-wrapper">
        @if($lists->count())
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Leads Table</th>
                    <th>Log Table</th>
                    <th>Total Leads</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($lists as $list)
                <tr>
                    <td><div class="cell-strong">{{ $list->name }}</div></td>
                    <td><code class="cell-code">{{ $list->table_name }}</code></td>
                    <td><code class="cell-code">{{ $list->log_table_name }}</code></td>
                    <td>{{ number_format($list->total_leads) }}</td>
                    <td>
                        @php
                            $badge = match($list->status) {
                                'ready'    => ['class' => 'badge-success', 'icon' => 'bi-check-circle-fill', 'label' => 'Ready'],
                                'archived' => ['class' => 'badge-secondary', 'icon' => 'bi-archive-fill', 'label' => 'Archived'],
                                default    => ['class' => 'badge-warning', 'icon' => 'bi-pencil-fill', 'label' => 'Draft'],
                            };
                        @endphp
                        <span class="badge {{ $badge['class'] }}">
                            <i class="bi {{ $badge['icon'] }} badge-icon"></i> {{ $badge['label'] }}
                        </span>
                    </td>
                    <td class="cell-muted">{{ $list->created_at->format('m/d/Y') }}</td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('lead-lists.leads', $list) }}" class="btn btn-outline btn-xs" title="View Leads">
                                <i class="bi bi-eye"></i> View
                            </a>
                            @if($list->status !== 'archived')
                                <button class="btn btn-outline btn-xs" onclick="openImport({{ $list->id }}, '{{ addslashes($list->name) }}')" title="Import CSV">
                                    <i class="bi bi-upload"></i> Import
                                </button>
                                @if($list->total_leads > 0)
                                <a href="{{ route('lead-lists.export', $list) }}" class="btn btn-outline btn-xs" title="Export CSV">
                                    <i class="bi bi-download"></i> Export
                                </a>
                                @endif
                                <button class="btn btn-outline btn-xs" onclick="archiveList({{ $list->id }}, '{{ addslashes($list->name) }}')" title="Archive">
                                    <i class="bi bi-archive"></i>
                                </button>
                            @else
                                <form method="POST" action="{{ route('lead-lists.unarchive', $list) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-outline btn-xs" style="color:#0891b2;border-color:#0891b2;" title="Restore">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('lead-lists.destroy', $list) }}" class="delete-form" data-name="{{ $list->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-list-ul"></i>
            <h3>No lead lists yet</h3>
            <p>Create the first list to start importing contacts for the dialer.</p>
        </div>
        @endif
    </div>
</div>

{{-- Create Modal --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-list-ul modal-icon"></i> New Lead List</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('lead-lists.store') }}">
            @csrf
            <div class="modal-body">
                @if($isSuperAdmin)
                <div class="form-group">
                    <label class="form-label">Tenant *</label>
                    <select name="tenant_id" class="form-control" required>
                        <option value="">— Select Tenant —</option>
                        @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}" {{ old('tenant_id') == $tenant->id ? 'selected' : '' }}>
                            {{ $tenant->name }}
                        </option>
                        @endforeach
                    </select>
                    @error('tenant_id')
                        <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                    @enderror
                </div>
                @endif
                <div class="form-group">
                    <label class="form-label">Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. North Portfolio May 2026" required autofocus value="{{ old('name') }}">
                    @error('name')
                        <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create</button>
            </div>
        </form>
    </div>
</div>

{{-- Import CSV Modal --}}
<div class="modal-overlay" id="importModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-upload modal-icon"></i> Import CSV — <span id="importListName" style="color:var(--primary);"></span></div>
            <button class="modal-close" onclick="closeModal('importModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="tip-box" style="margin-bottom:20px;">
                <i class="bi bi-info-circle tip-icon"></i>
                <div>
                    Required column: <code>phone</code> (also: <code>telefono</code>, <code>numero</code>).
                    All other columns are stored as custom fields. DNC numbers and duplicates are skipped automatically.
                </div>
            </div>

            {{-- File picker estilizado --}}
            <div class="form-group">
                <label class="form-label">CSV File *</label>
                <label for="csvFile" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border:2px dashed var(--border);border-radius:var(--radius-sm);cursor:pointer;background:var(--bg-th);transition:border-color .15s;" id="csvDropZone">
                    <i class="bi bi-file-earmark-spreadsheet" style="font-size:28px;color:var(--primary);flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:13px;font-weight:500;color:var(--text-strong);" id="csvFileName">Click to select a CSV file</div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Max 50 MB · .csv or .txt</div>
                    </div>
                    <input type="file" id="csvFile" accept=".csv,.txt" style="display:none;">
                </label>
            </div>

            {{-- Progress --}}
            <div id="importProgress" style="display:none;margin-top:4px;">
                <div style="height:8px;background:var(--bg-stripe);border-radius:4px;overflow:hidden;">
                    <div id="importBar" style="height:100%;background:var(--primary);width:0%;transition:width .4s ease;"></div>
                </div>
                <div id="importStatus" style="margin-top:8px;font-size:12px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('importModal')">Cancel</button>
            <button type="button" class="btn btn-primary" id="importBtn" onclick="submitImport()">
                <i class="bi bi-upload"></i> Import
            </button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.LeadListsConfig = {
    routes: {
        import: '{{ url("lead-lists") }}',
        archive: '{{ url("lead-lists") }}',
    },
    csrf: '{{ csrf_token() }}',
    hasErrors: @json($errors->any()),
};
</script>
<script src="{{ asset('js/lead-lists.js') }}"></script>
@endpush
