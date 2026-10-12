@extends('layouts.app')

@section('title', $pageTitle ?? 'All Leads')
@section('page-title', $pageTitle ?? 'All Leads')
@section('page-subtitle', $leads->total() . ' total leads')

@section('header-actions')
    @if(Auth::user()->isAdmin())
        <button class="btn btn-primary btn-sm">
            <i class="bi bi-upload"></i> Import
        </button>
    @endif
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

    <div class="table-wrapper">
        @if($leads->count())
        <table>
            <thead>
                <tr>
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

@endsection