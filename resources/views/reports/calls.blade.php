@extends('layouts.app')

@section('title', 'Calls Report')
@section('page-title', 'Calls Report')
@section('page-subtitle', 'Inbound and outbound call reporting')

@push('styles')
<style>
    .calls-metrics { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:16px; }
    .calls-metric { border:1px solid var(--border); border-radius:8px; padding:12px; background:var(--surface); }
    .calls-metric-label { font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
    .calls-metric-value { font-size:20px; color:var(--text-strong); font-weight:700; }
    .badge-call { display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:600;border: 1px solid;}
    .badge-inbound { background:#e0f2fe; color:#075985; }
    .badge-outbound { background:#dcfce7; color:#166534; }
    .calls-detailed-table { table-layout:auto; }
    .calls-detailed-table th,
    .calls-detailed-table td { padding:8px 7px; vertical-align:top; }
    .calls-detailed-table th { white-space:nowrap; }
    .calls-col-date { width:132px; min-width:132px; white-space:nowrap; }
    .calls-col-direction { min-width:84px; }
    .calls-col-mode { min-width:62px; }
    .calls-col-phone { min-width:96px; }
    .calls-col-campaign { min-width:126px; max-width:170px; }
    .calls-col-agent { min-width:92px; max-width:140px; }
    .calls-col-time { min-width:72px; white-space:nowrap; }
    .calls-col-recording { min-width:96px; }
    .calls-col-actions { min-width:72px; }
    .calls-stacked-cell { line-height:1.25; }
    .calls-stacked-primary { color:var(--text-strong); font-weight:600; overflow-wrap:anywhere; }
    .calls-stacked-secondary { color:var(--text-muted); font-size:12px; margin-top:3px; overflow-wrap:anywhere; }
    .calls-detailed-table .btn-sm { padding:4px 7px; white-space:nowrap; }
    .calls-detail-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px; }
    .calls-detail-section { border:1px solid var(--border); border-radius:8px; padding:12px; background:var(--bg-card); color:var(--text-strong); }
    .calls-detail-section h4 { margin:0 0 10px; font-size:13px; font-weight:600; color:var(--text-strong); }
    .calls-detail-row { display:flex; justify-content:space-between; gap:12px; font-size:13px; padding:6px 0; border-bottom:1px solid var(--border); }
    .calls-detail-row:last-child { border-bottom:0; }
    .calls-detail-key { color:var(--text-muted); font-weight:500; }
    .calls-detail-value { color:var(--text-strong); font-weight:600; text-align:right; word-break:break-word; }
    .recording-actions { display:flex; gap:6px; flex-wrap:wrap; }
    .recording-status { color:var(--text-muted); font-size:12px; }
    .recording-player-card { border:1px solid var(--border); border-radius:8px; padding:14px; background:var(--surface); margin-bottom:14px; }
    .recording-player-header { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:12px; }
    .recording-player-title { font-weight:700; color:var(--text-strong); font-size:14px; }
    .recording-player-meta { color:var(--text-muted); font-size:12px; margin-top:3px; }
    .recording-player-controls { display:grid; grid-template-columns:auto 1fr auto; gap:10px; align-items:center; }
    .recording-play-large { width:42px; height:42px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; border:0; background:var(--primary); color:#fff; cursor:pointer; font-size:18px; }
    .recording-play-large:disabled { opacity:.55; cursor:not-allowed; }
    .recording-progress-wrap { display:flex; flex-direction:column; gap:5px; min-width:0; }
    .recording-progress { width:100%; accent-color:var(--primary); }
    .recording-time-row { display:flex; justify-content:space-between; color:var(--text-muted); font-size:12px; font-variant-numeric:tabular-nums; }
    .recording-volume { width:92px; accent-color:var(--primary); }
    .recording-download { white-space:nowrap; }
    .recording-empty { border:1px dashed var(--border); border-radius:8px; padding:14px; color:var(--text-muted); background:var(--surface); margin-bottom:14px; }
    @media (max-width: 768px) {
        .recording-player-header { flex-direction:column; }
        .recording-player-controls { grid-template-columns:auto 1fr; }
        .recording-volume { grid-column:1 / -1; width:100%; }
    }
</style>
@endpush

@section('content')
@php
    $advancedFilters = collect([
        $filters['agent_id'],
        $filters['disposition'],
        $filters['direction'] !== 'ALL' ? $filters['direction'] : null,
        $filters['result'] !== 'all' ? $filters['result'] : null,
        $filters['campaign_id'],
        $filters['queue'],
        $filters['call_mode'],
        $filters['answered'] !== 'all' ? $filters['answered'] : null,
        $filters['dnis'],
        $filters['duration_min'],
        $filters['duration_max'],
        $filters['linkedid'],
        $filters['call_uuid'],
        $filters['conversation_id'],
        $viewType !== 'detailed' ? $viewType : null,
    ])->filter(fn ($value) => $value !== null && $value !== '')->count();
@endphp

<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.calls.index') }}" id="callsFilterForm">
            <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
                <div>
                    <label class="form-label">From</label>
                    <input type="date" name="from" class="form-control" value="{{ $filters['from'] }}" style="width:130px;">
                </div>
                <div>
                    <label class="form-label">To</label>
                    <input type="date" name="to" class="form-control" value="{{ $filters['to'] }}" style="width:130px;">
                </div>
                <div style="flex:1;min-width:200px;">
                    <label class="form-label">Phone</label>
                    <div style="position:relative;">
                        <i class="bi bi-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;"></i>
                        <input type="text" name="phone" class="form-control" style="padding-left:30px;" placeholder="Phone..." value="{{ $filters['phone'] }}">
                    </div>
                </div>
                <div style="display:flex;gap:6px;align-items:flex-end;">
                    <button type="button" id="moreFiltersBtn" onclick="toggleMoreFilters()" style="display:inline-flex;align-items:center;gap:5px;padding:8px 12px;border-radius:var(--radius-sm);font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--primary);white-space:nowrap;">
                        <i class="bi bi-sliders" style="font-size:12px;"></i>
                        Filters
                        @if($advancedFilters > 0)
                            <span style="background:var(--primary);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;">{{ $advancedFilters }}</span>
                        @endif
                        <i class="bi bi-chevron-down" id="moreFiltersChevron" style="font-size:11px;transition:transform .2s;"></i>
                    </button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                    <a href="{{ route('reports.calls.index') }}" class="btn btn-secondary"><i class="bi bi-x"></i> Clear</a>
                    <a href="{{ route('reports.calls.export', request()->query()) }}" class="btn btn-secondary"><i class="bi bi-download"></i> Export CSV</a>
                </div>
            </div>

            <div id="moreFilters" style="display:{{ $advancedFilters > 0 ? 'flex' : 'none' }};align-items:flex-end;gap:8px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
                <div>
                    <label class="form-label">Agent</label>
                    <select name="agent_id" class="form-control" style="width:180px;">
                        <option value="">All Agents</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((string) $filters['agent_id'] === (string) $agent->id)>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Disposition</label>
                    <select name="disposition" class="form-control" style="width:150px;">
                        <option value="">All</option>
                        @foreach($dispositions as $disposition)
                            <option value="{{ $disposition->name }}" @selected($filters['disposition'] === $disposition->name)>{{ $disposition->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Direction</label>
                    <select name="direction" class="form-control" style="width:120px;">
                        <option value="all" @selected($filters['direction'] === 'ALL')>All</option>
                        <option value="INBOUND" @selected($filters['direction'] === 'INBOUND')>Inbound</option>
                        <option value="OUTBOUND" @selected($filters['direction'] === 'OUTBOUND')>Outbound</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Outcome</label>
                    <select name="result" class="form-control" style="width:150px;">
                        @foreach([
                            'all' => 'All',
                            'answered' => 'Answered',
                            'no_answer' => 'No Answer',
                            'abandoned' => 'Abandoned',
                            'caller_hung_up' => 'Caller Hung Up',
                            'agent_hung_up' => 'Agent Hung Up',
                            'busy' => 'Busy',
                            'timeout' => 'Timeout',
                            'cancel' => 'Cancel',
                            'failed' => 'Failed',
                            'congestion' => 'Congestion',
                            'unknown' => 'Unknown',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['result'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Campaign</label>
                    <select name="campaign_id" class="form-control" style="width:170px;">
                        <option value="">All Campaigns</option>
                        @foreach($campaigns as $campaign)
                            <option value="{{ $campaign->id }}" @selected((string) $filters['campaign_id'] === (string) $campaign->id)>{{ $campaign->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Queue</label>
                    <select name="queue" class="form-control" style="width:160px;">
                        <option value="">All Queues</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->value }}" @selected($filters['queue'] === $queue->value)>{{ $queue->label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Call Mode</label>
                    <select name="call_mode" class="form-control" style="width:140px;">
                        <option value="">All Modes</option>
                        @foreach($callModes as $mode)
                            <option value="{{ $mode }}" @selected($filters['call_mode'] === $mode)>{{ $mode }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Answered</label>
                    <select name="answered" class="form-control" style="width:130px;">
                        <option value="all" @selected($filters['answered'] === 'all')>All</option>
                        <option value="answered" @selected($filters['answered'] === 'answered')>Answered</option>
                        <option value="unanswered" @selected($filters['answered'] === 'unanswered')>Unanswered</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">DNIS / Caller ID</label>
                    <input type="text" name="dnis" class="form-control" value="{{ $filters['dnis'] }}" style="width:150px;">
                </div>
                <div>
                    <label class="form-label">Min Duration</label>
                    <input type="number" name="duration_min" class="form-control" value="{{ $filters['duration_min'] }}" min="0" style="width:120px;">
                </div>
                <div>
                    <label class="form-label">Max Duration</label>
                    <input type="number" name="duration_max" class="form-control" value="{{ $filters['duration_max'] }}" min="0" style="width:120px;">
                </div>
                <div>
                    <label class="form-label">LinkedID</label>
                    <input type="text" name="linkedid" class="form-control" value="{{ $filters['linkedid'] }}" style="width:150px;">
                </div>
                <div>
                    <label class="form-label">Call UUID</label>
                    <input type="text" name="call_uuid" class="form-control" value="{{ $filters['call_uuid'] }}" style="width:180px;">
                </div>
                <div>
                    <label class="form-label">Conversation ID</label>
                    <input type="number" name="conversation_id" class="form-control" value="{{ $filters['conversation_id'] }}" style="width:130px;">
                </div>
                <div>
                    <label class="form-label">Type</label>
                    <select name="view_type" class="form-control" style="width:150px;">
                        <option value="detailed" @selected($viewType === 'detailed')>Detailed</option>
                        <option value="agent_summary" @selected($viewType === 'agent_summary')>Group by Agent</option>
                    </select>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="calls-metrics">
    @foreach(($viewType === 'agent_summary' ? [
        'Total Agents' => number_format($metrics['total_agents']),
        'Inbound Answered' => number_format($metrics['inbound_answered']),
        'Outbound Attempts' => number_format($metrics['outbound_attempts']),
        'Outbound Answered' => number_format($metrics['outbound_answered']),
        'Outbound No Answer' => number_format($metrics['outbound_no_answer']),
        'Total Answered Calls' => number_format($metrics['total_answered_calls']),
        'Total Talk Time' => $report->formatSeconds($metrics['total_talk_time']),
        'Average Talk Time' => $report->formatSeconds($metrics['avg_talk_time']),
    ] : [
        'Total Calls' => number_format($metrics['total_calls']),
        'Answered' => number_format($metrics['answered']),
        'No Answer' => number_format($metrics['no_answer']),
        'Abandoned' => number_format($metrics['abandoned']),
        'Answer Rate' => $metrics['answer_rate'] . '%',
        'Inbound' => number_format($metrics['inbound']),
        'Outbound' => number_format($metrics['outbound']),
        'Total Talk Time' => $report->formatSeconds($metrics['total_talk_time']),
        'Average Talk Time' => $report->formatSeconds($metrics['avg_talk_time']),
    ]) as $label => $value)
        <div class="calls-metric">
            <div class="calls-metric-label">{{ $label }}</div>
            <div class="calls-metric-value">{{ $value }}</div>
        </div>
    @endforeach
</div>

@if($viewType === 'detailed')
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-telephone-inbound-fill" style="color:var(--primary);margin-right:6px;"></i> Calls</span>
        <span style="font-size:13px;color:var(--text-muted);">{{ $calls->total() }} records</span>
    </div>
    <div class="table-wrapper">
        @if($calls->count())
        <table class="calls-detailed-table">
            <thead>
                <tr>
                    <th class="calls-col-date">Date</th>
                    <th class="calls-col-direction">Direction</th>
                    <th class="calls-col-mode">Mode</th>
                    <th class="calls-col-phone">ANI</th>
                    <th class="calls-col-phone">DNIS / Caller ID</th>
                    <th class="calls-col-campaign">Campaign / Queue</th>
                    <th class="calls-col-agent">Agent</th>
                    <th>Disposition</th>
                    <th class="calls-col-time">Ring Time</th>
                    <th class="calls-col-time">Queue Wait</th>
                    <th class="calls-col-time">Talk Time</th>
                    <th class="calls-col-time">Wrap-up Time</th>
                    <th class="calls-col-time">Duration</th>
                    <th class="calls-col-recording">Recording</th>
                    <th class="calls-col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($calls as $call)
                    <tr>
                        <td class="calls-col-date">{{ $report->formatDate($report->callStartAt($call)) }}</td>
                        <td>
                            <span class="badge-call {{ strtoupper((string) $call->direction) === 'OUTBOUND' ? 'badge-outbound' : 'badge-inbound' }}">
                                {{ $report->directionLabel($call) }}
                            </span>
                        </td>
                        <td>{{ $report->modeLabel($call) }}</td>
                        <td>{{ $report->customerNumber($call) }}</td>
                        <td>{{ $report->campaignNumber($call) }}</td>
                        <td class="calls-stacked-cell">
                            <div class="calls-stacked-primary">{{ $report->campaignName($call) }}</div>
                            <div class="calls-stacked-secondary">{{ $report->queueName($call) }}</div>
                        </td>
                        <td class="calls-stacked-cell">{{ $report->agentName($call) }}</td>
                        <td>{{ $call->disposition ?: '—' }}</td>
                        <td>{{ $report->formatSeconds($call->ring_seconds) }}</td>
                        <td>{{ $report->formatSeconds($report->queueWaitSeconds($call)) }}</td>
                        <td>{{ $report->formatSeconds($call->talk_seconds) }}</td>
                        <td>{{ $report->formatSeconds($call->wrapup_seconds) }}</td>
                        <td>{{ $report->formatSeconds($call->call_duration_seconds) }}</td>
                        <td>
                            @if(!$report->recordingAvailable($call))
                                <span class="recording-status">Unavailable</span>
                            @else
                                <div class="recording-actions">
                                    <a class="btn btn-secondary btn-sm js-direct-download" data-no-progress="true" href="{{ route('reports.calls.recording.download', $call) }}">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </div>
                            @endif
                        </td>
                        <td>
                            <button type="button" class="btn btn-secondary btn-sm"
                                data-call-detail-url="{{ route('reports.calls.show', $call) }}"
                                @if($report->recordingAvailable($call))
                                    data-recording-play-url="{{ route('reports.calls.recording', $call) }}"
                                    data-recording-download-url="{{ route('reports.calls.recording.download', $call) }}"
                                @endif>
                                <i class="bi bi-eye"></i> View
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($calls->hasPages())
            <div style="padding:16px 20px;border-top:1px solid var(--border);">
                {{ $calls->links('vendor.pagination.custom') }}
            </div>
        @endif
        @else
            <div class="empty-state">
                <i class="bi bi-telephone"></i>
                <h3>No calls found</h3>
                <p>Try adjusting your filters.</p>
            </div>
        @endif
    </div>
</div>
@else
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-bar-chart" style="color:var(--primary);margin-right:6px;"></i> Summary by Agent</span>
        <span style="font-size:13px;color:var(--text-muted);">{{ $grouped->count() }} groups</span>
    </div>
    <div class="table-wrapper">
        @if($grouped->count())
        <table>
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Campaign</th>
                    <th>Inbound Answered</th>
                    <th>Outbound Attempts</th>
                    <th>Outbound Answered</th>
                    <th>Outbound No Answer</th>
                    <th>Total Answered Calls</th>
                    <th>Total Talk Time</th>
                    <th>Average Talk Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($grouped as $row)
                    <tr>
                        <td>{{ $row->agent_name }}</td>
                        <td>{{ $row->campaign_name }}</td>
                        <td>{{ $row->inbound_answered }}</td>
                        <td>{{ $row->outbound_attempts }}</td>
                        <td>{{ $row->outbound_answered }}</td>
                        <td>{{ $row->outbound_no_answer }}</td>
                        <td>{{ $row->total_answered_calls }}</td>
                        <td>{{ $report->formatSeconds($row->total_talk_time) }}</td>
                        <td>{{ $report->formatSeconds($row->avg_talk_time) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @else
            <div class="empty-state">
                <i class="bi bi-bar-chart"></i>
                <h3>No calls found</h3>
                <p>Try adjusting your filters.</p>
            </div>
        @endif
    </div>
</div>
@endif

<div class="modal-overlay" id="callDetailModal">
    <div class="modal" style="max-width:920px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;background:var(--bg-card);color:var(--text-strong);">
        <div class="modal-header">
            <div class="modal-title">
                <i class="bi bi-telephone" style="color:var(--primary);margin-right:6px;"></i>
                <span>Call Details</span>
            </div>
            <button class="modal-close" type="button" id="closeCallDetail">&times;</button>
        </div>
        <div class="modal-body" id="callDetailBody" style="overflow-y:auto;">Loading...</div>
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" id="closeCallDetailFooter">Close</button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
const recordingState = {
    audio: null,
    currentInlineEndpoint: null,
    currentDownloadEndpoint: null,
    currentSignedInlineUrl: null,
    currentCallId: null,
    isDownloading: false,
    suppressCleanupUntil: 0,
    retryingExpiredUrl: false,
};

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-call-detail-url]');
    if (!button) return;

    const modal = document.getElementById('callDetailModal');
    const body = document.getElementById('callDetailBody');
    modal.classList.add('active');
    body.textContent = 'Loading...';
    stopRecordingAudio();

    try {
        const response = await fetch(button.dataset.callDetailUrl, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) throw new Error('Unable to load call details.');
        const data = await response.json();
        body.innerHTML = renderCallDetail(data, button.dataset.recordingPlayUrl || null, button.dataset.recordingDownloadUrl || null);
        initializeRecordingPanel();
        if (button.dataset.recordingPlayUrl) {
            loadRecordingUrl(button.dataset.recordingPlayUrl, false).catch(() => {
                setRecordingStatus('Unable to load recording.');
            });
        }
    } catch (error) {
        body.textContent = error.message || 'Unable to load call details.';
    }
});

document.getElementById('closeCallDetail')?.addEventListener('click', closeCallDetail);
document.getElementById('closeCallDetailFooter')?.addEventListener('click', closeCallDetail);
document.getElementById('callDetailModal')?.addEventListener('click', (event) => {
    if (event.target.id === 'callDetailModal') closeCallDetail();
});

function closeCallDetail() {
    stopRecordingAudio();
    document.getElementById('callDetailModal')?.classList.remove('active');
}

window.addEventListener('pagehide', stopRecordingAudioUnlessDirectDownload);
window.addEventListener('beforeunload', stopRecordingAudioUnlessDirectDownload);

function toggleMoreFilters() {
    const panel = document.getElementById('moreFilters');
    const chevron = document.getElementById('moreFiltersChevron');
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'flex';
    chevron.style.transform = visible ? 'rotate(0deg)' : 'rotate(180deg)';
}

function escapeHtml(value) {
    return String(value ?? '—').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[char]);
}

function detailRow(label, value) {
    return `<div class="calls-detail-row"><span class="calls-detail-key">${escapeHtml(label)}</span><span class="calls-detail-value">${escapeHtml(value || '—')}</span></div>`;
}

function detailRowHtml(label, html) {
    return `<div class="calls-detail-row"><span class="calls-detail-key">${escapeHtml(label)}</span><span class="calls-detail-value">${html || '—'}</span></div>`;
}

function renderSection(title, rows) {
    return `<div class="calls-detail-section"><h4>${escapeHtml(title)}</h4>${rows.join('')}</div>`;
}

function renderCallDetail(data, recordingPlayUrl, recordingDownloadUrl) {
    const call = data.call || {};
    const isInbound = String(call.direction || '').toLowerCase() === 'inbound';

    return `
        ${renderRecordingPanel(call, recordingPlayUrl, recordingDownloadUrl)}
        <div class="calls-detail-grid">
            ${renderSection('Call Information', [
                detailRow('Direction', call.direction),
                detailRow('Mode', call.mode || call.call_mode),
                detailRow('ANI', call.customer_number),
                detailRow('DNIS / Caller ID', call.campaign_number),
                detailRow('Campaign', call.campaign),
                detailRow('Queue', call.queue),
                detailRow('Agent', call.agent),
                detailRow('Outcome', call.result),
                detailRow('Disposition', call.disposition)
            ])}
            ${renderSection('Timestamps', [
                detailRow('Started', call.started_at),
                ...(isInbound ? [detailRow('Entered Queue', call.entered_queue_at)] : []),
                detailRow('Answered', call.answered_at),
                detailRow('Ended', call.ended_at)
            ])}
            ${renderSection('Durations', [
                detailRow('Ring Time', call.ring_time),
                ...(isInbound ? [detailRow('Queue Wait', call.wait_time)] : []),
                detailRow('Talk Time', call.talk_time),
                detailRow('Wrap-Up Time', call.wrapup_time),
                detailRow('Call Duration', call.duration),
                // Recording Length is the WAV audio duration reported by the <audio>
                // element (audio.duration), NOT an official Asterisk timing. It can be
                // shorter than Call Duration when part of the ring had no RTP/audio.
                // Filled from the loadedmetadata event; stays — until metadata is ready.
                detailRowHtml('Recording Length', '<span id="recordingLengthValue">—</span>')
            ])}
        </div>
    `;
}

function renderRecordingPanel(call, recordingPlayUrl, recordingDownloadUrl) {
    const available = Boolean(recordingPlayUrl);
    const linkedid = call.linkedid || call.call_uuid || 'Selected call';
    const meta = [
        call.direction,
        call.agent,
        call.started_at
    ].filter((item) => item && item !== '—').join(' · ');

    if (!available) {
        const message = call.id ? 'Recording not available.' : 'Select a call to view its recording.';
        return `
            <div class="recording-empty" id="recordingPanel" data-recording-available="false">
                <div class="recording-player-title">Call Recording</div>
                <div class="recording-player-meta">${escapeHtml(message)}</div>
            </div>
        `;
    }

    return `
        <div class="recording-player-card" id="recordingPanel"
            data-recording-available="true"
            data-recording-play-url="${escapeHtml(recordingPlayUrl)}"
            data-recording-download-url="${escapeHtml(recordingDownloadUrl || '')}">
            <div class="recording-player-header">
                <div>
                    <div class="recording-player-title">Call Recording</div>
                    <div class="recording-player-meta">call-${escapeHtml(linkedid)}.wav</div>
                    <div class="recording-player-meta">${escapeHtml(meta || '—')}</div>
                </div>
                ${recordingDownloadUrl ? `<button type="button" class="btn btn-secondary btn-sm recording-download js-direct-download" data-no-progress="true" data-recording-download-endpoint="${escapeHtml(recordingDownloadUrl)}"><i class="bi bi-download"></i> Download Recording</button>` : ''}
            </div>
            <div class="recording-player-controls">
                <button type="button" class="recording-play-large" id="recordingToggle" disabled title="Play/Pause">
                    <i class="bi bi-play-fill" id="recordingToggleIcon"></i>
                </button>
                <div class="recording-progress-wrap">
                    <input type="range" class="recording-progress" id="recordingProgress" min="0" max="1000" value="0" disabled>
                    <div class="recording-time-row">
                        <span id="recordingCurrentTime">00:00</span>
                        <span id="recordingStatus">Loading...</span>
                        <span id="recordingDuration">00:00</span>
                    </div>
                    <div class="recording-player-meta" id="recordingDownloadStatus"></div>
                </div>
                <input type="range" class="recording-volume" id="recordingVolume" min="0" max="1" step="0.01" value="1" title="Volume">
            </div>
            <audio id="recordingAudio" preload="none" style="display:none;"></audio>
        </div>
    `;
}

function initializeRecordingPanel() {
    // Reset Recording Length to — on every open, before any metadata loads, so a newly
    // opened call never briefly shows the previous call's recording length.
    resetRecordingLength();

    const audio = document.getElementById('recordingAudio');
    if (!audio) return;

    recordingState.audio = audio;
    recordingState.currentInlineEndpoint = document.getElementById('recordingPanel')?.dataset.recordingPlayUrl || null;
    recordingState.currentDownloadEndpoint = document.getElementById('recordingPanel')?.dataset.recordingDownloadUrl || null;
    recordingState.currentSignedInlineUrl = null;
    recordingState.currentCallId = recordingState.currentInlineEndpoint;
    recordingState.isDownloading = false;
    recordingState.retryingExpiredUrl = false;

    document.getElementById('recordingToggle')?.addEventListener('click', toggleRecordingPlayback);
    document.querySelector('[data-recording-download-endpoint]')?.addEventListener('click', downloadRecordingFromModal);
    document.getElementById('recordingProgress')?.addEventListener('input', seekRecording);
    document.getElementById('recordingVolume')?.addEventListener('input', (event) => {
        audio.volume = Number(event.target.value || 1);
    });

    audio.addEventListener('loadedmetadata', syncRecordingControls);
    // The <audio> element is recreated on every modal open (renderRecordingPanel), so this
    // listener is bound to a fresh element each time and never accumulates duplicates.
    audio.addEventListener('loadedmetadata', updateRecordingLength);
    audio.addEventListener('canplay', markRecordingReady);
    audio.addEventListener('timeupdate', syncRecordingControls);
    audio.addEventListener('play', syncRecordingControls);
    audio.addEventListener('pause', syncRecordingControls);
    audio.addEventListener('ended', syncRecordingControls);
    audio.addEventListener('error', handleRecordingError);
}

async function loadRecordingUrl(inlineEndpoint, retryingExpiredUrl) {
    const status = document.getElementById('recordingStatus');
    const toggle = document.getElementById('recordingToggle');
    const progress = document.getElementById('recordingProgress');
    const audio = recordingState.audio;
    if (!audio || !inlineEndpoint) return;

    recordingState.currentInlineEndpoint = inlineEndpoint;
    recordingState.retryingExpiredUrl = retryingExpiredUrl;
    status.textContent = 'Loading...';
    toggle.disabled = true;
    progress.disabled = true;

    const response = await fetch(inlineEndpoint, { headers: { 'Accept': 'application/json' } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.url) throw new Error(data.message || 'Unable to load recording.');

    recordingState.currentSignedInlineUrl = data.url;
    audio.src = data.url;
    audio.load();
}

async function downloadRecordingFromModal(event) {
    event.preventDefault();
    event.stopPropagation();

    const button = event.currentTarget;
    const downloadEndpoint = button?.dataset?.recordingDownloadEndpoint || recordingState.currentDownloadEndpoint;
    if (!button || !downloadEndpoint || recordingState.isDownloading) return;

    const originalHtml = button.innerHTML;
    recordingState.isDownloading = true;
    button.disabled = true;
    button.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';

    try {
        const response = await fetch(downloadEndpoint, { headers: { 'Accept': 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.url) throw new Error(data.message || 'Unable to download recording.');

        const link = document.createElement('a');
        link.href = data.url;
        link.download = '';
        link.style.display = 'none';
        link.dataset.noProgress = 'true';
        link.className = 'js-direct-download';
        document.body.appendChild(link);
        recordingState.suppressCleanupUntil = Date.now() + 2000;
        link.click();
        link.remove();
    } catch (error) {
        setRecordingDownloadStatus('Unable to download recording.');
    } finally {
        recordingState.isDownloading = false;
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
}

function markRecordingReady() {
    const toggle = document.getElementById('recordingToggle');
    const progress = document.getElementById('recordingProgress');

    if (toggle) toggle.disabled = false;
    if (progress) progress.disabled = false;
    setRecordingStatus('Ready');
    syncRecordingControls();
}

function toggleRecordingPlayback() {
    const audio = recordingState.audio;
    if (!audio) return;

    if (audio.paused) {
        audio.play().catch(() => {
            setRecordingStatus('Unable to load recording.');
        });
    } else {
        audio.pause();
    }
}

function seekRecording(event) {
    const audio = recordingState.audio;
    if (!audio || !Number.isFinite(audio.duration) || audio.duration <= 0) return;

    audio.currentTime = (Number(event.target.value) / 1000) * audio.duration;
}

async function handleRecordingError() {
    if (recordingState.currentInlineEndpoint && !recordingState.retryingExpiredUrl) {
        try {
            await loadRecordingUrl(recordingState.currentInlineEndpoint, true);
            return;
        } catch (error) {
            // Fall through to the standard user-facing error.
        }
    }

    // The recording could not load — keep Recording Length at —.
    resetRecordingLength();
    setRecordingStatus('Unable to load recording.');
}

function syncRecordingControls() {
    const audio = recordingState.audio;
    if (!audio) return;

    const progress = document.getElementById('recordingProgress');
    const current = document.getElementById('recordingCurrentTime');
    const duration = document.getElementById('recordingDuration');
    const icon = document.getElementById('recordingToggleIcon');

    if (Number.isFinite(audio.duration) && audio.duration > 0) {
        progress.value = String(Math.round((audio.currentTime / audio.duration) * 1000));
        duration.textContent = formatPlaybackTime(audio.duration);
    }

    current.textContent = formatPlaybackTime(audio.currentTime || 0);
    icon.className = audio.paused ? 'bi bi-play-fill' : 'bi bi-pause-fill';

    if (!audio.paused && !audio.ended) {
        setRecordingStatus('Playing');
    } else if (audio.ended) {
        setRecordingStatus('Ended');
    } else if (audio.currentTime > 0) {
        setRecordingStatus('Paused');
    }
}

function setRecordingStatus(message) {
    const status = document.getElementById('recordingStatus');
    if (status) status.textContent = message;
}

function setRecordingDownloadStatus(message) {
    const status = document.getElementById('recordingDownloadStatus') || document.getElementById('recordingStatus');
    if (status) status.textContent = message;
}

function stopRecordingAudio() {
    const audio = recordingState.audio || document.getElementById('recordingAudio');
    if (audio) {
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
    }

    recordingState.audio = null;
    recordingState.currentInlineEndpoint = null;
    recordingState.currentDownloadEndpoint = null;
    recordingState.currentSignedInlineUrl = null;
    recordingState.currentCallId = null;
    recordingState.isDownloading = false;
    recordingState.suppressCleanupUntil = 0;
    recordingState.retryingExpiredUrl = false;
}

function stopRecordingAudioUnlessDirectDownload() {
    if (recordingState.isDownloading || Date.now() < recordingState.suppressCleanupUntil) return;

    stopRecordingAudio();
}

function formatPlaybackTime(seconds) {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;

    return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

// Recording Length uses HH:MM:SS (e.g. 00:00:23) to match the modal's Durations rows.
function formatDurationHms(seconds) {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainder = seconds % 60;

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

function resetRecordingLength() {
    const el = document.getElementById('recordingLengthValue');
    if (el) el.textContent = '—';
}

// Read the WAV duration straight from the <audio> element after loadedmetadata. This is
// the captured-audio length only — never a recomputation of Asterisk timings — and is
// never persisted. Falls back to — when the duration is not a finite positive number.
function updateRecordingLength() {
    const el = document.getElementById('recordingLengthValue');
    if (!el) return;

    const audio = recordingState.audio;
    if (audio && Number.isFinite(audio.duration) && audio.duration > 0) {
        el.textContent = formatDurationHms(audio.duration);
    } else {
        el.textContent = '—';
    }
}
</script>
@endpush
