@php
    $agentId = (int) $agent->id;
    $isAssigned = (bool) $assigned;
    $hasVoice = (bool) $agent->voiceExtension;
    $type = $selectedType ?? 'email';
    $voiceRoutingApplies = $type === 'voice' || ($type === 'omni' && (bool) ($voiceEnabled ?? false));
    $supportsPriorityControl = in_array($type, ['voice', 'omni'], true);
    $showEmailCapacity = in_array($type, ['email', 'omni'], true);
    $showChatCapacity = in_array($type, ['chat', 'omni'], true);
    $showPriority = $voiceRoutingApplies;
    $emailCapacity = old("agents.{$agentId}.max_emails", $pivot->max_emails ?? 3);
    $chatCapacity = old("agents.{$agentId}.max_chats", $pivot->max_chats ?? 1);
    $priority = (int) old("agents.{$agentId}.priority", $pivot->priority ?? 0);
    $priorityLabels = \App\Models\OmniQueue::routingPriorityLabels();
    $priorityLabel = \App\Models\OmniQueue::routingPriorityLabel($priority);
    $active = (bool) old("agents.{$agentId}.active", $pivot->active ?? true);
    $editorId = "agent-settings-{$agentId}";
@endphp

<div class="ca-row agent-card"
    data-agent-row
    data-agent-id="{{ $agentId }}"
    data-agent-name="{{ strtolower($agent->name) }}"
    data-agent-email="{{ strtolower($agent->email) }}"
    data-assigned="{{ $isAssigned ? '1' : '0' }}">
    <div class="ca-info">
        <div class="agent-row-top">
            <input type="checkbox" class="ca-chk agent-bulk-check" data-agent-select aria-label="Select {{ $agent->name }}">
            <span class="ca-avatar">{{ strtoupper(substr($agent->name, 0, 1)) }}</span>
            <span class="ca-info">
                <span class="ca-name">{{ $agent->name }}</span>
                <span class="ca-email">{{ $agent->email }}</span>
                <span class="agent-card-summary">
                    <span class="capability-badge {{ $hasVoice ? '' : 'digital' }}">{{ $hasVoice ? 'Voice ready' : 'Digital channels only' }}</span>
                    @if($isAssigned)
                        @if($showEmailCapacity)<span class="summary-chip">Email {{ $emailCapacity }}</span>@endif
                        @if($showChatCapacity)<span class="summary-chip">Chat {{ $chatCapacity }}</span>@endif
                        @if($showPriority)<span class="summary-chip">{{ $priorityLabel }}</span>@endif
                        <span class="summary-chip {{ $active ? 'enabled' : 'disabled' }}">{{ $active ? 'Enabled' : 'Disabled' }}</span>
                    @endif
                </span>
            </span>
            <button type="button" class="ca-btn ca-btn-add" data-agent-action="assign" style="{{ $isAssigned ? 'display:none;' : '' }}">
                <i class="bi bi-plus-circle"></i> Assign
            </button>
            <button type="button"
                class="ca-btn"
                data-agent-action="edit"
                aria-expanded="false"
                aria-controls="{{ $editorId }}"
                style="{{ $isAssigned ? '' : 'display:none;' }}">
                <i class="bi bi-pencil"></i> Edit
            </button>
            <button type="button" class="ca-btn ca-btn-remove" data-agent-action="remove" style="{{ $isAssigned ? '' : 'display:none;' }}">
                <i class="bi bi-dash-circle"></i> Remove
            </button>
        </div>

        <div id="{{ $editorId }}" class="agent-settings" style="display:none;">
            <input type="hidden"
                class="agent-enabled-input"
                name="agents[{{ $agentId }}][enabled]"
                value="1"
                {{ $isAssigned ? '' : 'disabled' }}>

            <div class="agent-settings-grid">
                @if($showEmailCapacity)
                <div class="capacity-email">
                    <label class="form-label">Email capacity</label>
                    <input type="number"
                        name="agents[{{ $agentId }}][max_emails]"
                        class="form-control form-control-sm agent-setting-input"
                        value="{{ $emailCapacity }}"
                        min="0"
                        max="50"
                        {{ $isAssigned ? '' : 'disabled' }}>
                </div>
                @else
                    <input type="hidden" class="agent-setting-input" name="agents[{{ $agentId }}][max_emails]" value="{{ $emailCapacity }}" {{ $isAssigned ? '' : 'disabled' }}>
                @endif

                @if($showChatCapacity)
                <div class="capacity-chat">
                    <label class="form-label">Chat capacity</label>
                    <input type="number"
                        name="agents[{{ $agentId }}][max_chats]"
                        class="form-control form-control-sm agent-setting-input"
                        value="{{ $chatCapacity }}"
                        min="0"
                        max="50"
                        {{ $isAssigned ? '' : 'disabled' }}>
                </div>
                @else
                    <input type="hidden" class="agent-setting-input" name="agents[{{ $agentId }}][max_chats]" value="{{ $chatCapacity }}" {{ $isAssigned ? '' : 'disabled' }}>
                @endif

                @if($supportsPriorityControl)
                <div class="agent-priority-setting" style="{{ $showPriority ? '' : 'display:none;' }}">
                    <label class="form-label">Routing priority</label>
                    <select
                        name="agents[{{ $agentId }}][priority]"
                        class="form-control form-control-sm agent-setting-input"
                        {{ $isAssigned && $showPriority ? '' : 'disabled' }}>
                        @foreach($priorityLabels as $value => $label)
                            <option value="{{ $value }}" @selected($priority === (int) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="muted-help">Higher-priority agents are offered calls before lower-priority agents. Agents with the same priority are distributed according to the queue strategy.</div>
                </div>
                <input type="hidden" class="agent-setting-input agent-priority-hidden" name="agents[{{ $agentId }}][priority]" value="{{ $priority }}" {{ $isAssigned && !$showPriority ? '' : 'disabled' }}>
                @else
                    <input type="hidden" class="agent-setting-input" name="agents[{{ $agentId }}][priority]" value="{{ $priority }}" {{ $isAssigned ? '' : 'disabled' }}>
                @endif
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;">
                    <input type="checkbox"
                    class="agent-setting-input"
                    name="agents[{{ $agentId }}][active]"
                    value="1"
                    {{ $active ? 'checked' : '' }}
                    {{ $isAssigned ? '' : 'disabled' }}>
                Receive work from this queue
            </label>
            <button type="button" class="ca-btn" data-agent-action="done" style="margin-top:10px;">
                <i class="bi bi-check2"></i> Done
            </button>
        </div>
    </div>
</div>
