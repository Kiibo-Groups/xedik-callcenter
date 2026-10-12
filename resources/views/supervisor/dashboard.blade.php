@extends('layouts.app')

@section('title', 'Voice Dashboard')
@section('page-title', 'Voice Dashboard')
@section('page-subtitle', 'KPIs and operational performance')

@push('styles')
<style>
    .vd-shell { display:grid; gap:16px; }
    .vd-panel { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; box-shadow:var(--shadow-sm); }
    .vd-control { padding:18px; display:grid; gap:14px; }
    .vd-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
    .vd-title { margin:0; font-size:25px; font-weight:850; color:var(--text-strong); }
    .vd-subtitle { margin-top:4px; font-size:13px; color:var(--text-muted); }
    .vd-status { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .vd-pill { display:inline-flex; align-items:center; gap:7px; border:1px solid var(--border); border-radius:999px; padding:6px 11px; font-size:12px; font-weight:800; color:var(--text-strong); background:var(--bg-th); }
    .vd-pill-dot { width:8px; height:8px; border-radius:50%; background:#64748b; }
    .vd-pill.live .vd-pill-dot { background:#16a34a; animation:vd-pulse 1.6s ease-in-out infinite; }
    .vd-pill.loading .vd-pill-dot { background:#2563eb; }
    .vd-pill.error .vd-pill-dot { background:#dc2626; }
    .vd-filterbar { display:grid; grid-template-columns:1.2fr 1.2fr 1fr 1fr 1fr auto auto; gap:10px; align-items:end; padding-top:14px; border-top:1px solid var(--border); }
    .vd-field label { display:block; margin-bottom:5px; color:var(--text-muted); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .vd-field select, .vd-field input { width:100%; min-height:38px; border:1px solid var(--border); border-radius:8px; background:var(--bg-page); color:var(--text-strong); padding:7px 10px; font-size:13px; }
    .vd-field select:disabled { cursor:not-allowed; opacity:.6; }
    .vd-custom-range { display:none; grid-template-columns:1fr 1fr; gap:8px; }
    .vd-custom-range.is-visible { display:grid; }
    .vd-status-text { color:var(--text-muted); font-size:12px; }
    .vd-alert { display:none; border:1px solid #fecaca; background:#fef2f2; color:#991b1b; border-radius:9px; padding:10px 12px; font-size:13px; }
    .vd-alert.is-visible { display:block; }
    .vd-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
    .vd-kpi { min-height:126px; padding:15px; border:1px solid var(--border); border-radius:12px; background:var(--bg-card); box-shadow:var(--shadow-sm); display:flex; flex-direction:column; justify-content:space-between; transition:transform .15s ease, box-shadow .15s ease; }
    .vd-kpi:hover { transform:translateY(-1px); box-shadow:var(--shadow); }
    .vd-kpi-top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .vd-kpi-icon { width:38px; height:38px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; color:var(--accent); background:color-mix(in srgb, var(--accent) 13%, transparent); font-size:18px; }
    .vd-kpi-label { color:var(--text-muted); font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .vd-kpi-value { color:var(--text-strong); font-weight:900; font-size:30px; line-height:1; font-variant-numeric:tabular-nums; }
    .vd-kpi-unit { color:var(--text-muted); font-size:12px; font-weight:700; margin-top:5px; }
    .vd-kpi[data-key="total_calls"] { --accent:#2563eb; }
    .vd-kpi[data-key="calls_in_progress"] { --accent:#d97706; }
    .vd-kpi[data-key="total_answered"] { --accent:#0d9488; }
    .vd-kpi[data-key="overall_aht"] { --accent:#9333ea; }
    .vd-kpi[data-key="inbound_calls"] { --accent:#059669; }
    .vd-kpi[data-key="inbound_answered"] { --accent:#0d9488; }
    .vd-kpi[data-key="queue_timeouts"] { --accent:#f97316; }
    .vd-kpi[data-key="queue_timeout_rate"] { --accent:#c2410c; }
    .vd-kpi[data-key="inbound_answer_rate"] { --accent:#0284c7; }
    .vd-kpi[data-key="inbound_aht"] { --accent:#9333ea; }
    .vd-kpi[data-key="inbound_average_talk_time"] { --accent:#4f46e5; }
    .vd-kpi[data-key="inbound_average_wrap_up"] { --accent:#ca8a04; }
    .vd-kpi[data-key="outbound_calls"] { --accent:#7c3aed; }
    .vd-kpi[data-key="outbound_answered"] { --accent:#0d9488; }
    .vd-kpi[data-key="outbound_unanswered"] { --accent:#f97316; }
    .vd-kpi[data-key="outbound_answer_rate"] { --accent:#0284c7; }
    .vd-kpi[data-key="outbound_aht"] { --accent:#9333ea; }
    .vd-kpi[data-key="outbound_average_talk_time"] { --accent:#4f46e5; }
    .vd-kpi[data-key="outbound_average_wrap_up"] { --accent:#ca8a04; }
    .vd-kpi[data-key="outbound_contact_rate"] { --accent:#64748b; }
    .vd-kpi[data-key="abandoned_calls"] { --accent:#dc2626; }
    .vd-kpi[data-key="asa"] { --accent:#0891b2; }
    .vd-kpi[data-key="service_level"] { --accent:#16a34a; }
    .vd-kpi[data-key="abandon_rate"] { --accent:#e11d48; }
    .vd-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:14px; }
    .vd-widget { grid-column:span 6; min-width:0; }
    .vd-widget-wide { grid-column:span 12; }
    .vd-card-head { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--border); }
    .vd-card-title { font-size:15px; font-weight:850; color:var(--text-strong); }
    .vd-card-body { padding:15px 16px; }
    .vd-chart-box { min-height:280px; position:relative; }
    .vd-table-wrap { overflow-x:auto; }
    .vd-table { width:100%; border-collapse:collapse; }
    .vd-table th { color:var(--text-muted); font-size:10px; text-transform:uppercase; letter-spacing:.04em; text-align:left; border-bottom:1px solid var(--border); padding:9px 10px; white-space:nowrap; }
    .vd-table td { border-bottom:1px solid var(--border); padding:10px; font-size:13px; color:var(--text-strong); vertical-align:middle; }
    .vd-table tr:last-child td { border-bottom:0; }
    .vd-empty { color:var(--text-muted); text-align:center; padding:24px; font-size:13px; }
    .vd-drawer-backdrop { display:none; position:fixed; inset:0; z-index:1040; background:rgba(15,23,42,.45); }
    .vd-drawer-backdrop.is-open { display:block; }
    .vd-drawer { position:fixed; top:0; right:0; bottom:0; z-index:1050; width:min(430px,94vw); background:var(--bg-card); border-left:1px solid var(--border); box-shadow:var(--shadow-lg); transform:translateX(100%); transition:transform .18s ease; display:flex; flex-direction:column; }
    .vd-drawer.is-open { transform:translateX(0); }
    .vd-drawer-head { padding:18px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
    .vd-drawer-body { padding:18px; overflow:auto; display:grid; gap:18px; }
    .vd-switch-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px solid var(--border); }
    .vd-switch-row:last-child { border-bottom:0; }
    .vd-toast { display:none; color:#166534; background:#dcfce7; border:1px solid #bbf7d0; border-radius:8px; padding:9px 11px; font-size:13px; }
    .vd-toast.is-visible { display:block; }
    @keyframes vd-pulse { 50% { opacity:.55; transform:scale(1.35); } }
    @media (prefers-reduced-motion:reduce) { .vd-pill.live .vd-pill-dot, .vd-kpi, .vd-drawer { animation:none; transition:none; } }
    @media (max-width:1200px) { .vd-filterbar { grid-template-columns:repeat(3,1fr); } .vd-widget { grid-column:span 12; } }
    @media (max-width:760px) { .vd-hero { flex-direction:column; } .vd-status { justify-content:flex-start; } .vd-filterbar { grid-template-columns:1fr; } }
</style>
@endpush

@section('content')
<div class="vd-shell" data-dashboard-root
     data-data-url="{{ $dashboardDataUrl }}"
     data-preferences-key="xedik.voiceDashboard.{{ $tenantKey }}.{{ $userKey }}.preferences">
    <section class="vd-panel vd-control">
        <div class="vd-hero">
            <div>
                <h1 class="vd-title">Voice Dashboard</h1>
                <div class="vd-subtitle">KPIs and operational performance</div>
            </div>
            <div class="vd-status">
                <span id="vd-live-pill" class="vd-pill static"><span class="vd-pill-dot"></span><span id="vd-live-label">Static</span></span>
                <span id="vd-last-update" class="vd-status-text">Last update: N/A</span>
                <span id="vd-next-update" class="vd-status-text">Next update: Off</span>
                <button type="button" id="vd-refresh" class="btn btn-primary btn-sm"><i class="bi bi-arrow-clockwise"></i> Refresh now</button>
                <button type="button" id="vd-customize" class="btn btn-secondary btn-sm"><i class="bi bi-sliders"></i> Customize Dashboard</button>
            </div>
        </div>
        <div class="vd-filterbar">
            <div class="vd-field">
                <label for="vd-campaign">Campaign</label>
                <select id="vd-campaign">
                    <option value="">All Campaigns</option>
                    @foreach($filterOptions['campaigns'] ?? [] as $campaign)
                        <option value="{{ $campaign['id'] }}">{{ $campaign['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="vd-field">
                <label for="vd-queue">Queue</label>
                <select id="vd-queue">
                    <option value="">All Queues</option>
                    @foreach($filterOptions['queues'] ?? [] as $queue)
                        <option value="{{ $queue['id'] }}" data-campaign-id="{{ $queue['campaign_id'] }}">{{ $queue['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="vd-field">
                <label for="vd-preset">Date Range</label>
                <select id="vd-preset">
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="last_7_days">Last 7 Days</option>
                    <option value="last_30_days">Last 30 Days</option>
                    <option value="this_month">This Month</option>
                    <option value="custom">Custom Range</option>
                </select>
            </div>
            <div class="vd-custom-range" id="vd-custom-range">
                <div class="vd-field">
                    <label for="vd-date-from">From</label>
                    <input type="date" id="vd-date-from">
                </div>
                <div class="vd-field">
                    <label for="vd-date-to">To</label>
                    <input type="date" id="vd-date-to">
                </div>
            </div>
            <div class="vd-field">
                <label for="vd-auto-refresh">Auto Refresh</label>
                <select id="vd-auto-refresh" title="Auto Refresh is available only for Today.">
                    <option value="0">Off</option>
                    <option value="30">30 seconds</option>
                    <option value="60">1 minute</option>
                    <option value="300">5 minutes</option>
                </select>
            </div>
        </div>
        <div id="vd-error" class="vd-alert">Unable to load dashboard data.</div>
    </section>

    <section class="vd-kpis" id="vd-kpis"></section>

    <section class="vd-grid">
        <div class="vd-panel vd-widget vd-widget-wide" data-dashboard-widget="calls_by_hour">
            <div class="vd-card-head"><div class="vd-card-title">Calls by Hour</div></div>
            <div class="vd-card-body"><div class="vd-chart-box"><canvas id="vd-calls-chart"></canvas></div></div>
        </div>
        <div class="vd-panel vd-widget" data-dashboard-widget="direction_breakdown">
            <div class="vd-card-head"><div class="vd-card-title">Inbound vs Outbound</div></div>
            <div class="vd-card-body"><div class="vd-chart-box"><canvas id="vd-direction-chart"></canvas></div></div>
        </div>
        <div class="vd-panel vd-widget" data-dashboard-widget="outcome_breakdown">
            <div class="vd-card-head"><div class="vd-card-title">Answered vs Unanswered</div></div>
            <div class="vd-card-body"><div class="vd-chart-box"><canvas id="vd-outcome-chart"></canvas></div></div>
        </div>
        <div class="vd-panel vd-widget vd-widget-wide" data-dashboard-widget="top_queues">
            <div class="vd-card-head"><div class="vd-card-title">Top Queues by Volume</div></div>
            <div class="vd-card-body vd-table-wrap"><table class="vd-table"><thead><tr><th>Queue</th><th>Total Calls</th><th>Inbound</th><th>Outbound</th><th>Answered</th><th>Abandoned</th><th>ASA</th><th>AHT</th><th>Service Level</th></tr></thead><tbody id="vd-top-queues"></tbody></table></div>
        </div>
        <div class="vd-panel vd-widget vd-widget-wide" data-dashboard-widget="top_agents">
            <div class="vd-card-head"><div class="vd-card-title">Top Agents by Calls</div></div>
            <div class="vd-card-body vd-table-wrap"><table class="vd-table"><thead><tr><th>Agent</th><th>Total Calls</th><th>Handled</th><th>Inbound</th><th>Outbound</th><th>Average Talk Time</th><th>AHT</th><th>Average Wrap-Up</th></tr></thead><tbody id="vd-top-agents"></tbody></table></div>
        </div>
        <div class="vd-panel vd-widget" data-dashboard-widget="dispositions">
            <div class="vd-card-head"><div class="vd-card-title">Dispositions Breakdown</div></div>
            <div class="vd-card-body"><div class="vd-chart-box"><canvas id="vd-dispositions-chart"></canvas></div></div>
        </div>
        <div class="vd-panel vd-widget" data-dashboard-widget="calls_in_progress_detail">
            <div class="vd-card-head"><div class="vd-card-title">Calls in Progress</div></div>
            <div class="vd-card-body vd-table-wrap"><table class="vd-table"><thead><tr><th>Direction</th><th>Phone</th><th>Agent</th><th>Queue</th><th>State</th></tr></thead><tbody id="vd-progress-calls"></tbody></table></div>
        </div>
        <div class="vd-panel vd-widget vd-widget-wide" data-dashboard-widget="recent_calls">
            <div class="vd-card-head"><div class="vd-card-title">Recent Calls</div></div>
            <div class="vd-card-body vd-table-wrap"><table class="vd-table"><thead><tr><th>Date/Time</th><th>Direction</th><th>ANI/Phone</th><th>Agent</th><th>Campaign</th><th>Queue</th><th>Status/Result</th><th>Duration</th><th>Disposition</th></tr></thead><tbody id="vd-recent-calls"></tbody></table></div>
        </div>
    </section>

    <div id="vd-drawer-backdrop" class="vd-drawer-backdrop"></div>
    <aside id="vd-drawer" class="vd-drawer" aria-hidden="true">
        <div class="vd-drawer-head">
            <strong>Customize Dashboard</strong>
            <button type="button" id="vd-drawer-close" class="btn btn-secondary btn-sm"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="vd-drawer-body">
            <div class="vd-field">
                <label for="vd-preset-view">Preset</label>
                <select id="vd-preset-view">
                    <option value="supervisor">Supervisor View</option>
                    <option value="executive">Executive View</option>
                    <option value="inbound">Inbound View</option>
                    <option value="outbound">Outbound View</option>
                    <option value="custom">My Custom View</option>
                </select>
            </div>
            <div>
                <h4 class="vd-card-title">KPIs</h4>
                <div id="vd-kpi-switches"></div>
            </div>
            <div>
                <h4 class="vd-card-title">Charts / Panels</h4>
                <div id="vd-widget-switches"></div>
            </div>
            <div class="vd-toast" id="vd-toast">View saved.</div>
            <div style="display:flex; gap:8px;">
                <button type="button" id="vd-reset-view" class="btn btn-secondary btn-sm">Reset to Default</button>
                <button type="button" id="vd-save-view" class="btn btn-primary btn-sm">Save View</button>
            </div>
        </div>
    </aside>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
(() => {
    const root = document.querySelector('[data-dashboard-root]');
    if (!root) return;

    const dataUrl = root.dataset.dataUrl;
    const preferencesKey = root.dataset.preferencesKey;
    const kpiGroups = {general:'General KPIs', inbound:'Inbound KPIs', outbound:'Outbound KPIs'};
    const kpiMeta = {
        total_calls:['Total Calls','bi-telephone','Total calls in the selected range.','general'],
        calls_in_progress:['Calls in Progress','bi-broadcast','Voice calls with no ended_at.','general'],
        total_answered:['Total Answered','bi-check2-circle','All calls with answered_at across inbound and outbound.','general'],
        overall_aht:['Overall AHT','bi-hourglass-split','Overall talk time plus wrap-up time for answered terminal calls.','general'],
        inbound_calls:['Inbound Calls','bi-telephone-inbound','Inbound voice calls.','inbound'],
        inbound_answered:['Inbound Answered','bi-check2-circle','Inbound calls with answered_at.','inbound'],
        queue_timeouts:['Queue Timeouts','bi-hourglass-bottom','Inbound calls that reached the queue timeout before an agent answered.','inbound'],
        abandoned_calls:['Abandoned Calls','bi-exclamation-triangle','Inbound calls with exit_reason ABANDON.','inbound'],
        inbound_answer_rate:['Inbound Answer Rate','bi-percent','Inbound answered divided by terminal inbound calls.','inbound'],
        asa:['ASA','bi-stopwatch','Average Speed of Answer for answered terminal inbound calls.','inbound'],
        service_level:['Service Level','bi-speedometer2','Eligible inbound calls answered within the queue threshold.','inbound'],
        abandon_rate:['Abandon Rate','bi-percent','Abandoned inbound divided by terminal inbound calls.','inbound'],
        queue_timeout_rate:['Queue Timeout Rate','bi-percent','Eligible inbound calls that ended because the queue timeout was reached.','inbound'],
        inbound_aht:['Inbound AHT','bi-hourglass-split','Inbound talk time plus wrap-up time.','inbound'],
        inbound_average_talk_time:['Inbound Average Talk Time','bi-clock-history','Inbound average talk_seconds.','inbound'],
        inbound_average_wrap_up:['Inbound Average Wrap-Up','bi-arrow-repeat','Inbound average wrapup_seconds.','inbound'],
        outbound_calls:['Outbound Calls','bi-telephone-outbound','Outbound voice calls.','outbound'],
        outbound_answered:['Outbound Answered','bi-check2-circle','Outbound calls with answered_at.','outbound'],
        outbound_unanswered:['Outbound Unanswered','bi-x-circle','Terminal outbound calls without answer.','outbound'],
        outbound_answer_rate:['Outbound Answer Rate','bi-percent','Outbound answered divided by terminal outbound calls.','outbound'],
        outbound_aht:['Outbound AHT','bi-hourglass-split','Outbound talk time plus wrap-up time.','outbound'],
        outbound_average_talk_time:['Outbound Average Talk Time','bi-clock-history','Outbound average talk_seconds.','outbound'],
        outbound_average_wrap_up:['Outbound Average Wrap-Up','bi-arrow-repeat','Outbound average wrapup_seconds.','outbound'],
        outbound_contact_rate:['Outbound Contact Rate','bi-person-check','N/A until a contact taxonomy exists.','outbound']
    };
    const widgetMeta = {
        calls_by_hour:'Calls by Hour',
        direction_breakdown:'Inbound vs Outbound',
        outcome_breakdown:'Answered vs Unanswered',
        top_queues:'Top Queues by Volume',
        top_agents:'Top Agents by Calls',
        dispositions:'Dispositions Breakdown',
        recent_calls:'Recent Calls',
        calls_in_progress_detail:'Calls in Progress Detail'
    };
    const presets = {
        executive:{kpis:['total_calls','inbound_calls','outbound_calls','inbound_answered','outbound_answered','inbound_answer_rate','outbound_answer_rate','service_level','overall_aht'], widgets:['calls_by_hour','top_queues','direction_breakdown']},
        supervisor:{kpis:['total_calls','calls_in_progress','inbound_calls','inbound_answered','queue_timeouts','abandoned_calls','inbound_answer_rate','asa','service_level','abandon_rate','queue_timeout_rate','inbound_aht','outbound_calls','outbound_answered','outbound_unanswered','outbound_answer_rate','outbound_aht'], widgets:['calls_by_hour','top_queues','top_agents','recent_calls','calls_in_progress_detail','direction_breakdown','outcome_breakdown']},
        inbound:{kpis:['inbound_calls','inbound_answered','queue_timeouts','abandoned_calls','inbound_answer_rate','asa','service_level','abandon_rate','queue_timeout_rate','inbound_aht','inbound_average_talk_time','inbound_average_wrap_up'], widgets:['calls_by_hour','outcome_breakdown','top_queues','recent_calls']},
        outbound:{kpis:['outbound_calls','outbound_answered','outbound_unanswered','outbound_answer_rate','outbound_aht','outbound_average_talk_time','outbound_average_wrap_up','outbound_contact_rate'], widgets:['calls_by_hour','top_agents','dispositions','recent_calls','calls_in_progress_detail']}
    };
    const legacyKpiMap = {
        answered_calls:['total_answered'],
        unanswered_calls:['outbound_unanswered'],
        answer_rate:['inbound_answer_rate','outbound_answer_rate'],
        aht:['overall_aht'],
        average_talk_time:['inbound_average_talk_time','outbound_average_talk_time'],
        average_wrap_up:['inbound_average_wrap_up','outbound_average_wrap_up'],
        contact_rate_outbound:['outbound_contact_rate']
    };
    const kpiKeys = Object.keys(kpiMeta);
    const panelKeys = Object.keys(widgetMeta);
    const allWidgetKeys = new Set([...kpiKeys, ...panelKeys]);
    const validAutoRefreshValues = new Set(['0', '30', '60', '300']);
    const state = {
        payload:null,
        isRequestInFlight:false,
        requestSequence:0,
        refreshIntervalId:null,
        countdownIntervalId:null,
        nextRefreshAt:null,
        charts:{},
        preferences:normalizePreferences(loadPreferences())
    };
    const els = {
        campaign:document.getElementById('vd-campaign'),
        queue:document.getElementById('vd-queue'),
        preset:document.getElementById('vd-preset'),
        customRange:document.getElementById('vd-custom-range'),
        dateFrom:document.getElementById('vd-date-from'),
        dateTo:document.getElementById('vd-date-to'),
        autoRefresh:document.getElementById('vd-auto-refresh'),
        refresh:document.getElementById('vd-refresh'),
        pill:document.getElementById('vd-live-pill'),
        liveLabel:document.getElementById('vd-live-label'),
        lastUpdate:document.getElementById('vd-last-update'),
        nextUpdate:document.getElementById('vd-next-update'),
        error:document.getElementById('vd-error'),
        kpis:document.getElementById('vd-kpis'),
        drawer:document.getElementById('vd-drawer'),
        backdrop:document.getElementById('vd-drawer-backdrop'),
        toast:document.getElementById('vd-toast')
    };

    applyInitialPreferences();
    buildCustomizationSwitches();
    bindEvents();
    loadData();
    configureAutoRefresh();

    function loadPreferences() {
        try { return JSON.parse(localStorage.getItem(preferencesKey) || 'null') || {}; } catch (_) { return {}; }
    }
    function savePreferences() {
        localStorage.setItem(preferencesKey, JSON.stringify(state.preferences));
    }
    function applyInitialPreferences() {
        const pref = state.preferences;
        els.preset.value = pref.datePreset || 'today';
        toggleCustomRange();
        syncAutoRefreshAvailability();
    }
    function normalizePreferences(pref) {
        const normalized = pref && typeof pref === 'object' ? {...pref} : {};
        normalized.preset = presets[normalized.preset] || normalized.preset === 'custom' ? normalized.preset : 'supervisor';
        normalized.todayAutoRefresh = validAutoRefreshValue(normalized.todayAutoRefresh ?? normalized.autoRefresh ?? '30');
        delete normalized.autoRefresh;
        normalized.kpis = normalizeKpiKeys(normalized.kpis || []);
        normalized.widgets = normalizePanelKeys(normalized.widgets || []);
        if (Array.isArray(normalized.visibleWidgets)) {
            normalized.visibleWidgets = normalizeVisibleWidgets(normalized.visibleWidgets);
        } else if (normalized.kpis.length || normalized.widgets.length) {
            normalized.visibleWidgets = [...normalized.kpis, ...normalized.widgets];
        }

        return normalized;
    }
    function normalizeKpiKeys(keys) {
        return Array.from(new Set((Array.isArray(keys) ? keys : [])
            .flatMap(key => legacyKpiMap[key] || [key])
            .filter(key => kpiKeys.includes(key))));
    }
    function normalizePanelKeys(keys) {
        return Array.from(new Set((Array.isArray(keys) ? keys : [])
            .filter(key => panelKeys.includes(key))));
    }
    function normalizeVisibleWidgets(keys) {
        return Array.from(new Set((Array.isArray(keys) ? keys : [])
            .flatMap(key => legacyKpiMap[key] || [key])
            .filter(key => allWidgetKeys.has(key))));
    }
    function validAutoRefreshValue(value) {
        const normalized = String(value ?? '30');
        return validAutoRefreshValues.has(normalized) ? normalized : '30';
    }
    function isTodayRange() {
        return els.preset.value === 'today';
    }
    function syncAutoRefreshAvailability() {
        if (!isTodayRange()) {
            els.autoRefresh.value = '0';
            els.autoRefresh.disabled = true;
            els.autoRefresh.title = 'Auto Refresh is available only for Today.';
            state.nextRefreshAt = null;
            return;
        }

        els.autoRefresh.disabled = false;
        els.autoRefresh.title = '';
        els.autoRefresh.value = validAutoRefreshValue(state.preferences.todayAutoRefresh ?? '30');
    }
    function autoRefreshIsActive() {
        return isTodayRange() && !els.autoRefresh.disabled && els.autoRefresh.value !== '0';
    }
    function currentView() {
        const pref = state.preferences;
        const base = presets[pref.preset || 'supervisor'];
        if (Array.isArray(pref.visibleWidgets)) {
            const visibleWidgets = normalizeVisibleWidgets(pref.visibleWidgets);
            return {
                kpis: visibleWidgets.filter(key => kpiKeys.includes(key)),
                widgets: visibleWidgets.filter(key => panelKeys.includes(key))
            };
        }

        return {
            kpis: normalizeKpiKeys(pref.kpis || base.kpis),
            widgets: normalizePanelKeys(pref.widgets || base.widgets)
        };
    }
    function applyDrawerSelection() {
        const selectedKpis = [];
        const selectedPanels = [];
        document.querySelectorAll('[data-pref-switch]').forEach(input => {
            if (!input.checked) return;
            const key = input.dataset.key;
            if (input.dataset.type === 'kpi' && kpiKeys.includes(key)) selectedKpis.push(key);
            if (input.dataset.type === 'widget' && panelKeys.includes(key)) selectedPanels.push(key);
        });

        state.preferences.preset = 'custom';
        state.preferences.kpis = Array.from(new Set(selectedKpis));
        state.preferences.widgets = Array.from(new Set(selectedPanels));
        state.preferences.visibleWidgets = [...state.preferences.kpis, ...state.preferences.widgets];
    }
    function bindEvents() {
        els.refresh.addEventListener('click', () => loadData());
        els.campaign.addEventListener('change', () => { filterQueues(); loadData(); });
        els.queue.addEventListener('change', () => loadData());
        els.preset.addEventListener('change', () => {
            toggleCustomRange();
            state.preferences.datePreset = els.preset.value;
            syncAutoRefreshAvailability();
            configureAutoRefresh();
            savePreferences();
            loadData();
        });
        [els.dateFrom, els.dateTo].forEach(el => el.addEventListener('change', () => loadData()));
        els.autoRefresh.addEventListener('change', () => {
            if (!isTodayRange()) {
                els.autoRefresh.value = '0';
                configureAutoRefresh();
                return;
            }

            state.preferences.todayAutoRefresh = validAutoRefreshValue(els.autoRefresh.value);
            savePreferences();
            configureAutoRefresh();
        });
        document.getElementById('vd-customize').addEventListener('click', openDrawer);
        document.getElementById('vd-drawer-close').addEventListener('click', closeDrawer);
        els.backdrop.addEventListener('click', closeDrawer);
        document.getElementById('vd-save-view').addEventListener('click', () => {
            applyDrawerSelection();
            state.preferences.preset = 'custom';
            document.getElementById('vd-preset-view').value = 'custom';
            savePreferences();
            applyVisibility();
            showToast();
            closeDrawer();
        });
        document.getElementById('vd-reset-view').addEventListener('click', () => {
            state.preferences = {};
            localStorage.removeItem(preferencesKey);
            applyInitialPreferences();
            renderKpis(state.payload?.kpis || {});
            buildCustomizationSwitches();
            applyVisibility();
            configureAutoRefresh();
        });
        document.getElementById('vd-preset-view').addEventListener('change', event => {
            const preset = event.target.value;
            if (preset !== 'custom' && presets[preset]) {
                state.preferences.preset = preset;
                state.preferences.kpis = presets[preset].kpis.slice();
                state.preferences.widgets = presets[preset].widgets.slice();
                state.preferences.visibleWidgets = [...state.preferences.kpis, ...state.preferences.widgets];
                renderKpis(state.payload?.kpis || {});
                buildCustomizationSwitches();
                applyVisibility();
            }
        });
        window.addEventListener('beforeunload', () => {
            if (state.refreshIntervalId) window.clearInterval(state.refreshIntervalId);
            if (state.countdownIntervalId) window.clearInterval(state.countdownIntervalId);
        });
    }
    function toggleCustomRange() {
        els.customRange.classList.toggle('is-visible', els.preset.value === 'custom');
    }
    function filterQueues() {
        const campaign = els.campaign.value;
        Array.from(els.queue.options).forEach(option => {
            if (!option.value) return;
            option.hidden = campaign && option.dataset.campaignId !== campaign;
        });
        if (els.queue.selectedOptions[0]?.hidden) els.queue.value = '';
    }
    async function loadData() {
        if (state.isRequestInFlight) return;
        const sequence = ++state.requestSequence;
        state.isRequestInFlight = true;
        setStatus('loading');
        els.error.classList.remove('is-visible');
        try {
            const response = await fetch(dataUrl + '?' + new URLSearchParams(filters()).toString(), {headers:{'Accept':'application/json'}});
            if (!response.ok) throw new Error('Request failed');
            const payload = await response.json();
            if (sequence !== state.requestSequence) return;
            state.payload = payload;
            applyData(payload);
            setStatus(autoRefreshIsActive() ? 'live' : 'static');
            state.nextRefreshAt = autoRefreshIsActive() ? Date.now() + (Number(els.autoRefresh.value) * 1000) : null;
            updateCountdown();
        } catch (error) {
            els.error.classList.add('is-visible');
            setStatus('error');
        } finally {
            state.isRequestInFlight = false;
        }
    }
    function filters() {
        return {
            campaign_id: els.campaign.value,
            queue_id: els.queue.value,
            preset: els.preset.value,
            date_from: els.dateFrom.value,
            date_to: els.dateTo.value
        };
    }
    function configureAutoRefresh() {
        if (state.refreshIntervalId) window.clearInterval(state.refreshIntervalId);
        if (state.countdownIntervalId) window.clearInterval(state.countdownIntervalId);
        state.refreshIntervalId = null;
        state.countdownIntervalId = null;
        syncAutoRefreshAvailability();
        const seconds = Number(els.autoRefresh.value);
        if (autoRefreshIsActive()) {
            state.nextRefreshAt = Date.now() + seconds * 1000;
            state.countdownIntervalId = window.setInterval(updateCountdown, 1000);
            state.refreshIntervalId = window.setInterval(loadData, seconds * 1000);
        } else {
            state.nextRefreshAt = null;
            setStatus('static');
        }
        updateCountdown();
    }
    function setStatus(status) {
        els.pill.className = 'vd-pill ' + status;
        els.liveLabel.textContent = status === 'live' ? 'Live' : status === 'loading' ? 'Loading' : status === 'error' ? 'Error' : 'Static';
    }
    function updateCountdown() {
        if (!state.nextRefreshAt || els.autoRefresh.value === '0') {
            els.nextUpdate.textContent = 'Next update: Off';
            return;
        }
        const seconds = Math.max(0, Math.ceil((state.nextRefreshAt - Date.now()) / 1000));
        els.nextUpdate.textContent = 'Next update in: ' + seconds + 's';
    }
    function applyData(payload) {
        renderKpis(payload.kpis || {});
        renderCharts(payload);
        renderTable('vd-top-queues', payload.top_queues || [], ['queue','total_calls','inbound','outbound','answered','abandoned','asa','aht','service_level']);
        renderTable('vd-top-agents', payload.top_agents || [], ['agent','total_calls','handled','inbound','outbound','average_talk_time','aht','average_wrap_up']);
        renderTable('vd-recent-calls', payload.recent_calls || [], ['datetime','direction','phone','agent','campaign','queue','status','duration','disposition']);
        renderTable('vd-progress-calls', payload.calls_in_progress || [], ['direction','phone','agent','queue','state']);
        els.lastUpdate.textContent = 'Last update: ' + new Date().toLocaleTimeString();
        applyVisibility();
    }
    function renderKpis(kpis) {
        const view = currentView();
        els.kpis.innerHTML = view.kpis.map(key => {
            const meta = kpiMeta[key] || [key, 'bi-circle', '', 'general'];
            const data = kpis[key] || {formatted:'N/A', unit:'', applicable:false};
            return `<article class="vd-kpi" data-key="${key}" data-dashboard-widget="${key}" data-dashboard-widget-type="kpi" title="${escapeHtml(meta[2])}">
                <div class="vd-kpi-top"><div><div class="vd-kpi-label">${meta[0]}</div></div><span class="vd-kpi-icon"><i class="bi ${meta[1]}"></i></span></div>
                <div><div class="vd-kpi-value">${escapeHtml(data.formatted)}</div><div class="vd-kpi-unit">${escapeHtml(data.unit || '')}</div></div>
            </article>`;
        }).join('');
    }
    function renderCharts(payload) {
        const time = payload.series?.calls_by_time || {labels:[], inbound:[], outbound:[], answered:[], abandoned:[]};
        chart('vd-calls-chart', 'line', time.labels, [
            {label:'Inbound', data:time.inbound || [], borderColor:'#059669', backgroundColor:'rgba(5,150,105,.12)', tension:.35},
            {label:'Outbound', data:time.outbound || [], borderColor:'#7c3aed', backgroundColor:'rgba(124,58,237,.12)', tension:.35},
            {label:'Answered', data:time.answered || [], borderColor:'#0d9488', backgroundColor:'rgba(13,148,136,.12)', tension:.35},
            {label:'Abandoned', data:time.abandoned || [], borderColor:'#dc2626', backgroundColor:'rgba(220,38,38,.12)', tension:.35}
        ]);
        donut('vd-direction-chart', payload.breakdowns?.direction || [], ['#059669','#7c3aed']);
        donut('vd-outcome-chart', payload.breakdowns?.outcome || [], ['#0d9488','#f97316','#dc2626']);
        donut('vd-dispositions-chart', payload.breakdowns?.dispositions || [], ['#2563eb','#059669','#d97706','#7c3aed','#dc2626','#0d9488']);
    }
    function chart(id, type, labels, datasets) {
        const canvas = document.getElementById(id);
        if (!canvas || typeof Chart === 'undefined') return;
        if (!state.charts[id]) {
            state.charts[id] = new Chart(canvas, {type, data:{labels,datasets}, options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}}}});
        } else {
            state.charts[id].data.labels = labels;
            state.charts[id].data.datasets = datasets;
            state.charts[id].update();
        }
    }
    function donut(id, rows, colors) {
        chart(id, 'doughnut', rows.map(row => row.label || row.name), [{data:rows.map(row => row.value ?? row.total), backgroundColor:colors, borderWidth:0}]);
    }
    function renderTable(id, rows, keys) {
        const body = document.getElementById(id);
        if (!body) return;
        if (!rows.length) {
            body.innerHTML = `<tr><td colspan="${keys.length}" class="vd-empty">No calls found for the selected filters.</td></tr>`;
            return;
        }
        body.innerHTML = rows.map(row => `<tr>${keys.map(key => `<td>${escapeHtml(row[key] ?? 'N/A')}</td>`).join('')}</tr>`).join('');
    }
    function buildCustomizationSwitches() {
        const view = currentView();
        document.getElementById('vd-preset-view').value = state.preferences.preset || 'supervisor';
        document.getElementById('vd-kpi-switches').innerHTML = Object.entries(kpiGroups).map(([groupKey, groupLabel]) => {
            const switches = Object.entries(kpiMeta)
                .filter(([, meta]) => (meta[3] || 'general') === groupKey)
                .map(([key, meta]) => switchRow('kpi', key, meta[0], view.kpis.includes(key)))
                .join('');

            return `<div class="vd-kpi-switch-group"><h5 class="vd-card-title">${groupLabel}</h5>${switches}</div>`;
        }).join('');
        document.getElementById('vd-widget-switches').innerHTML = Object.entries(widgetMeta).map(([key, label]) => switchRow('widget', key, label, view.widgets.includes(key))).join('');
        document.querySelectorAll('[data-pref-switch]').forEach(input => input.addEventListener('change', event => {
            applyDrawerSelection();
            document.getElementById('vd-preset-view').value = 'custom';
            renderKpis(state.payload?.kpis || {});
            applyVisibility();
        }));
    }
    function switchRow(type, key, label, checked) {
        return `<label class="vd-switch-row"><span>${escapeHtml(label)}</span><input data-pref-switch data-type="${type}" data-key="${key}" type="checkbox" ${checked ? 'checked' : ''}></label>`;
    }
    function applyVisibility() {
        applyWidgetVisibility(currentView());
        Object.values(state.charts).forEach(chart => chart.resize());
    }
    function applyWidgetVisibility(view) {
        const visibleKpis = new Set(view.kpis);
        const visiblePanels = new Set(view.widgets);
        document.querySelectorAll('[data-dashboard-widget]').forEach(widget => {
            const key = widget.dataset.dashboardWidget;
            const isKpi = widget.dataset.dashboardWidgetType === 'kpi';
            widget.hidden = isKpi ? !visibleKpis.has(key) : !visiblePanels.has(key);
        });
    }
    function openDrawer() {
        buildCustomizationSwitches();
        els.drawer.classList.add('is-open');
        els.backdrop.classList.add('is-open');
        els.drawer.setAttribute('aria-hidden', 'false');
    }
    function closeDrawer() {
        els.drawer.classList.remove('is-open');
        els.backdrop.classList.remove('is-open');
        els.drawer.setAttribute('aria-hidden', 'true');
    }
    function showToast() {
        els.toast.classList.add('is-visible');
        window.setTimeout(() => els.toast.classList.remove('is-visible'), 1600);
    }
    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    }
})();
</script>
@endsection
