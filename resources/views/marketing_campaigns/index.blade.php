@extends('layouts.app')

@section('title', 'Marketing Campaigns')
@section('page-title', 'Marketing Campaigns')
@section('page-subtitle', 'Campañas masivas multicanal de WhatsApp Oficial, SMS y Email')

@section('content')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-megaphone-fill"></i></div>
        <div><div class="stat-label">Total Campañas</div><div class="stat-value">{{ $campaigns->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-play-fill"></i></div>
        <div><div class="stat-label">En Ejecución</div><div class="stat-value">{{ $campaigns->where('status', 'running')->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="bi bi-clock-history"></i></div>
        <div><div class="stat-label">Programadas</div><div class="stat-value">{{ $campaigns->where('status', 'scheduled')->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-send-check-fill"></i></div>
        <div><div class="stat-label">Mensajes Enviados</div><div class="stat-value">{{ number_format($campaigns->sum('sent_count')) }}</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <span class="card-title"><i class="bi bi-megaphone-fill icon-primary"></i> Campañas Multicanal (WhatsApp · SMS · Email)</span>
            <div style="display:flex;gap:6px;">
                <button type="button" class="btn btn-outline btn-xs js-channel-filter active" onclick="filterMarketingTable('all', this)">Todos ({{ $campaigns->count() }})</button>
                <button type="button" class="btn btn-outline btn-xs js-channel-filter" onclick="filterMarketingTable('whatsapp', this)" style="color:#16a34a;border-color:#bbf7d0;"><i class="bi bi-whatsapp"></i> WhatsApp ({{ $campaigns->where('channel','whatsapp')->count() }})</button>
                <button type="button" class="btn btn-outline btn-xs js-channel-filter" onclick="filterMarketingTable('sms', this)" style="color:#0284c7;border-color:#bae6fd;"><i class="bi bi-chat-dots-fill"></i> SMS ({{ $campaigns->where('channel','sms')->count() }})</button>
                <button type="button" class="btn btn-outline btn-xs js-channel-filter" onclick="filterMarketingTable('email', this)" style="color:#4f46e5;border-color:#c7d2fe;"><i class="bi bi-envelope-fill"></i> Email ({{ $campaigns->where('channel','email')->count() }})</button>
            </div>
        </div>
        <button class="btn btn-primary" onclick="openModal('createMarketingModal')">
            <i class="bi bi-plus-circle-fill"></i> Nueva Campaña
        </button>
    </div>

    <div class="table-wrapper">
        @if($campaigns->count())
        <table>
            <thead>
                <tr>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Campaña</th>
                    <th>Canal</th>
                    <th>Lista de Leads</th>
                    <th>Remitente / Plantilla</th>
                    <th>Lote / Reintentos</th>
                    <th>Estado &amp; Progreso</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach($campaigns as $mc)
                @php
                    $s = $stats[$mc->id] ?? [
                        'pending' => 0,
                        'sending' => 0,
                        'sent'    => $mc->sent_count,
                        'failed'  => $mc->failed_count,
                        'skipped' => $mc->skipped_count,
                        'total'   => $mc->total_queued,
                    ];
                    $chBadge = match ($mc->channel) {
                        'whatsapp' => ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'bi-whatsapp', 'label' => 'WHATSAPP'],
                        'sms'      => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'icon' => 'bi-chat-dots-fill', 'label' => 'SMS'],
                        'email'    => ['bg' => '#e0e7ff', 'color' => '#4338ca', 'icon' => 'bi-envelope-fill', 'label' => 'EMAIL'],
                        default    => ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'bi-megaphone', 'label' => strtoupper($mc->channel)],
                    };
                    $stBadge = match ($mc->status) {
                        'running'   => ['class' => 'badge-success',   'icon' => 'bi-play-fill',       'label' => 'Running'],
                        'paused'    => ['class' => 'badge-warning',   'icon' => 'bi-pause-fill',      'label' => 'Paused'],
                        'scheduled' => ['class' => 'badge-info',      'icon' => 'bi-clock-history',   'label' => 'Scheduled'],
                        'completed' => ['class' => 'badge-secondary', 'icon' => 'bi-check2-all',      'label' => 'Completed'],
                        default     => ['class' => 'badge-secondary', 'icon' => 'bi-stop-fill',       'label' => 'Idle'],
                    };
                @endphp
                <tr data-mc-row="{{ $mc->id }}" data-mc-channel="{{ $mc->channel }}">
                    @if($isSuperAdmin)
                    <td class="cell-muted">{{ $mc->tenant?->name ?? '—' }}</td>
                    @endif
                    <td>
                        <div class="cell-strong">{{ $mc->name }}</div>
                        <div class="cell-muted">{{ $mc->campaign?->name ?? 'General' }}</div>
                    </td>
                    <td>
                        <span class="badge" style="background:{{ $chBadge['bg'] }};color:{{ $chBadge['color'] }};display:inline-flex;align-items:center;gap:4px;">
                            <i class="bi {{ $chBadge['icon'] }}"></i> {{ $chBadge['label'] }}
                        </span>
                    </td>
                    <td>
                        @if($mc->leadList)
                            <div>{{ $mc->leadList->name }}</div>
                            <div class="cell-muted">{{ number_format($mc->leadList->total_leads) }} leads en lista</div>
                        @else
                            <span class="cell-muted">Sin lista</span>
                        @endif
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:12px;">{{ $mc->senderLabel() }}</div>
                        <div class="cell-muted" style="font-size:11px;">{{ $mc->templateLabel() }}</div>
                    </td>
                    <td class="cell-muted">{{ $mc->batch_size }} / {{ $mc->max_retries }}</td>
                    <td>
                        <span class="badge {{ $stBadge['class'] }}">
                            <i class="bi {{ $stBadge['icon'] }} badge-icon"></i> {{ $stBadge['label'] }}
                        </span>
                        @if($mc->scheduled_at && $mc->status === 'scheduled')
                        <div style="margin-top:4px;font-size:11px;color:#0891b2;font-weight:500;">
                            <i class="bi bi-calendar-event"></i> {{ $mc->scheduled_at->format('d/m/Y H:i') }}
                        </div>
                        @endif
                        @if($mc->daily_start_time && $mc->daily_end_time)
                        <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">
                            <i class="bi bi-clock"></i> {{ substr($mc->daily_start_time, 0, 5) }} - {{ substr($mc->daily_end_time, 0, 5) }}
                        </div>
                        @endif
                        @php
                            $total = max(0, (int) ($s['total'] ?? 0));
                            $done  = (int) ($s['sent'] ?? 0) + (int) ($s['failed'] ?? 0);
                            $pct   = $total > 0 ? round($done / $total * 100) : 0;
                        @endphp
                        @if($total > 0 || $mc->sent_count > 0)
                        <div style="margin-top:6px;">
                            <div style="height:5px;background:var(--bg-stripe);border-radius:3px;overflow:hidden;width:140px;">
                                <div style="height:100%;background:#16a34a;width:{{ $pct }}%;transition:width .3s;"></div>
                            </div>
                            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">
                                {{ number_format($s['sent'] ?? 0) }} enviados · {{ number_format($s['failed'] ?? 0) }} fallidos · {{ number_format($s['pending'] ?? 0) }} en cola ({{ $pct }}%)
                            </div>
                        </div>
                        @endif
                    </td>
                    <td>
                        <div class="table-actions">
                            @if(in_array($mc->status, ['idle', 'paused', 'scheduled']))
                            <button class="btn btn-outline btn-xs" style="color:#16a34a;border-color:#16a34a;" onclick="marketingAction({{ $mc->id }}, 'play')" title="Iniciar">
                                <i class="bi bi-play-fill"></i> {{ $mc->status === 'scheduled' ? 'Start Now' : 'Start' }}
                            </button>
                            @endif
                            @if($mc->status === 'running')
                            <button class="btn btn-outline btn-xs" style="color:#b45309;border-color:#b45309;" onclick="marketingAction({{ $mc->id }}, 'pause')" title="Pausar">
                                <i class="bi bi-pause-fill"></i> Pause
                            </button>
                            @endif
                            @if(in_array($mc->status, ['running', 'paused', 'scheduled']))
                            <button class="btn btn-danger btn-xs" onclick="marketingAction({{ $mc->id }}, 'stop')" title="Detener y limpiar cola">
                                <i class="bi bi-stop-fill"></i>
                            </button>
                            @endif
                            @if($mc->status === 'completed')
                            <button class="btn btn-outline btn-xs" style="color:#0891b2;border-color:#0891b2;" onclick="marketingAction({{ $mc->id }}, 'reset')" title="Reiniciar contadores">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset
                            </button>
                            @endif
                            <form method="POST" action="{{ route('marketing-campaigns.refresh-stats', $mc) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-xs" title="Actualizar / Procesar lote">
                                    <i class="bi bi-arrow-clockwise"></i> Stats
                                </button>
                            </form>
                            @if(!$mc->isRunning())
                            <a href="{{ route('marketing-campaigns.edit', $mc) }}" class="btn btn-outline btn-xs" title="Editar / Ver bitácora">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            @endif
                            @if(!in_array($mc->status, ['running', 'paused']))
                            <form method="POST" action="{{ route('marketing-campaigns.destroy', $mc) }}" class="delete-form" data-name="esta campaña de marketing" style="display:inline;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-megaphone"></i>
            <h3>Aún no hay campañas de Marketing</h3>
            <p>Crea tu primera campaña masiva de WhatsApp Oficial, SMS o Email usando tus Listas de Leads.</p>
        </div>
        @endif
    </div>
</div>

{{-- Create Modal --}}
<div class="modal-overlay" id="createMarketingModal">
    <div class="modal" style="max-width:640px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-megaphone-fill modal-icon"></i> Nueva Campaña de Marketing</div>
            <button class="modal-close" onclick="closeModal('createMarketingModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('marketing-campaigns.store') }}" id="createMarketingForm">
            @csrf
            <div class="modal-body">
                @include('marketing_campaigns._form')
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createMarketingModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Crear Campaña</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
function syncMarketingFormChannel(form) {
    if (!form) return;
    const ch = form.querySelector('.js-mc-channel')?.value || 'whatsapp';
    form.querySelectorAll('.js-mc-section').forEach(sec => {
        sec.style.display = sec.dataset.channel === ch ? '' : 'none';
    });
    const bodyWrap = form.querySelector('.js-mc-body-wrap');
    if (bodyWrap) {
        bodyWrap.style.display = (ch === 'sms' || ch === 'email') ? '' : 'none';
    }
}

function syncMarketingFormTenant(form) {
    if (!form) return;
    const tenantSelect = form.querySelector('.js-mc-tenant');
    if (!tenantSelect) return;
    const tenantId = tenantSelect.value;
    form.querySelectorAll('.js-mc-tenant-scoped').forEach(sel => {
        Array.from(sel.options).forEach(opt => {
            if (!opt.value) return;
            const match = !tenantId || String(opt.dataset.tenantId) === String(tenantId);
            opt.hidden = !match;
            opt.disabled = !match;
            if (!match && opt.selected) opt.selected = false;
        });
    });
}

function filterMarketingTable(channel, btn) {
    document.querySelectorAll('.js-channel-filter').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    document.querySelectorAll('tr[data-mc-row]').forEach(row => {
        row.style.display = (channel === 'all' || row.dataset.mcChannel === channel) ? '' : 'none';
    });
}

async function marketingAction(id, action) {
    try {
        const res = await fetch(`{{ url('marketing-campaigns') }}/${id}/${action}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
        });
        const data = await res.json();
        if (!res.ok || data.ok === false) {
            Swal.fire('Error', data.error || 'No se pudo ejecutar la acción.', 'error');
            return;
        }
        window.location.reload();
    } catch (e) {
        Swal.fire('Error', e.message || 'Error de conexión.', 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const f = document.getElementById('createMarketingForm');
    if (f) {
        syncMarketingFormChannel(f);
        syncMarketingFormTenant(f);
    }
});
</script>
@endpush

