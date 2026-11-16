@extends('layouts.app')

@section('title', 'Orders & Comandas')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
        <h1 class="page-title" style="display:flex;align-items:center;gap:10px;margin:0;font-size:22px;font-weight:700;">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:rgba(37,99,235,0.14);color:#2563eb;">
                <i class="bi bi-receipt-cutoff"></i>
            </span>
            Orders & Comandas
        </h1>
        <p style="margin:4px 0 0;color:var(--text-muted,#64748b);font-size:13px;">
            Centralized AI order reception across Voice AI, WhatsApp AI, and Social Messenger
        </p>
    </div>

    <div style="display:flex;align-items:center;gap:10px;">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('newOrderModal').style.display='flex'" style="display:inline-flex;align-items:center;gap:6px;">
            <i class="bi bi-plus-lg"></i> New Manual Order
        </button>
        <a href="{{ route('orders.kds') }}" target="_blank" class="btn btn-primary" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;display:inline-flex;align-items:center;gap:8px;font-weight:700;padding:10px 18px;border-radius:10px;color:#fff;text-decoration:none;box-shadow:0 4px 14px rgba(37,99,235,0.35);">
            <i class="bi bi-display"></i> Open Live KDS Board
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
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(37,99,235,0.12);color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-receipt-cutoff"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">Today's Orders</div>
            <div style="font-size:24px;font-weight:800;">{{ $stats['today_count'] }}</div>
        </div>
    </div>

    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(16,185,129,0.12);color:#10b981;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">Today's Revenue</div>
            <div style="font-size:24px;font-weight:800;color:#10b981;">${{ number_format($stats['today_sales'], 2) }}</div>
        </div>
    </div>

    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(245,158,11,0.12);color:#d97706;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-clock-history"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">In Preparation</div>
            <div style="font-size:24px;font-weight:800;color:#d97706;">{{ $stats['active_kitchen'] }}</div>
        </div>
    </div>

    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;">
        <div style="width:46px;height:46px;border-radius:12px;background:rgba(59,130,246,0.12);color:#3b82f6;display:flex;align-items:center;justify-content:center;font-size:22px;">
            <i class="bi bi-box-seam"></i>
        </div>
        <div>
            <div style="font-size:12px;color:var(--text-muted,#64748b);font-weight:600;text-transform:uppercase;">Ready / In Transit</div>
            <div style="font-size:24px;font-weight:800;color:#3b82f6;">{{ $stats['ready_count'] }}</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="padding:14px 18px;margin-bottom:18px;">
    <form method="GET" action="{{ route('orders.index') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Search by order #, customer, phone, or items..." class="form-control" style="max-width:300px;">
        <select name="status" class="form-control" style="max-width:190px;">
            <option value="">All statuses</option>
            <option value="new" @selected(request('status') === 'new')>New Order</option>
            <option value="preparing" @selected(request('status') === 'preparing')>Preparing</option>
            <option value="ready" @selected(request('status') === 'ready')>Ready / Out for Delivery</option>
            <option value="delivered" @selected(request('status') === 'delivered')>Completed</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
        </select>
        <select name="channel" class="form-control" style="max-width:180px;">
            <option value="">All channels</option>
            <option value="voice" @selected(request('channel') === 'voice')>Voice AI</option>
            <option value="whatsapp" @selected(request('channel') === 'whatsapp')>WhatsApp AI</option>
            <option value="facebook" @selected(request('channel') === 'facebook')>Facebook AI</option>
            <option value="instagram" @selected(request('channel') === 'instagram')>Instagram AI</option>
            <option value="manual" @selected(request('channel') === 'manual')>Manual</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        @if(request()->hasAny(['q', 'status', 'channel']))
            <a href="{{ route('orders.index') }}" class="btn btn-secondary">Clear</a>
        @endif
    </form>
</div>

