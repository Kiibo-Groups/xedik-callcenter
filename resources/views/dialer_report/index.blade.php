@extends('layouts.app')

@section('title', 'Lead List Report')
@section('page-title', 'Lead List Report')
@section('page-subtitle', 'Lead attempts and outcomes by Lead List')

@section('content')

{{-- Filters --}}
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" action="{{ route('dialer-report.index') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group mb-0" style="min-width:220px;">
                <label class="form-label">Lead List *</label>
                <select name="lead_list_id" class="form-control" onchange="this.form.submit()">
                    <option value="">— Select a lead list —</option>
                    @foreach($leadLists as $ll)
                    <option value="{{ $ll->id }}" {{ $selectedListId == $ll->id ? 'selected' : '' }}>
                        {{ $ll->name }} ({{ number_format($ll->total_leads) }})
                    </option>
                    @endforeach
                </select>
            </div>

            @if($selectedListId && $filteredCampaigns->isNotEmpty())
            <div class="form-group mb-0" style="min-width:200px;">
                <label class="form-label">Campaign</label>
                <select name="dialer_campaign_id" class="form-control" onchange="this.form.submit()">
                    <option value="">— All campaigns —</option>
                    @foreach($filteredCampaigns as $dc)
                    <option value="{{ $dc->id }}" {{ $selectedCampaignId == $dc->id ? 'selected' : '' }}>
                        {{ $dc->name ?: $dc->campaign?->name ?: 'Campaign #'.$dc->id }}
                    </option>
                    @endforeach
                </select>
            </div>
            @endif

            @if($selectedListId)
            <a href="{{ route('dialer-report.index') }}" class="btn btn-secondary btn-sm" style="margin-bottom:1px;">
                <i class="bi bi-x"></i> Clear
            </a>
            <a href="{{ route('dialer-report.export', request()->query()) }}" class="btn btn-outline btn-sm" style="margin-bottom:1px;">
                <i class="bi bi-download"></i> Export CSV
            </a>
            @endif
        </form>
    </div>
</div>

@if($selectedList && $stats)
{{-- Stats --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-telephone-fill"></i></div>
        <div><div class="stat-label">Total Calls</div><div class="stat-value">{{ number_format($stats['total']) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Answered</div><div class="stat-value">{{ number_format($stats['answered']) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f1f5f9;color:#64748b;"><i class="bi bi-telephone-minus-fill"></i></div>
        <div><div class="stat-label">No Answer</div><div class="stat-value">{{ number_format($stats['no_answer']) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef9c3;color:#b45309;"><i class="bi bi-telephone-x-fill"></i></div>
        <div><div class="stat-label">Busy</div><div class="stat-value">{{ number_format($stats['busy']) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div><div class="stat-label">Failed</div><div class="stat-value">{{ number_format($stats['failed']) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-stopwatch-fill"></i></div>
        <div>
            <div class="stat-label">Avg Duration</div>
            <div class="stat-value">{{ gmdate('i:s', $stats['avg_duration']) }}</div>
            <div class="cell-muted" style="font-size:10px;">{{ gmdate('i:s', $stats['avg_billsec']) }} avg talk</div>
        </div>
    </div>
</div>
@endif

{{-- Table --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-journal-text icon-primary"></i>
            {{ $selectedList ? $selectedList->name : 'Lead List Report' }}
            @if($selectedList)
            <span class="cell-muted" style="font-size:12px;font-weight:400;margin-left:8px;">{{ $selectedList->log_table_name }}</span>
            @endif
        </span>
        @if($logs)
        <span class="cell-muted" style="font-size:12px;">{{ number_format($logs->total()) }} records</span>
        @endif
    </div>
    <div class="table-wrapper">
        @if(!$selectedListId)
        <div class="empty-state">
            <i class="bi bi-funnel"></i>
            <h3>Select a Lead List</h3>
            <p>Choose a lead list above to view its call history.</p>
        </div>
        @elseif($logs && $logs->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>Phone</th>
                    <th>Outcome</th>
                    <th>Disposition</th>
                    <th title="Time before the call was answered">Ring Time</th>
                    <th title="Actual conversation time">Talk Time</th>
                    <th title="Ring Time + Talk Time">Total Duration</th>
                    <th>Campaign</th>
                    <th>Uniqueid</th>
                    <th>Called At</th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                @php
                    $ringTime = max(0, ($log->duration ?? 0) - ($log->billsec ?? 0));
                    $outcome = filled($log->final_status) ? trim((string) $log->final_status) : null;
                    $badge   = match($outcome) {
                        'ANSWER'    => ['bg'=>'#dcfce7','color'=>'#16a34a'],
                        'BUSY'      => ['bg'=>'#fef9c3','color'=>'#b45309'],
                        'NO_ANSWER' => ['bg'=>'#f1f5f9','color'=>'#64748b'],
                        default     => ['bg'=>'#fee2e2','color'=>'#dc2626'],
                    };
                    $disposition = filled($log->disposition) ? trim((string) $log->disposition) : null;
                @endphp
                <tr>
                    <td><code class="cell-code">{{ $log->phone }}</code></td>
                    <td>
                        @if($outcome)
                        <span class="badge" style="background:{{ $badge['bg'] }};color:{{ $badge['color'] }};">
                            {{ $outcome }}
                        </span>
                        @else
                            <span class="cell-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>
                        @if($disposition)
                            {{ $disposition }}
                        @else
                            <span class="cell-muted">&mdash;</span>
                        @endif
                    </td>
                    <td class="cell-muted">
                        @if($ringTime > 0)
                            {{ gmdate('i:s', $ringTime) }}
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if(($log->billsec ?? 0) > 0)
                            <span style="color:#16a34a;font-weight:500;">{{ gmdate('i:s', $log->billsec) }}</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td class="cell-muted">{{ gmdate('i:s', $log->duration ?? 0) }}</td>
                    <td>
                        @if($log->dc_name)
                            <div class="cell-strong" style="font-size:12px;">{{ $log->dc_name }}</div>
                            <div class="cell-muted" style="font-size:10px;">{{ $log->crm_campaign_name }}</div>
                        @elseif($log->crm_campaign_name)
                            <div style="font-size:12px;">{{ $log->crm_campaign_name }}</div>
                        @else
                            <span class="cell-muted">#{{ $log->dialer_campaign_id }}</span>
                        @endif
                    </td>
                    <td class="cell-muted" style="font-size:10px;">{{ $log->uniqueid ?? '—' }}</td>
                    <td class="cell-muted">
                        {{ $log->called_at ? \Carbon\Carbon::parse($log->called_at)->format('m/d H:i:s') : '—' }}
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

        @elseif($selectedListId)
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h3>No call records yet</h3>
            <p>Run a dialer campaign using this lead list to generate call history.</p>
        </div>
        @endif
    </div>
</div>

{{-- Legend --}}
@if($selectedList)
<div class="tip-box" style="margin-top:16px;">
    <i class="bi bi-info-circle tip-icon"></i>
    <div>
        <strong>Ring Time</strong> = time from dial until answered (or hung up).
        <strong>Talk Time</strong> = actual conversation time after answer.
        <strong>Total Duration</strong> = Ring Time + Talk Time.
        For unanswered calls, all time is Ring Time and Talk Time is 0.
    </div>
</div>
@endif

@endsection
