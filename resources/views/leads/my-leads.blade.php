@extends('layouts.app')

@section('title', 'My Leads')
@section('page-title', 'My Leads')
@section('page-subtitle', 'Leads assigned to you — ' . $leads->total() . ' total')

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-person-check" style="color:var(--primary);margin-right:6px;"></i>
            My Leads <span style="font-size:13px;font-weight:400;color:var(--text-muted);">({{ $leads->total() }})</span>
        </span>
        <form method="GET" action="{{ route('leads.my') }}" class="filters-bar" style="margin:0">
            <div class="search-input-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="search" class="form-control" placeholder="Search…"
                       value="{{ request('search') }}">
            </div>
            {{-- Status dropdown dinámico desde catálogo --}}
            <select name="status" class="form-control" style="width:160px">
                <option value="">All Statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s->slug }}" {{ request('status') == $s->slug ? 'selected' : '' }}>
                        {{ $s->name }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel"></i> Filter
            </button>
            @if(request()->hasAny(['search','status']))
                <a href="{{ route('leads.my') }}" class="btn btn-secondary btn-sm">
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
                    <th>Status</th>
                    <th>Last Call</th>
                    <th>Scheduled</th>
                    <th>Created</th>
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
                            <a href="tel:{{ $lead->phone }}" style="display:flex;align-items:center;gap:4px;font-size:13px;color:inherit;text-decoration:none;">
                                <i class="bi bi-telephone" style="color:var(--text-muted);"></i> {{ $lead->phone }}
                            </a>
                        @endif
                        @if($lead->email)
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">{{ $lead->email }}</div>
                        @endif
                    </td>
                    <td>
                        {{-- Badge con colores dinámicos del catálogo --}}
                        <span class="badge" style="{{ $lead->status_badge_style }}">
                            {{ $lead->status_label }}
                        </span>
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
                            <span style="color:var(--text-muted);font-size:12px;">No calls</span>
                        @endif
                    </td>
                    <td>
                        @php $sched = $lead->scheduledCalls->count(); @endphp
                        @if($sched)
                            <span class="badge badge-warning">
                                <i class="bi bi-calendar2-check" style="margin-right:3px;"></i>
                                {{ $sched }} scheduled
                            </span>
                        @else
                            <span style="color:var(--text-muted);font-size:12px;">—</span>
                        @endif
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);">
                        {{ $lead->created_at->format('M d, Y') }}
                    </td>
                    <td>
                        <a href="{{ route('leads.show', $lead) }}" class="btn btn-primary btn-xs">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
            <div class="empty-state">
                <i class="bi bi-person-check"></i>
                <h3>No leads assigned to you</h3>
                <p>Your assigned leads will appear here.</p>
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
