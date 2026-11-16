@extends('layouts.app')

@section('title', 'Pedidos & Comandas')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
        <h1 class="page-title" style="display:flex;align-items:center;gap:10px;margin:0;font-size:22px;font-weight:700;">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:rgba(249,115,22,0.14);color:#f97316;">
                <i class="bi bi-fire"></i>
            </span>
            Pedidos & Comandas IA
        </h1>
        <p style="margin:4px 0 0;color:var(--text-muted,#64748b);font-size:13px;">
            Recepción centralizada de pedidos por Voz IA, WhatsApp IA y Facebook/Instagram Messenger
        </p>
    </div>

    <div style="display:flex;align-items:center;gap:10px;">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('newOrderModal').style.display='flex'" style="display:inline-flex;align-items:center;gap:6px;">
            <i class="bi bi-plus-lg"></i> Nueva Comanda Manual
        </button>
        <a href="{{ route('orders.kds') }}" target="_blank" class="btn btn-primary" style="background:linear-gradient(135deg,#ea580c,#dc2626);border:none;display:inline-flex;align-items:center;gap:8px;font-weight:700;padding:10px 18px;border-radius:10px;color:#fff;text-decoration:none;box-shadow:0 4px 14px rgba(234,88,12,0.35);">
            <i class="bi bi-display"></i> Abrir KDS Pantalla de Cocina
        </a>
    </div>
</div>

@if(session('success'))
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13.5px;">
        <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
    </div>
@endif

<!-- KPI Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:20px;">
    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(249,115,22,0.12);color:#ea580c;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-receipt-cutoff"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">Pedidos Hoy</div>
            <div style="font-size:24px;font-weight:800;">{{ $stats['today_count'] }}</div>
        </div>
    </div>

    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(16,185,129,0.12);color:#10b981;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">Ventas de Hoy</div>
            <div style="font-size:24px;font-weight:800;color:#10b981;">${{ number_format($stats['today_sales'], 2) }}</div>
        </div>
    </div>

    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(239,68,68,0.12);color:#ef4444;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-fire"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">En Cocina / Horno</div>
            <div style="font-size:24px;font-weight:800;color:#ef4444;">{{ $stats['active_kitchen'] }}</div>
        </div>
    </div>

    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(59,130,246,0.12);color:#3b82f6;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-scooter"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">Listos / En Reparto</div>
            <div style="font-size:24px;font-weight:800;color:#3b82f6;">{{ $stats['ready_count'] }}</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="padding:14px 18px;margin-bottom:18px;">
    <form method="GET" action="{{ route('orders.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Buscar por # comanda, cliente, teléfono o platillo..." class="form-control" style="max-width:300px;">
        <select name="status" class="form-control" style="max-width:180px;">
            <option value="">Todos los estados</option>
            <option value="new" @selected(request('status') === 'new')>🔥 Nuevo</option>
            <option value="preparing" @selected(request('status') === 'preparing')>🍕 En Horno</option>
            <option value="ready" @selected(request('status') === 'ready')>🛵 Listo / En Camino</option>
            <option value="delivered" @selected(request('status') === 'delivered')>✅ Entregado</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>❌ Cancelado</option>
        </select>
        <select name="channel" class="form-control" style="max-width:180px;">
            <option value="">Todos los canales</option>
            <option value="voice" @selected(request('channel') === 'voice')>📞 Voz IA</option>
            <option value="whatsapp" @selected(request('channel') === 'whatsapp')>💬 WhatsApp IA</option>
            <option value="facebook" @selected(request('channel') === 'facebook')>💙 Facebook IA</option>
            <option value="instagram" @selected(request('channel') === 'instagram')>📸 Instagram IA</option>
            <option value="manual" @selected(request('channel') === 'manual')>✍️ Manual</option>
        </select>
        <button type="submit" class="btn btn-primary">Filtrar</button>
        @if(request()->hasAny(['q', 'status', 'channel']))
            <a href="{{ route('orders.index') }}" class="btn btn-secondary">Limpiar</a>
        @endif
    </form>
</div>

