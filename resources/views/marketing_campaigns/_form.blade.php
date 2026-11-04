@php
    $mc = $marketingCampaign ?? null;
    $currentChannel = old('channel', $mc?->channel ?? 'whatsapp');
    $currentTenant  = old('tenant_id', $mc?->tenant_id ?? '');
@endphp

<div class="form-row cols-2">
    <div class="form-group">
        <label class="form-label">Nombre de la Campaña *</label>
        <input type="text" name="name" class="form-control" placeholder="Ej. Promo Generadores Noviembre — WhatsApp" required value="{{ old('name', $mc?->name) }}">
        @error('name')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
    <div class="form-group">
        <label class="form-label">Canal de Envío *</label>
        <select name="channel" class="form-control js-mc-channel" required onchange="syncMarketingFormChannel(this.closest('form'))">
            <option value="whatsapp" {{ $currentChannel === 'whatsapp' ? 'selected' : '' }}>💬 WhatsApp Oficial (Meta Cloud API)</option>
            <option value="sms" {{ $currentChannel === 'sms' ? 'selected' : '' }}>📱 SMS Masivo</option>
            <option value="email" {{ $currentChannel === 'email' ? 'selected' : '' }}>✉️ Email Marketing (SMTP)</option>
        </select>
        @error('channel')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>
</div>

