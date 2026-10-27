@extends('layouts.app')

@section('title', $leadList->name . ' — Leads')
@section('page-title', $leadList->name)
@section('page-subtitle', number_format($leads->total()) . ' leads · ' . $leadList->table_name)

@section('header-actions')
<div style="display:flex;align-items:center;gap:8px;">
    <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addLeadModal')">
        <i class="bi bi-person-plus-fill"></i> Add Lead
    </button>
    <a href="{{ route('lead-lists.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Lists
    </a>
</div>
@endsection

@section('content')

@php
    $counts = \Illuminate\Support\Facades\DB::table($leadList->table_name)
        ->selectRaw("status, COUNT(*) as total")
        ->groupBy('status')
        ->pluck('total', 'status');

    $dncCount = \Illuminate\Support\Facades\DB::table($leadList->table_name . ' as dl')
        ->join('dialer_dnc as dnc', function($j) {
            $j->on('dnc.phone', '=', 'dl.phone')
              ->where('dnc.tenant_id', '=', auth()->user()->tenant_id);
        })
        ->count();
@endphp

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
        <div>
            <div class="stat-label">Total Leads</div>
            <div class="stat-value">{{ number_format(array_sum($counts->toArray())) }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;color:#1d4ed8;"><i class="bi bi-hourglass-split"></i></div>
        <div>
            <div class="stat-label">New</div>
            <div class="stat-value">{{ number_format($counts['NEW'] ?? 0) }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-telephone-fill"></i></div>
        <div>
            <div class="stat-label">Answered</div>
            <div class="stat-value">{{ number_format($counts['ANSWER'] ?? 0) }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f1f5f9;color:#64748b;"><i class="bi bi-telephone-minus-fill"></i></div>
        <div>
            <div class="stat-label">No Answer</div>
            <div class="stat-value">{{ number_format($counts['NO_ANSWER'] ?? 0) }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef9c3;color:#b45309;"><i class="bi bi-telephone-x-fill"></i></div>
        <div>
            <div class="stat-label">Busy</div>
            <div class="stat-value">{{ number_format($counts['BUSY'] ?? 0) }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div>
            <div class="stat-label">Failed</div>
            <div class="stat-value">{{ number_format($counts['FAILED'] ?? 0) }}</div>
        </div>
    </div>
    @if($dncCount > 0)
    <div class="stat-card">
        <div class="stat-icon" style="background:#fce7f3;color:#9d174d;"><i class="bi bi-slash-circle-fill"></i></div>
        <div>
            <div class="stat-label">Blocked (DNC)</div>
            <div class="stat-value">{{ number_format($dncCount) }}</div>
        </div>
    </div>
    @endif
</div>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
        <span class="card-title" style="flex-shrink:0;">
            <i class="bi bi-table icon-primary"></i> Leads
            @php
                $statusBadge = match($leadList->status) {
                    'ready'    => 'badge-success',
                    'archived' => 'badge-secondary',
                    default    => 'badge-warning',
                };
            @endphp
            <span class="badge {{ $statusBadge }}" style="margin-left:8px;font-weight:500;">{{ ucfirst($leadList->status) }}</span>
            @if($q)
            <span class="cell-muted" style="margin-left:8px;font-size:12px;font-weight:400;">
                {{ number_format($leads->total()) }} result{{ $leads->total() != 1 ? 's' : '' }} for "{{ $q }}"
            </span>
            @endif
        </span>

        {{-- Search --}}
        <form method="GET" action="{{ route('lead-lists.leads', $leadList) }}"
              style="display:flex;align-items:center;gap:6px;">
            <div style="position:relative;">
                <i class="bi bi-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;pointer-events:none;"></i>
                <input type="text" name="q" value="{{ $q }}"
                       placeholder="Search phone, custom fields…"
                       autocomplete="off"
                       style="padding:6px 10px 6px 28px;font-size:12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);color:var(--text-strong);outline:none;width:220px;">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="padding:5px 8px;" title="Search">
                <i class="bi bi-search"></i>
            </button>
            @if($q)
            <a href="{{ route('lead-lists.leads', $leadList) }}"
               class="btn btn-secondary btn-sm" style="padding:5px 8px;" title="Clear">
                <i class="bi bi-x-lg"></i>
            </a>
            @endif
        </form>
    </div>

    <div class="table-wrapper">
        @if($leads->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th style="width:60px;">#</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th style="text-align:center;width:60px;">Calls</th>
                    <th style="width:80px;">Duration</th>
                    <th style="width:110px;">Last Called</th>
                    <th>Custom Fields</th>
                    <th style="width:70px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($leads as $lead)
                @php
                    $custom = $lead->custom_fields ? json_decode($lead->custom_fields, true) : [];
                    $preview = collect($custom)->map(fn($v, $k) => $k . ': ' . $v)->implode(' · ');
                    $statusColor = match($lead->status) {
                        'NEW'       => ['bg'=>'#eff6ff', 'color'=>'#1d4ed8'],
                        'ANSWER'    => ['bg'=>'#dcfce7', 'color'=>'#16a34a'],
                        'NO_ANSWER' => ['bg'=>'#f1f5f9', 'color'=>'#64748b'],
                        'BUSY'      => ['bg'=>'#fef9c3', 'color'=>'#b45309'],
                        'FAILED'    => ['bg'=>'#fee2e2', 'color'=>'#dc2626'],
                        default     => ['bg'=>'#f1f5f9', 'color'=>'#64748b'],
                    };
                    $leadJson = [
                        'id'           => $lead->id,
                        'phone'        => $lead->phone,
                        'status'       => $lead->status,
                        'called_count' => $lead->called_count,
                        'duration'     => $lead->last_duration ? gmdate('i:s', $lead->last_duration) : null,
                        'last_called'  => $lead->last_called_at
                                            ? \Carbon\Carbon::parse($lead->last_called_at)->format('m/d H:i')
                                            : null,
                        'custom'       => $custom,
                    ];
                @endphp
                <tr data-lead='@json($leadJson)' data-lead-id="{{ $lead->id }}">
                    <td class="cell-muted">{{ $lead->id }}</td>
                    <td><code class="cell-code">{{ $lead->phone }}</code></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                            <span class="badge" style="background:{{ $statusColor['bg'] }};color:{{ $statusColor['color'] }};">
                                {{ $lead->status }}
                            </span>
                            @if($lead->is_dnc)
                            <span class="badge" style="background:#fce7f3;color:#9d174d;" title="Do Not Call">
                                <i class="bi bi-slash-circle-fill" style="font-size:9px;margin-right:2px;"></i> DNC
                            </span>
                            @endif
                        </div>
                    </td>
                    <td style="text-align:center;">{{ $lead->called_count }}</td>
                    <td class="cell-muted">
                        @if($lead->last_duration)
                            {{ gmdate('i:s', $lead->last_duration) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="cell-muted">
                        {{ $lead->last_called_at ? \Carbon\Carbon::parse($lead->last_called_at)->format('m/d H:i') : '—' }}
                    </td>
                    <td class="cell-muted cf-preview" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        {{ $preview ?: '—' }}
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <button type="button" class="btn btn-outline btn-xs"
                                    onclick="openLeadModal(this.closest('tr'))" title="View details">
                                <i class="bi bi-eye"></i>
                            </button>
                            <form method="POST" action="{{ route('lead-lists.leads.destroy', [$leadList, $lead->id]) }}" class="delete-lead-form" data-phone="{{ $lead->phone }}" style="margin:0;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs" title="Delete lead"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if($leads->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $leads->links('vendor.pagination.custom') }}
        </div>
        @endif

        @else
        <div class="empty-state">
            <i class="bi {{ $q ? 'bi-search' : 'bi-inbox' }}"></i>
            <h3>{{ $q ? 'No results for "' . $q . '"' : 'No leads imported yet' }}</h3>
            <p>{{ $q ? 'Try a different search term.' : 'Use the Add Lead button or Import CSV to add contacts.' }}</p>
        </div>
        @endif
    </div>
</div>

<div class="tip-box" style="margin-top:16px;">
    <i class="bi bi-info-circle tip-icon"></i>
    <div>
        <strong>Only column required:</strong> <code>phone</code> (also accepted: <code>telefono</code>, <code>numero</code>).
        All other columns in the CSV are stored as <strong>Custom Fields</strong>.
        Click any row to view or edit its custom fields.
        Numbers already on the DNC list are skipped at import time.
    </div>
</div>

{{-- LEAD DETAIL MODAL --}}
<div class="modal-overlay" id="leadDetailModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header" style="padding:14px 20px;">
            <div class="modal-title"><i class="bi bi-person-lines-fill" style="color:var(--primary);margin-right:6px;"></i> Lead Detail</div>
            <button class="modal-close" onclick="closeLeadModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:16px 20px;overflow-y:auto;max-height:60vh;" id="leadDetailBody">
        </div>
        <div class="modal-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="btn btn-secondary" onclick="closeLeadModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="leadDetailSaveBtn" onclick="saveLeadChanges()">
                <i class="bi bi-save"></i> Save
            </button>
        </div>
    </div>
</div>

{{-- ADD LEAD MANUALLY MODAL --}}
<div class="modal-overlay" id="addLeadModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header" style="padding:14px 20px;">
            <div class="modal-title"><i class="bi bi-person-plus-fill" style="color:var(--primary);margin-right:6px;"></i> Add Lead Manually</div>
            <button class="modal-close" onclick="closeModal('addLeadModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('lead-lists.leads.store', $leadList) }}">
            @csrf
            <div class="modal-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:14px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Phone Number <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="phone" class="form-control" placeholder="e.g. +523312345678 or 3312345678" required autofocus style="font-size:13px;">
                    <span style="font-size:11px;color:var(--text-muted);display:block;margin-top:3px;">Validated against DNC and duplicates.</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">First Name</label>
                        <input type="text" name="first_name" class="form-control" placeholder="Juan" style="font-size:13px;">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Last Name</label>
                        <input type="text" name="last_name" class="form-control" placeholder="Pérez" style="font-size:13px;">
                    </div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="juan@ejemplo.com" style="font-size:13px;">
                </div>
            </div>
            <div class="modal-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addLeadModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add to List</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.LeadListConfig = {
    updateBaseUrl: '{{ url("/lead-lists/{$leadList->id}/leads") }}/',
    csrf: '{{ csrf_token() }}',
};
</script>
<script src="{{ asset('js/lead-list-leads.js') }}"></script>
@endpush
