@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', ($isRestaurantTenant ?? false) ? 'Order Command Center' : 'Centro de Control')
@section('page-subtitle', (($isRestaurantTenant ?? false) ? 'Live Operations — ' : 'Resumen Operativo — ') . ($currentTenant?->name ?? 'General'))

@section('content')
@if($isRestaurantTenant ?? false)
<div style="display:flex;flex-direction:column;gap:24px;">

    <!-- ── 1. EXECUTIVE BANNER (RESTAURANT / ORDERS VERTICAL) ──────────────── -->
    <div class="card" style="padding:24px 28px;background:linear-gradient(135deg, rgba(37,99,235,0.10) 0%, rgba(16,185,129,0.04) 100%);border:1px solid rgba(37,99,235,0.2);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:18px;">
                <div style="width:54px;height:54px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#1d4ed8);display:flex;align-items:center;justify-content:center;color:white;font-size:24px;box-shadow:0 8px 18px rgba(37,99,235,0.32);flex-shrink:0;">
                    <i class="bi bi-bag-check-fill"></i>
                </div>
                <div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <h2 style="font-size:20px;font-weight:800;color:var(--text-strong);letter-spacing:-0.02em;margin:0;">
                            Welcome back, {{ Auth::user()->name }}!
                        </h2>
                        <span class="badge" style="background:rgba(37,99,235,0.14);color:#2563eb;font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;border:1px solid rgba(37,99,235,0.25);">
                            <i class="bi bi-shop me-1"></i> {{ $currentTenant?->name ?? 'Business' }}
                        </span>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.28);padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;color:#10b981;">
                            <span style="width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
                            AI Reception Active (Voice · WhatsApp · Social)
                        </span>
                    </div>
                    <p style="font-size:13px;color:var(--text-muted);margin:5px 0 0;">
                        Real-time automated order reception, KDS workflow, and omnichannel customer notifications for <strong>{{ $currentTenant?->name }}</strong>.
                    </p>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                @if($isSuperAdmin && $availableTenants->count() > 1)
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:12px;font-weight:600;color:var(--text-muted);">Tenant:</span>
                        <select onchange="window.location.href='?tenant_id=' + this.value"
                                style="background:var(--bg-input);color:var(--text-strong);border:1px solid var(--border);border-radius:8px;padding:7px 12px;font-size:13px;font-weight:600;outline:none;cursor:pointer;">
                            @foreach($availableTenants as $t)
                                <option value="{{ $t->id }}" {{ $tenantId === (int)$t->id ? 'selected' : '' }}>
                                    {{ $t->name }} ({{ $t->isRestaurant() ? 'Orders' : 'Call Center' }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <a href="{{ route('orders.index') }}" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
                    <i class="bi bi-receipt-cutoff"></i> All Orders
                </a>
                <a href="{{ route('orders.kds') }}" target="_blank" class="btn btn-primary" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;display:inline-flex;align-items:center;gap:8px;font-weight:700;padding:9px 16px;border-radius:10px;color:#fff;text-decoration:none;box-shadow:0 4px 14px rgba(37,99,235,0.35);">
                    <i class="bi bi-display"></i> Launch Live KDS
                </a>
            </div>
        </div>
    </div>

    <!-- ── 2. REAL-TIME ORDER & REVENUE KPIs ──────────────────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-bag-plus-fill"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Today's Orders</div>
                <div class="stat-value" style="font-size:26px;font-weight:800;color:var(--text-strong);">{{ $orderStats['today_orders'] ?? 0 }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">
                    <span style="color:#3b82f6;font-weight:700;">{{ $orderStats['total_orders'] ?? 0 }}</span> total all-time orders
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Today's Revenue</div>
                <div class="stat-value" style="font-size:26px;font-weight:800;color:#10b981;">${{ number_format($orderStats['today_revenue'] ?? 0, 2) }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">
                    All-time: <strong style="color:var(--text-strong);">${{ number_format($orderStats['total_revenue'] ?? 0, 2) }}</strong>
                    &bull; Avg: <strong>${{ number_format($orderStats['avg_ticket'] ?? 0, 2) }}</strong>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon yellow"><i class="bi bi-clock-history"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Active in Preparation</div>
                <div class="stat-value" style="font-size:26px;font-weight:800;color:#d97706;">{{ $orderStats['in_preparation'] ?? 0 }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">
                    <span style="color:#ef4444;font-weight:700;">{{ $orderStats['new_count'] ?? 0 }}</span> New &bull;
                    <span style="color:#d97706;font-weight:700;">{{ $orderStats['preparing_count'] ?? 0 }}</span> Preparing
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-box-seam-fill"></i></div>
            <div style="flex:1;">
                <div class="stat-label">Ready &amp; Completed</div>
                <div class="stat-value" style="font-size:26px;font-weight:800;color:var(--text-strong);">{{ ($orderStats['ready_count'] ?? 0) + ($orderStats['delivered_count'] ?? 0) }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">
                    <span style="color:#3b82f6;font-weight:700;">{{ $orderStats['ready_count'] ?? 0 }}</span> In Transit &bull;
                    <span style="color:#10b981;font-weight:700;">{{ $orderStats['delivered_count'] ?? 0 }}</span> Completed
                </div>
            </div>
        </div>
    </div>

    <!-- ── 3. MAIN 2-COLUMN COMMAND CENTER ────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

        <!-- LEFT COLUMN: Live Orders Feed + Recent Voice AI Calls -->
        <div style="display:flex;flex-direction:column;gap:24px;">

            <!-- Live & Recent Orders -->
            <div class="card">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="bi bi-receipt-cutoff" style="color:#2563eb;font-size:18px;"></i>
                        <span class="card-title">Latest Orders &amp; Active Queue</span>
                    </div>
                    <a href="{{ route('orders.index') }}" class="btn btn-outline btn-sm" style="font-size:11.5px;padding:4px 10px;">
                        Manage All Orders <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body" style="padding:0;">
                    @if($recentOrders->isEmpty())
                        <div style="padding:36px;text-align:center;color:var(--text-muted);">
                            <i class="bi bi-bag-x" style="font-size:32px;opacity:0.4;"></i>
                            <p style="margin-top:8px;font-size:13px;">No orders received yet. Incoming orders from Voice AI, WhatsApp, or Messenger will appear here in real time.</p>
                        </div>
                    @else
                        <div style="overflow-x:auto;">
                            <table class="table" style="width:100%;border-collapse:collapse;margin:0;">
                                <thead>
                                    <tr style="background:var(--bg-th);border-bottom:1px solid var(--border);text-align:left;font-size:11px;text-transform:uppercase;color:var(--text-muted);">
                                        <th style="padding:11px 16px;">Order #</th>
                                        <th style="padding:11px 16px;">Channel</th>
                                        <th style="padding:11px 16px;">Customer</th>
                                        <th style="padding:11px 16px;">Items &amp; Fulfillment</th>
                                        <th style="padding:11px 16px;">Total</th>
                                        <th style="padding:11px 16px;">Call Audio</th>
                                        <th style="padding:11px 16px;text-align:right;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentOrders as $ro)
                                        @php
                                            $recUid = $ro->call?->recording_id;
                                            $recUrl = $recUid ? route('dialer.recordingByUid', $recUid) : null;
                                            $stBadge = match($ro->status) {
                                                'new'       => ['label' => 'New Order', 'bg' => '#fef2f2', 'color' => '#dc2626'],
                                                'preparing' => ['label' => 'Preparing', 'bg' => '#fffbeb', 'color' => '#d97706'],
                                                'ready'     => ['label' => 'Ready / In Transit', 'bg' => '#eff6ff', 'color' => '#2563eb'],
                                                'delivered' => ['label' => 'Completed', 'bg' => '#ecfdf5', 'color' => '#059669'],
                                                default     => ['label' => 'Cancelled', 'bg' => '#f1f5f9', 'color' => '#64748b'],
                                            };
                                        @endphp
                                        <tr style="border-bottom:1px solid var(--border);font-size:13px;">
                                            <td style="padding:12px 16px;font-weight:800;font-family:monospace;">
                                                <a href="{{ route('orders.index', ['q' => $ro->order_number]) }}" style="color:var(--text-strong);text-decoration:none;">
                                                    #{{ $ro->order_number }}
                                                </a>
                                                <div style="font-size:11px;color:var(--text-muted);font-family:sans-serif;font-weight:400;">
                                                    {{ $ro->created_at?->diffForHumans() }}
                                                </div>
                                            </td>
                                            <td style="padding:12px 16px;">
                                                @if($ro->channel === 'voice')
                                                    <span class="badge" style="background:rgba(139,92,246,0.14);color:#7c3aed;font-size:11px;padding:3px 8px;border-radius:6px;font-weight:600;">
                                                        <i class="bi bi-telephone-fill"></i> Voice AI
                                                    </span>
                                                @elseif($ro->channel === 'whatsapp')
                                                    <span class="badge" style="background:rgba(16,185,129,0.14);color:#059669;font-size:11px;padding:3px 8px;border-radius:6px;font-weight:600;">
                                                        <i class="bi bi-whatsapp"></i> WhatsApp AI
                                                    </span>
                                                @elseif($ro->channel === 'facebook')
                                                    <span class="badge" style="background:rgba(37,99,235,0.14);color:#2563eb;font-size:11px;padding:3px 8px;border-radius:6px;font-weight:600;">
                                                        <i class="bi bi-messenger"></i> Facebook AI
                                                    </span>
                                                @else
                                                    <span class="badge" style="background:rgba(100,116,139,0.14);color:#475569;font-size:11px;padding:3px 8px;border-radius:6px;font-weight:600;">
                                                        {{ ucfirst($ro->channel) }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td style="padding:12px 16px;">
                                                <div style="font-weight:600;color:var(--text-strong);">{{ $ro->customer_name ?: 'Customer' }}</div>
                                                <div style="font-size:11px;color:var(--text-muted);">{{ $ro->customer_phone ?: '—' }}</div>
                                            </td>
                                            <td style="padding:12px 16px;max-width:250px;">
                                                <div style="font-weight:600;color:var(--text-strong);">{{ \Illuminate\Support\Str::limit($ro->items_summary, 70) }}</div>
                                                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                                    @if($ro->delivery_type === 'pickup')
                                                        <span style="color:#d97706;font-weight:600;"><i class="bi bi-shop"></i> Store Pickup</span>
                                                    @else
                                                        <span style="color:#2563eb;font-weight:600;"><i class="bi bi-truck"></i> Delivery:</span> {{ \Illuminate\Support\Str::limit($ro->delivery_address ?: 'Specified', 38) }}
                                                    @endif
                                                </div>
                                            </td>
                                            <td style="padding:12px 16px;">
                                                <div style="font-weight:800;color:#10b981;">${{ number_format((float) $ro->total_amount, 2) }}</div>
                                                <div style="font-size:11px;color:var(--text-muted);">{{ $ro->payment_method ?: 'Cash' }}</div>
                                            </td>
                                            <td style="padding:12px 16px;">
                                                @if($recUrl)
                                                    <audio controls preload="none" src="{{ $recUrl }}" style="height:28px;width:165px;"></audio>
                                                @else
                                                    <span style="font-size:11px;color:var(--text-muted);">—</span>
                                                @endif
                                            </td>
                                            <td style="padding:12px 16px;text-align:right;">
                                                <span style="background:{{ $stBadge['bg'] }};color:{{ $stBadge['color'] }};padding:4px 9px;border-radius:8px;font-weight:700;font-size:11.5px;display:inline-block;">
                                                    {{ $stBadge['label'] }}
                                                </span>
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

        <!-- RIGHT COLUMN: Channel Analytics, Fulfillment Split & Business Config -->
        <div style="display:flex;flex-direction:column;gap:24px;">

            <!-- Orders by AI Channel -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-pie-chart-fill me-2" style="color:#3b82f6;"></i>Orders by Channel</span>
                </div>
                <div class="card-body" style="padding:18px;display:flex;flex-direction:column;gap:14px;">
                    @php
                        $totCh = max(1, (int) ($orderStats['total_orders'] ?? 0));
                        $channelsUi = [
                            ['key' => 'voice',    'label' => 'Voice AI (Phone Calls)', 'icon' => 'bi-telephone-inbound-fill', 'color' => '#8b5cf6'],
                            ['key' => 'whatsapp', 'label' => 'WhatsApp AI Bot',        'icon' => 'bi-whatsapp',               'color' => '#10b981'],
                            ['key' => 'facebook', 'label' => 'Facebook Messenger AI',  'icon' => 'bi-messenger',              'color' => '#3b82f6'],
                            ['key' => 'manual',   'label' => 'Manual / Counter Entry', 'icon' => 'bi-pencil-square',          'color' => '#64748b'],
                        ];
                    @endphp
                    @foreach($channelsUi as $chItem)
                        @php
                            $cnt = (int) ($orderStats['by_channel'][$chItem['key']] ?? 0);
                            $pct = round(($cnt / $totCh) * 100);
                        @endphp
                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:12.5px;font-weight:600;margin-bottom:5px;">
                                <span><i class="bi {{ $chItem['icon'] }} me-1" style="color:{{ $chItem['color'] }};"></i> {{ $chItem['label'] }}</span>
                                <span>{{ $cnt }} ({{ $pct }}%)</span>
                            </div>
                            <div style="height:7px;background:var(--bg-th,#e2e8f0);border-radius:999px;overflow:hidden;">
                                <div style="height:100%;width:{{ $pct }}%;background:{{ $chItem['color'] }};border-radius:999px;"></div>
                            </div>
                        </div>
                    @endforeach

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;padding-top:12px;border-top:1px solid var(--border);">
                        <div style="background:var(--bg-th);padding:10px 12px;border-radius:10px;text-align:center;">
                            <div style="font-size:11px;color:var(--text-muted);font-weight:600;">HOME DELIVERY</div>
                            <div style="font-size:18px;font-weight:800;color:#2563eb;margin-top:2px;">{{ $orderStats['delivery_count'] ?? 0 }}</div>
                        </div>
                        <div style="background:var(--bg-th);padding:10px 12px;border-radius:10px;text-align:center;">
                            <div style="font-size:11px;color:var(--text-muted);font-weight:600;">STORE PICKUP</div>
                            <div style="font-size:18px;font-weight:800;color:#d97706;margin-top:2px;">{{ $orderStats['pickup_count'] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Business Profile & Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-sliders me-2" style="color:#10b981;"></i>Business Settings &amp; Quick Access</span>
                </div>
                <div class="card-body" style="padding:18px;display:flex;flex-direction:column;gap:12px;">
                    <div style="background:var(--bg-th);padding:12px 14px;border-radius:10px;border:1px solid var(--border);font-size:12.5px;display:flex;flex-direction:column;gap:8px;">
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--text-muted);">Admin / Kitchen WhatsApp:</span>
                            <strong style="color:var(--text-strong);">{{ $currentTenant?->kitchenWhatsappPhone() ?: 'Not configured' }}</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--text-muted);">Delivery Fee:</span>
                            <strong style="color:var(--text-strong);">{{ $currentTenant?->settings['delivery_fee'] ?? '$0.00' }}</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--text-muted);">Estimated Prep/Delivery:</span>
                            <strong style="color:var(--text-strong);">{{ $currentTenant?->settings['estimated_time'] ?? '30 - 45 min' }}</strong>
                        </div>
                    </div>

                    <a href="{{ route('orders.kds') }}" target="_blank" class="btn btn-primary" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:11px;font-weight:700;border-radius:10px;text-decoration:none;">
                        <i class="bi bi-display"></i>
                        <span>Open Fullscreen KDS Display</span>
                    </a>

                    <a href="{{ route('orders.index') }}" class="btn btn-outline" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;">
                        <i class="bi bi-receipt-cutoff text-primary"></i>
                        <span>Orders History &amp; Call Recordings</span>
                    </a>

                    <a href="{{ route('console.index') }}" class="btn btn-outline" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12.5px;">
                        <i class="bi bi-chat-dots-fill text-success"></i>
                        <span>Open Omnichannel Inbox (WhatsApp / Social)</span>
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>
@else
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
@endif
@endsection