<!-- Orders Table -->
<div class="card" style="overflow-x:auto;">
    <table class="table" style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="text-align:left;border-bottom:1px solid var(--border,#e2e8f0);font-size:12px;text-transform:uppercase;color:var(--text-muted,#64748b);">
                <th style="padding:12px 16px;">Comanda</th>
                <th style="padding:12px 16px;">Canal</th>
                <th style="padding:12px 16px;">Cliente</th>
                <th style="padding:12px 16px;">Platillos / Pedido</th>
                <th style="padding:12px 16px;">Entrega & Dirección</th>
                <th style="padding:12px 16px;">Total & Pago</th>
                <th style="padding:12px 16px;">Estatus</th>
                <th style="padding:12px 16px;">Acción Rápida</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
                <tr style="border-bottom:1px solid var(--border,#e2e8f0);font-size:13.5px;" id="order-row-{{ $order->id }}">
                    <td style="padding:12px 16px;font-weight:800;font-family:monospace;font-size:14px;">
                        #{{ $order->order_number }}
                        <div style="font-size:11px;color:var(--text-muted,#64748b);font-family:sans-serif;font-weight:400;">
                            {{ $order->created_at?->format('d/m H:i') }}
                        </div>
                    </td>
                    <td style="padding:12px 16px;">
                        @if($order->channel === 'voice')
                            <span class="badge" style="background:rgba(139,92,246,0.14);color:#7c3aed;padding:4px 9px;border-radius:6px;font-weight:600;font-size:12px;">
                                <i class="bi bi-telephone-inbound-fill"></i> Voz IA
                            </span>
                        @elseif($order->channel === 'whatsapp')
                            <span class="badge" style="background:rgba(16,185,129,0.14);color:#059669;padding:4px 9px;border-radius:6px;font-weight:600;font-size:12px;">
                                <i class="bi bi-whatsapp"></i> WhatsApp IA
                            </span>
                        @elseif($order->channel === 'facebook')
                            <span class="badge" style="background:rgba(37,99,235,0.14);color:#2563eb;padding:4px 9px;border-radius:6px;font-weight:600;font-size:12px;">
                                <i class="bi bi-messenger"></i> Facebook IA
                            </span>
                        @else
                            <span class="badge" style="background:rgba(100,116,139,0.14);color:#475569;padding:4px 9px;border-radius:6px;font-weight:600;font-size:12px;">
                                {{ ucfirst($order->channel) }}
                            </span>
                        @endif
                    </td>
                    <td style="padding:12px 16px;">
                        <div style="font-weight:600;">{{ $order->customer_name ?: 'Cliente' }}</div>
                        @if($order->customer_phone)
                            <div style="font-size:12px;color:var(--text-muted,#64748b);">{{ $order->customer_phone }}</div>
                        @endif
                    </td>
                    <td style="padding:12px 16px;max-width:300px;">
                        <div style="font-weight:600;line-height:1.4;">{{ $order->items_summary }}</div>
                    </td>
                    <td style="padding:12px 16px;max-width:240px;">
                        @if($order->delivery_type === 'pickup')
                            <span style="color:#d97706;font-weight:700;font-size:12px;"><i class="bi bi-shop"></i> Pasar a Recoger</span>
                        @else
                            <span style="color:#2563eb;font-weight:700;font-size:12px;"><i class="bi bi-scooter"></i> Envío a Domicilio</span>
                            <div style="font-size:12px;color:var(--text-muted,#64748b);margin-top:2px;">{{ $order->delivery_address ?: 'Sin dirección especificada' }}</div>
                        @endif
                    </td>
                    <td style="padding:12px 16px;">
                        <div style="font-weight:800;font-size:15px;color:#10b981;">
                            ${{ number_format((float) $order->total_amount, 2) }}
                        </div>
                        <div style="font-size:11.5px;color:var(--text-muted,#64748b);">{{ $order->payment_method ?: 'Efectivo' }}</div>
                    </td>
                    <td style="padding:12px 16px;">
                        @php
                            $statusMap = [
                                'new'       => ['label' => '🔥 Nuevo', 'bg' => '#fef2f2', 'color' => '#dc2626'],
                                'preparing' => ['label' => '🍕 En Horno', 'bg' => '#fffbeb', 'color' => '#d97706'],
                                'ready'     => ['label' => '🛵 Listo / En Camino', 'bg' => '#eff6ff', 'color' => '#2563eb'],
                                'delivered' => ['label' => '✅ Entregado', 'bg' => '#ecfdf5', 'color' => '#059669'],
                                'cancelled' => ['label' => '❌ Cancelado', 'bg' => '#f1f5f9', 'color' => '#64748b'],
                            ];
                            $st = $statusMap[$order->status] ?? $statusMap['new'];
                        @endphp
                        <span style="background:{{ $st['bg'] }};color:{{ $st['color'] }};padding:5px 10px;border-radius:8px;font-weight:700;font-size:12px;display:inline-block;">
                            {{ $st['label'] }}
                        </span>
                    </td>
                    <td style="padding:12px 16px;">
                        <select onchange="changeOrderStatus({{ $order->id }}, this.value)" class="form-control" style="font-size:12.5px;padding:5px 8px;min-width:140px;">
                            <option value="new" @selected($order->status === 'new')>🔥 Nuevo</option>
                            <option value="preparing" @selected($order->status === 'preparing')>🍕 Meter al Horno</option>
                            <option value="ready" @selected($order->status === 'ready')>🛵 Listo (Avisar WA)</option>
                            <option value="delivered" @selected($order->status === 'delivered')>✅ Entregado</option>
                            <option value="cancelled" @selected($order->status === 'cancelled')>❌ Cancelar</option>
                        </select>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="padding:40px;text-align:center;color:var(--text-muted,#64748b);">
                        No hay comandas registradas aún. Cuando entre un pedido por Voz IA, WhatsApp o Facebook aparecerá aquí y en el KDS de Cocina.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">
    {{ $orders->links() }}
