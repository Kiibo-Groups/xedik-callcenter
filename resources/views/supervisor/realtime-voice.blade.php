@extends('layouts.app')

@section('title', 'Realtime Voice Monitor')
@section('page-title', 'Realtime Voice Monitor')
@section('page-subtitle', 'Live queue and agent activity')

@push('styles')
<style>
    .rt-shell { display:grid; gap:14px; }
    .rt-control-panel { display:grid; gap:13px; padding:16px; border:1px solid var(--border); border-radius:10px; background:var(--bg-card); box-shadow:var(--shadow-sm); }
    .rt-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
    .rt-title { margin:0; font-size:23px; font-weight:800; color:var(--text-strong); }
    .rt-subtitle { margin-top:3px; color:var(--text-muted); font-size:13px; }
    .rt-toolbar { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .rt-live-pill { display:inline-flex; align-items:center; gap:7px; border-radius:999px; padding:6px 11px; font-size:12px; font-weight:800; border:1px solid var(--border); background:var(--bg-th); color:var(--text-strong); }
    .rt-live-dot { width:8px; height:8px; border-radius:50%; background:#64748b; }
    .rt-live-pill.live .rt-live-dot { background:#16a34a; animation:rt-pulse 1.6s ease-in-out infinite; }
    .rt-live-pill.delayed .rt-live-dot { background:#d97706; }
    .rt-live-pill.offline .rt-live-dot { background:#dc2626; }
    .rt-live-pill.stopped .rt-live-dot { background:#64748b; }
    .rt-updated { color:var(--text-muted); font-size:12px; }
    .rt-paused-message { display:none; color:var(--text-muted); font-size:12px; width:100%; text-align:right; }
    .rt-paused-message.is-visible { display:block; }
    .rt-filters { display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; padding-top:12px; border-top:1px solid var(--border); }
    .rt-filter-field { min-width:190px; }
    .rt-filter-field label { display:block; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); margin-bottom:5px; }
    .rt-filter-field select { width:100%; min-height:36px; border:1px solid var(--border); border-radius:7px; background:var(--bg-page); color:var(--text-strong); padding:6px 9px; font-size:13px; }
    .rt-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(108px,1fr)); gap:10px; }
    .rt-kpi { position:relative; overflow:hidden; border:1px solid var(--border); border-radius:10px; background:var(--bg-card); padding:11px 12px 10px; box-shadow:var(--shadow-sm); transition:transform .15s ease, box-shadow .15s ease; }
    .rt-kpi:hover { transform:translateY(-1px); box-shadow:var(--shadow); }
    .rt-kpi::before { content:""; position:absolute; inset:0 auto 0 0; width:4px; background:var(--accent,#64748b); }
    .rt-kpi-icon { color:var(--accent,#64748b); font-size:15px; margin-bottom:7px; }
    .rt-kpi-label { color:var(--text-muted); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
    .rt-kpi-value { color:var(--text-strong); font-weight:900; font-size:23px; line-height:1; margin-top:5px; font-variant-numeric:tabular-nums; }
    .rt-kpi.ready { --accent:#16a34a; }
    .rt-kpi.ringing { --accent:#2563eb; }
    .rt-kpi.on_call { --accent:#6d28d9; }
    .rt-kpi.outbound_call { --accent:#0891b2; }
    .rt-kpi.wrap_up { --accent:#d97706; }
    .rt-kpi.paused { --accent:#ea580c; }
    .rt-kpi.busy { --accent:#dc2626; }
    .rt-kpi.unavailable { --accent:#64748b; }
    .rt-kpi.waiting_calls { --accent:#0d9488; }
    .rt-card { border:1px solid var(--border); border-radius:10px; background:var(--bg-card); box-shadow:var(--shadow-sm); overflow:hidden; }
    .rt-card-head { display:flex; justify-content:space-between; align-items:center; padding:13px 15px; border-bottom:1px solid var(--border); }
    .rt-card-title { color:var(--text-strong); font-size:15px; font-weight:800; }
    .rt-table-wrap { overflow-x:auto; }
    .rt-table { width:100%; border-collapse:collapse; }
    .rt-table th { position:sticky; top:0; z-index:1; background:var(--bg-card); color:var(--text-muted); font-size:10px; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid var(--border); padding:9px 10px; text-align:left; white-space:nowrap; }
    .rt-table td { color:var(--text-strong); font-size:13px; border-bottom:1px solid var(--border); padding:10px; vertical-align:middle; }
    .rt-table tr:hover td { background:var(--bg-row-hover); }
    .rt-table tr:last-child td { border-bottom:0; }
    .rt-agent { min-width:150px; }
    .rt-agent-name { font-weight:800; color:var(--text-strong); line-height:1.2; }
    .rt-muted { color:var(--text-muted); font-size:12px; margin-top:2px; }
    .rt-mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .rt-badge { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:800; border:1px solid transparent; white-space:nowrap; }
    .rt-badge::before { content:""; width:7px; height:7px; border-radius:50%; background:currentColor; opacity:.9; }
    .rt-state-ready { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
    .rt-state-paused { background:#ffedd5; color:#9a3412; border-color:#fed7aa; }
    .rt-state-ringing { background:#dbeafe; color:#1d4ed8; border-color:#bfdbfe; }
    .rt-state-on-call { background:#ede9fe; color:#5b21b6; border-color:#ddd6fe; }
    .rt-state-outbound-call { background:#cffafe; color:#155e75; border-color:#a5f3fc; }
    .rt-state-wrap-up { background:#fef3c7; color:#92400e; border-color:#fde68a; }
    .rt-state-busy { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    .rt-state-unavailable { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }
    .rt-state-not-ready { background:#e0f2fe; color:#075985; border-color:#bae6fd; }
    .rt-position { display:inline-flex; align-items:center; justify-content:center; min-width:28px; height:24px; border-radius:999px; background:var(--bg-th); color:var(--text-strong); font-weight:800; }
    .rt-wait-time { color:#0f766e; font-weight:900; }
    .rt-empty { padding:28px 18px; color:var(--text-muted); text-align:center; }
    .rt-empty strong { display:block; color:var(--text-strong); font-size:14px; margin-bottom:3px; }
    .rt-alert { display:none; border:1px solid #fde68a; background:#fffbeb; color:#92400e; border-radius:8px; padding:10px 12px; font-size:13px; }
    .rt-spinner { display:inline-block; width:14px; height:14px; border:2px solid currentColor; border-right-color:transparent; border-radius:50%; animation:rt-spin .7s linear infinite; vertical-align:-2px; }
    @keyframes rt-spin { to { transform:rotate(360deg); } }
    @keyframes rt-pulse { 0%,100% { transform:scale(1); opacity:1; } 50% { transform:scale(1.35); opacity:.65; } }
    @media (prefers-reduced-motion: reduce) {
        .rt-live-pill.live .rt-live-dot, .rt-spinner { animation:none; }
        .rt-kpi { transition:none; }
    }
    @media (max-width: 900px) {
        .rt-hero { flex-direction:column; }
        .rt-toolbar { justify-content:flex-start; }
        .rt-filter-field { flex:1 1 220px; }
    }
</style>
@endpush

@section('content')
<div class="rt-shell">
    <div class="rt-control-panel">
        <div class="rt-hero">
            <div>
                <h1 class="rt-title">Realtime Voice Monitor</h1>
                <div class="rt-subtitle">Live queue and agent activity</div>
            </div>
            <div class="rt-toolbar">
                <span id="rt-live-status" class="rt-live-pill offline" aria-live="polite">
                    <span class="rt-live-dot"></span>
                    <span id="rt-live-label">Loading</span>
                </span>
                <span id="rt-updated" class="rt-updated">Updated just now</span>
                <button type="button" id="rt-refresh" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
                <span id="rt-paused-message" class="rt-paused-message">Monitoring paused — data is no longer updating</span>
            </div>
        </div>

        <div class="rt-filters" aria-label="Realtime voice monitor filters">
            <div class="rt-filter-field">
                <label for="rt-campaign-filter">Campaign</label>
                <select id="rt-campaign-filter">
                    <option value="">All Campaigns</option>
                    @foreach($filterOptions['campaigns'] ?? [] as $campaign)
                        <option value="{{ $campaign['id'] }}">{{ $campaign['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="rt-filter-field">
                <label for="rt-queue-filter">Queue</label>
                <select id="rt-queue-filter">
                    <option value="">All Queues</option>
                    @foreach($filterOptions['queues'] ?? [] as $queue)
                        <option value="{{ $queue['id'] }}" data-campaign-id="{{ $queue['campaign_id'] }}">{{ $queue['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" id="rt-start" class="btn btn-primary btn-sm" aria-pressed="false">
                <i class="bi bi-play-fill"></i> Start Monitoring
            </button>
        </div>
    </div>

    <div id="rt-error" class="rt-alert">
        Unable to refresh realtime voice data. Showing the last successful snapshot.
    </div>

    <div class="rt-kpis">
        @foreach([
            'ready' => ['Ready', 'bi-check-circle'],
            'ringing' => ['Ringing', 'bi-telephone-inbound'],
            'on_call' => ['On Call', 'bi-headset'],
            'outbound_call' => ['Outbound Call', 'bi-telephone-outbound'],
            'wrap_up' => ['Wrap-Up', 'bi-clipboard-check'],
            'paused' => ['Paused', 'bi-pause-circle'],
            'busy' => ['Busy', 'bi-slash-circle'],
            'unavailable' => ['Unavailable', 'bi-wifi-off', 'Agent unavailable according to Asterisk queue state.'],
            'waiting_calls' => ['Waiting Calls', 'bi-people'],
        ] as $key => $meta)
            <div class="rt-kpi {{ $key }}" @if(isset($meta[2])) title="{{ $meta[2] }}" @endif>
                <i class="bi {{ $meta[1] }} rt-kpi-icon" aria-hidden="true"></i>
                <div class="rt-kpi-label">{{ $meta[0] }}</div>
                <div class="rt-kpi-value" data-kpi="{{ $key }}">0</div>
            </div>
        @endforeach
    </div>

    <div class="rt-card">
        <div class="rt-card-head">
            <div class="rt-card-title">Agents in Real Time</div>
        </div>
        <div class="rt-table-wrap">
            <table class="rt-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Extension</th>
                        <th>Status</th>
                        <th>Time in Status</th>
                        <th>Login Time</th>
                        <th>Queue</th>
                        <th>Current Activity</th>
                    </tr>
                </thead>
                <tbody id="rt-agents-body">
                    <tr><td colspan="7" class="rt-empty"><strong>No active agents</strong>Agents will appear when they sign in.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="rt-card">
        <div class="rt-card-head">
            <div class="rt-card-title">Waiting Calls</div>
        </div>
        <div class="rt-table-wrap">
            <table class="rt-table">
                <thead>
                    <tr>
                        <th>ANI</th>
                        <th>Queue</th>
                        <th>Position</th>
                        <th>Waiting Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="rt-waiting-body">
                    <tr><td colspan="5" class="rt-empty"><strong>No calls waiting</strong></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const dataUrl = @json($monitorDataUrl);
    const pollMs = 3000;
    let isMonitorRequestInFlight = false;
    let started = false;
    let lastPayload = null;
    let monitorIntervalId = null;
    let liveCounterIntervalId = null;
    let serverOffsetMs = 0;
    let lastCorrectedNowMs = 0;
    let pendingRenderFrameId = null;
    let pendingRenderPayload = null;
    let pendingRenderTimings = null;
    let nextLiveCounterTickAtMs = Date.now() + 1000;
    const futureEpochToleranceSeconds = 5;
    const debugRenderTiming = Boolean(window.xedik_REALTIME_MONITOR_DEBUG);

    const stateClass = (state) => 'rt-state-' + String(state || 'not_ready').replace(/_/g, '-');
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
    const dash = (value) => value === null || value === undefined || value === '' ? '—' : value;
    const duration = (seconds) => {
        if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) return '&mdash;';
        const total = Math.max(0, Math.floor(Number(seconds)));
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    };
    const monitorNowEpoch = () => {
        return Math.floor(monitorNowMs() / 1000);
    };
    const monitorNowMs = () => {
        const next = Date.now() + serverOffsetMs;
        if (next < lastCorrectedNowMs) {
            return lastCorrectedNowMs;
        }
        lastCorrectedNowMs = next;
        return next;
    };
    const secondsSinceEpoch = (epoch) => {
        const parsed = Number(epoch);
        if (!Number.isInteger(parsed) || parsed <= 0) return null;
        const elapsed = monitorNowEpoch() - parsed;
        if (elapsed < -futureEpochToleranceSeconds) {
            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn('Realtime monitor timer epoch is in the future.', {epoch: parsed, now: monitorNowEpoch()});
            }
            return null;
        }
        return Math.max(0, elapsed);
    };
    const dateTime = (iso) => {
        if (!iso) return '';
        const date = new Date(iso);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
    };
    const agoFromEpoch = (epoch) => {
        const seconds = secondsSinceEpoch(epoch);
        if (seconds === null) return 'No snapshot received';
        if (seconds < 5) return 'Updated just now';
        if (seconds < 60) return `Updated ${seconds}s ago`;
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `Updated ${minutes}m ago`;
        return `Updated ${Math.floor(minutes / 60)}h ${minutes % 60}m ago`;
    };

    function selectedFilters() {
        const params = new URLSearchParams();
        const campaignId = document.getElementById('rt-campaign-filter').value;
        const queueId = document.getElementById('rt-queue-filter').value;
        if (campaignId) params.set('campaign_id', campaignId);
        if (queueId) params.set('queue_id', queueId);
        return params.toString();
    }

    function updateQueueOptions() {
        const campaignId = document.getElementById('rt-campaign-filter').value;
        const queueSelect = document.getElementById('rt-queue-filter');
        Array.from(queueSelect.options).forEach((option) => {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            option.hidden = campaignId && option.dataset.campaignId !== campaignId;
        });
        if (queueSelect.selectedOptions[0]?.hidden) {
            queueSelect.value = '';
        }
    }

    function updateServerOffset(payload, requestStartedAtMs, responseReceivedAtMs) {
        if (!Number.isInteger(Number(payload.generated_at_epoch))) {
            return;
        }

        const startedAt = Number.isFinite(requestStartedAtMs) ? requestStartedAtMs : Date.now();
        const receivedAt = Number.isFinite(responseReceivedAtMs) ? responseReceivedAtMs : Date.now();
        const midpointMs = startedAt + ((receivedAt - startedAt) / 2);
        serverOffsetMs = (Number(payload.generated_at_epoch) * 1000) - midpointMs;
    }

    function render(payload, requestStartedAtMs = null, responseReceivedAtMs = null, parseStartedAtMs = null, parseFinishedAtMs = null) {
        pendingRenderPayload = payload;
        pendingRenderTimings = {requestStartedAtMs, responseReceivedAtMs, parseStartedAtMs, parseFinishedAtMs};
        if (pendingRenderFrameId !== null) {
            return;
        }

        pendingRenderFrameId = window.requestAnimationFrame(() => {
            pendingRenderFrameId = null;
            const nextPayload = pendingRenderPayload;
            const nextTimings = pendingRenderTimings || {};
            pendingRenderPayload = null;
            pendingRenderTimings = null;
            applyMonitorData(nextPayload, nextTimings);
        });
    }

    function applyMonitorData(payload, timings = {}) {
        const totalStartedAtMs = performance.now();
        const agents = payload.agents || [];
        const waitingCalls = payload.waiting_calls || [];
        lastPayload = payload;
        if (Number.isFinite(timings.parseStartedAtMs) && Number.isFinite(timings.parseFinishedAtMs)) {
            reportTiming('responseParse', timings.parseStartedAtMs, null, {
                elapsedMs: timings.parseFinishedAtMs - timings.parseStartedAtMs
            });
        }

        const offsetStartedAtMs = performance.now();
        updateServerOffset(payload, timings.requestStartedAtMs, timings.responseReceivedAtMs);
        reportTiming('updateServerOffset', offsetStartedAtMs);

        const freshnessStartedAtMs = performance.now();
        renderFreshness(payload.freshness || {}, true);
        reportTiming('renderFreshness', freshnessStartedAtMs);

        const kpisStartedAtMs = performance.now();
        renderKpis(payload.kpis || {});
        reportTiming('renderKpis', kpisStartedAtMs);

        const agentsStartedAtMs = performance.now();
        renderAgents(agents);
        reportTiming('renderAgents', agentsStartedAtMs, agents.length);

        const waitingStartedAtMs = performance.now();
        renderWaitingCalls(waitingCalls);
        reportTiming('renderWaitingCalls', waitingStartedAtMs, waitingCalls.length);

        const countersStartedAtMs = performance.now();
        updateLiveCounters(true);
        if (!started) {
            renderStoppedState();
        }
        reportTiming('updateLiveCounters', countersStartedAtMs);
        reportTiming('applyMonitorData', totalStartedAtMs, agents.length + waitingCalls.length);
    }

    function renderFreshness(freshness, forceSnapshotUpdate = false) {
        if (!started && !forceSnapshotUpdate) {
            renderStoppedState();
            return;
        }

        const status = freshness.status || 'offline';
        const pill = document.getElementById('rt-live-status');
        const nextPillClass = `rt-live-pill ${status}`;
        if (pill.className !== nextPillClass) {
            pill.className = nextPillClass;
        }
        setText(document.getElementById('rt-live-label'), status === 'live' ? 'Live' : (status === 'delayed' ? 'Delayed' : 'Offline'));
        const snapshotEpoch = freshness.last_snapshot_epoch || null;
        const updated = document.getElementById('rt-updated');
        setText(updated, agoFromEpoch(snapshotEpoch));
        if (freshness.last_snapshot_at) {
            const nextTitle = dateTime(freshness.last_snapshot_at);
            if (updated.getAttribute('title') !== nextTitle) {
                updated.title = nextTitle;
            }
        } else {
            updated.removeAttribute('title');
        }
    }

    function renderStoppedState() {
        const pill = document.getElementById('rt-live-status');
        if (pill.className !== 'rt-live-pill stopped') {
            pill.className = 'rt-live-pill stopped';
        }
        setText(document.getElementById('rt-live-label'), 'Stopped');
        document.getElementById('rt-paused-message').classList.add('is-visible');
    }

    function clearStoppedState() {
        document.getElementById('rt-paused-message').classList.remove('is-visible');
    }

    function renderKpis(kpis) {
        document.querySelectorAll('[data-kpi]').forEach((el) => {
            const next = String(kpis[el.dataset.kpi] ?? 0);
            if (el.textContent !== next) {
                el.textContent = next;
            }
        });
    }

    function textOrDash(value) {
        return value === null || value === undefined || value === '' ? '—' : String(value);
    }

    function agentKey(agent) {
        return String(agent.identity || agent.extension || agent.agent || '');
    }

    function setText(el, value) {
        if (el && el.textContent !== value) {
            el.textContent = value;
        }
    }

    function setTimerEpoch(el, epoch, fallbackSeconds) {
        if (!el) return;

        const nextEpoch = epoch ? String(epoch) : '';
        if (el.dataset.durationEpoch !== nextEpoch) {
            el.dataset.durationEpoch = nextEpoch;
            el.innerHTML = duration(nextEpoch ? secondsSinceEpoch(nextEpoch) : fallbackSeconds);
        }
    }

    function reportTiming(label, startedAtMs, count = null, extra = {}) {
        if (!debugRenderTiming || !window.console || typeof window.console.debug !== 'function') {
            return;
        }

        const elapsedMs = performance.now() - startedAtMs;
        window.console.debug('Realtime monitor timing', {label, count, elapsedMs, ...extra});
    }

    function createAgentRow(agent) {
        const row = document.createElement('tr');
        row.dataset.agentKey = agentKey(agent);
        row.innerHTML = `
            <td>
                <div class="rt-agent">
                    <span class="rt-agent-name" data-agent-cell="name"></span>
                </div>
            </td>
            <td data-agent-cell="extension"></td>
            <td>
                <span data-agent-cell="status-badge"></span>
            </td>
            <td class="rt-mono"><span data-agent-timer="status"></span></td>
            <td class="rt-mono"><span data-agent-timer="login"></span></td>
            <td data-agent-cell="queue"></td>
            <td data-agent-cell="activity"></td>
        `;
        updateAgentRow(row, agent);

        return row;
    }

    function updateAgentRow(row, agent) {
        const status = agent.operational_status || agent.status;
        row.dataset.agent = agentKey(agent);
        row.dataset.status = status || '';

        setText(row.querySelector('[data-agent-cell="name"]'), textOrDash(agent.agent));
        setText(row.querySelector('[data-agent-cell="extension"]'), textOrDash(agent.extension));
        setText(row.querySelector('[data-agent-cell="queue"]'), textOrDash(agent.queue));
        setText(row.querySelector('[data-agent-cell="activity"]'), textOrDash(agent.current_activity));

        const queueCell = row.querySelector('[data-agent-cell="queue"]');
        if (queueCell) {
            const queueTitle = agent.queue_detail || '';
            if (queueCell.getAttribute('title') !== queueTitle) {
                queueCell.setAttribute('title', queueTitle);
            }
        }

        const badge = row.querySelector('[data-agent-cell="status-badge"]');
        if (badge) {
            const nextBadgeClass = `rt-badge ${stateClass(status)}`;
            if (badge.className !== nextBadgeClass) {
                badge.className = nextBadgeClass;
            }
            const title = status === 'unavailable' ? 'Agent unavailable according to Asterisk queue state.' : '';
            if (title) {
                badge.setAttribute('title', title);
            } else {
                badge.removeAttribute('title');
            }
            setText(badge, agent.operational_status_label || agent.status_label || '');
        }

        setTimerEpoch(row.querySelector('[data-agent-timer="status"]'), agent.status_since_epoch, agent.time_in_status_seconds);
        setTimerEpoch(row.querySelector('[data-agent-timer="login"]'), agent.login_since_epoch, agent.login_seconds);
    }

    function renderAgents(agents) {
        const renderStartedAtMs = performance.now();
        const body = document.getElementById('rt-agents-body');
        if (agents.length === 0) {
            if (!body.querySelector('.rt-empty')) {
                body.innerHTML = '<tr><td colspan="7" class="rt-empty"><strong>No active agents</strong>Agents will appear when they sign in.</td></tr>';
            }
            reportTiming('agents', renderStartedAtMs, 0);
            return;
        }

        body.querySelectorAll('.rt-empty').forEach((row) => row.closest('tr')?.remove());

        const existingRows = new Map(
            Array.from(body.querySelectorAll('tr[data-agent-key]')).map((row) => [row.dataset.agentKey, row])
        );
        const seen = new Set();

        agents.forEach((agent, index) => {
            const key = agentKey(agent);
            seen.add(key);

            let row = existingRows.get(key);
            if (row) {
                updateAgentRow(row, agent);
            } else {
                row = createAgentRow(agent);
            }

            const currentAtIndex = body.children[index] || null;
            if (currentAtIndex !== row) {
                body.insertBefore(row, currentAtIndex);
            }
        });

        existingRows.forEach((row, key) => {
            if (!seen.has(key)) {
                row.remove();
            }
        });
        reportTiming('agents', renderStartedAtMs, agents.length);
    }

    function waitingCallKey(call) {
        return String(call.interaction_key || `${call.ani_original || call.ani || ''}:${call.queue_detail || call.queue || ''}:${call.position || ''}`);
    }

    function createWaitingCallRow(call) {
        const row = document.createElement('tr');
        row.dataset.waitingCallKey = waitingCallKey(call);
        row.innerHTML = `
            <td data-waiting-cell="ani"></td>
            <td data-waiting-cell="queue"></td>
            <td><span class="rt-position" data-waiting-cell="position"></span></td>
            <td class="rt-mono rt-wait-time"><span data-waiting-timer="wait"></span></td>
            <td><span class="rt-badge rt-state-ringing" data-waiting-cell="status"></span></td>
        `;
        updateWaitingCallRow(row, call);

        return row;
    }

    function setWaitingTimerEpoch(el, epoch, fallbackSeconds, title) {
        if (!el) return;

        const nextEpoch = epoch ? String(epoch) : '';
        const nextTitle = title || '';
        if (el.dataset.waitingSinceEpoch !== nextEpoch) {
            el.dataset.waitingSinceEpoch = nextEpoch;
            el.innerHTML = duration(nextEpoch ? secondsSinceEpoch(nextEpoch) : fallbackSeconds);
        }
        if (el.getAttribute('title') !== nextTitle) {
            if (nextTitle) {
                el.setAttribute('title', nextTitle);
            } else {
                el.removeAttribute('title');
            }
        }
    }

    function updateWaitingCallRow(row, call) {
        row.dataset.waitingCall = waitingCallKey(call);

        const aniCell = row.querySelector('[data-waiting-cell="ani"]');
        setText(aniCell, textOrDash(call.ani));
        const aniTitle = call.ani_original || call.ani || '';
        if (aniCell && aniCell.getAttribute('title') !== aniTitle) {
            aniCell.setAttribute('title', aniTitle);
        }

        const queueCell = row.querySelector('[data-waiting-cell="queue"]');
        setText(queueCell, textOrDash(call.queue));
        const queueTitle = call.queue_detail || '';
        if (queueCell && queueCell.getAttribute('title') !== queueTitle) {
            queueCell.setAttribute('title', queueTitle);
        }

        setText(row.querySelector('[data-waiting-cell="position"]'), textOrDash(call.position));
        setText(row.querySelector('[data-waiting-cell="status"]'), textOrDash(call.status));
        setWaitingTimerEpoch(
            row.querySelector('[data-waiting-timer="wait"]'),
            call.waiting_since_epoch,
            call.waiting_seconds,
            dateTime(call.waiting_since)
        );
    }

    function renderWaitingCalls(calls) {
        const renderStartedAtMs = performance.now();
        const body = document.getElementById('rt-waiting-body');
        if (calls.length === 0) {
            if (!body.querySelector('.rt-empty')) {
                body.innerHTML = '<tr><td colspan="5" class="rt-empty"><strong>No calls waiting</strong></td></tr>';
            }
            reportTiming('waiting_calls', renderStartedAtMs, 0);
            return;
        }

        body.querySelectorAll('.rt-empty').forEach((row) => row.closest('tr')?.remove());

        const existingRows = new Map(
            Array.from(body.querySelectorAll('tr[data-waiting-call-key]')).map((row) => [row.dataset.waitingCallKey, row])
        );
        const seen = new Set();

        calls.forEach((call, index) => {
            const key = waitingCallKey(call);
            seen.add(key);

            let row = existingRows.get(key);
            if (row) {
                updateWaitingCallRow(row, call);
            } else {
                row = createWaitingCallRow(call);
            }

            const currentAtIndex = body.children[index] || null;
            if (currentAtIndex !== row) {
                body.insertBefore(row, currentAtIndex);
            }
        });

        existingRows.forEach((row, key) => {
            if (!seen.has(key)) {
                row.remove();
            }
        });
        reportTiming('waiting_calls', renderStartedAtMs, calls.length);
    }

    function updateLiveCounters(force = false) {
        if (!started && !force) {
            return;
        }

        document.querySelectorAll('[data-duration-epoch]').forEach((el) => {
            const next = duration(secondsSinceEpoch(el.dataset.durationEpoch));
            if (el.innerHTML !== next) {
                el.innerHTML = next;
            }
        });
        document.querySelectorAll('[data-waiting-since-epoch]').forEach((el) => {
            const next = duration(secondsSinceEpoch(el.dataset.waitingSinceEpoch));
            if (el.innerHTML !== next) {
                el.innerHTML = next;
            }
        });
        if (lastPayload?.freshness) {
            renderFreshness(lastPayload.freshness);
        }
    }

    function updateLiveCountersWithDriftMeasurement() {
        const actualTickAtMs = Date.now();
        const tickDriftMs = actualTickAtMs - nextLiveCounterTickAtMs;
        nextLiveCounterTickAtMs = actualTickAtMs + 1000;
        if (debugRenderTiming) {
            reportTiming('liveCounterTick', performance.now(), null, {tickDriftMs});
        }
        updateLiveCounters();
    }

    async function loadData() {
        if (isMonitorRequestInFlight) return;
        isMonitorRequestInFlight = true;
        const requestStartedAtMs = Date.now();
        const refreshButton = document.getElementById('rt-refresh');
        const startButton = document.getElementById('rt-start');
        const error = document.getElementById('rt-error');
        const original = refreshButton.innerHTML;
        refreshButton.disabled = true;
        startButton.disabled = true;
        refreshButton.innerHTML = '<span class="rt-spinner"></span> Refreshing';

        try {
            const query = selectedFilters();
            const response = await fetch(query ? `${dataUrl}?${query}` : dataUrl, {
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin'
            });
            if (!response.ok) throw new Error('Request failed');
            const parseStartedAtMs = performance.now();
            const payload = await response.json();
            const parseFinishedAtMs = performance.now();
            const responseReceivedAtMs = Date.now();
            render(payload, requestStartedAtMs, responseReceivedAtMs, parseStartedAtMs, parseFinishedAtMs);
            error.style.display = 'none';
        } catch (e) {
            error.style.display = 'block';
        } finally {
            refreshButton.disabled = false;
            startButton.disabled = false;
            refreshButton.innerHTML = original;
            isMonitorRequestInFlight = false;
            updateStartStopButton();
        }
    }

    function updateStartStopButton() {
        const startButton = document.getElementById('rt-start');
        const campaignFilter = document.getElementById('rt-campaign-filter');
        const queueFilter = document.getElementById('rt-queue-filter');
        startButton.setAttribute('aria-pressed', started ? 'true' : 'false');
        startButton.innerHTML = started
            ? '<i class="bi bi-stop-fill"></i> Stop Monitoring'
            : '<i class="bi bi-play-fill"></i> Start Monitoring';
        campaignFilter.disabled = started;
        queueFilter.disabled = started;
    }

    function startMonitoring() {
        if (started) return;
        started = true;
        clearStoppedState();
        updateStartStopButton();
        if (monitorIntervalId === null) {
            monitorIntervalId = window.setInterval(loadData, pollMs);
        }
        loadData();
    }

    function stopMonitoring() {
        started = false;
        if (monitorIntervalId !== null) {
            window.clearInterval(monitorIntervalId);
            monitorIntervalId = null;
        }
        renderStoppedState();
        updateStartStopButton();
    }

    function toggleMonitoring() {
        if (started) {
            stopMonitoring();
            return;
        }
        startMonitoring();
    }

    document.getElementById('rt-campaign-filter').addEventListener('change', updateQueueOptions);
    document.getElementById('rt-start').addEventListener('click', toggleMonitoring);
    document.getElementById('rt-refresh').addEventListener('click', loadData);
    liveCounterIntervalId = window.setInterval(updateLiveCountersWithDriftMeasurement, 1000);
    updateQueueOptions();
    updateStartStopButton();

    window.addEventListener('beforeunload', function () {
        window.clearInterval(monitorIntervalId);
        window.clearInterval(liveCounterIntervalId);
    });
})();
</script>
@endpush
