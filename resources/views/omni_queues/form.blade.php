@extends('layouts.app')

@section('title', $queue->exists ? 'Edit Queue' : 'New Queue')
@section('page-title', $queue->exists ? 'Edit Queue' : 'New Queue')
@section('page-subtitle', 'Configure routing, channels and campaign agents')

@section('content')

@if($errors->any())
<div class="alert alert-danger">
    <ul style="margin:0;padding-left:16px;">
        @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

@php
    $currentUser = auth()->user();
    $isTechnicalUser = (bool) ($currentUser?->isSuperAdmin());
    $selectedType = old('type', $queue->type ?? 'email');
    $selectedCampaignId = old('campaign_id', $selectedCampaignId ?? $queue->campaign_id);
    $voiceSettings = $voiceSettings ?? $queue->voiceSettings ?? null;
    $queueAgents = collect($queueAgents ?? []);
    $ineligibleAssignedAgents = collect($ineligibleAssignedAgents ?? []);
    $ineligibleAssignedIds = $ineligibleAssignedAgents->pluck('id')->map(fn($id) => (int) $id)->all();
    $queueNameLocked = $voiceSettings
        && $voiceSettings->sync_status === 'synced'
        && $voiceSettings->last_synced_at;
    $voiceEnabled = $selectedType === 'voice'
        ? true
        : (bool) old('voice_is_enabled', $voiceSettings?->is_enabled ?? false);
    $voiceValue = fn (string $field, mixed $default) => old($field, $voiceSettings?->{$field} ?? $default);
    $showRetry = $queue->exists
        && $queue->supportsVoice()
        && $queue->hasVoiceRoutingEnabled()
        && in_array($voiceSettings?->sync_status, ['pending', 'failed'], true);
    $statusLabels = [
        'pending' => 'Configuring',
        'synced' => 'Active',
        'failed' => 'Requires attention',
    ];
    $routingPriorityLabels = \App\Models\OmniQueue::routingPriorityLabels();
    $strategyLabels = [
        'ringall' => 'Ring all available agents',
        'leastrecent' => 'Agent waiting longest',
        'fewestcalls' => 'Agent with fewest calls',
        'random' => 'Random',
        'rrmemory' => 'Round robin',
        'linear' => 'Fixed order',
        'wrandom' => 'Weighted random',
    ];
    $currentStatus = $statusLabels[$voiceSettings?->sync_status ?? 'pending'] ?? 'Configuring';
    $campaignName = collect($campaigns)->firstWhere('id', (int) $selectedCampaignId)?->name;
    $assignedCount = $queueAgents->count();
    $generalErrorFields = ['name', 'type', 'campaign_id', 'assignment_mode', 'max_wait_minutes', 'default_agent_id', 'script_id', 'active'];
    $channelErrorFields = ['voice_is_enabled', 'asterisk_queue_name', 'strategy', 'timeout', 'retry', 'wrapup_time', 'maxlen', 'music_on_hold', 'service_level', 'weight', 'ring_in_use', 'autopause'];
    $agentErrorFields = ['agents'];
    $initialTab = 'general';
    if ($errors->hasAny($channelErrorFields)) $initialTab = 'channels';
    if ($errors->hasAny($agentErrorFields)) $initialTab = 'agents';
    if ($errors->hasAny($generalErrorFields)) $initialTab = 'general';
@endphp

