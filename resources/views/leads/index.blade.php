@extends('layouts.app')

@section('title', $pageTitle ?? 'All Leads')
@section('page-title', $pageTitle ?? 'All Leads')
@section('page-subtitle', $leads->total() . ' total leads')

@section('header-actions')
    <div style="display:flex;align-items:center;gap:8px;">
        @if(Auth::user()->isAdmin() || Auth::user()->hasPermission('manage_lead_lists'))
            <a href="{{ route('lead-lists.index') }}" class="btn btn-secondary btn-sm" title="Importar listas o base de datos CSV">
                <i class="bi bi-upload"></i> Import List
            </a>
        @endif
        <a href="{{ route('leads.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> New Lead
        </a>
    </div>
@endsection

@section('content')

@php $user = Auth::user(); @endphp

{{-- STATS --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Leads</div><div class="stat-value">{{ $totalLeads }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="bi bi-star-fill"></i></div>
        <div><div class="stat-label">New</div><div class="stat-value">{{ $newLeads }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-trophy-fill"></i></div>
        <div><div class="stat-label">Closed Won</div><div class="stat-value">{{ $closedWon }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-calendar-check-fill"></i></div>
        <div><div class="stat-label">Scheduled Calls</div><div class="stat-value">{{ $scheduled }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-people" style="color:var(--primary);margin-right:6px;"></i> Leads
        </span>        
        <form method="GET" action="{{ route($filterRoute ?? 'leads.index') }}" class="filters-bar" style="margin:0">
            <div class="search-input-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="search" class="form-control" placeholder="Search name, email, phone…"
                       value="{{ request('search') }}">
            </div>

            {{-- Status filter --}}
            <select name="status" class="form-control" style="width:150px">
                <option value="">All Statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s->slug }}" {{ request('status') == $s->slug ? 'selected' : '' }}>
                        {{ $s->name }}
                    </option>
                @endforeach
            </select>

            {{-- Campaign filter --}}
            @if($campaigns->count() > 0)
            <select name="campaign_id" class="form-control" style="width:150px">
                <option value="">All Campaigns</option>
                @foreach($campaigns as $c)
                    <option value="{{ $c->id }}" {{ request('campaign_id') == $c->id ? 'selected' : '' }}>
                        {{ $c->name }}
                    </option>
                @endforeach
            </select>
            @endif

            {{-- Agent filter (admin only) --}}
            @if($user->isAdmin())
            <select name="agent_id" class="form-control" style="width:150px">
                <option value="">All Agents</option>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}" {{ request('agent_id') == $agent->id ? 'selected' : '' }}>
                        {{ $agent->name }}
                    </option>
                @endforeach
            </select>
            @endif

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel"></i> Filter
            </button>
            @if(request()->hasAny(['search','status','agent_id','campaign_id']))
                <a href="{{ route('leads.index') }}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-x"></i> Clear
                </a>
            @endif
        </form>
    </div>

    {{-- BULK ACTIONS BAR (Visible when checkboxes selected) --}}
    <div id="bulkActionBar" style="display:none;background:var(--bg-input,#f1f5f9);padding:10px 16px;border-bottom:1px solid var(--border);align-items:center;justify-content:space-between;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span class="badge" style="background:var(--primary);color:white;font-size:12px;padding:4px 8px;" id="selectedCountBadge">0 seleccionados</span>
            <span style="font-size:13px;color:var(--text-muted);">Prospectos seleccionados en esta página</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="openBulkAddModal()">
                <i class="bi bi-send-plus-fill"></i> Enviar a Lista de Marcador (Lead List)
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="clearLeadSelection()">
                <i class="bi bi-x-circle"></i> Desmarcar todos
            </button>
        </div>
    </div>

    <div class="table-wrapper">
        @if($leads->count())
        <table>
            <thead>
                <tr>
                    <th style="width:36px;text-align:center;">
                        <input type="checkbox" id="selectAllLeads" title="Select all on this page" style="cursor:pointer;">
                    </th>
                    <th>Lead</th>
                    <th>Contact</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Last Call</th>
                    <th>Updated</th>
                    <th>Created</th>
                    <th>Source</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($leads as $lead)
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" class="lead-select-chk" value="{{ $lead->id }}" style="cursor:pointer;">
                    </td>
                    <td>
                        <div style="font-weight:600;color:var(--text-strong)">{{ $lead->full_name }}</div>
                        <div style="font-size:12px;color:var(--text-muted)">
                            {{ $lead->city }}{{ $lead->state ? ', '.$lead->state : '' }}
                        </div>
                    </td>
                    <td>
                        @if($lead->phone)
                            <div style="display:flex;align-items:center;gap:4px;font-size:13px;">
                                <i class="bi bi-telephone" style="color:var(--text-muted);font-size:12px;"></i>
                                <a href="tel:{{ $lead->phone }}" style="color:inherit;text-decoration:none;">{{ $lead->phone }}</a>
                            </div>
                        @endif
                        @if($lead->email)
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">{{ $lead->email }}</div>
                        @endif
                    </td>
                    @if($isSuperAdmin)
                    <td>
                        @if($lead->tenant)
                            <span class="badge" style="background:var(--primary-light,#e0e7ff);color:var(--primary);">
                                {{ $lead->tenant->name }}
                            </span>
                        @else
                            <span style="color:var(--text-muted);font-size:12px;">—</span>
                        @endif
                    </td>
                    @endif
                    <td>
                        @if($lead->campaign)
                            <span class="badge" style="background:{{ $lead->campaign->bg_color }};color:{{ $lead->campaign->color }};">
                                {{ $lead->campaign->name }}
                            </span>
                        @else
                            <span style="color:var(--text-muted);font-size:12px;font-style:italic;">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge" style="{{ $lead->status_badge_style }}">
                            {{ $lead->status_label }}
                        </span>
                    </td>
                    <td>
                        @if($lead->agent)
                            <div style="display:flex;align-items:center;gap:6px;">
                                <div style="width:26px;height:26px;border-radius:50%;background:var(--primary);color:white;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    {{ strtoupper(substr($lead->agent->name,0,1)) }}
                                </div>
                                <span style="font-size:13px;">{{ $lead->agent->name }}</span>
                            </div>
                        @else
                            <span style="color:var(--text-muted);font-size:12px;font-style:italic;">Unassigned</span>
                        @endif
                    </td>
                    <td>
                        @if($lead->lastCall)
                            <span class="badge" style="{{ $lead->lastCall->disposition_badge_style }}">
                                {{ $lead->lastCall->disposition_label }}
                            </span>
                            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                {{ $lead->lastCall->called_at->diffForHumans() }}
                            </div>
                        @else
                            <span style="color:var(--text-muted);font-size:12px;">No calls yet</span>
                        @endif
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;">
                        {{ $lead->updated_at->format('M d, Y H:i') }}
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;">
                        {{ $lead->created_at->format('M d, Y H:i') }}
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);">
                        {{ $lead->source ?? '—' }}
                    </td>
                    <td>
                        <a href="{{ route('leads.show', $lead) }}" class="btn btn-outline btn-xs">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <h3>No leads found</h3>
                <p>Try adjusting your search or filters.</p>
            </div>
        @endif
    </div>

    @if($leads->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $leads->links('vendor.pagination.custom') }}
        </div>
    @endif
</div>

{{-- BULK ADD TO LEAD LIST MODAL --}}
<div class="modal-overlay" id="bulkAddModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header" style="padding:14px 20px;">
            <div class="modal-title"><i class="bi bi-send-plus-fill" style="color:var(--primary);margin-right:6px;"></i> Enviar a Lista del Marcador</div>
            <button class="modal-close" onclick="closeModal('bulkAddModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('leads.bulkAddToList') }}" id="bulkAddForm">
            @csrf
            <div id="hiddenLeadInputs"></div>
            <div class="modal-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:14px;">
                <div style="background:var(--bg-input,#f8fafc);padding:12px;border-radius:var(--radius-sm);border:1px solid var(--border);">
                    <div style="font-size:13px;font-weight:600;color:var(--text-strong);" id="bulkSummaryText">
                        0 prospectos seleccionados
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                        Se agregarán con estado <strong>NEW</strong> para ser marcados por la campaña asociada.
                    </div>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:6px;display:block;">Selecciona la Lista de Destino <span style="color:#ef4444;">*</span></label>
                    <select name="lead_list_id" class="form-control" required style="font-size:13px;">
                        <option value="">-- Elige una lista del marcador --</option>
                        @foreach($leadLists ?? [] as $ll)
                            <option value="{{ $ll->id }}">{{ $ll->name }} (ID #{{ $ll->id }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('bulkAddModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Confirmar y Agregar</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('selectAllLeads');
    var checkboxes = document.querySelectorAll('.lead-select-chk');
    var bar = document.getElementById('bulkActionBar');
    var badge = document.getElementById('selectedCountBadge');

    function updateBulkBar() {
        var checked = document.querySelectorAll('.lead-select-chk:checked');
        var count = checked.length;
        if (count > 0) {
            bar.style.display = 'flex';
            badge.textContent = count + (count === 1 ? ' seleccionado' : ' seleccionados');
        } else {
            bar.style.display = 'none';
        }
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (chk) {
                chk.checked = selectAll.checked;
            });
            updateBulkBar();
        });
    }

    checkboxes.forEach(function (chk) {
        chk.addEventListener('change', updateBulkBar);
    });

    window.clearLeadSelection = function () {
        checkboxes.forEach(function (chk) { chk.checked = false; });
        if (selectAll) selectAll.checked = false;
        updateBulkBar();
    };

    window.openBulkAddModal = function () {
        var checked = document.querySelectorAll('.lead-select-chk:checked');
        if (checked.length === 0) {
            alert('Por favor selecciona al menos un prospecto.');
            return;
        }
        var container = document.getElementById('hiddenLeadInputs');
        container.innerHTML = '';
        checked.forEach(function (chk) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'lead_ids[]';
            input.value = chk.value;
            container.appendChild(input);
        });
        document.getElementById('bulkSummaryText').textContent = checked.length + (checked.length === 1 ? ' prospecto seleccionado' : ' prospectos seleccionados');
        openModal('bulkAddModal');
    };
});
</script>
@endpush