@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Centro de Control')
@section('page-subtitle', 'Resumen Operativo — ' . ($currentTenant?->name ?? 'General'))

@section('content')
<div style="display:flex;flex-direction:column;gap:24px;">

    <!-- ── 1. BANNER PRINCIPAL CON SEPARACIÓN POR TENANT ──────────────────────── -->
    <div class="card" style="padding:24px 28px;background:linear-gradient(135deg, rgba(37,99,235,0.08) 0%, rgba(15,23,42,0.02) 100%);border:1px solid rgba(37,99,235,0.18);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:18px;">
                <div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#1d4ed8);display:flex;align-items:center;justify-content:center;color:white;font-size:24px;box-shadow:0 8px 18px rgba(37,99,235,0.3);flex-shrink:0;">
                    <i class="bi bi-speedometer2"></i>
                </div>
                <div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <h2 style="font-size:20px;font-weight:700;color:var(--text-strong);letter-spacing:-0.02em;">
                            ¡Hola, {{ Auth::user()->name }}!
                        </h2>
                        <span class="badge" style="background:rgba(37,99,235,0.15);color:#3b82f6;font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px;border:1px solid rgba(37,99,235,0.25);">
                            <i class="bi bi-building me-1"></i> Tenant: {{ $currentTenant?->name ?? 'Default' }}
                        </span>
                    </div>
                    <p style="font-size:13px;color:var(--text-muted);margin-top:4px;">
                        XEDIK Enterprise Contact Suite — Panel operativo exclusivo de <strong>{{ $currentTenant?->name ?? 'Organización' }}</strong>
                    </p>
                </div>
            </div>

            <!-- Selector de Tenant (Visible para SuperAdmin) & Estado PBX -->
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                @if($isSuperAdmin && $availableTenants->count() > 1)
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:12px;font-weight:600;color:var(--text-muted);">Filtrar Tenant:</span>
                        <select onchange="window.location.href='?tenant_id=' + this.value" 
                                style="background:var(--bg-input);color:var(--text-strong);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:13px;font-weight:600;outline:none;cursor:pointer;">
                            @foreach($availableTenants as $t)
                                <option value="{{ $t->id }}" {{ $tenantId === (int)$t->id ? 'selected' : '' }}>
                                    {{ $t->name }} (ID {{ $t->id }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.25);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;color:#10b981;">
                    <span style="width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
                    PBX Asterisk Online
                </div>
            </div>
        </div>
    </div>

    <!-- ── 2. METRICAS CLAVE DEL DIA (KPIs) ──────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;">
        <!-- Card 1: Llamadas Hoy -->
        <div class="stat-card" style="position:relative;overflow:hidden;">
            <div class="stat-icon blue"><i class="bi bi-telephone-inbound-fill"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Llamadas de Hoy</div>
                <div class="stat-value" style="font-size:24px;font-weight:800;color:var(--text-strong);">{{ $todayCallsCount }}</div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                    <span style="color:#3b82f6;font-weight:600;">{{ $todayVoicebotCalls }}</span> Bot IA &bull; 
                    <span style="color:#10b981;font-weight:600;">{{ $todayHumanCalls }}</span> Agente
                </div>
            </div>
        </div>

        <!-- Card 2: Duración Promedio -->
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-clock-history"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Duración Promedio (AHT)</div>
                <div class="stat-value" style="font-size:24px;font-weight:800;color:var(--text-strong);">{{ $avgDurationFormatted }}</div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Tiempo promedio de conversación</div>
            </div>
        </div>

        <!-- Card 3: Total Leads -->
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-people-fill"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Prospectos (Leads)</div>
                <div class="stat-value" style="font-size:24px;font-weight:800;color:var(--text-strong);">{{ $totalLeads }}</div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                    <span style="color:#8b5cf6;font-weight:600;">+{{ $todayLeads }}</span> registrados hoy
                </div>
            </div>
        </div>

        <!-- Card 4: Citas / Compromisos -->
        <div class="stat-card">
            <div class="stat-icon yellow"><i class="bi bi-calendar-check-fill"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Citas &amp; Cierres IA</div>
                <div class="stat-value" style="font-size:24px;font-weight:800;color:var(--text-strong);">{{ $scheduledCallsCount }}</div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Agendadas en este tenant</div>
            </div>
        </div>
    </div>

    <!-- ── 3. CUERPO PRINCIPAL (2 COLUMNAS) ─────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

        <!-- ── COLUMNA IZQUIERDA: Campañas y Citas ── -->
        <div style="display:flex;flex-direction:column;gap:24px;">

            <!-- Widget: Campañas Dialer Activas -->
            <div class="card">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="bi bi-broadcast" style="color:#3b82f6;font-size:18px;"></i>
                        <span class="card-title">Campañas de Marcación Activas (Tenant: {{ $currentTenant?->name }})</span>
                    </div>
                    <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 10px;">
                        Ver Campañas <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body" style="padding:0;">
                    @if($dialerCampaigns->isEmpty())
                        <div style="padding:32px;text-align:center;color:var(--text-muted);">
                            <i class="bi bi-inbox" style="font-size:32px;opacity:0.4;"></i>
                            <p style="margin-top:8px;font-size:13px;">No hay campañas de marcación registradas para este tenant.</p>
                            <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-primary btn-sm" style="margin-top:12px;">
                                <i class="bi bi-plus-circle me-1"></i> Crear Campaña Dialer
                            </a>
                        </div>
                    @else
                        <div style="overflow-x:auto;">
                            <table class="table" style="width:100%;border-collapse:collapse;margin:0;">
                                <thead>
                                    <tr style="background:var(--bg-th);border-bottom:1px solid var(--border);text-align:left;">
                                        <th style="padding:12px 18px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Campaña</th>
                                        <th style="padding:12px 18px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Tipo</th>
                                        <th style="padding:12px 18px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Lista Asociada</th>
                                        <th style="padding:12px 18px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Estado</th>
                                        <th style="padding:12px 18px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;text-align:right;">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($dialerCampaigns as $c)
                                        <tr style="border-bottom:1px solid var(--border);transition:background .15s;">
                                            <td style="padding:14px 18px;">
                                                <div style="font-weight:600;color:var(--text-strong);font-size:13px;">{{ $c->name }}</div>
                                                <div style="font-size:11px;color:var(--text-muted);">Cola: {{ $c->omniQueue?->name ?? 'General' }}</div>
                                            </td>
                                            <td style="padding:14px 18px;">
                                                <span class="badge" style="background:rgba(59,130,246,0.12);color:#3b82f6;font-size:11px;padding:3px 8px;border-radius:6px;">
                                                    {{ ucfirst($c->dialer_type ?? 'Preview') }}
                                                </span>
                                            </td>
                                            <td style="padding:14px 18px;font-size:12px;color:var(--text-strong);">
                                                <i class="bi bi-file-earmark-spreadsheet me-1" style="color:var(--text-muted);"></i>
                                                {{ $c->leadList?->name ?? 'Sin Lista' }}
                                            </td>
                                            <td style="padding:14px 18px;">
                                                @if($c->status === 'running')
                                                    <span class="badge" style="background:rgba(16,185,129,0.15);color:#10b981;font-size:11px;padding:4px 10px;border-radius:20px;font-weight:600;">
                                                        <i class="bi bi-play-circle-fill me-1"></i> En Ejecución
                                                    </span>
                                                @elseif($c->status === 'paused')
                                                    <span class="badge" style="background:rgba(245,158,11,0.15);color:#f59e0b;font-size:11px;padding:4px 10px;border-radius:20px;font-weight:600;">
                                                        <i class="bi bi-pause-circle-fill me-1"></i> Pausada
                                                    </span>
                                                @else
                                                    <span class="badge" style="background:rgba(100,116,139,0.15);color:#94a3b8;font-size:11px;padding:4px 10px;border-radius:20px;font-weight:600;">
                                                        {{ ucfirst($c->status) }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td style="padding:14px 18px;text-align:right;">
                                                <a href="{{ route('dialer-campaigns.edit', $c->id) }}" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:11px;">
                                                    Abrir
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Widget: Citas / Compromisos Recientes Generados por el Voicebot -->
            <div class="card">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="bi bi-calendar2-event-fill" style="color:#f59e0b;font-size:18px;"></i>
                        <span class="card-title">Citas y Compromisos de Entrega Acordados</span>
                    </div>
                    <span style="font-size:12px;color:var(--text-muted);">Confirmados por Voicebot o Agentes</span>
                </div>
                <div class="card-body" style="padding:0;">
                    @if($upcomingAppointments->isEmpty())
                        <div style="padding:32px;text-align:center;color:var(--text-muted);">
                            <i class="bi bi-calendar-x" style="font-size:32px;opacity:0.4;"></i>
                            <p style="margin-top:8px;font-size:13px;">Aún no hay citas o pedidos programados para este tenant.</p>
                        </div>
                    @else
                        <div style="display:flex;flex-direction:column;">
                            @foreach($upcomingAppointments as $app)
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--border);gap:16px;flex-wrap:wrap;">
                                    <div style="display:flex;align-items:center;gap:14px;">
                                        <div style="width:42px;height:42px;border-radius:10px;background:rgba(245,158,11,0.12);color:#f59e0b;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
                                            <i class="bi bi-alarm-fill"></i>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;color:var(--text-strong);font-size:13px;">
                                                {{ $app->lead?->full_name ?: ($app->lead?->phone ?: 'Prospecto') }}
                                            </div>
                                            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                                <i class="bi bi-telephone me-1"></i> {{ $app->lead?->phone ?: 'Sin Teléfono' }}
                                                @if($app->notes)
                                                    &bull; <span style="color:var(--text-strong);">{{ Str::limit($app->notes, 60) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-size:12px;font-weight:700;color:#f59e0b;">
                                            <i class="bi bi-calendar-event me-1"></i>
                                            {{ $app->scheduled_at ? $app->scheduled_at->format('d M, Y H:i A') : 'Por definir' }}
                                        </div>
                                        @if($app->lead_id)
                                            <a href="{{ route('leads.show', $app->lead_id) }}" style="font-size:11px;color:#3b82f6;text-decoration:none;">
                                                Ver Lead <i class="bi bi-arrow-right"></i>
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <!-- Widget: Historial Reciente de Llamadas del Tenant -->
            <div class="card">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="bi bi-telephone-outbound" style="color:#10b981;font-size:18px;"></i>
                        <span class="card-title">Últimas Llamadas Realizadas / Recibidas</span>
                    </div>
                    <a href="{{ route('reports.calls.index') }}" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 10px;">
                        Reporte Completo <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body" style="padding:0;">
                    @if($recentCalls->isEmpty())
                        <div style="padding:32px;text-align:center;color:var(--text-muted);">
                            <i class="bi bi-telephone-x" style="font-size:32px;opacity:0.4;"></i>
                            <p style="margin-top:8px;font-size:13px;">No hay llamadas registradas en este tenant todavía.</p>
                        </div>
                    @else
                        <div style="overflow-x:auto;">
                            <table class="table" style="width:100%;border-collapse:collapse;margin:0;">
                                <thead>
                                    <tr style="background:var(--bg-th);border-bottom:1px solid var(--border);text-align:left;">
                                        <th style="padding:10px 18px;font-size:11px;font-weight:700;color:var(--text-muted);">Destino / Lead</th>
                                        <th style="padding:10px 18px;font-size:11px;font-weight:700;color:var(--text-muted);">Atendió</th>
                                        <th style="padding:10px 18px;font-size:11px;font-weight:700;color:var(--text-muted);">Duración</th>
                                        <th style="padding:10px 18px;font-size:11px;font-weight:700;color:var(--text-muted);text-align:right;">Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentCalls as $rc)
                                        <tr style="border-bottom:1px solid var(--border);">
                                            <td style="padding:12px 18px;">
                                                <div style="font-weight:600;color:var(--text-strong);font-size:13px;">
                                                    {{ $rc->lead?->full_name ?: ($rc->lead?->phone ?: 'Desconocido') }}
                                                </div>
                                                <div style="font-size:11px;color:var(--text-muted);">
                                                    <i class="bi {{ $rc->direction === 'inbound' ? 'bi-arrow-down-left text-primary' : 'bi-arrow-up-right text-success' }} me-1"></i>
                                                    {{ ucfirst($rc->direction ?? 'outbound') }} &bull; {{ $rc->disposition ?? 'Completada' }}
                                                </div>
                                            </td>
                                            <td style="padding:12px 18px;">
                                                @if($rc->agent)
                                                    <span class="badge" style="background:rgba(16,185,129,0.12);color:#10b981;font-size:11px;padding:3px 8px;border-radius:6px;">
                                                        <i class="bi bi-person me-1"></i> {{ $rc->agent->name }}
                                                    </span>
                                                @else
                                                    <span class="badge" style="background:rgba(59,130,246,0.12);color:#3b82f6;font-size:11px;padding:3px 8px;border-radius:6px;">
                                                        <i class="bi bi-robot me-1"></i> Voicebot IA
                                                    </span>
                                                @endif
                                            </td>
                                            <td style="padding:12px 18px;font-size:12px;color:var(--text-strong);font-weight:600;">
                                                {{ sprintf('%02d:%02d', floor($rc->duration / 60), $rc->duration % 60) }}
                                            </td>
                                            <td style="padding:12px 18px;text-align:right;font-size:11px;color:var(--text-muted);">
                                                {{ $rc->called_at ? $rc->called_at->diffForHumans() : '-' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

        </div>

        <!-- ── COLUMNA DERECHA: Agentes en Vivo y Accesos Rápidos ── -->
        <div style="display:flex;flex-direction:column;gap:24px;">

            <!-- Widget: Estado de Agentes del Tenant -->
            <div class="card">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="bi bi-people" style="color:#8b5cf6;font-size:18px;"></i>
                        <span class="card-title">Equipo en Tiempo Real</span>
                    </div>
                    <span class="badge" style="background:rgba(139,92,246,0.15);color:#8b5cf6;font-size:11px;padding:3px 8px;border-radius:12px;font-weight:600;">
                        {{ $agentsRealtime->where('is_online', true)->count() }} Online
                    </span>
                </div>
                <div class="card-body" style="padding:14px 18px;">
                    @if($agentsRealtime->isEmpty())
                        <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px;">
                            No hay agentes registrados en este tenant.
                        </div>
                    @else
                        <div style="display:flex;flex-direction:column;gap:12px;">
                            @foreach($agentsRealtime as $ag)
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--bg-th);border-radius:10px;border:1px solid var(--border);">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div style="position:relative;">
                                            <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#4f46e5);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;">
                                                {{ strtoupper(substr($ag['name'], 0, 2)) }}
                                            </div>
                                            <span style="position:absolute;bottom:-1px;right:-1px;width:10px;height:10px;border-radius:50%;border:2px solid var(--bg-card);
                                                background:{{ $ag['status_badge'] === 'green' ? '#10b981' : ($ag['status_badge'] === 'purple' ? '#a855f7' : ($ag['status_badge'] === 'yellow' ? '#f59e0b' : '#64748b')) }};"></span>
                                        </div>
                                        <div>
                                            <div style="font-size:13px;font-weight:600;color:var(--text-strong);">{{ $ag['name'] }}</div>
                                            <div style="font-size:11px;color:var(--text-muted);">Ext: <strong>{{ $ag['extension'] }}</strong> &bull; {{ $ag['role'] }}</div>
                                        </div>
                                    </div>
                                    <div>
                                        @if($ag['status_badge'] === 'green')
                                            <span class="badge" style="background:rgba(16,185,129,0.15);color:#10b981;font-size:10px;padding:3px 8px;border-radius:12px;font-weight:600;">
                                                Ready
                                            </span>
                                        @elseif($ag['status_badge'] === 'purple')
                                            <span class="badge" style="background:rgba(168,85,247,0.15);color:#a855f7;font-size:10px;padding:3px 8px;border-radius:12px;font-weight:600;">
                                                En Llamada
                                            </span>
                                        @elseif($ag['status_badge'] === 'yellow')
                                            <span class="badge" style="background:rgba(245,158,11,0.15);color:#f59e0b;font-size:10px;padding:3px 8px;border-radius:12px;font-weight:600;">
                                                En Pausa
                                            </span>
                                        @else
                                            <span class="badge" style="background:rgba(100,116,139,0.15);color:#64748b;font-size:10px;padding:3px 8px;border-radius:12px;font-weight:600;">
                                                Offline
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <!-- Widget: Accesos Rápidos Inteligentes -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Accesos Rápidos</span>
                </div>
                <div class="card-body" style="padding:16px;display:flex;flex-direction:column;gap:10px;">
                    <!-- Botón Prominente Consola WebRTC -->
                    <a href="{{ route('console.index') }}" class="btn btn-primary" style="display:flex;align-items:center;justify-content:center;gap:10px;padding:12px;font-weight:700;border-radius:10px;text-decoration:none;">
                        <i class="bi bi-headset" style="font-size:18px;"></i>
                        <span>Abrir Consola de Agente WebRTC</span>
                    </a>

                    <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-outline" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;">
                        <i class="bi bi-broadcast text-primary" style="font-size:16px;"></i>
                        <span>Administrar Campañas Dialer</span>
                    </a>

                    <a href="{{ route('leads.index') }}" class="btn btn-outline" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;">
                        <i class="bi bi-person-lines-fill text-purple" style="font-size:16px;color:#8b5cf6;"></i>
                        <span>Gestionar Base de Leads</span>
                    </a>

                    <a href="{{ route('reports.calls.index') }}" class="btn btn-outline" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;">
                        <i class="bi bi-bar-chart-line text-success" style="font-size:16px;color:#10b981;"></i>
                        <span>Reportes de Telefonía &amp; Grabaciones</span>
                    </a>
                </div>
            </div>

        </div>

    </div>

</div>
@endsection