<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RestaurantOrder;
use App\Models\Tenant;
use App\Models\WhatsappAccount;
use App\Services\Restaurant\RestaurantOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantOrderController extends Controller
{
    private function resolveTargetTenantId(): ?int
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }
        if ($user->tenant_id) {
            return (int) $user->tenant_id;
        }
        if (session()->has('active_tenant_id')) {
            return (int) session('active_tenant_id');
        }
        return null;
    }

    public function index(Request $request)
    {
        $tenantId = $this->resolveTargetTenantId();

        $query = RestaurantOrder::withoutGlobalScopes()->orderByDesc('id');
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('order_number', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_phone', 'like', "%{$q}%")
                    ->orWhere('items_summary', 'like', "%{$q}%");
            });
        }

        $orders = $query->paginate(25)->withQueryString();

        $statsQuery = RestaurantOrder::withoutGlobalScopes()->whereDate('created_at', today());
        if ($tenantId) {
            $statsQuery->where('tenant_id', $tenantId);
        }

        $stats = [
            'today_count'   => (clone $statsQuery)->count(),
            'today_sales'   => (float) (clone $statsQuery)->where('status', '!=', 'cancelled')->sum('total_amount'),
            'active_kitchen'=> (int) RestaurantOrder::withoutGlobalScopes()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereIn('status', ['new', 'preparing'])
                ->count(),
            'ready_count'   => (int) RestaurantOrder::withoutGlobalScopes()
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('status', 'ready')
                ->count(),
        ];

        return view('orders.index', compact('orders', 'stats'));
    }

    public function kds(Request $request)
    {
        $tenantId = $this->resolveTargetTenantId();
        $tenant = $tenantId
            ? Tenant::find($tenantId)
            : Tenant::where('slug', 'tizon317')->first();

        return view('orders.kds', compact('tenant'));
    }

    public function poll(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTargetTenantId();

        $activeOrders = RestaurantOrder::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('status', ['new', 'preparing', 'ready'])
            ->orderBy('id', 'asc')
            ->get()
            ->map(function (RestaurantOrder $o) {
                $elapsedSec = max(0, (int) $o->created_at?->diffInSeconds(now()));
                return [
                    'id'               => $o->id,
                    'order_number'     => $o->order_number,
                    'channel'          => $o->channel,
                    'customer_name'    => $o->customer_name ?: 'Cliente',
                    'customer_phone'   => $o->customer_phone,
                    'items_summary'    => $o->items_summary,
                    'delivery_type'    => $o->delivery_type,
                    'delivery_address' => $o->delivery_address,
                    'payment_method'   => $o->payment_method ?: 'Efectivo',
                    'total_amount'     => $o->total_amount ? number_format((float) $o->total_amount, 2) : '0.00',
                    'status'           => $o->status,
                    'created_time'     => $o->created_at?->format('h:i A'),
                    'created_ts'       => $o->created_at?->timestamp ?? time(),
                    'elapsed_seconds'  => $elapsedSec,
                ];
            });

        $deliveredToday = RestaurantOrder::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereDate('created_at', today())
            ->where('status', 'delivered')
            ->count();

        $salesToday = (float) RestaurantOrder::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereDate('created_at', today())
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        return response()->json([
            'ok'              => true,
            'orders'          => $activeOrders,
            'delivered_today' => $deliveredToday,
            'sales_today'     => number_format($salesToday, 2),
            'server_time'     => now()->format('h:i:s A'),
        ]);
    }

    public function store(Request $request, RestaurantOrderService $orderService)
    {
        $validated = $request->validate([
            'customer_name'    => 'required|string|max:191',
            'customer_phone'   => 'nullable|string|max:64',
            'items_summary'    => 'required|string|max:2000',
            'delivery_type'    => 'required|in:delivery,pickup',
            'delivery_address' => 'nullable|string|max:500',
            'payment_method'   => 'required|string|max:64',
            'total_amount'     => 'required|numeric|min:0',
            'notify_customer'  => 'nullable|boolean',
        ]);

        $tenantId = $this->resolveTargetTenantId() ?? (Tenant::where('slug', 'tizon317')->value('id') ?? 6);

        $orderService->createOrderAndNotify([
            'tenant_id'        => $tenantId,
            'channel'          => 'manual',
            'customer_name'    => $validated['customer_name'],
            'customer_phone'   => $validated['customer_phone'] ?? null,
            'items_summary'    => $validated['items_summary'],
            'delivery_type'    => $validated['delivery_type'],
            'delivery_address' => $validated['delivery_address'] ?? null,
            'payment_method'   => $validated['payment_method'],
            'total_amount'     => (float) $validated['total_amount'],
            'raw_text'         => $validated['items_summary'],
            'notify_customer'  => $request->boolean('notify_customer', true),
            'notify_kitchen'   => true,
        ]);

        return redirect()->back()->with('success', 'Comanda creada y enviada al KDS de cocina.');
    }

    public function updateStatus(Request $request, int $orderId, RestaurantOrderService $orderService): JsonResponse
    {
        $validated = $request->validate([
            'status'          => 'required|in:new,preparing,ready,delivered,cancelled',
            'notify_customer' => 'nullable|boolean',
        ]);

        $order = RestaurantOrder::withoutGlobalScopes()->findOrFail($orderId);
        $newStatus = $validated['status'];

        $updates = ['status' => $newStatus];
        if ($newStatus === 'preparing' && !$order->preparing_at) {
            $updates['preparing_at'] = now();
        } elseif ($newStatus === 'ready' && !$order->ready_at) {
            $updates['ready_at'] = now();
        } elseif ($newStatus === 'delivered' && !$order->delivered_at) {
            $updates['delivered_at'] = now();
        }

        $order->update($updates);

        // Si pasa a "ready" (Listo / En reparto), avisar al cliente por WhatsApp
        if ($newStatus === 'ready' && $request->boolean('notify_customer', true) && !empty($order->customer_phone)) {
            $waAccount = WhatsappAccount::withoutGlobalScopes()
                ->where('tenant_id', $order->tenant_id)
                ->where('is_active', true)
                ->first()
                ?? WhatsappAccount::withoutGlobalScopes()->where('is_active', true)->first();

            $tenant = Tenant::find($order->tenant_id);
            $restName = $tenant?->name ?: 'Tizón 317 Pizzería';

            if ($waAccount) {
                $readyMsg = $order->delivery_type === 'pickup'
                    ? "🍕 *¡Tu pedido #{$order->order_number} en {$restName} ya salió del horno y está LISTO para recoger!* 🔥\n\n📋 *Pedido:* {$order->items_summary}\n💰 *Total:* \${$order->total_amount} MXN ({$order->payment_method})\n\n¡Te esperamos en sucursal!"
                    : "🛵 *¡Tu pedido #{$order->order_number} de {$restName} ya salió del horno y va EN CAMINO a tu domicilio!* 🍕🔥\n\n📍 *Dirección:* {$order->delivery_address}\n💰 *Total:* \${$order->total_amount} MXN ({$order->payment_method})\n\n¡Que lo disfrutes!";

                $statusNote = $order->delivery_type === 'pickup'
                    ? '¡LISTO PARA RECOGER EN SUCURSAL!'
                    : '¡YA SALIÓ DEL HORNO Y VA EN CAMINO A TU DOMICILIO!';

                $orderService->sendSmartWhatsApp(
                    $waAccount,
                    $order->customer_phone,
                    $readyMsg,
                    'confirmacion_pedido_restaurante',
                    [
                        $order->customer_name ?: 'Cliente',
                        "{$restName} (#{$order->order_number})",
                        $order->items_summary,
                        $statusNote,
                    ],
                    [
                        "{$order->customer_name} (Pedido #{$order->order_number} en {$restName})",
                        "{$statusNote} — {$order->items_summary}",
                    ]
                );
            }
        }

        return response()->json([
            'ok'     => true,
            'id'     => $order->id,
            'status' => $order->status,
        ]);
    }
}
