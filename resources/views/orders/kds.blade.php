<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/xedik-logo.svg') }}?v=20261114">
    <title>KDS Cocina en Vivo — {{ $tenant?->name ?? 'Tizón 317' }} | XEDIK</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #090d16;
            color: #f8fafc;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .kds-header {
            background: #0f172a;
            border-bottom: 1px solid #1e293b;
            padding: 12px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .kds-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .kds-brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #ea580c, #dc2626);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 4px 14px rgba(234, 88, 12, 0.4);
        }
        .kds-title {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .kds-subtitle {
            font-size: 12px;
            color: #94a3b8;
        }
        .kds-metrics {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .kds-pill {
            background: #1e293b;
            border: 1px solid #334155;
            padding: 7px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .kds-board {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            padding: 16px;
            overflow: hidden;
        }
        .kds-col {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .kds-col-header {
            padding: 14px 18px;
            border-bottom: 1px solid #1e293b;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 800;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .kds-col-new .kds-col-header { background: rgba(239, 68, 68, 0.12); color: #f87171; border-bottom-color: rgba(239, 68, 68, 0.25); }
        .kds-col-prep .kds-col-header { background: rgba(245, 158, 11, 0.12); color: #fbbf24; border-bottom-color: rgba(245, 158, 11, 0.25); }
        .kds-col-ready .kds-col-header { background: rgba(16, 185, 129, 0.12); color: #34d399; border-bottom-color: rgba(16, 185, 129, 0.25); }

        .kds-col-count {
            background: rgba(255,255,255,0.12);
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 14px;
        }
        .kds-col-body {
            flex: 1;
            overflow-y: auto;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .kds-card {
            background: #1e293b;
            border: 2px solid #334155;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
            animation: popIn 0.25s ease-out;
        }
        @keyframes popIn {
            from { transform: scale(0.96); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .kds-card.flash-new {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.35);
        }
        .kds-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px dashed #334155;
            padding-bottom: 10px;
        }
        .kds-order-num {
            font-family: 'JetBrains Mono', monospace;
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
        }
        .kds-timer {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
        }
        .timer-ok { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .timer-warn { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .timer-late { background: rgba(239, 68, 68, 0.25); color: #f87171; }

        .kds-items {
            background: #0f172a;
            border-left: 4px solid #f97316;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.45;
            color: #f8fafc;
        }
        .kds-meta {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 13px;
            color: #cbd5e1;
        }
        .kds-badge-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .kds-btn {
            width: 100%;
            padding: 13px 16px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: transform 0.1s, opacity 0.15s;
        }
        .kds-btn:active { transform: scale(0.98); }
        .btn-to-prep { background: linear-gradient(135deg, #f59e0b, #d97706); color: #0f172a; }
        .btn-to-ready { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #ffffff; }
        .btn-to-done { background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; }
        .empty-col {
            text-align: center;
            color: #475569;
            padding: 50px 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>
<header class="kds-header">
    <div class="kds-brand">
        <div class="kds-brand-icon"><i class="bi bi-fire"></i></div>
        <div>
            <div class="kds-title">KDS COCINA — {{ strtoupper($tenant?->name ?? 'TIZÓN 317 PIZZERÍA') }}</div>
            <div class="kds-subtitle">Sincronización en vivo cada 4s · Voz IA · WhatsApp IA · Facebook IA</div>
        </div>
    </div>

    <div class="kds-metrics">
        <div class="kds-pill">
            <i class="bi bi-check2-all" style="color:#34d399;"></i>
            <span>Entregados Hoy: <strong id="kdsDeliveredCount">0</strong></span>
        </div>
        <div class="kds-pill">
            <i class="bi bi-cash-coin" style="color:#38bdf8;"></i>
            <span>Ventas Hoy: <strong id="kdsSalesToday">$0.00</strong></span>
        </div>
        <button type="button" id="soundToggleBtn" onclick="toggleSound()" class="kds-pill" style="cursor:pointer;background:#065f46;border-color:#059669;color:#fff;">
            <i class="bi bi-volume-up-fill"></i> <span id="soundLabel">Campana Activa</span>
        </button>
        <button type="button" onclick="toggleFullScreen()" class="kds-pill" style="cursor:pointer;color:#e2e8f0;">
            <i class="bi bi-arrows-fullscreen"></i> Pantalla Completa
        </button>
        <a href="{{ route('orders.index') }}" class="kds-pill" style="color:#94a3b8;text-decoration:none;">
            <i class="bi bi-arrow-left"></i> Volver al CRM
        </a>
    </div>
</header>

<main class="kds-board">
    <!-- Columna 1: Nuevos -->
    <section class="kds-col kds-col-new">
        <div class="kds-col-header">
            <span><i class="bi bi-bell-fill"></i> 1. Nuevas Comandas</span>
            <span class="kds-col-count" id="countNew">0</span>
        </div>
        <div class="kds-col-body" id="colNew"></div>
    </section>

    <!-- Columna 2: En Horno / Preparando -->
    <section class="kds-col kds-col-prep">
        <div class="kds-col-header">
            <span><i class="bi bi-fire"></i> 2. En Horno / Preparando</span>
            <span class="kds-col-count" id="countPrep">0</span>
        </div>
        <div class="kds-col-body" id="colPrep"></div>
    </section>

    <!-- Columna 3: Listos / En Reparto -->
    <section class="kds-col kds-col-ready">
        <div class="kds-col-header">
            <span><i class="bi bi-scooter"></i> 3. Listos / En Reparto</span>
            <span class="kds-col-count" id="countReady">0</span>
        </div>
        <div class="kds-col-body" id="colReady"></div>
    </section>
</main>

<script>
let knownOrderIds = new Set();
let firstLoad = true;
let soundEnabled = true;
let latestOrders = [];

function playKitchenBell() {
    if (!soundEnabled) return;
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const playTone = (freq, start, duration) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
            gain.gain.setValueAtTime(0.35, ctx.currentTime + start);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + duration);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(ctx.currentTime + start);
            osc.stop(ctx.currentTime + start + duration);
        };
        playTone(880, 0, 0.35);
        playTone(1174.66, 0.22, 0.55);
    } catch (e) {}
}

function toggleSound() {
    soundEnabled = !soundEnabled;
    const btn = document.getElementById('soundToggleBtn');
    const lbl = document.getElementById('soundLabel');
    if (soundEnabled) {
        btn.style.background = '#065f46';
        lbl.textContent = 'Campana Activa';
        playKitchenBell();
    } else {
        btn.style.background = '#334155';
        lbl.textContent = 'Silenciado';
    }
}

function toggleFullScreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
    } else {
        document.exitFullscreen().catch(() => {});
    }
}

function formatTimer(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

function channelBadge(ch) {
    if (ch === 'voice') return '<span style="background:rgba(139,92,246,0.2);color:#c4b5fd;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;"><i class="bi bi-telephone-fill"></i> VOZ IA</span>';
    if (ch === 'whatsapp') return '<span style="background:rgba(16,185,129,0.2);color:#6ee7b7;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;"><i class="bi bi-whatsapp"></i> WHATSAPP IA</span>';
    if (ch === 'facebook') return '<span style="background:rgba(59,130,246,0.2);color:#93c5fd;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;"><i class="bi bi-messenger"></i> FACEBOOK IA</span>';
    return `<span style="background:#334155;color:#cbd5e1;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;">${ch.toUpperCase()}</span>`;
}

function renderCard(o) {
    const elapsed = Math.max(0, Math.floor(Date.now() / 1000) - o.created_ts);
    const timerClass = elapsed < 720 ? 'timer-ok' : (elapsed < 1500 ? 'timer-warn' : 'timer-late');
    const deliveryHtml = o.delivery_type === 'pickup'
        ? '<span style="color:#fbbf24;font-weight:800;"><i class="bi bi-shop"></i> PASAR A RECOGER</span>'
        : `<span style="color:#38bdf8;font-weight:800;"><i class="bi bi-scooter"></i> DOMICILIO:</span> ${o.delivery_address || 'Confirmar dirección'}`;

    let actionBtn = '';
    if (o.status === 'new') {
        actionBtn = `<button class="kds-btn btn-to-prep" onclick="advanceOrder(${o.id}, 'preparing')"><i class="bi bi-fire"></i> METER AL HORNO</button>`;
    } else if (o.status === 'preparing') {
        actionBtn = `<button class="kds-btn btn-to-ready" onclick="advanceOrder(${o.id}, 'ready')"><i class="bi bi-scooter"></i> LISTO (AVISAR WHATSAPP)</button>`;
    } else if (o.status === 'ready') {
        actionBtn = `<button class="kds-btn btn-to-done" onclick="advanceOrder(${o.id}, 'delivered')"><i class="bi bi-check-circle-fill"></i> MARCAR ENTREGADO</button>`;
    }

    return `
        <div class="kds-card ${o.status === 'new' ? 'flash-new' : ''}">
            <div class="kds-card-top">
                <div>
                    <div class="kds-order-num">#${o.order_number}</div>
                    <div style="font-size:11.5px;color:#94a3b8;">Hora: ${o.created_time}</div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    ${channelBadge(o.channel)}
                    <span class="kds-timer ${timerClass}" data-ts="${o.created_ts}">${formatTimer(elapsed)}</span>
                </div>
            </div>

            <div class="kds-items">${o.items_summary}</div>

            <div class="kds-meta">
                <div><i class="bi bi-person-fill"></i> <strong>${o.customer_name}</strong> ${o.customer_phone ? '(' + o.customer_phone + ')' : ''}</div>
                <div>${deliveryHtml}</div>
                <div class="kds-badge-row" style="margin-top:4px;padding-top:8px;border-top:1px solid #334155;">
                    <span><i class="bi bi-credit-card"></i> Pago: <strong>${o.payment_method}</strong></span>
                    <span style="font-size:17px;font-weight:800;color:#34d399;">$${o.total_amount} MXN</span>
                </div>
            </div>

            ${actionBtn}
        </div>
    `;
}

function renderBoard(orders) {
    const news = orders.filter(o => o.status === 'new');
    const preps = orders.filter(o => o.status === 'preparing');
    const readys = orders.filter(o => o.status === 'ready');

    document.getElementById('countNew').textContent = news.length;
    document.getElementById('countPrep').textContent = preps.length;
    document.getElementById('countReady').textContent = readys.length;

    document.getElementById('colNew').innerHTML = news.length
        ? news.map(renderCard).join('')
        : '<div class="empty-col"><i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>Sin comandas nuevas</div>';

    document.getElementById('colPrep').innerHTML = preps.length
        ? preps.map(renderCard).join('')
        : '<div class="empty-col"><i class="bi bi-fire" style="font-size:32px;display:block;margin-bottom:8px;"></i>Horno libre</div>';

    document.getElementById('colReady').innerHTML = readys.length
        ? readys.map(renderCard).join('')
        : '<div class="empty-col"><i class="bi bi-scooter" style="font-size:32px;display:block;margin-bottom:8px;"></i>Sin pedidos pendientes de entrega</div>';
}

async function pollKds() {
    try {
        const res = await fetch('{{ route("orders.poll") }}', { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!data.ok) return;

        document.getElementById('kdsDeliveredCount').textContent = data.delivered_today;
        document.getElementById('kdsSalesToday').textContent = '$' + data.sales_today;

        let hasNewArrival = false;
        for (const o of data.orders) {
            if (!knownOrderIds.has(o.id)) {
                knownOrderIds.add(o.id);
                if (!firstLoad && o.status === 'new') {
                    hasNewArrival = true;
                }
            }
        }
        firstLoad = false;

        if (hasNewArrival) {
            playKitchenBell();
        }

        latestOrders = data.orders;
        renderBoard(latestOrders);
    } catch (e) {}
}

async function advanceOrder(orderId, nextStatus) {
    try {
        const res = await fetch(`/orders/${orderId}/status`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ status: nextStatus, notify_customer: true })
        });
        const data = await res.json();
        if (data.ok) {
            pollKds();
        }
    } catch (e) {}
}

setInterval(() => {
    document.querySelectorAll('.kds-timer').forEach(el => {
        const ts = parseInt(el.getAttribute('data-ts'), 10);
        if (!ts) return;
        const sec = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        el.textContent = formatTimer(sec);
    });
}, 1000);

pollKds();
setInterval(pollKds, 4000);
</script>
</body>
</html>