<!-- Orders Table -->
<div class="card" style="overflow-x:auto;">
    <table class="table" style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="text-align:left;border-bottom:1px solid var(--border,#e2e8f0);font-size:12px;text-transform:uppercase;color:var(--text-muted,#64748b);">
                <th style="padding:12px 16px;">Order #</th>
                <th style="padding:12px 16px;">Channel</th>
                <th style="padding:12px 16px;">Customer</th>
                <th style="padding:12px 16px;">Order Items</th>
                <th style="padding:12px 16px;">Fulfillment & Address</th>
                <th style="padding:12px 16px;">Total & Payment</th>
                <th style="padding:12px 16px;">Original Audio</th>
                <th style="padding:12px 16px;">Status</th>
                <th style="padding:12px 16px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
                @php
                    $recordingUid = $order->call?->recording_id;
                    $audioUrl = $recordingUid ? route('dialer.recordingByUid', $recordingUid) : null;
                    $statusMap = [
                        'new'       => ['label' => 'New Order', 'bg' => '#fef2f2', 'color' => '#dc2626'],
                        'preparing' => ['label' => 'Preparing', 'bg' => '#fffbeb', 'color' => '#d97706'],
                        'ready'     => ['label' => 'Ready / In Transit', 'bg' => '#eff6ff', 'color' => '#2563eb'],
                        'delivered' => ['label' => 'Completed', 'bg' => '#ecfdf5', 'color' => '#059669'],
                        'cancelled' => ['label' => 'Cancelled', 'bg' => '#f1f5f9', 'color' => '#64748b'],
                    ];
                    $st = $statusMap[$order->status] ?? $statusMap['new'];
                    $orderJson = [
                        'id'               => $order->id,
                        'order_number'     => $order->order_number,
                        'channel'          => strtoupper($order->channel) . ' AI',
                        'tenant_name'      => $order->tenant?->name ?? '—',
                        'customer_name'    => $order->customer_name ?: 'Customer',
                        'customer_phone'   => $order->customer_phone ?: '—',
                        'items_summary'    => $order->items_summary,
                        'delivery_type'    => $order->delivery_type === 'pickup' ? 'Store Pickup' : 'Home Delivery',
                        'delivery_address' => $order->delivery_address ?: ($order->delivery_type === 'pickup' ? 'Store Pickup' : 'Not specified'),
                        'payment_method'   => $order->payment_method ?: 'Cash',
                        'total_amount'     => '$' . number_format((float) $order->total_amount, 2),
                        'status_label'     => $st['label'],
                        'created_at'       => $order->created_at?->format('M d, Y h:i A'),
                        'audio_url'        => $audioUrl,
                        'duration'         => $order->call?->duration ? gmdate('i:s', (int) $order->call->duration) : null,
                        'notes'            => $order->notes ?: ($order->call?->notes ?? 'No transcript available.'),
                    ];
                @endphp
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
                                <i class="bi bi-telephone-inbound-fill"></i> Voice AI
                            </span>
                        @elseif($order->channel === 'whatsapp')
                            <span class="badge" style="background:rgba(16,185,129,0.14);color:#059669;padding:4px 9px;border-radius:6px;font-weight:600;font-size:12px;">
                                <i class="bi bi-whatsapp"></i> WhatsApp AI
                            </span>
                        @elseif($order->channel === 'facebook')
                            <span class="badge" style="background:rgba(37,99,235,0.14);color:#2563eb;padding:4px 9px;border-radius:6px;font-weight:600;font-size:12px;">
                                <i class="bi bi-messenger"></i> Facebook AI
                            </span>
                        @else
                            <span class="badge" style="background:rgba(100,116,139,0.14);color:#475569;padding:4px 9px;border-radius:6px;font-weight:600;font-size:12px;">
                                {{ ucfirst($order->channel) }}
                            </span>
                        @endif
                    </td>
                    <td style="padding:12px 16px;">
                        <div style="font-weight:600;">{{ $order->customer_name ?: 'Customer' }}</div>
                        @if($order->customer_phone)
                            <div style="font-size:12px;color:var(--text-muted,#64748b);">{{ $order->customer_phone }}</div>
                        @endif
                    </td>
                    <td style="padding:12px 16px;max-width:260px;">
                        <div style="font-weight:600;line-height:1.4;">{{ $order->items_summary }}</div>
                    </td>
                    <td style="padding:12px 16px;max-width:220px;">
                        @if($order->delivery_type === 'pickup')
                            <span style="color:#d97706;font-weight:700;font-size:12px;"><i class="bi bi-shop"></i> Store Pickup</span>
                        @else
                            <span style="color:#2563eb;font-weight:700;font-size:12px;"><i class="bi bi-truck"></i> Delivery</span>
                            <div style="font-size:12px;color:var(--text-muted,#64748b);margin-top:2px;">{{ $order->delivery_address ?: 'Address not specified' }}</div>
                        @endif
                    </td>
                    <td style="padding:12px 16px;">
                        <div style="font-weight:800;font-size:15px;color:#10b981;">
                            ${{ number_format((float) $order->total_amount, 2) }}
                        </div>
                        <div style="font-size:11.5px;color:var(--text-muted,#64748b);">{{ $order->payment_method ?: 'Cash' }}</div>
                    </td>
                    <td style="padding:12px 16px;min-width:210px;">
                        @if($audioUrl)
                            <audio controls preload="none" src="{{ $audioUrl }}" style="height:32px;width:200px;"></audio>
                        @else
                            <span style="font-size:12px;color:var(--text-muted,#94a3b8);">— Text Channel —</span>
                        @endif
                    </td>
                    <td style="padding:12px 16px;">
                        <span style="background:{{ $st['bg'] }};color:{{ $st['color'] }};padding:5px 10px;border-radius:8px;font-weight:700;font-size:12px;display:inline-block;">
                            {{ $st['label'] }}
                        </span>
                    </td>
                    <td style="padding:12px 16px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <button type="button" class="btn btn-outline btn-xs"
                                    onclick='openOrderDetailModal(@json($orderJson))'
                                    title="View Full Order Details & Transcript">
                                <i class="bi bi-eye-fill"></i> Details
                            </button>
                            <select onchange="changeOrderStatus({{ $order->id }}, this.value)" class="form-control" style="font-size:12px;padding:4px 8px;min-width:135px;">
                                <option value="new" @selected($order->status === 'new')>New Order</option>
                                <option value="preparing" @selected($order->status === 'preparing')>Start Preparing</option>
                                <option value="ready" @selected($order->status === 'ready')>Mark Ready (WA)</option>
                                <option value="delivered" @selected($order->status === 'delivered')>Completed</option>
                                <option value="cancelled" @selected($order->status === 'cancelled')>Cancel</option>
                            </select>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="padding:40px;text-align:center;color:var(--text-muted,#64748b);">
                        No orders recorded yet. Incoming orders from Voice AI, WhatsApp, or Messenger will appear here automatically.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">
    {{ $orders->links() }}
