<div class="form-group">
    <label class="form-label">Name</label>
    <input type="text" name="name" class="form-control" placeholder="e.g. Audio-Promo 1 — Cartera Norte" value="{{ old('name') }}">
    <div class="form-hint">Internal label to identify this dialer campaign</div>
    @error('name')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>

<div class="form-group">
    <label class="form-label">Type</label>
    <select name="dialer_type" class="form-control js-dialer-type">
        <option value="power" {{ old('dialer_type') === 'power' ? 'selected' : '' }}>Power Dialer</option>
        <option value="preview" {{ old('dialer_type') === 'preview' ? 'selected' : '' }}>Preview Dialer</option>
    </select>
    @error('dialer_type')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>

@if($isSuperAdmin)
<div class="form-group">
    <label class="form-label">Tenant *</label>
    <select name="tenant_id" class="form-control js-dialer-tenant" required>
        <option value="">— Select Tenant —</option>
        @foreach($tenants as $tenant)
        <option value="{{ $tenant->id }}" {{ old('tenant_id') == $tenant->id ? 'selected' : '' }}>{{ $tenant->name }}</option>
        @endforeach
    </select>
    @error('tenant_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>
@endif

<div class="form-row cols-2">
    <div class="form-group">
        <label class="form-label">Campaign *</label>
        <select name="campaign_id" class="form-control js-dialer-campaign" required>
            <option value="">— Select —</option>
            @foreach($crmCampaigns as $c)
            <option value="{{ $c->id }}" data-tenant-id="{{ $c->tenant_id }}" {{ old('campaign_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>
        @error('campaign_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
    <div class="form-group">
        <label class="form-label">Lead List <span class="js-preview-required" style="display:none;">*</span></label>
        <select name="lead_list_id" class="form-control js-lead-list">
            <option value="">— Assign later —</option>
            @foreach($leadLists as $ll)
            <option value="{{ $ll->id }}" data-tenant-id="{{ $ll->tenant_id }}" {{ old('lead_list_id') == $ll->id ? 'selected' : '' }}>{{ $ll->name }} ({{ number_format($ll->total_leads) }})</option>
            @endforeach
        </select>
        @error('lead_list_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-row js-preview-fields" style="display:none;">
    <div class="form-group">
        <label class="form-label">Queue *</label>
        <select name="omni_queue_id" class="form-control js-queue">
            <option value="" data-placeholder="true">— Select Campaign First —</option>
            <option value="__no_compatible" data-no-compatible="true" disabled hidden>— No voice-enabled queue for this campaign —</option>
            @foreach($voiceQueues as $queue)
            <option value="{{ $queue->id }}" data-tenant-id="{{ $queue->tenant_id }}" data-campaign-id="{{ $queue->campaign_id }}" {{ old('omni_queue_id') == $queue->id ? 'selected' : '' }}>
                {{ $queue->name }}
            </option>
            @endforeach
        </select>
        <div class="form-hint">Operational queue for reporting &amp; statistics. It does not distribute calls.</div>
        @error('omni_queue_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-row cols-2">
    <div class="form-group js-preview-fields" style="display:none;">
        <label class="form-label">Script *</label>
        <select name="script_id" class="form-control js-script">
            <option value="" data-placeholder="true">— Select Campaign First —</option>
            <option value="__no_compatible" data-no-compatible="true" disabled hidden>— No compatible scripts —</option>
            @foreach($scripts as $script)
            <option value="{{ $script->id }}" data-tenant-id="{{ $script->tenant_id }}" data-campaign-id="{{ $script->campaign_id }}" {{ old('script_id') == $script->id ? 'selected' : '' }}>
                {{ $script->name }} ({{ $script->direction }})
            </option>
            @endforeach
        </select>
        <div class="form-hint">Preview requires an active outbound/both script for the selected campaign.</div>
        @error('script_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
    <div class="form-group">
        <label class="form-label">Max Retries *</label>
        <input type="number" name="max_retries" class="form-control" value="{{ old('max_retries', 3) }}" min="1" max="100" required>
        <div class="form-hint">Attempts per lead (BUSY/NO_ANSWER/FAILED only)</div>
        @error('max_retries')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-group js-preview-fields" style="display:none;">
    <label class="form-label">Offer Timeout Seconds *</label>
    <input type="number" name="offer_timeout_seconds" class="form-control" value="{{ old('offer_timeout_seconds', 60) }}" min="10" max="3600">
    <div class="form-hint">How long an offer remains available to an agent.</div>
    @error('offer_timeout_seconds')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>

<div class="form-group js-preview-fields" style="display:none;">
    <label class="form-label">Lead order</label>
    <select name="lead_order_direction" class="form-control">
        <option value="asc" {{ old('lead_order_direction', 'asc') === 'asc' ? 'selected' : '' }}>Top to bottom</option>
        <option value="desc" {{ old('lead_order_direction') === 'desc' ? 'selected' : '' }}>Bottom to top</option>
    </select>
    <div class="form-hint">Preview Dialer lead selection order for never-offered leads.</div>
    @error('lead_order_direction')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>

<div class="form-row cols-2 js-power-fields">
    <div class="form-group">
        <label class="form-label">Caller ID *</label>
        <input type="text" name="caller_id" class="form-control" placeholder="e.g. 6621234567" required value="{{ old('caller_id') }}">
        @error('caller_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
    <div class="form-group">
        <label class="form-label">Asterisk Context *</label>
        <input type="text" name="context" class="form-control" value="{{ old('context', 'marcador_simulado') }}" required>
        <div class="form-hint">Dialplan context in Asterisk</div>
        @error('context')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-group mb-0 js-power-fields">
    <label class="form-label">Concurrent Channels *</label>
    <input type="number" name="dial_block_size" class="form-control" value="{{ old('dial_block_size', 5) }}" min="1" max="100" required>
    <div class="form-hint">Max simultaneous calls</div>
    @error('dial_block_size')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>

<div class="form-group js-power-fields" style="margin-top:14px;">
    <label class="form-label">Voicebot Agent ID (ElevenLabs ConvAI)</label>
    <input type="text" name="voicebot_agent_id" class="form-control" placeholder="e.g. agent_2101m5xst5cgf1gv86jxhf2an2nd" value="{{ old('voicebot_agent_id') }}">
    <div class="form-hint">ID del Asesor en ElevenLabs para llamadas automáticas IA. Si se deja vacío, se tomará del guion de la campaña.</div>
    @error('voicebot_agent_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>

<div style="margin-top:1.25rem;padding-top:1rem;border-top:1px dashed var(--border-color, #e2e8f0);">
    <div style="font-weight:600;font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:6px;color:var(--text-primary, #0f172a);">
        <i class="bi bi-calendar-event text-primary"></i> Programación / Scheduling (Opcional)
    </div>
    <div class="form-row cols-2">
        <div class="form-group">
            <label class="form-label">Fecha y Hora de Inicio</label>
            <input type="datetime-local" name="scheduled_at" class="form-control" value="{{ old('scheduled_at') }}">
            <div class="form-hint">Si es a futuro, la campaña esperará automáticamente a esta fecha/hora para iniciar.</div>
            @error('scheduled_at')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label class="form-label">Fecha y Hora de Fin</label>
            <input type="datetime-local" name="scheduled_end_at" class="form-control" value="{{ old('scheduled_end_at') }}">
            <div class="form-hint">La campaña se pausará automáticamente al llegar a este momento.</div>
            @error('scheduled_end_at')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="form-row cols-2">
        <div class="form-group">
            <label class="form-label">Ventana Diaria: Hora Inicio</label>
            <input type="time" name="daily_start_time" class="form-control" value="{{ old('daily_start_time') }}">
            <div class="form-hint">Ej: 09:00. No marcará antes de esta hora.</div>
            @error('daily_start_time')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label class="form-label">Ventana Diaria: Hora Fin</label>
            <input type="time" name="daily_end_time" class="form-control" value="{{ old('daily_end_time') }}">
            <div class="form-hint">Ej: 18:00. Pausará las llamadas fuera de esta hora.</div>
            @error('daily_end_time')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
        </div>
    </div>
</div>