<style>
    .form-page-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); margin-bottom:20px; }
    .tab-nav { display:flex; border-bottom:2px solid var(--border); gap:0; padding:0 24px; border-radius:var(--radius) var(--radius) 0 0; }
    .tab-btn { background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; padding:12px 18px; font-size:14px; font-weight:500; color:var(--text-muted); cursor:pointer; display:flex; align-items:center; gap:6px; transition:color .15s, border-color .15s; white-space:nowrap; }
    .tab-btn:hover { color:var(--text-strong); }
    .tab-btn.active { color:var(--primary); border-bottom-color:var(--primary); }
    .tab-err-dot { width:7px; height:7px; border-radius:50%; background:#ef4444; display:inline-block; flex-shrink:0; }
    .tab-panels { padding:24px 24px 0; }
    .tab-panel { display:none; padding-bottom:28px; }
    .tab-panel.active { display:block; }
    .form-actions { display:flex; justify-content:flex-end; gap:8px; padding:20px 24px; border-top:1px solid var(--border); margin-top:8px; }
    .form-hint { font-size:11px; color:var(--text-muted); margin-top:4px; line-height:1.4; }
    .queue-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .queue-grid-3 { display:grid; grid-template-columns:repeat(3, 1fr); gap:16px; }
    .queue-grid-4 { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; }
    .section-title { font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:12px; }
    .muted-help { font-size:11px; color:var(--text-muted); margin-top:4px; }
    .channel-card-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:14px; margin-bottom:18px; }
    .channel-card { border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg-card); padding:14px; min-height:150px; display:flex; flex-direction:column; gap:10px; }
    .channel-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .channel-card-title { display:flex; align-items:center; gap:9px; font-size:15px; font-weight:700; color:var(--text-strong); }
    .channel-icon { width:30px; height:30px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:var(--primary-light); color:var(--primary); flex-shrink:0; }
    .channel-description { font-size:12px; color:var(--text-muted); line-height:1.45; margin:0; }
    .channel-status { display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; background:#ecfdf5; color:#047857; white-space:nowrap; }
    .channel-status.disabled { background:#f1f5f9; color:#64748b; }
    .channel-switch { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:var(--text-strong); margin-top:auto; }
    .voice-details { border-top:1px solid var(--border); padding-top:16px; margin-top:4px; }
    .ca-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
    .ca-toolbar .form-control { flex:1; min-width:180px; max-width:340px; }
    .ca-summary { font-size:12px; color:var(--text-muted); white-space:nowrap; }
    .ca-summary strong { color:var(--text-strong); }
    .ca-dual { display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:start; margin-bottom:4px; }
    .ca-panel { border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg-card); display:flex; flex-direction:column; min-height:320px; }
    .ca-panel-hd { flex-shrink:0; padding:9px 13px; border-bottom:1px solid var(--border); border-radius:var(--radius-sm) var(--radius-sm) 0 0; }
    .ca-panel-hd--assigned { background:#f0fdf4; }
    .ca-panel-hd--available { background:var(--bg-th); }
    .ca-panel-hd strong { display:block; font-size:12px; font-weight:700; color:var(--text-strong); }
    .ca-panel-hd p { font-size:11px; color:var(--text-muted); margin:2px 0 0; }
    .ca-panel-body { flex:1; min-height:0; }
    .ca-panel-ft { flex-shrink:0; padding:8px 11px; border-top:1px solid var(--border); background:var(--bg-th); border-radius:0 0 var(--radius-sm) var(--radius-sm); display:flex; gap:8px; flex-wrap:wrap; }
    .ca-empty { padding:22px 14px; text-align:center; color:var(--text-muted); font-size:12px; }
    .ca-row { display:flex; align-items:flex-start; gap:10px; padding:12px; border-bottom:1px solid var(--border); transition:background .1s; }
    .ca-row:last-child { border-bottom:none; }
    .ca-row:hover { background:var(--bg-th); }
    .ca-row.ca-hidden { display:none !important; }
    .ca-avatar { width:28px; height:28px; border-radius:50%; flex-shrink:0; background:var(--primary); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; text-transform:uppercase; }
    .ca-info { flex:1; min-width:0; }
    .ca-name { display:block; font-size:13px; font-weight:600; color:var(--text-strong); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ca-email { display:block; font-size:11px; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ca-btn { flex-shrink:0; border:1px solid var(--border); background:var(--bg-card); color:var(--text-muted); font-size:12px; display:inline-flex; align-items:center; justify-content:center; gap:4px; cursor:pointer; padding:6px 9px; border-radius:6px; transition:all .15s; }
    .ca-btn-add:hover { background:#dcfce7; border-color:#16a34a; color:#166534; }
    .ca-btn-remove:hover { background:#fef2f2; border-color:#dc2626; color:#991b1b; }
    .agent-row-main { display:flex; align-items:flex-start; gap:10px; flex:1; min-width:0; }
    .agent-card { display:block; }
    .agent-card .agent-row-top { display:flex; align-items:center; gap:10px; }
    .agent-card-summary { display:flex; align-items:center; gap:7px; flex-wrap:wrap; margin-top:4px; font-size:11px; color:var(--text-muted); }
    .summary-chip { display:inline-flex; align-items:center; padding:2px 6px; border-radius:999px; background:var(--bg-th); color:var(--text-mid); font-weight:600; }
    .summary-chip.enabled { background:#ecfdf5; color:#047857; }
    .summary-chip.disabled { background:#fef2f2; color:#b91c1c; }
    .agent-settings { margin-top:10px; padding:10px; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg-th); }
    .agent-settings-grid { display:grid; grid-template-columns:repeat(3, minmax(120px, 1fr)); gap:10px; }
    .capability-badge { display:inline-flex; align-items:center; margin-top:4px; padding:3px 7px; border-radius:999px; font-size:11px; font-weight:600; background:#eef2ff; color:#3730a3; }
    .capability-badge.digital { background:#f0f9ff; color:#0369a1; }
    .invalid-assignments { border:1px solid #facc15; background:#fefce8; border-radius:var(--radius-sm); margin-bottom:14px; }
    .invalid-assignments-hd { padding:10px 12px; border-bottom:1px solid #fde68a; font-weight:700; font-size:13px; color:#854d0e; }
    .invalid-assignments .ca-row { border-color:#fde68a; }
    .agent-filter { border:1px solid var(--border); background:#fff; border-radius:6px; padding:7px 10px; cursor:pointer; font-size:12px; }
    .agent-filter.active { border-color:var(--primary); color:var(--primary); font-weight:600; }
    .status-pill { display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:600; background:#eef2ff; color:#3730a3; }
    .status-pill.failed { background:#fef2f2; color:#b91c1c; }
    .status-pill.synced { background:#ecfdf5; color:#047857; }
    .advanced-voice { border:1px solid var(--border); border-radius:var(--radius-sm); margin-top:16px; background:var(--bg-card); }
    .advanced-voice summary { cursor:pointer; padding:12px 14px; font-weight:600; color:var(--text-strong); list-style:none; display:flex; align-items:center; gap:8px; }
    .advanced-voice summary::-webkit-details-marker { display:none; }
    .advanced-voice summary::before { content:'+'; width:18px; height:18px; border-radius:50%; border:1px solid var(--border); display:inline-flex; align-items:center; justify-content:center; font-size:13px; color:var(--text-muted); }
    .advanced-voice[open] summary::before { content:'-'; }
    .advanced-voice-body { padding:0 14px 14px; border-top:1px solid var(--border); }
    .voice-disabled-note { padding:12px 14px; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg-th); color:var(--text-muted); font-size:13px; }
    @media (max-width: 900px) {
        .queue-grid-2, .queue-grid-3, .queue-grid-4, .channel-card-grid { grid-template-columns:1fr; }
        .ca-dual, .agent-settings-grid { grid-template-columns:1fr; }
    }
</style>

<form method="POST"
    action="{{ $queue->exists ? route('omni-queues.update', $queue) : route('omni-queues.store') }}">
    @csrf
    @if($queue->exists) @method('PUT') @endif

    <div class="form-page-card">
        <div class="tab-nav" role="tablist">
            <button type="button" class="tab-btn {{ $initialTab === 'general' ? 'active' : '' }}" data-tab="general">
                <i class="bi bi-sliders"></i> General
                @if($errors->hasAny($generalErrorFields)) <span class="tab-err-dot" title="This tab has errors"></span> @endif
            </button>
            <button type="button" class="tab-btn {{ $initialTab === 'channels' ? 'active' : '' }}" data-tab="channels">
                <i class="bi bi-diagram-3"></i> Channels
                @if($errors->hasAny($channelErrorFields)) <span class="tab-err-dot" title="This tab has errors"></span> @endif
            </button>
            <button type="button" class="tab-btn {{ $initialTab === 'agents' ? 'active' : '' }}" data-tab="agents">
                <i class="bi bi-people"></i> Agents
                @if($errors->hasAny($agentErrorFields)) <span class="tab-err-dot" title="This tab has errors"></span> @endif
            </button>
        </div>

        <div class="tab-panels">
        <div id="tab-general" class="tab-panel {{ $initialTab === 'general' ? 'active' : '' }}">
            <div class="section-title">General</div>
            <div class="queue-grid-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Queue Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="name" class="form-control" value="{{ old('name', $queue->name) }}" placeholder="e.g. Support Queue">
                    @error('name') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">Type <span style="color:#dc2626;">*</span></label>
                    <select name="type" class="form-control">
                        <option value="email" {{ $selectedType === 'email' ? 'selected' : '' }}>Email</option>
                        <option value="chat" {{ $selectedType === 'chat' ? 'selected' : '' }}>Chat</option>
                        <option value="voice" {{ $selectedType === 'voice' ? 'selected' : '' }}>Voice</option>
                        <option value="omni" {{ $selectedType === 'omni' ? 'selected' : '' }}>Omni</option>
                    </select>
                    @error('type') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="queue-grid-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Campaign</label>
                    <select name="campaign_id" id="campaignSelect" class="form-control">
                        <option value="">None</option>
                        @foreach($campaigns as $campaign)
                            <option value="{{ $campaign->id }}" {{ (int) $selectedCampaignId === (int) $campaign->id ? 'selected' : '' }}>
                                {{ $campaign->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="muted-help">Agents are filtered by the selected campaign.</p>
                    @error('campaign_id') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">Assignment Mode <span style="color:#dc2626;">*</span></label>
                    <select name="assignment_mode" id="assignmentModeSelect" class="form-control">
                        <option value="round_robin" {{ old('assignment_mode', $queue->assignment_mode) === 'round_robin' ? 'selected' : '' }}>Round Robin</option>
                        <option value="least_busy" {{ old('assignment_mode', $queue->assignment_mode) === 'least_busy' ? 'selected' : '' }}>Least Busy</option>
                        <option value="direct" {{ old('assignment_mode', $queue->assignment_mode) === 'direct' ? 'selected' : '' }}>Direct</option>
                    </select>
                    @error('assignment_mode') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="queue-grid-2" style="margin-bottom:16px;">
                <div>
                    <label class="form-label">Max Wait Time (minutes) <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="max_wait_minutes" class="form-control" value="{{ old('max_wait_minutes', $queue->max_wait_minutes ?? 30) }}" min="1" max="1440">
                    @error('max_wait_minutes') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div id="defaultAgentRow" style="display:none;">
                    <label class="form-label">Default Agent</label>
                    <select name="default_agent_id" id="defaultAgentSelect" class="form-control" data-selected="{{ old('default_agent_id', $queue->default_agent_id ?? '') }}">
                        <option value="">Select agent...</option>
                    </select>
                    <p class="muted-help">Direct assignments use a campaign-eligible agent.</p>
                    @error('default_agent_id') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label class="form-label">Default Script</label>
                <select name="script_id" id="scriptSelect" class="form-control" style="max-width:420px;">
                    <option value="">Use campaign default</option>
                    @foreach($scripts ?? [] as $script)
                        <option value="{{ $script->id }}"
                            data-campaign="{{ $script->campaign_id }}"
                            {{ old('script_id', $queue->script_id) == $script->id ? 'selected' : '' }}>
                            {{ $script->name }} ({{ $script->direction }}){{ $script->is_default ? ' *' : '' }}
                        </option>
                    @endforeach
                </select>
                @error('script_id') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>

            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="active" value="1" {{ old('active', $queue->active ?? true) ? 'checked' : '' }}>
                Active
            </label>
        </div>

        <div id="tab-channels" class="tab-panel {{ $initialTab === 'channels' ? 'active' : '' }}">
            <div class="section-title">Channels</div>

            <div class="channel-card-grid">
                <div id="emailChannelCard" class="channel-card" style="display:none;">
                    <div class="channel-card-head">
                        <div class="channel-card-title">
                            <span class="channel-icon"><i class="bi bi-envelope"></i></span>
                            Email
                        </div>
                        <span class="channel-status">Enabled</span>
                    </div>
                    <p class="channel-description">Handles email conversations assigned through this queue.</p>
                    <p class="muted-help">Capacity is configured per assigned agent.</p>
                </div>

                <div id="chatChannelCard" class="channel-card" style="display:none;">
                    <div class="channel-card-head">
                        <div class="channel-card-title">
                            <span class="channel-icon"><i class="bi bi-chat-dots"></i></span>
                            Chat
                        </div>
                        <span class="channel-status">Enabled</span>
                    </div>
                    <p class="channel-description">Handles real-time digital conversations assigned through this queue.</p>
                    <p class="muted-help">Capacity is configured per assigned agent.</p>
                </div>

                <div id="voiceSettingsCard" class="channel-card" style="display:none;">
                    <div class="channel-card-head">
                        <div class="channel-card-title">
                            <span class="channel-icon"><i class="bi bi-telephone"></i></span>
                            Voice
                        </div>
                        <span id="voiceChannelStatus" class="channel-status {{ $voiceEnabled ? '' : 'disabled' }}">{{ $voiceEnabled ? 'Enabled' : 'Disabled' }}</span>
                    </div>
                    <p class="channel-description">Routes inbound calls to assigned voice-ready agents.</p>
                    <label class="channel-switch">
                        <input type="hidden" name="voice_is_enabled" value="0">
                        <input type="checkbox" id="voiceRoutingEnabled" name="voice_is_enabled" value="1" {{ $voiceEnabled ? 'checked' : '' }}>
                        Enable Voice Routing
                    </label>
                    <p id="voiceRoutingHelp" class="muted-help">Voice queues always use voice routing. Omni queues can enable it when the voice channel is ready.</p>
                </div>
            </div>

            <input type="hidden" name="asterisk_queue_name" value="{{ $voiceValue('asterisk_queue_name', $defaultVoiceQueueName ?? '') }}">

            <div id="voiceDisabledMessage" class="voice-disabled-note" style="display:none;">
                Voice routing is disabled for this queue.
            </div>

            <div id="voiceEnabledFields" class="voice-details">
                <h3 style="font-size:16px;margin-bottom:6px;">Voice Configuration</h3>
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">
                    Voice routing is configured automatically when you save this queue.
                </p>
                <div class="queue-grid-2" style="margin-bottom:16px;">
                    <div>
                        <label class="form-label">Distribution Strategy</label>
                        <select name="strategy" class="form-control">
                            @foreach($strategyLabels as $value => $label)
                                <option value="{{ $value }}" {{ $voiceValue('strategy', 'rrmemory') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="muted-help">
                            Choose how incoming voice work is offered to available agents.
                            Round robin offers each new call to the next available agent, continuing from the previous distribution position.
                        </p>
                        @error('strategy') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="form-label">Music on Hold</label>
                        <input type="text" name="music_on_hold" class="form-control" value="{{ $voiceValue('music_on_hold', 'default') }}" maxlength="128">
                        @error('music_on_hold') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="queue-grid-4" style="margin-bottom:16px;">
                    <div>
                        <label class="form-label">Ring Timeout</label>
                        <input type="number" name="timeout" class="form-control" value="{{ $voiceValue('timeout', 20) }}" min="1" max="300">
                        @error('timeout') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="form-label">After Call Work</label>
                        <input type="number" name="wrapup_time" class="form-control" value="{{ $voiceValue('wrapup_time', 10) }}" min="0" max="3600">
                        @error('wrapup_time') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="form-label">Maximum Waiting Calls</label>
                        <input type="number" name="maxlen" class="form-control" value="{{ $voiceValue('maxlen', 0) }}" min="0" max="10000">
                        @error('maxlen') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="form-label">Service Level</label>
                        <input type="number" name="service_level" class="form-control" value="{{ $voiceValue('service_level', 60) }}" min="0" max="86400">
                        @error('service_level') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                    </div>
                </div>

                <details id="advancedVoiceSettings" class="advanced-voice">
                    <summary>Advanced Voice Settings</summary>
                    <div class="advanced-voice-body">
                        <div class="queue-grid-4" style="margin:14px 0 16px;">
                            <div>
                                <label class="form-label">Retry Interval</label>
                                <input type="number" name="retry" class="form-control" value="{{ $voiceValue('retry', 5) }}" min="1" max="300">
                                <p class="muted-help">Time before the queue attempts to offer the waiting call again.</p>
                                @error('retry') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label class="form-label">Routing Weight</label>
                                <input type="number" name="weight" class="form-control" value="{{ $voiceValue('weight', '') }}">
                                <p class="muted-help">Priority used when the same agent belongs to multiple voice queues. Higher queue weight is considered first.</p>
                                @error('weight') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-top:28px;">
                                    <input type="hidden" name="ring_in_use" value="0">
                                    <input type="checkbox" name="ring_in_use" value="1" {{ $voiceValue('ring_in_use', false) ? 'checked' : '' }}>
                                    Allow calls while agent is already busy
                                </label>
                                <p class="muted-help">Allows the queue to offer another call to an agent whose voice endpoint is already in use.</p>
                            </div>
                            <div>
                                <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-top:28px;">
                                    <input type="hidden" name="autopause" value="0">
                                    <input type="checkbox" name="autopause" value="1" {{ $voiceValue('autopause', false) ? 'checked' : '' }}>
                                    Pause agent after failed call delivery
                                </label>
                                <p class="muted-help">Automatically pauses the agent for this queue after an unsuccessful call delivery.</p>
                            </div>
                        </div>

                        @if($isTechnicalUser)
                        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:14px;">
                            <div class="section-title">Technical Information</div>
                            <div class="queue-grid-2" style="margin-bottom:14px;">
                                <div>
                                    <label class="form-label">Internal Voice Queue Identifier</label>
                                    <input type="text" class="form-control" value="{{ $voiceValue('asterisk_queue_name', $defaultVoiceQueueName ?? '') }}" readonly>
                                    <p class="muted-help">
                                        @if($queueNameLocked)
                                            This identifier cannot be changed after voice routing has been configured.
                                        @else
                                            Generated and maintained by the CRM.
                                        @endif
                                    </p>
                                </div>
                                <div>
                                    <label class="form-label">Provisioning Status</label>
                                    <div style="margin-top:8px;">
                                        <span class="status-pill {{ $voiceSettings?->sync_status === 'failed' ? 'failed' : ($voiceSettings?->sync_status === 'synced' ? 'synced' : '') }}">
                                            {{ $currentStatus }}
                                        </span>
                                    </div>
                                    <p class="muted-help">Last Configured: {{ $voiceSettings?->last_synced_at?->format('Y-m-d H:i') ?? 'Never' }}</p>
                                </div>
                            </div>
                            <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;font-size:13px;">
                                <span><span style="color:var(--text-muted);">Parallel Queue Processing:</span> <strong>Enabled</strong></span>
                                @if($showRetry)
                                <button type="submit" form="voiceConfigRetryForm" class="btn btn-outline btn-sm">
                                    <i class="bi bi-arrow-repeat"></i> Retry Voice Configuration
                                </button>
                                @endif
                            </div>
                            @if(($voiceSettings?->sync_status) === 'failed' && $voiceSettings?->sync_error)
                                <div style="font-size:12px;color:#dc2626;margin-top:10px;">Safe Configuration Error: {{ $voiceSettings->sync_error }}</div>
                            @endif
                        </div>
                        @endif
                    </div>
                </details>

                @if($voiceEnabled && $queue->exists && $queue->supportsVoice())
                <div id="voiceServiceStatus" style="border-top:1px solid var(--border);margin-top:16px;padding-top:14px;">
                    <div class="section-title">Voice Service Status</div>
                    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;font-size:13px;">
                        <span class="status-pill {{ $voiceSettings?->sync_status === 'failed' ? 'failed' : ($voiceSettings?->sync_status === 'synced' ? 'synced' : '') }}">
                            {{ $currentStatus }}
                        </span>
                        <span><span style="color:var(--text-muted);">Last configured:</span> {{ $voiceSettings?->last_synced_at?->format('Y-m-d H:i') ?? 'Never' }}</span>
                    </div>
                    @if(($voiceSettings?->sync_status) === 'failed' && $voiceSettings?->sync_error)
                        <div style="font-size:12px;color:#dc2626;margin-top:10px;">{{ $voiceSettings->sync_error }}</div>
                    @endif
                    @if($showRetry && !$isTechnicalUser)
                    <button type="submit" form="voiceConfigRetryForm" class="btn btn-outline btn-sm" style="margin-top:12px;">
                        <i class="bi bi-arrow-repeat"></i> Retry Voice Configuration
                    </button>
                    @endif
                </div>
                @endif
                </div>
        </div>

        <div id="tab-agents" class="tab-panel {{ $initialTab === 'agents' ? 'active' : '' }}">
            <div class="section-title">Agents</div>
            @if(empty($selectedCampaignId))
                <div class="alert alert-info" style="margin-bottom:0;">Select a campaign to view its available agents.</div>
            @else
                <div class="ca-toolbar">
                    <input type="search" id="agentSearch" class="form-control" placeholder="Search agents by name or email">
                    <button type="button" class="agent-filter active" data-agent-filter="all">All eligible</button>
                    <button type="button" class="agent-filter" data-agent-filter="assigned">Assigned</button>
                    <button type="button" class="agent-filter" data-agent-filter="available">Available</button>
                    <div class="ca-summary">
                        Eligible:&nbsp;<strong id="eligibleAgentCount">{{ collect($agents)->count() - count($ineligibleAssignedIds) }}</strong>&emsp;
                        Available:&nbsp;<strong id="availableAgentSummaryCount">0</strong>&emsp;
                        Assigned:&nbsp;<strong id="assignedAgentCount">{{ $assignedCount }}</strong>&emsp;
                        Voice-ready assigned:&nbsp;<strong id="voiceReadyAssignedCount">0</strong>&emsp;
                        Digital-only assigned:&nbsp;<strong id="digitalOnlyAssignedCount">0</strong>
                    </div>
                </div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;font-size:13px;color:var(--text-muted);">
                    <span>Campaign: <strong style="color:var(--text-main);">{{ $campaignName }}</strong></span>
                </div>

                @if($ineligibleAssignedAgents->isNotEmpty())
                    <div class="invalid-assignments" id="invalidAssignments">
                        <div class="invalid-assignments-hd">Assignments requiring attention</div>
                        @foreach($ineligibleAssignedAgents as $agent)
                        @php
                            $pivot = $queueAgents->firstWhere('id', $agent->id)?->pivot;
                        @endphp
                        <div class="ca-row" data-invalid-agent-row data-agent-name="{{ strtolower($agent->name) }}" data-agent-email="{{ strtolower($agent->email) }}">
                            <div class="agent-row-main">
                                <span class="ca-avatar">{{ strtoupper(substr($agent->name, 0, 1)) }}</span>
                                <span class="ca-info">
                                    <span class="ca-name">{{ $agent->name }}</span>
                                    <span class="ca-email">{{ $agent->email }}</span>
                                    <span class="capability-badge" style="background:#fef3c7;color:#92400e;">No longer eligible for the selected campaign</span>
                                </span>
                            </div>
                            <div class="invalid-agent-inputs">
                                <input type="hidden" name="agents[{{ $agent->id }}][enabled]" value="1">
                                <input type="hidden" name="agents[{{ $agent->id }}][max_emails]" value="{{ old("agents.{$agent->id}.max_emails", $pivot->max_emails ?? 3) }}">
                                <input type="hidden" name="agents[{{ $agent->id }}][max_chats]" value="{{ old("agents.{$agent->id}.max_chats", $pivot->max_chats ?? 1) }}">
                                <input type="hidden" name="agents[{{ $agent->id }}][priority]" value="{{ old("agents.{$agent->id}.priority", $pivot->priority ?? 0) }}">
                                @if($pivot?->active ?? true)
                                    <input type="hidden" name="agents[{{ $agent->id }}][active]" value="1">
                                @endif
                            </div>
                            <button type="button" class="ca-btn ca-btn-remove" data-remove-invalid>
                                <i class="bi bi-dash-circle"></i> Remove assignment
                            </button>
                        </div>
                        @endforeach
                        <div style="padding:10px 12px;font-size:12px;color:#854d0e;">
                            Remove these assignments or choose a campaign where the agents are eligible before saving.
                        </div>
                    </div>
                @endif

                <div class="ca-dual">
                    <div class="ca-panel" data-agent-panel="available">
                        <div class="ca-panel-hd ca-panel-hd--available">
                            <strong>Available Agents (<span id="availableAgentCount">0</span>)</strong>
                            <p>Campaign-eligible agents who can be assigned to this queue.</p>
                        </div>
                        <div class="ca-panel-body" id="availableAgentList">
                            @foreach($agents as $agent)
                            @php
                                $pivot = $queueAgents->firstWhere('id', $agent->id)?->pivot;
                                $checked = (bool) old("agents.{$agent->id}.enabled", $pivot !== null);
                                $ineligible = in_array((int) $agent->id, $ineligibleAssignedIds, true);
                            @endphp
                            @continue($ineligible || $checked)
                            @include('omni_queues.partials.agent_assignment_card', [
                                'agent' => $agent,
                                'pivot' => $pivot,
                                'assigned' => false,
                                'selectedType' => $selectedType,
                                'voiceEnabled' => $voiceEnabled,
                            ])
                            @endforeach
                        </div>
                        <div class="ca-panel-ft">
                            <button type="button" class="btn btn-secondary btn-xs" data-bulk-action="select-visible-available">
                                <i class="bi bi-check2-square"></i> Select all visible
                            </button>
                            <button type="button" class="btn btn-primary btn-xs" data-bulk-action="assign-selected">
                                <i class="bi bi-plus-circle"></i> Assign selected
                            </button>
                        </div>
                    </div>

                    <div class="ca-panel" data-agent-panel="assigned">
                        <div class="ca-panel-hd ca-panel-hd--assigned">
                            <strong>Assigned Agents (<span id="assignedAgentPanelCount">0</span>)</strong>
                            <p>Queue-specific routing and capacity settings apply here.</p>
                        </div>
                        <div class="ca-panel-body" id="assignedAgentList">
                            @foreach($agents as $agent)
                            @php
                                $pivot = $queueAgents->firstWhere('id', $agent->id)?->pivot;
                                $checked = (bool) old("agents.{$agent->id}.enabled", $pivot !== null);
                                $ineligible = in_array((int) $agent->id, $ineligibleAssignedIds, true);
                            @endphp
                            @continue($ineligible || !$checked)
                            @include('omni_queues.partials.agent_assignment_card', [
                                'agent' => $agent,
                                'pivot' => $pivot,
                                'assigned' => true,
                                'selectedType' => $selectedType,
                                'voiceEnabled' => $voiceEnabled,
                            ])
                            @endforeach
                        </div>
                        <div class="ca-panel-ft">
                            <button type="button" class="btn btn-secondary btn-xs" data-bulk-action="select-visible-assigned">
                                <i class="bi bi-check2-square"></i> Select all visible
                            </button>
                            <button type="button" class="btn btn-danger btn-xs" data-bulk-action="remove-selected">
                                <i class="bi bi-dash-circle"></i> Remove selected
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        </div>{{-- .tab-panels --}}
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save
        </button>
        <a href="{{ route('omni-queues.index') }}" class="btn btn-secondary">Back</a>
    </div>
</form>

@if($queue->exists && $queue->supportsVoice())
<form id="voiceConfigRetryForm" method="POST" action="{{ route('omni-queues.sync-asterisk', $queue) }}" style="display:none;">
    @csrf
</form>
@endif

@endsection

@push('scripts')
<script src="{{ asset('js/omni-queue-form.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.tab-btn[data-tab]');
    const panes = document.querySelectorAll('.tab-panel');
    const typeSelect = document.querySelector('select[name="type"]');
    const voiceCard = document.getElementById('voiceSettingsCard');
    const emailCard = document.getElementById('emailChannelCard');
    const chatCard = document.getElementById('chatChannelCard');
    const enabled = document.getElementById('voiceRoutingEnabled');
    const campaignSelect = document.getElementById('campaignSelect');
    let search = document.getElementById('agentSearch');
    let filterButtons = document.querySelectorAll('.agent-filter');
    let activeFilter = 'all';
    const routingPriorityLabels = @json($routingPriorityLabels);

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            tabs.forEach((item) => item.classList.remove('active'));
            panes.forEach((pane) => pane.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(`tab-${tab.dataset.tab}`)?.classList.add('active');
        });
    });

    function syncChannelVisibility() {
        const type = typeSelect?.value || 'email';
        const supportsVoice = type === 'voice' || type === 'omni';
        const voiceStatus = document.getElementById('voiceChannelStatus');
        if (voiceCard) voiceCard.style.display = supportsVoice ? 'block' : 'none';
        if (emailCard) emailCard.style.display = (type === 'email' || type === 'omni') ? 'block' : 'none';
        if (chatCard) chatCard.style.display = (type === 'chat' || type === 'omni') ? 'block' : 'none';
        document.querySelectorAll('.capacity-email').forEach((el) => el.style.display = (type === 'email' || type === 'omni') ? '' : 'none');
        document.querySelectorAll('.capacity-chat').forEach((el) => el.style.display = (type === 'chat' || type === 'omni') ? '' : 'none');

        if (!enabled) return;
        if (type === 'voice') {
            enabled.checked = true;
            enabled.disabled = true;
        } else if (type === 'omni') {
            enabled.disabled = false;
        } else {
            enabled.checked = false;
            enabled.disabled = false;
        }

        const voiceRoutingEnabled = type === 'voice' || (type === 'omni' && enabled.checked);
        if (voiceStatus) {
            voiceStatus.textContent = voiceRoutingEnabled ? 'Enabled' : 'Disabled';
            voiceStatus.classList.toggle('disabled', !voiceRoutingEnabled);
        }
        document.getElementById('voiceEnabledFields')?.style.setProperty('display', voiceRoutingEnabled ? '' : 'none');
        document.getElementById('voiceDisabledMessage')?.style.setProperty('display', supportsVoice && !voiceRoutingEnabled ? 'block' : 'none');
        document.querySelectorAll('[data-agent-row]').forEach((row) => {
            syncPriorityInputState(row);
            updateAgentSummary(row);
        });
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function priorityLabel(value) {
        return routingPriorityLabels[String(value)] || routingPriorityLabels['4'];
    }

    function prioritySelect(name, value, assigned) {
        const current = String(value ?? '0');
        const options = Object.entries(routingPriorityLabels)
            .map(([key, label]) => `<option value="${key}" ${current === key ? 'selected' : ''}>${escapeHtml(label)}</option>`)
            .join('');

        return `<select name="${name}" class="form-control form-control-sm agent-setting-input" ${assigned ? '' : 'disabled'}>${options}</select>
            <div class="muted-help">Higher-priority agents are offered calls before lower-priority agents. Agents with the same priority are distributed according to the queue strategy.</div>`;
    }

    function voiceRoutingApplies() {
        const type = typeSelect?.value || 'email';
        return type === 'voice' || (type === 'omni' && !!enabled?.checked);
    }

    function syncPriorityInputState(row) {
        const assigned = row?.dataset.assigned === '1';
        const wrapper = row?.querySelector('.agent-priority-setting');
        const select = wrapper?.querySelector('select[name$="[priority]"]');
        const hidden = row?.querySelector('.agent-priority-hidden');
        const applies = voiceRoutingApplies();

        if (wrapper) wrapper.style.display = applies ? '' : 'none';
        if (select) select.disabled = !assigned || !applies;
        if (hidden) {
            if (select) hidden.value = select.value;
            hidden.disabled = !assigned || applies;
        }
    }

    function updateAgentCounts() {
        const assigned = document.querySelectorAll('#assignedAgentList [data-agent-row]').length;
        const available = document.querySelectorAll('#availableAgentList [data-agent-row]').length;
        const voiceReadyAssigned = Array.from(document.querySelectorAll('#assignedAgentList [data-agent-row]'))
            .filter((row) => row.querySelector('.capability-badge:not(.digital)')).length;
        setText('assignedAgentCount', assigned + document.querySelectorAll('[data-invalid-agent-row]').length);
        setText('assignedAgentPanelCount', assigned);
        setText('availableAgentCount', available);
        setText('availableAgentSummaryCount', available);
        setText('eligibleAgentCount', assigned + available);
        setText('voiceReadyAssignedCount', voiceReadyAssigned);
        setText('digitalOnlyAssignedCount', assigned - voiceReadyAssigned);
        updateSelectVisibleButtons();
    }

    function setAgentAssigned(row, assigned) {
        const assignedList = document.getElementById('assignedAgentList');
        const availableList = document.getElementById('availableAgentList');
        const target = assigned ? assignedList : availableList;
        if (!row || !target) return;

        row.dataset.assigned = assigned ? '1' : '0';
        row.querySelector('.agent-enabled-input')?.toggleAttribute('disabled', !assigned);
        row.querySelectorAll('.agent-setting-input').forEach((input) => input.disabled = !assigned);
        syncPriorityInputState(row);
        row.querySelector('[data-agent-action="assign"]')?.style.setProperty('display', assigned ? 'none' : 'inline-flex');
        row.querySelector('[data-agent-action="edit"]')?.style.setProperty('display', assigned ? 'inline-flex' : 'none');
        row.querySelector('[data-agent-action="remove"]')?.style.setProperty('display', assigned ? 'inline-flex' : 'none');
        row.querySelector('.agent-settings')?.style.setProperty('display', 'none');
        row.querySelector('[data-agent-action="edit"]')?.setAttribute('aria-expanded', 'false');
        row.querySelector('[data-agent-action="edit"]')?.replaceChildren(document.createElement('i'), document.createTextNode(' Edit'));
        row.querySelector('[data-agent-action="edit"] i')?.classList.add('bi', 'bi-pencil');
        const bulkSelect = row.querySelector('[data-agent-select]');
        if (bulkSelect) bulkSelect.checked = false;
        target.appendChild(row);
        updateAgentSummary(row);
        updateAgentCounts();
        applyAgentFilter();
    }

    function updateAgentSummary(row) {
        const summary = row?.querySelector('.agent-card-summary');
        if (!summary) return;
        const assigned = row.dataset.assigned === '1';
        const capability = row.querySelector('.capability-badge')?.outerHTML || '';
        if (!assigned) {
            summary.innerHTML = capability;
            return;
        }
        const email = row.querySelector('[name$="[max_emails]"]')?.value;
        const chat = row.querySelector('[name$="[max_chats]"]')?.value;
        const priority = row.querySelector('[name$="[priority]"]')?.value;
        const active = row.querySelector('[name$="[active]"]')?.checked;
        const type = typeSelect?.value || 'email';
        const chips = [capability];
        if (type === 'email' || type === 'omni') chips.push(`<span class="summary-chip">Email ${email}</span>`);
        if (type === 'chat' || type === 'omni') chips.push(`<span class="summary-chip">Chat ${chat}</span>`);
        if (voiceRoutingApplies()) chips.push(`<span class="summary-chip">${priorityLabel(priority)}</span>`);
        chips.push(`<span class="summary-chip ${active ? 'enabled' : 'disabled'}">${active ? 'Enabled' : 'Disabled'}</span>`);
        summary.innerHTML = chips.join('');
    }

    function toggleAgentEditor(row, open) {
        const editor = row?.querySelector('.agent-settings');
        const button = row?.querySelector('[data-agent-action="edit"]');
        if (!editor || !button) return;
        editor.style.display = open ? '' : 'none';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.innerHTML = open ? '<i class="bi bi-chevron-up"></i> Collapse' : '<i class="bi bi-pencil"></i> Edit';
        if (!open) updateAgentSummary(row);
    }

    function applyAgentFilter() {
        const term = (search?.value || '').trim().toLowerCase();
        const showAssignedPanel = activeFilter !== 'available';
        const showAvailablePanel = activeFilter !== 'assigned';
        document.querySelector('[data-agent-panel="assigned"]')?.style.setProperty('display', showAssignedPanel ? '' : 'none');
        document.querySelector('[data-agent-panel="available"]')?.style.setProperty('display', showAvailablePanel ? '' : 'none');

        document.querySelectorAll('[data-agent-row]').forEach((row) => {
            const matchesSearch = !term
                || (row.dataset.agentName || '').includes(term)
                || (row.dataset.agentEmail || '').includes(term);
            row.classList.toggle('ca-hidden', !matchesSearch);
        });

        document.querySelectorAll('[data-invalid-agent-row]').forEach((row) => {
            const matchesSearch = !term
                || (row.dataset.agentName || '').includes(term)
                || (row.dataset.agentEmail || '').includes(term);
            const matchesFilter = activeFilter !== 'available';
            row.classList.toggle('ca-hidden', !(matchesSearch && matchesFilter));
        });
        updateSelectVisibleButtons();
    }

    function bindAgentControls() {
        search = document.getElementById('agentSearch');
        filterButtons = document.querySelectorAll('.agent-filter');

        filterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                filterButtons.forEach((item) => item.classList.remove('active'));
                button.classList.add('active');
                activeFilter = button.dataset.agentFilter || 'all';
                applyAgentFilter();
            });
        });

        const agentsTab = document.getElementById('tab-agents');
        if (agentsTab && !agentsTab.dataset.assignmentBound) {
            agentsTab.dataset.assignmentBound = '1';
            agentsTab.addEventListener('click', (event) => {
            const assign = event.target.closest('[data-agent-action="assign"]');
            if (assign) {
                setAgentAssigned(assign.closest('[data-agent-row]'), true);
                return;
            }

            const remove = event.target.closest('[data-agent-action="remove"]');
            if (remove) {
                setAgentAssigned(remove.closest('[data-agent-row]'), false);
                return;
            }

            const edit = event.target.closest('[data-agent-action="edit"]');
            if (edit) {
                const row = edit.closest('[data-agent-row]');
                toggleAgentEditor(row, edit.getAttribute('aria-expanded') !== 'true');
                return;
            }

            const done = event.target.closest('[data-agent-action="done"]');
            if (done) {
                toggleAgentEditor(done.closest('[data-agent-row]'), false);
                return;
            }

            const bulk = event.target.closest('[data-bulk-action]');
            if (bulk) {
                handleBulkAction(bulk.dataset.bulkAction);
                return;
            }

            const removeInvalid = event.target.closest('[data-remove-invalid]');
            if (removeInvalid) {
                const row = removeInvalid.closest('[data-invalid-agent-row]');
                row?.querySelectorAll('input').forEach((input) => input.disabled = true);
                row?.remove();
                updateAgentCounts();
                applyAgentFilter();
            }
        });
        }

        search?.addEventListener('input', applyAgentFilter);
        agentsTab?.querySelectorAll('[data-agent-select]').forEach((input) => {
            input.addEventListener('change', updateSelectVisibleButtons);
        });
        agentsTab?.querySelectorAll('.agent-setting-input').forEach((input) => {
            input.addEventListener('input', () => updateAgentSummary(input.closest('[data-agent-row]')));
            input.addEventListener('change', () => updateAgentSummary(input.closest('[data-agent-row]')));
        });
        updateAgentCounts();
    }

    function visibleRows(selector) {
        return Array.from(document.querySelectorAll(selector))
            .filter((row) => !row.classList.contains('ca-hidden') && row.offsetParent !== null);
    }

    function updateSelectVisibleButton(button, rows) {
        if (!button) return;
        const selectable = rows
            .map((row) => row.querySelector('[data-agent-select]'))
            .filter(Boolean);
        const allSelected = selectable.length > 0 && selectable.every((check) => check.checked);
        button.dataset.selectionMode = allSelected ? 'deselect' : 'select';
        button.innerHTML = allSelected
            ? '<i class="bi bi-square"></i> Deselect all visible'
            : '<i class="bi bi-check2-square"></i> Select all visible';
    }

    function updateSelectVisibleButtons() {
        updateSelectVisibleButton(
            document.querySelector('[data-bulk-action="select-visible-available"]'),
            visibleRows('#availableAgentList [data-agent-row]')
        );
        updateSelectVisibleButton(
            document.querySelector('[data-bulk-action="select-visible-assigned"]'),
            visibleRows('#assignedAgentList [data-agent-row]')
        );
    }

    function toggleVisibleSelection(selector, button) {
        const rows = visibleRows(selector);
        const checks = rows
            .map((row) => row.querySelector('[data-agent-select]'))
            .filter(Boolean);
        const shouldSelect = button?.dataset.selectionMode !== 'deselect';
        checks.forEach((check) => check.checked = shouldSelect);
        updateSelectVisibleButtons();
    }

    function handleBulkAction(action) {
        if (action === 'select-visible-available') {
            toggleVisibleSelection(
                '#availableAgentList [data-agent-row]',
                document.querySelector('[data-bulk-action="select-visible-available"]')
            );
            return;
        }
        if (action === 'select-visible-assigned') {
            toggleVisibleSelection(
                '#assignedAgentList [data-agent-row]',
                document.querySelector('[data-bulk-action="select-visible-assigned"]')
            );
            return;
        }
        if (action === 'assign-selected') {
            visibleRows('#availableAgentList [data-agent-row]')
                .filter((row) => row.querySelector('[data-agent-select]')?.checked)
                .forEach((row) => setAgentAssigned(row, true));
            return;
        }
        if (action === 'remove-selected') {
            const rows = visibleRows('#assignedAgentList [data-agent-row]')
                .filter((row) => row.querySelector('[data-agent-select]')?.checked);
            if (!rows.length) return;
            if (!window.confirm('Remove selected agents from this queue?')) return;
            rows.forEach((row) => setAgentAssigned(row, false));
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML;
    }

    function buildAgentCard(agent, assigned) {
        const type = typeSelect?.value || 'email';
        const hasVoice = !!agent.voice_configured;
        const showEmail = type === 'email' || type === 'omni';
        const showChat = type === 'chat' || type === 'omni';
        const showPriority = voiceRoutingApplies();
        const initial = escapeHtml((agent.name || '?').charAt(0).toUpperCase());
        const capability = hasVoice ? 'Voice ready' : 'Digital channels only';
        const capabilityClass = hasVoice ? '' : ' digital';
        const summary = [
            `<span class="capability-badge${capabilityClass}">${capability}</span>`,
            assigned && showEmail ? '<span class="summary-chip">Email 3</span>' : '',
            assigned && showChat ? '<span class="summary-chip">Chat 1</span>' : '',
            assigned && showPriority ? `<span class="summary-chip">${priorityLabel(0)}</span>` : '',
            assigned ? '<span class="summary-chip enabled">Enabled</span>' : '',
        ].filter(Boolean).join('');
        return `<div class="ca-row agent-card" data-agent-row data-agent-id="${agent.id}" data-agent-name="${escapeHtml(String(agent.name || '').toLowerCase())}" data-agent-email="${escapeHtml(String(agent.email || '').toLowerCase())}" data-assigned="${assigned ? '1' : '0'}">
            <div class="ca-info">
                <div class="agent-row-top">
                    <input type="checkbox" class="ca-chk agent-bulk-check" data-agent-select aria-label="Select ${escapeHtml(agent.name)}">
                    <span class="ca-avatar">${initial}</span>
                    <span class="ca-info"><span class="ca-name">${escapeHtml(agent.name)}</span><span class="ca-email">${escapeHtml(agent.email)}</span><span class="agent-card-summary">${summary}</span></span>
                    <button type="button" class="ca-btn ca-btn-add" data-agent-action="assign" style="${assigned ? 'display:none;' : ''}"><i class="bi bi-plus-circle"></i> Assign</button>
                    <button type="button" class="ca-btn" data-agent-action="edit" aria-expanded="false" aria-controls="agent-settings-${agent.id}" style="${assigned ? '' : 'display:none;'}"><i class="bi bi-pencil"></i> Edit</button>
                    <button type="button" class="ca-btn ca-btn-remove" data-agent-action="remove" style="${assigned ? '' : 'display:none;'}"><i class="bi bi-dash-circle"></i> Remove</button>
                </div>
                <div id="agent-settings-${agent.id}" class="agent-settings" style="display:none;">
                    <input type="hidden" class="agent-enabled-input" name="agents[${agent.id}][enabled]" value="1" ${assigned ? '' : 'disabled'}>
                    <div class="agent-settings-grid">
                        ${showEmail ? `<div class="capacity-email"><label class="form-label">Email capacity</label><input type="number" name="agents[${agent.id}][max_emails]" class="form-control form-control-sm agent-setting-input" value="3" min="0" max="50" ${assigned ? '' : 'disabled'}></div>` : `<input type="hidden" class="agent-setting-input" name="agents[${agent.id}][max_emails]" value="3" ${assigned ? '' : 'disabled'}>`}
                        ${showChat ? `<div class="capacity-chat"><label class="form-label">Chat capacity</label><input type="number" name="agents[${agent.id}][max_chats]" class="form-control form-control-sm agent-setting-input" value="1" min="0" max="50" ${assigned ? '' : 'disabled'}></div>` : `<input type="hidden" class="agent-setting-input" name="agents[${agent.id}][max_chats]" value="1" ${assigned ? '' : 'disabled'}>`}
                        ${(type === 'voice' || type === 'omni') ? `<div class="agent-priority-setting" style="${showPriority ? '' : 'display:none;'}"><label class="form-label">Routing priority</label>${prioritySelect(`agents[${agent.id}][priority]`, 0, assigned && showPriority)}</div><input type="hidden" class="agent-setting-input agent-priority-hidden" name="agents[${agent.id}][priority]" value="0" ${assigned && !showPriority ? '' : 'disabled'}>` : `<input type="hidden" class="agent-setting-input" name="agents[${agent.id}][priority]" value="0" ${assigned ? '' : 'disabled'}>`}
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;"><input type="checkbox" class="agent-setting-input" name="agents[${agent.id}][active]" value="1" checked ${assigned ? '' : 'disabled'}> Receive work from this queue</label>
                    <button type="button" class="ca-btn" data-agent-action="done" style="margin-top:10px;"><i class="bi bi-check2"></i> Done</button>
                </div>
            </div>
        </div>`;
    }

    function buildInvalidAssignment(row) {
        const id = row.dataset.agentId;
        return `<div class="ca-row" data-invalid-agent-row data-agent-name="${row.dataset.agentName || ''}" data-agent-email="${row.dataset.agentEmail || ''}">
            <div class="agent-row-main"><span class="ca-avatar">${escapeHtml((row.dataset.agentName || '?').charAt(0).toUpperCase())}</span><span class="ca-info"><span class="ca-name">${escapeHtml(row.querySelector('.ca-name')?.textContent || 'Selected agent')}</span><span class="ca-email">${escapeHtml(row.querySelector('.ca-email')?.textContent || '')}</span><span class="capability-badge" style="background:#fef3c7;color:#92400e;">No longer eligible for the selected campaign</span></span></div>
            <div class="invalid-agent-inputs"><input type="hidden" name="agents[${id}][enabled]" value="1"><input type="hidden" name="agents[${id}][max_emails]" value="3"><input type="hidden" name="agents[${id}][max_chats]" value="1"><input type="hidden" name="agents[${id}][priority]" value="0"><input type="hidden" name="agents[${id}][active]" value="1"></div>
            <button type="button" class="ca-btn ca-btn-remove" data-remove-invalid><i class="bi bi-dash-circle"></i> Remove assignment</button>
        </div>`;
    }

    campaignSelect?.addEventListener('change', () => {
        const agentsTab = document.getElementById('tab-agents');
        if (!agentsTab) return;
        const selectedText = campaignSelect.options[campaignSelect.selectedIndex]?.text || '';
        if (!campaignSelect.value) {
            agentsTab.innerHTML = '<div class="section-title">Agents</div><div class="alert alert-info" style="margin-bottom:0;">Select a campaign to view its available agents.</div>';
            return;
        }

        const selectedRows = Array.from(document.querySelectorAll('#assignedAgentList [data-agent-row]'));
        const selectedIds = selectedRows.map((row) => String(row.dataset.agentId));
        fetch(`{{ url('omni-queues/agents-by-campaign') }}/${campaignSelect.value}`, { headers: { 'Accept': 'application/json' } })
            .then((response) => response.ok ? response.json() : Promise.reject())
            .then((data) => {
                const eligibleIds = new Set((data.agents || []).map((agent) => String(agent.id)));
                const invalidRows = selectedRows.filter((row) => !eligibleIds.has(String(row.dataset.agentId)));
                const assignedCards = [];
                const availableCards = [];
                (data.agents || []).forEach((agent) => {
                    const assigned = selectedIds.includes(String(agent.id));
                    (assigned ? assignedCards : availableCards).push(buildAgentCard(agent, assigned));
                });

                agentsTab.innerHTML = `<div class="section-title">Agents</div>
                    <div class="ca-toolbar"><input type="search" id="agentSearch" class="form-control" placeholder="Search agents by name or email"><button type="button" class="agent-filter active" data-agent-filter="all">All eligible</button><button type="button" class="agent-filter" data-agent-filter="assigned">Assigned</button><button type="button" class="agent-filter" data-agent-filter="available">Available</button><div class="ca-summary">Eligible:&nbsp;<strong id="eligibleAgentCount">${(data.agents || []).length}</strong>&emsp; Available:&nbsp;<strong id="availableAgentSummaryCount">0</strong>&emsp; Assigned:&nbsp;<strong id="assignedAgentCount">${assignedCards.length + invalidRows.length}</strong>&emsp; Voice-ready assigned:&nbsp;<strong id="voiceReadyAssignedCount">0</strong>&emsp; Digital-only assigned:&nbsp;<strong id="digitalOnlyAssignedCount">0</strong></div></div>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;font-size:13px;color:var(--text-muted);"><span>Campaign: <strong style="color:var(--text-main);">${escapeHtml(selectedText)}</strong></span></div>
                    ${invalidRows.length ? `<div class="invalid-assignments" id="invalidAssignments"><div class="invalid-assignments-hd">Assignments requiring attention</div>${invalidRows.map(buildInvalidAssignment).join('')}<div style="padding:10px 12px;font-size:12px;color:#854d0e;">Remove these assignments or choose a campaign where the agents are eligible before saving.</div></div>` : ''}
                    <div class="ca-dual"><div class="ca-panel" data-agent-panel="available"><div class="ca-panel-hd ca-panel-hd--available"><strong>Available Agents (<span id="availableAgentCount">0</span>)</strong><p>Campaign-eligible agents who can be assigned to this queue.</p></div><div class="ca-panel-body" id="availableAgentList">${availableCards.join('')}</div><div class="ca-panel-ft"><button type="button" class="btn btn-secondary btn-xs" data-bulk-action="select-visible-available"><i class="bi bi-check2-square"></i> Select all visible</button><button type="button" class="btn btn-primary btn-xs" data-bulk-action="assign-selected"><i class="bi bi-plus-circle"></i> Assign selected</button></div></div><div class="ca-panel" data-agent-panel="assigned"><div class="ca-panel-hd ca-panel-hd--assigned"><strong>Assigned Agents (<span id="assignedAgentPanelCount">0</span>)</strong><p>Queue-specific routing and capacity settings apply here.</p></div><div class="ca-panel-body" id="assignedAgentList">${assignedCards.join('')}</div><div class="ca-panel-ft"><button type="button" class="btn btn-secondary btn-xs" data-bulk-action="select-visible-assigned"><i class="bi bi-check2-square"></i> Select all visible</button><button type="button" class="btn btn-danger btn-xs" data-bulk-action="remove-selected"><i class="bi bi-dash-circle"></i> Remove selected</button></div></div></div>`;
                activeFilter = 'all';
                bindAgentControls();
                syncChannelVisibility();
                applyAgentFilter();
            })
            .catch(() => {});
    });

    typeSelect?.addEventListener('change', syncChannelVisibility);
    enabled?.addEventListener('change', syncChannelVisibility);
    bindAgentControls();
    syncChannelVisibility();
    applyAgentFilter();
});
</script>
@endpush
