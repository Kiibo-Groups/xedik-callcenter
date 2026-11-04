@extends('layouts.app')

@section('title', 'Editar Campaña de Marketing')
@section('page-title', $marketingCampaign->name)
@section('page-subtitle', strtoupper($marketingCampaign->channel) . ' — ' . ucfirst($marketingCampaign->status))

@section('header-actions')
    <a href="{{ route('marketing-campaigns.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Volver a Campañas
    </a>
@endsection

@section('content')

<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">En Cola Total</div><div class="stat-value">{{ number_format($stats['total'] ?? 0) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
        <div><div class="stat-label">Enviados</div><div class="stat-value">{{ number_format($stats['sent'] ?? 0) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="stat-label">Pendientes</div><div class="stat-value">{{ number_format($stats['pending'] ?? 0) }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div><div class="stat-label">Fallidos / Omitidos</div><div class="stat-value">{{ number_format(($stats['failed'] ?? 0) + ($stats['skipped'] ?? 0)) }}</div></div>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-sliders icon-primary"></i> Configuración de la Campaña</span>
    </div>
    <div class="card-body" style="padding:20px;">
        <form method="POST" action="{{ route('marketing-campaigns.update', $marketingCampaign) }}" id="editMarketingForm">
            @csrf
            @method('PUT')
            @include('marketing_campaigns._form', ['marketingCampaign' => $marketingCampaign])
            <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
                <a href="{{ route('marketing-campaigns.index') }}" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-journal-text icon-primary"></i> Bitácora de Envíos Recientes (Últimos 100 destinatarios)</span>
        <form method="POST" action="{{ route('marketing-campaigns.refresh-stats', $marketingCampaign) }}" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-outline btn-xs"><i class="bi bi-arrow-clockwise"></i> Actualizar Bitácora</button>
        </form>
    </div>
    <div class="table-wrapper">
        @if($queueItems->count())
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Destinatario</th>
                    <th>Nombre</th>
                    <th>Estado</th>
                    <th>Intentos</th>
                    <th>Fecha Envío</th>
                    <th>ID Proveedor / Detalle Error</th>
                </tr>
            </thead>
            <tbody>
                @foreach($queueItems as $item)
                <tr>
                    <td class="cell-muted">#{{ $item->id }}</td>
                    <td><code class="cell-code">{{ $item->recipient }}</code></td>
                    <td>{{ $item->recipient_name ?: '—' }}</td>
                    <td>
                        @if($item->status === 'sent')
                            <span class="badge badge-success"><i class="bi bi-check2"></i> Enviado</span>
                        @elseif($item->status === 'failed')
                            <span class="badge badge-danger"><i class="bi bi-x-circle"></i> Fallido</span>
                        @elseif($item->status === 'sending')
                            <span class="badge badge-info"><i class="bi bi-arrow-repeat"></i> Enviando</span>
                        @else
                            <span class="badge badge-secondary"><i class="bi bi-clock"></i> Pendiente</span>
                        @endif
                    </td>
                    <td class="cell-muted">{{ $item->attempts }}</td>
                    <td class="cell-muted">{{ $item->sent_at ? \Carbon\Carbon::parse($item->sent_at)->format('d/m/Y H:i:s') : '—' }}</td>
                    <td style="font-size:12px;">
                        @if($item->error_message)
                            <span style="color:var(--danger);">{{ $item->error_message }}</span>
                        @elseif($item->provider_message_id)
                            <code class="cell-code">{{ \Illuminate\Support\Str::limit($item->provider_message_id, 42) }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h3>Sin envíos en cola</h3>
            <p>Cuando inicies la campaña desde el listado principal, aquí verás el detalle en vivo de cada mensaje enviado.</p>
        </div>
        @endif
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
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const f = document.getElementById('editMarketingForm');
    if (f) {
        syncMarketingFormChannel(f);
        syncMarketingFormTenant(f);
    }
});
</script>
@endpush