</div>

<!-- Order Details & Audio/Transcript Modal -->
<div id="orderDetailModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.68);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div class="card" style="width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;border-radius:16px;overflow:hidden;">
        <div style="padding:18px 24px;border-bottom:1px solid var(--border,#e2e8f0);display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3 style="margin:0;font-size:18px;font-weight:800;" id="detOrderTitle">Order #—</h3>
                <div style="font-size:12px;color:var(--text-muted,#64748b);" id="detOrderMeta"></div>
            </div>
            <button type="button" onclick="closeOrderDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;">&times;</button>
        </div>

        <div style="padding:20px 24px;overflow-y:auto;display:flex;flex-direction:column;gap:16px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                <div style="background:var(--bg-subtle,#f8fafc);padding:12px;border-radius:10px;border:1px solid var(--border,#e2e8f0);">
                    <div style="font-size:11px;color:var(--text-muted,#64748b);text-transform:uppercase;font-weight:700;">Customer</div>
                    <div style="font-weight:700;font-size:14px;margin-top:2px;" id="detCustomerName"></div>
                    <div style="font-size:12.5px;color:var(--text-muted,#64748b);" id="detCustomerPhone"></div>
                </div>
                <div style="background:var(--bg-subtle,#f8fafc);padding:12px;border-radius:10px;border:1px solid var(--border,#e2e8f0);">
                    <div style="font-size:11px;color:var(--text-muted,#64748b);text-transform:uppercase;font-weight:700;">Fulfillment</div>
                    <div style="font-weight:700;font-size:14px;margin-top:2px;" id="detDeliveryType"></div>
                    <div style="font-size:12.5px;color:var(--text-muted,#64748b);" id="detDeliveryAddress"></div>
                </div>
                <div style="background:var(--bg-subtle,#f8fafc);padding:12px;border-radius:10px;border:1px solid var(--border,#e2e8f0);">
                    <div style="font-size:11px;color:var(--text-muted,#64748b);text-transform:uppercase;font-weight:700;">Total & Payment</div>
                    <div style="font-weight:800;font-size:18px;color:#10b981;margin-top:2px;" id="detTotalAmount"></div>
                    <div style="font-size:12.5px;color:var(--text-muted,#64748b);" id="detPaymentMethod"></div>
                </div>
            </div>

            <div>
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted,#64748b);margin-bottom:6px;">Order Items</div>
                <div id="detItemsSummary" style="background:rgba(37,99,235,0.06);border-left:4px solid #2563eb;padding:12px 14px;border-radius:8px;font-weight:700;font-size:14.5px;"></div>
            </div>

            <div id="detAudioContainer" style="display:none;background:var(--bg-subtle,#f8fafc);padding:14px;border-radius:10px;border:1px solid var(--border,#e2e8f0);">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:12px;font-weight:700;text-transform:uppercase;color:#7c3aed;">
                        <i class="bi bi-mic-fill"></i> Original Voice Call Recording
                    </span>
                    <span id="detAudioDuration" style="font-size:12px;color:var(--text-muted,#64748b);"></span>
                </div>
                <audio id="detAudioPlayer" controls style="width:100%;height:38px;"></audio>
            </div>

            <div>
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted,#64748b);margin-bottom:6px;">
                    Full Conversation Transcript / Order Log
                </div>
                <pre id="detTranscript" style="background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;font-size:12.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word;max-height:260px;overflow-y:auto;font-family:inherit;margin:0;"></pre>
            </div>
        </div>

        <div style="padding:14px 24px;border-top:1px solid var(--border,#e2e8f0);display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeOrderDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal New Manual Order -->
<div id="newOrderModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div class="card" style="width:100%;max-width:520px;padding:24px;border-radius:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h3 style="margin:0;font-size:18px;font-weight:700;"><i class="bi bi-receipt-cutoff" style="color:#2563eb;"></i> New Manual Order</h3>
            <button type="button" onclick="document.getElementById('newOrderModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="{{ route('orders.store') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Customer Name *</label>
                    <input type="text" name="customer_name" class="form-control" required placeholder="e.g. Adrian Quezada">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">WhatsApp Phone</label>
                    <input type="text" name="customer_phone" class="form-control" placeholder="e.g. 6361229546">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Order Items / Details *</label>
                <textarea name="items_summary" class="form-control" rows="2" required placeholder="e.g. 2x Combo Especial, 1x Extra Side"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Fulfillment *</label>
                    <select name="delivery_type" class="form-control" required>
                        <option value="delivery">Delivery</option>
                        <option value="pickup">Store Pickup</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Payment Method *</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="Efectivo">Cash (Efectivo)</option>
                        <option value="Tarjeta">Card (Tarjeta)</option>
                        <option value="Transferencia">Transfer</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Total ($ MXN) *</label>
                    <input type="number" step="0.01" name="total_amount" class="form-control" required placeholder="239.00">
                </div>
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Delivery Address (if applicable)</label>
                <input type="text" name="delivery_address" class="form-control" placeholder="Street, number, neighborhood, reference">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="notify_customer" value="1" checked>
                    Send WhatsApp confirmation to customer (uses Official Template if 24h window is closed)
                </label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('newOrderModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save & Send to KDS</button>
            </div>
        </form>
    </div>
</div>

<script>
function openOrderDetailModal(order) {
    document.getElementById('detOrderTitle').textContent = `Order #${order.order_number} (${order.status_label})`;
    document.getElementById('detOrderMeta').textContent = `${order.created_at} · Channel: ${order.channel} · Tenant: ${order.tenant_name}`;
    document.getElementById('detCustomerName').textContent = order.customer_name;
    document.getElementById('detCustomerPhone').textContent = order.customer_phone;
    document.getElementById('detDeliveryType').textContent = order.delivery_type;
    document.getElementById('detDeliveryAddress').textContent = order.delivery_address;
    document.getElementById('detTotalAmount').textContent = order.total_amount;
    document.getElementById('detPaymentMethod').textContent = `Payment: ${order.payment_method}`;
    document.getElementById('detItemsSummary').textContent = order.items_summary;
    document.getElementById('detTranscript').textContent = order.notes || '';

    const audioBox = document.getElementById('detAudioContainer');
    const audioPlayer = document.getElementById('detAudioPlayer');
    const audioDur = document.getElementById('detAudioDuration');

    if (order.audio_url) {
        audioBox.style.display = 'block';
        audioPlayer.src = order.audio_url;
        audioDur.textContent = order.duration ? `Duration: ${order.duration}` : '';
    } else {
        audioBox.style.display = 'none';
        audioPlayer.pause();
        audioPlayer.removeAttribute('src');
    }

    document.getElementById('orderDetailModal').style.display = 'flex';
}

function closeOrderDetailModal() {
    const audioPlayer = document.getElementById('detAudioPlayer');
    if (audioPlayer) audioPlayer.pause();
    document.getElementById('orderDetailModal').style.display = 'none';
}

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
        alert('Error updating order status.');
    }
}
</script>
@endsection