</div>

<!-- Modal Nueva Comanda Manual -->
<div id="newOrderModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div class="card" style="width:100%;max-width:520px;padding:24px;border-radius:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h3 style="margin:0;font-size:18px;font-weight:700;"><i class="bi bi-fire" style="color:#ea580c;"></i> Registrar Comanda Manual</h3>
            <button type="button" onclick="document.getElementById('newOrderModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="{{ route('orders.store') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Nombre del Cliente *</label>
                    <input type="text" name="customer_name" class="form-control" required placeholder="Ej. Adrian Quezada">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Teléfono WhatsApp</label>
                    <input type="text" name="customer_phone" class="form-control" placeholder="Ej. 6361229546">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Platillos / Detalle del Pedido *</label>
                <textarea name="items_summary" class="form-control" rows="2" required placeholder="Ej. 1x Combo Tizón ($399), 1x Pan de Ajo ($99)"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Modalidad *</label>
                    <select name="delivery_type" class="form-control" required>
                        <option value="delivery">🛵 Domicilio</option>
                        <option value="pickup">🏪 Pasar a Recoger</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Método de Pago *</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="Efectivo">Efectivo</option>
                        <option value="Tarjeta">Tarjeta</option>
                        <option value="Transferencia">Transferencia</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Total ($ MXN) *</label>
                    <input type="number" step="0.01" name="total_amount" class="form-control" required placeholder="239.00">
                </div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Dirección de Entrega (si es a domicilio)</label>
                <input type="text" name="delivery_address" class="form-control" placeholder="Calle, número, colonia y referencia">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="notify_customer" value="1" checked>
                    Enviar confirmación por WhatsApp al cliente (usa Plantilla si la ventana 24h está cerrada)
                </label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('newOrderModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar y Enviar a Cocina</button>
            </div>
        </form>
    </div>
</div>

<script>
async function changeOrderStatus(orderId, newStatus) {
    try {
        const res = await fetch(`/orders/${orderId}/status`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ status: newStatus, notify_customer: true })
        });
        const data = await res.json();
        if (data.ok) {
            window.location.reload();
        }
    } catch (e) {
        alert('Error actualizando el estado del pedido.');
    }
}
</script>
@endsection
