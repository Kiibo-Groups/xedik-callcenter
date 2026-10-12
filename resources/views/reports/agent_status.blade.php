@extends('layouts.app')

@section('title', 'Agent Status Report')
@section('page-title', 'Agent Status Report')
@section('page-subtitle', 'Track agent availability and time per status')

@section('header-actions')
<a href="{{ route('reports.agent-status') }}?{{ http_build_query(array_merge(request()->all(), ['export' => 'csv'])) }}" 
   class="btn btn-secondary btn-sm">
    <i class="bi bi-download"></i> Export CSV
</a>
@endsection

@section('content')

{{-- FILTERS --}}
<div class="card" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" action="{{ route('reports.agent-status') }}" id="filterForm" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label class="form-label">From</label>
            <input type="date" name="from" class="form-control form-control-sm"
                   value="{{ request('from', now()->format('Y-m-d')) }}">
        </div>
        <div>
            <label class="form-label">To</label>
            <input type="date" name="to" class="form-control form-control-sm"
                   value="{{ request('to', now()->format('Y-m-d')) }}">
        </div>
        <div>
            <label class="form-label">Campaign</label>
            <select name="campaign_id" class="form-control form-control-sm" style="min-width:150px;">
                <option value="">All Campaigns</option>
                @foreach($campaigns as $campaign)
                    <option value="{{ $campaign->id }}" {{ request('campaign_id') == $campaign->id ? 'selected' : '' }}>
                        {{ $campaign->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Agent</label>
            <select name="agent_id" class="form-control form-control-sm" style="min-width:150px;">
                <option value="">All Agents</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" {{ request('agent_id') == $user->id ? 'selected' : '' }}>
                        {{ $user->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Status</label>
            <select name="status" class="form-control form-control-sm">
                <option value="">All Statuses</option>
                <option value="ready"   {{ request('status') === 'ready'   ? 'selected' : '' }}>Ready</option>
                <option value="paused"  {{ request('status') === 'paused'  ? 'selected' : '' }}>Paused</option>
                <option value="ringing" {{ request('status') === 'ringing' ? 'selected' : '' }}>Ringing</option>
                <option value="in_call" {{ request('status') === 'in_call' ? 'selected' : '' }}>In Call</option>
                <option value="wrap_up" {{ request('status') === 'wrap_up' ? 'selected' : '' }}>Wrap-Up</option>
            </select>
        </div>
        <div>
            <label class="form-label">Pause Reason</label>
            <select name="pause_reason_id" class="form-control form-control-sm" style="min-width:140px;">
                <option value="">All Reasons</option>
                @foreach($pauseReasons as $reason)
                    <option value="{{ $reason->id }}" {{ request('pause_reason_id') == $reason->id ? 'selected' : '' }}>
                        {{ $reason->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label">Type</label>
            <select name="type" class="form-control form-control-sm" id="typeSelect">
                <option value="detailed" {{ ($type ?? 'detailed') === 'detailed' ? 'selected' : '' }}>Detailed</option>
                <option value="grouped"  {{ ($type ?? 'detailed') === 'grouped'  ? 'selected' : '' }}>Grouped</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-funnel"></i> Filter
            </button>
            <a href="{{ route('reports.agent-status') }}" class="btn btn-secondary btn-lg">
                <i class="bi bi-x"></i> Clear
            </a>
        </div>
    </form>
</div>

{{-- DETAILED VIEW --}}
@if(($type ?? 'detailed') === 'detailed')
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-clock-history" style="color:var(--primary)"></i> Status Log
        </span>
        <span style="font-size:12px;color:var(--text-muted);">
            {{ method_exists($logs, 'total') ? $logs->total() : 0 }} records
        </span>
    </div>
    <div class="table-wrapper">
        @if($logs->count())
        <table>
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Status</th>
                    <th>Pause Reason</th>
                    <th>Started At</th>
                    <th>Ended At</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                @php
                    $sc = match($log->status) {
                        'ready'   => ['bg' => '#dcfce7', 'color' => '#16a34a'],
                        'paused'  => ['bg' => '#fef9c3', 'color' => '#ca8a04'],
                        'ringing' => ['bg' => '#dbeafe', 'color' => '#2563eb'],
                        'in_call' => ['bg' => '#dcfce7', 'color' => '#16a34a'],
                        'wrap_up' => ['bg' => '#fef3c7', 'color' => '#d97706'],
                        default   => ['bg' => '#f1f5f9', 'color' => '#64748b'],
                    };
                @endphp
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="width:28px;height:28px;border-radius:50%;background:var(--primary);color:white;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                {{ strtoupper(substr($log->user->name, 0, 1)) }}
                            </div>
                            <span style="font-size:13px;font-weight:500;color:var(--text-strong);">{{ $log->user->name }}</span>
                        </div>
                    </td>
                    <td>
                        <span class="badge" style="background:{{ $sc['bg'] }};color:{{ $sc['color'] }};">
                            {{ \App\Models\AgentStatusLog::displayLabel($log->status) }}
                        </span>
                    </td>
                    <td style="font-size:13px;color:var(--text-mid);">
                        @if($log->pauseReason)
                            <span style="display:inline-flex;align-items:center;gap:6px;">
                                <span style="width:8px;height:8px;border-radius:50%;background:{{ $log->pauseReason->color }};flex-shrink:0;"></span>
                                {{ $log->pauseReason->name }}
                            </span>
                        @else
                            <span style="color:var(--text-muted);">—</span>
                        @endif
                    </td>
                    <td style="font-size:13px;color:var(--text-mid);">{{ $log->started_at->format('m/d/Y H:i:s') }}</td>
                    <td style="font-size:13px;color:var(--text-mid);">{{ $log->ended_at?->format('m/d/Y H:i:s') ?? '—' }}</td>
                    <td>
                        <span style="font-size:13px;color:var(--text-strong);">{{ $log->duration_formatted ?? '—' }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($logs->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $logs->links('vendor.pagination.custom') }}
        </div>
        @endif
        @else
        <div class="empty-state">
            <i class="bi bi-clock-history"></i>
            <h3>No records found</h3>
            <p>Try adjusting the filters or date range.</p>
        </div>
        @endif
    </div>
</div>
@endif

{{-- GROUPED VIEW --}}
@if(($type ?? 'detailed') === 'grouped' && isset($grouped))
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-bar-chart" style="color:var(--primary)"></i> Grouped Summary
        </span>
    </div>
    <div class="table-wrapper">
        @if($grouped->count())
        <table>
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Status</th>
                    <th style="text-align:center;">Times</th>
                    <th>Total Duration</th>
                </tr>
            </thead>
            <tbody>
                @foreach($grouped as $agentData)
                    @foreach($agentData['rows'] as $row)
                    @php
                        $isTotal = $row['is_total'];
                        $isReady = $row['is_ready'];
                        $isVoiceOccupied = $row['is_voice_occupied'] ?? false;
                        $isTotalPause = $row['status'] === 'Total Pauses';
                        $isTotalVoice = $row['status'] === 'Total Voice Occupancy';
                        $isGrandTotal = $row['status'] === 'Total';
                    @endphp
                    <tr style="{{ $isTotal ? 'background:var(--bg-th);' : '' }}">
                        <td style="font-size:13px;{{ $isTotal ? 'color:var(--text-muted);font-size:12px;' : 'font-weight:500;color:var(--text-strong);' }}">
                            @if(!$isTotal)
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="width:24px;height:24px;border-radius:50%;background:var(--primary);color:white;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        {{ strtoupper(substr($row['agent'], 0, 1)) }}
                                    </div>
                                    {{ $row['agent'] }}
                                </div>
                            @else
                                &nbsp;
                            @endif
                        </td>
                        <td>
                            @if($isGrandTotal)
                                <span style="font-size:12px;font-weight:700;color:var(--primary);">Total</span>
                            @elseif($isTotalVoice)
                                <span style="font-size:12px;font-weight:600;color:#16a34a;">Total Voice Occupancy</span>
                            @elseif($isTotalPause)
                                <span style="font-size:12px;font-weight:600;color:#ca8a04;">Total Pauses</span>
                            @elseif($isReady)
                                <span class="badge" style="background:#dcfce7;color:#16a34a;">Ready</span>
                            @elseif($isVoiceOccupied)
                                <span class="badge" style="background:#dcfce720;color:#16a34a;">{{ $row['status'] }}</span>
                            @else
                                @php $pc = $row['paused_color'] ?? '#f59e0b'; @endphp
                                <span class="badge" style="background:{{ $pc }}20;color:{{ $pc }};">{{ $row['status'] }}</span>
                            @endif
                        </td>
                        <td style="text-align:center;font-size:13px;color:var(--text-mid);">{{ $row['times'] }}</td>
                        <td>
                            <span style="font-size:13px;font-weight:{{ $isTotal ? '700' : '400' }};color:{{ $isGrandTotal ? 'var(--primary)' : ($isTotalPause ? '#ca8a04' : ($isTotalVoice ? '#16a34a' : 'var(--text-strong)')) }};">{{ $row['duration'] }}</span>
                        </td>
                    </tr>
                    @endforeach
                    {{-- separador entre agentes --}}
                    <tr><td colspan="4" style="padding:4px;background:var(--bg-page);border:none;"></td></tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-bar-chart"></i>
            <h3>No records found</h3>
            <p>Try adjusting the filters or date range.</p>
        </div>
        @endif
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const campaignSelect = document.querySelector('#filterForm [name="campaign_id"]');
    if (!campaignSelect) return;

    campaignSelect.addEventListener('change', function() {
        const campaignId = this.value;
        fetch(`/reports/agents-by-campaign?campaign_id=${campaignId}`)
            .then(r => r.json())
            .then(users => {
                const select = document.querySelector('#filterForm [name="agent_id"]');
                select.innerHTML = '<option value="">All Agents</option>';
                users.forEach(u => {
                    select.innerHTML += `<option value="${u.id}">${u.name}</option>`;
                });
            })
            .catch(err => console.error('Fetch error:', err));
    });
});
</script>
@endpush