@if($isSuperAdmin)
<div class="form-group">
    <label class="form-label">Tenant (Empresa) *</label>
    <select name="tenant_id" class="form-control js-mc-tenant" required onchange="syncMarketingFormTenant(this.closest('form'))">
        <option value="">— Seleccionar Tenant —</option>
        @foreach($tenants as $tenant)
        <option value="{{ $tenant->id }}" {{ (string) $currentTenant === (string) $tenant->id ? 'selected' : '' }}>{{ $tenant->name }}</option>
        @endforeach
    </select>
    @error('tenant_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
</div>
@endif

<div class="form-row cols-2">
    <div class="form-group">
        <label class="form-label">Campaña CRM (Opcional)</label>
        <select name="campaign_id" class="form-control js-mc-tenant-scoped">
            <option value="">— Sin campaña específica —</option>
            @foreach($crmCampaigns as $c)
            <option value="{{ $c->id }}" data-tenant-id="{{ $c->tenant_id }}" {{ (string) old('campaign_id', $mc?->campaign_id) === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Lista de Leads *</label>
        <select name="lead_list_id" class="form-control js-mc-tenant-scoped" required>
            <option value="">— Seleccionar Lista de Leads —</option>
            @foreach($leadLists as $ll)
            <option value="{{ $ll->id }}" data-tenant-id="{{ $ll->tenant_id }}" {{ (string) old('lead_list_id', $mc?->lead_list_id) === (string) $ll->id ? 'selected' : '' }}>
                {{ $ll->name }} ({{ number_format($ll->total_leads) }} leads · {{ $ll->status }})
            </option>
            @endforeach
        </select>
        <div class="form-hint">Usa las mismas listas subidas en Lead Lists (teléfono + email + variables).</div>
    </div>
</div>

{{-- ── WHATSAPP FIELDS ── --}}
<div class="js-mc-section" data-channel="whatsapp" style="padding:12px;background:var(--bg-stripe, #f8fafc);border-radius:8px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:13px;margin-bottom:10px;color:#16a34a;display:flex;align-items:center;gap:6px;">
        <i class="bi bi-whatsapp"></i> Configuración de WhatsApp Marketing
    </div>
    <div class="form-row cols-2">
        <div class="form-group mb-0">
            <label class="form-label">Cuenta / Número de WhatsApp *</label>
            <select name="whatsapp_account_id" class="form-control js-mc-tenant-scoped">
                <option value="">— Automática del Tenant —</option>
                @foreach($whatsappAccounts as $wa)
                <option value="{{ $wa->id }}" data-tenant-id="{{ $wa->tenant_id }}" {{ (string) old('whatsapp_account_id', $mc?->whatsapp_account_id) === (string) $wa->id ? 'selected' : '' }}>
                    {{ $wa->display_name }} ({{ $wa->phone_number }})
                </option>
                @endforeach
            </select>
        </div>
        <div class="form-group mb-0">
            <label class="form-label">Plantilla Oficial Aprobada (Meta) *</label>
            <select name="whatsapp_template_id" class="form-control js-mc-tenant-scoped">
                <option value="">— Seleccionar Plantilla —</option>
                @foreach($whatsappTemplates as $wt)
                <option value="{{ $wt->id }}" data-tenant-id="{{ $wt->tenant_id }}" data-preview="{{ $wt->body_preview }}" {{ (string) old('whatsapp_template_id', $mc?->whatsapp_template_id) === (string) $wt->id ? 'selected' : '' }}>
                    {{ $wt->display_name }} ({{ $wt->name }} · {{ $wt->language }})
                </option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="form-hint" style="margin-top:8px;">
        Si la plantilla contiene <code>@{{1}}</code>, se rellenará automáticamente con el <strong>Nombre del contacto</strong> de la lista. Cuando el cliente responda, el Bot de IA o la cola de agentes continuará la conversación con todo el contexto.
    </div>
</div>

{{-- ── SMS FIELDS ── --}}
<div class="js-mc-section" data-channel="sms" style="padding:12px;background:var(--bg-stripe, #f8fafc);border-radius:8px;margin-bottom:14px;display:none;">
    <div style="font-weight:600;font-size:13px;margin-bottom:10px;color:#0284c7;display:flex;align-items:center;gap:6px;">
        <i class="bi bi-chat-dots-fill"></i> Configuración de Campaña SMS
    </div>
    <div class="form-row cols-2">
        <div class="form-group">
            <label class="form-label">Número / Canal SMS *</label>
            <select name="sms_channel_id" class="form-control js-mc-tenant-scoped">
                <option value="">— Automático del Tenant —</option>
                @foreach($smsChannels as $sc)
                <option value="{{ $sc->id }}" data-tenant-id="{{ $sc->tenant_id }}" {{ (string) old('sms_channel_id', $mc?->sms_channel_id) === (string) $sc->id ? 'selected' : '' }}>
                    {{ $sc->display_name }} ({{ $sc->address }})
                </option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Plantilla SMS (Opcional)</label>
            <select name="sms_template_id" class="form-control js-mc-tenant-scoped" onchange="if(this.selectedOptions[0]?.dataset.body){ this.closest('form').querySelector('[name=custom_body]').value = this.selectedOptions[0].dataset.body; }">
                <option value="">— Escribir mensaje personalizado abajo —</option>
                @foreach($smsTemplates as $st)
                <option value="{{ $st->id }}" data-tenant-id="{{ $st->tenant_id }}" data-body="{{ $st->body }}" {{ (string) old('sms_template_id', $mc?->sms_template_id) === (string) $st->id ? 'selected' : '' }}>
                    {{ $st->name }}
                </option>
                @endforeach
            </select>
        </div>
    </div>
</div>

{{-- ── EMAIL FIELDS ── --}}
<div class="js-mc-section" data-channel="email" style="padding:12px;background:var(--bg-stripe, #f8fafc);border-radius:8px;margin-bottom:14px;display:none;">
    <div style="font-weight:600;font-size:13px;margin-bottom:10px;color:#4f46e5;display:flex;align-items:center;gap:6px;">
        <i class="bi bi-envelope-paper-fill"></i> Configuración de Email Marketing
    </div>
    <div class="form-row cols-2">
        <div class="form-group">
            <label class="form-label">Cuenta Remitente (SMTP) *</label>
            <select name="email_account_id" class="form-control js-mc-tenant-scoped">
                <option value="">— Automática del Tenant —</option>
                @foreach($emailAccounts as $ea)
                <option value="{{ $ea->id }}" data-tenant-id="{{ $ea->tenant_id }}" {{ (string) old('email_account_id', $mc?->email_account_id) === (string) $ea->id ? 'selected' : '' }}>
                    {{ $ea->name }} &lt;{{ $ea->email }}&gt;
                </option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Plantilla de Email (Opcional)</label>
            <select name="email_template_id" class="form-control js-mc-tenant-scoped">
                <option value="">— Usar Asunto y Cuerpo personalizados abajo —</option>
                @foreach($emailTemplates as $et)
                <option value="{{ $et->id }}" data-tenant-id="{{ $et->tenant_id }}" {{ (string) old('email_template_id', $mc?->email_template_id) === (string) $et->id ? 'selected' : '' }}>
                    {{ $et->name }} ({{ $et->subject }})
                </option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="form-group mb-0">
        <label class="form-label">Asunto del Correo (Subject)</label>
        <input type="text" name="custom_subject" class="form-control" placeholder="Ej. Hola @{{name}}, conoce nuestras promociones exclusivas" value="{{ old('custom_subject', $mc?->custom_subject) }}">
        <div class="form-hint">Si seleccionaste una Plantilla de Email y dejas esto vacío, usará el asunto de la plantilla.</div>
    </div>
</div>

{{-- ── CUSTOM BODY FOR SMS OR EMAIL ── --}}
<div class="form-group js-mc-body-wrap" style="display:none;">
    <label class="form-label">Contenido del Mensaje (SMS / Email HTML)</label>
    <textarea name="custom_body" class="form-control" rows="4" placeholder="Hola @{{name}}, te escribimos de parte de nuestro equipo...">{{ old('custom_body', $mc?->custom_body) }}</textarea>
    <div class="form-hint">
        Variables disponibles: <code>@{{name}}</code>, <code>@{{phone}}</code>, <code>@{{email}}</code>, <code>@{{date.today}}</code> o cualquier columna del CSV como <code>@{{leadlist.ciudad}}</code>.
    </div>
</div>

<div class="form-row cols-2">
    <div class="form-group">
        <label class="form-label">Tamaño de Lote (Mensajes por ciclo) *</label>
        <input type="number" name="batch_size" class="form-control" value="{{ old('batch_size', $mc?->batch_size ?? 20) }}" min="1" max="200" required>
        <div class="form-hint">Cantidad de mensajes enviados cada ciclo del worker (recomendado: 15 a 30).</div>
    </div>
    <div class="form-group">
        <label class="form-label">Reintentos Máximos *</label>
        <input type="number" name="max_retries" class="form-control" value="{{ old('max_retries', $mc?->max_retries ?? 1) }}" min="1" max="10" required>
        <div class="form-hint">Intentos por destinatario en caso de error temporal de red.</div>
    </div>
</div>

<div class="form-group">
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;">
        <input type="checkbox" name="open_conversation" value="1" {{ old('open_conversation', $mc?->open_conversation ?? true) ? 'checked' : '' }}>
        Registrar conversación en Bandeja Omnicanal / Activar Bot IA al responder
    </label>
    <div class="form-hint">Si está activo, cuando el cliente conteste el WhatsApp, SMS o Correo, el Bot de IA o los agentes verán el mensaje de la campaña como historial previo.</div>
</div>

<div style="margin-top:1.25rem;padding-top:1rem;border-top:1px dashed var(--border-color, #e2e8f0);">
    <div style="font-weight:600;font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:6px;color:var(--text-primary, #0f172a);">
        <i class="bi bi-calendar-event text-primary"></i> Programación / Ventana Horaria (Opcional)
    </div>
    <div class="form-row cols-2">
        <div class="form-group">
            <label class="form-label">Fecha y Hora de Inicio</label>
            <input type="datetime-local" name="scheduled_at" class="form-control" value="{{ old('scheduled_at', $mc?->scheduled_at?->format('Y-m-d\TH:i')) }}">
            <div class="form-hint">Si es a futuro, la campaña iniciará sola automáticamente.</div>
        </div>
        <div class="form-group">
            <label class="form-label">Fecha y Hora de Fin</label>
            <input type="datetime-local" name="scheduled_end_at" class="form-control" value="{{ old('scheduled_end_at', $mc?->scheduled_end_at?->format('Y-m-d\TH:i')) }}">
            <div class="form-hint">Se pausará automáticamente al llegar a esta fecha/hora.</div>
        </div>
    </div>
    <div class="form-row cols-2">
        <div class="form-group mb-0">
            <label class="form-label">Ventana Diaria: Hora Inicio</label>
            <input type="time" name="daily_start_time" class="form-control" value="{{ old('daily_start_time', $mc?->daily_start_time ? substr($mc->daily_start_time, 0, 5) : '') }}">
        </div>
        <div class="form-group mb-0">
            <label class="form-label">Ventana Diaria: Hora Fin</label>
            <input type="time" name="daily_end_time" class="form-control" value="{{ old('daily_end_time', $mc?->daily_end_time ? substr($mc->daily_end_time, 0, 5) : '') }}">
        </div>
    </div>
</div>

