@extends('layouts.app')

@section('title', 'Scheduled Calls')
@section('page-title', 'Scheduled Calls')
@section('page-subtitle', 'Callbacks and customer follow-ups')

@section('content')

{{-- STATS --}}
@php
    $user = Auth::user();
    $base = \App\Models\Call::with('lead')->where('is_scheduled',true)->where('completed',false)
                ->when(!$user->isAdmin(), fn($q) => $q->where('agent_id',$user->id));
    $totalScheduled = (clone $base)->count();
    $overdueCount   = (clone $base)->where('scheduled_at','<',now())->count();
    $todayCount     = (clone $base)->whereDate('scheduled_at', today())->count();
@endphp
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-calendar-week-fill"></i></div>
        <div><div class="stat-label">Total Pending</div><div class="stat-value">{{ $totalScheduled }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="bi bi-calendar-day-fill"></i></div>
        <div><div class="stat-label">Today</div><div class="stat-value">{{ $todayCount }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff1f2;color:#f43f5e;"><i class="bi bi-alarm-fill"></i></div>
        <div><div class="stat-label">Overdue</div><div class="stat-value" style="color:#f43f5e;">{{ $overdueCount }}</div></div>
    </div>
</div>

{{-- FILTERS + TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-calendar-check" style="color:var(--primary);margin-right:6px;"></i>
            Scheduled Calls
        </span>
        <form method="GET" action="{{ route('calls.schedule') }}" class="filters-bar" style="margin:0">
            <input type="date" name="date" class="form-control" style="width:160px"
                   value="{{ request('date') }}" placeholder="Filter by date">

            @if($user->isAdmin())
                <select name="agent_id" class="form-control" style="width:160px">
                    <option value="">All Agents</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" {{ request('agent_id')==$agent->id ? 'selected' : '' }}>
                            {{ $agent->name }}
                        </option>
                    @endforeach
                </select>
            @endif
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel"></i> Filter
            </button>
            @if(request()->hasAny(['date','agent_id']))
                <a href="{{ route('calls.schedule') }}" class="btn btn-secondary btn-sm">
                    <i class="bi bi-x"></i> Clear
                </a>
            @endif
        </form>
    </div>

    @if($scheduledCalls->count())
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Scheduled For</th>
                    <th>Lead</th>
                    <th>Contact</th>
                    @if($user->isAdmin()) <th>Agent</th> @endif
                    <th>Notes</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($scheduledCalls as $call)
                    @php $isOverdue = $call->scheduled_at && $call->scheduled_at->isPast(); @endphp
                    <tr class="{{ $isOverdue ? 'overdue-row' : '' }}" id="call-row-{{ $call->id }}">
                        <td>
                            <div style="font-weight:600;font-size:13px;{{ $isOverdue ? 'color:#ef4444;' : 'color:#1e293b;' }}">
                                {{ $call->scheduled_at?->format('M d, Y') }}
                                @if($isOverdue) <span style="font-size:10px;"> ⚠ Overdue</span> @endif
                            </div>
                            <div style="font-size:12px;color:#94a3b8;">
                                {{ $call->scheduled_at?->format('H:i') }}
                                ({{ $call->scheduled_at?->diffForHumans() }})
                            </div>
                        </td>
                        <td>
                            <a href="{{ route('leads.show', $call->lead) }}" style="font-weight:600;color:#1e293b;text-decoration:none;">
                                {{ $call->lead->full_name }}
                            </a>
                            <div style="font-size:12px;color:#94a3b8;">
                                {{ $call->lead->city }}{{ $call->lead->state ? ', '.$call->lead->state : '' }}
                            </div>
                        </td>
                        <td>
                            @if($call->lead->phone)
                                <a href="tel:{{ $call->lead->phone }}" style="display:flex;align-items:center;gap:4px;font-size:13px;color:inherit;text-decoration:none;">
                                    <i class="bi bi-telephone" style="color:#94a3b8;"></i>
                                    {{ $call->lead->phone }}
                                </a>
                            @endif
                        </td>
                        @if($user->isAdmin())
                        <td>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <div style="width:24px;height:24px;border-radius:50%;background:var(--primary);color:white;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;">
                                    {{ strtoupper(substr($call->agent?->name ?? 'U', 0, 1)) }}
                                </div>
                                <span style="font-size:12px;">{{ $call->agent?->name ?? 'Unassigned' }}</span>
                            </div>
                        </td>
                        @endif
                        <td>
                            <div style="font-size:12px;color:#475569;max-width:200px;">
                                {{ Str::limit($call->notes, 60) ?: '—' }}
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-warning">
                                <i class="bi bi-clock" style="margin-right:3px;"></i> Pending
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <a href="{{ route('leads.show', $call->lead) }}"
                                   class="btn btn-outline btn-xs">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <button class="btn btn-success btn-xs complete-call-btn"
                                        data-call-id="{{ $call->id }}" title="Mark as completed">
                                    <i class="bi bi-check-lg"></i> Done
                                </button>
                                <a href="tel:{{ $call->lead->phone }}"
                                   class="btn btn-primary btn-xs" title="Call now">
                                    <i class="bi bi-telephone-fill"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($scheduledCalls->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $scheduledCalls->links('vendor.pagination.custom') }}
        </div>
    @endif
    @else
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h3>No scheduled calls</h3>
            <p>Callbacks and customer follow-ups will appear here when you log a call with those dispositions.</p>
        </div>
    @endif
</div>

<style>
    .overdue-row { background: #fff8f8 !important; }
    .overdue-row:hover { background: #fff1f1 !important; }
</style>

@endsection

@section('scripts')
<script>
$(document).on('click', '.complete-call-btn', function() {
    const $btn = $(this);
    const callId = $btn.data('call-id');
    $btn.prop('disabled', true).html('<i class="bi bi-hourglass"></i>');
    $.ajax({
        url: `/calls/${callId}/complete`,
        method: 'PATCH',
        success: function() {
            showToast('Call marked as completed!', 'success');
            $(`#call-row-${callId}`).fadeOut(500, function(){ $(this).remove(); });
        },
        error: function() {
            showToast('Error updating call.', 'error');
            $btn.prop('disabled', false).html('<i class="bi bi-check-lg"></i> Done');
        }
    });
});
</script>
@endsection
