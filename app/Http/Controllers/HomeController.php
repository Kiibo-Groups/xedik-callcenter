<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\DialerCampaign;
use App\Models\Lead;
use App\Models\RestaurantOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoiceSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $isSuperAdmin = $user->isSuperAdmin();

        // ── 1. Tenant Resolution (Estricto por Tenant) ──────────────────────────
        $availableTenants = collect();
        if ($isSuperAdmin) {
            $availableTenants = Tenant::where('is_active', true)->orderBy('name')->get();
            $requestedTenantId = $request->query('tenant_id');
            if ($requestedTenantId && $availableTenants->contains('id', (int) $requestedTenantId)) {
                $tenantId = (int) $requestedTenantId;
                session(['active_tenant_id' => $tenantId]);
            } elseif (session()->has('active_tenant_id') && $availableTenants->contains('id', (int) session('active_tenant_id'))) {
                $tenantId = (int) session('active_tenant_id');
            } else {
                $tenantId = (int) ($user->tenant_id ?? $availableTenants->first()?->id ?? 1);
            }
        } else {
            $tenantId = (int) ($user->tenant_id ?? 0);
        }

        $currentTenant = Tenant::find($tenantId);
        $isRestaurantTenant = $currentTenant?->isRestaurant() ?? false;
        $today = Carbon::today();

        // ── 2. Métricas del Día (Filtradas estrictamente por Tenant) ───────────
        $todayCallsQuery = Call::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('called_at', $today);

        $todayCallsCount = (clone $todayCallsQuery)->count();
        $todayVoicebotCalls = (clone $todayCallsQuery)->where(function ($q) {
            $q->whereNull('agent_id')
              ->orWhere('notes', 'like', '%Voicebot%');
        })->count();
        $todayHumanCalls = max(0, $todayCallsCount - $todayVoicebotCalls);

        $todayAvgSecs = (int) round((float) ((clone $todayCallsQuery)->where('duration', '>', 0)->avg('duration') ?? 0));
        $avgDurationFormatted = sprintf('%02d:%02d', floor($todayAvgSecs / 60), $todayAvgSecs % 60);

        // Leads
        $totalLeads = Lead::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $todayLeads = Lead::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereDate('created_at', $today)->count();

        // Citas / Cierres Agendados (por Voicebot o Agentes)
        $scheduledCallsCount = Call::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_scheduled', true)
            ->count();

        // Campañas Dialer
        $activeCampaignsCount = DialerCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->count();
        $totalCampaignsCount = DialerCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        // ── 3. Campañas Dialer del Tenant ───────────────────────────────────────
        $dialerCampaigns = DialerCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['omniQueue', 'leadList'])
            ->latest()
            ->take(4)
            ->get();

        // ── 4. Estado de Agentes del Tenant en Tiempo Real ─────────────────────
        $tenantUsers = User::where('tenant_id', $tenantId)
            ->with(['voiceExtension', 'role'])
            ->get();

        $agentsRealtime = $tenantUsers->map(function (User $u) {
            $recentSession = VoiceSession::withoutGlobalScopes()
                ->where('user_id', $u->id)
                ->latest()
                ->first();

            $isOnline = false;
            $statusLabel = 'Desconectado';
            $statusBadge = 'gray';

            if ($recentSession && $recentSession->last_seen_at) {
                $lastSeen = Carbon::parse($recentSession->last_seen_at);
                if ($lastSeen->greaterThan(now()->subMinutes(8))) {
                    $isOnline = true;
                    if (in_array($recentSession->call_state, ['active', 'talking', 'ringing'], true)) {
                        $statusLabel = 'En Llamada';
                        $statusBadge = 'purple';
                    } elseif ($recentSession->status === 'paused') {
                        $statusLabel = 'En Pausa';
                        $statusBadge = 'yellow';
                    } elseif (in_array($recentSession->status, ['active', 'registered'], true)) {
                        $statusLabel = 'Disponible (Ready)';
                        $statusBadge = 'green';
                    } else {
                        $statusLabel = ucfirst($recentSession->status);
                        $statusBadge = 'blue';
                    }
                }
            }

            return [
                'id'           => $u->id,
                'name'         => $u->name,
                'email'        => $u->email,
                'role'         => $u->role?->name ?? 'Agente',
                'extension'    => $u->voiceExtension?->extension ?? 'Sin Ext',
                'is_online'    => $isOnline,
                'status_label' => $statusLabel,
                'status_badge' => $statusBadge,
            ];
        });

        // ── 5. Feed de Citas / Compromisos Agendados ────────────────────────────
        $upcomingAppointments = Call::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_scheduled', true)
            ->with('lead')
            ->orderBy('scheduled_at', 'desc')
            ->take(5)
            ->get();

        // ── 6. Últimas Llamadas Registradas ─────────────────────────────────────
        $recentCalls = Call::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['lead', 'agent'])
            ->latest('called_at')
            ->take(5)
            ->get();

        // ── 7. Métricas y Pedidos en Vivo para Giro Restaurante / Orders ────────
        $orderStats = [];
        $recentOrders = collect();
        if ($isRestaurantTenant) {
            $baseOrders = RestaurantOrder::withoutGlobalScopes()->where('tenant_id', $tenantId);
            $todayOrdersQuery = (clone $baseOrders)->whereDate('created_at', $today);

            $totalOrdersCount = (clone $baseOrders)->count();
            $totalValidOrders = (clone $baseOrders)->where('status', '!=', 'cancelled');
            $totalRevenue     = (float) (clone $totalValidOrders)->sum('total_amount');
            $avgTicket        = (float) ((clone $totalValidOrders)->avg('total_amount') ?? 0);

            $newCount       = (int) (clone $baseOrders)->where('status', 'new')->count();
            $preparingCount = (int) (clone $baseOrders)->where('status', 'preparing')->count();
            $readyCount     = (int) (clone $baseOrders)->where('status', 'ready')->count();
            $deliveredCount = (int) (clone $baseOrders)->where('status', 'delivered')->count();

            $channelCounts = [
                'voice'     => (int) (clone $baseOrders)->where('channel', 'voice')->count(),
                'whatsapp'  => (int) (clone $baseOrders)->where('channel', 'whatsapp')->count(),
                'facebook'  => (int) (clone $baseOrders)->where('channel', 'facebook')->count(),
                'instagram' => (int) (clone $baseOrders)->where('channel', 'instagram')->count(),
                'manual'    => (int) (clone $baseOrders)->where('channel', 'manual')->count(),
            ];

            $deliveryCount = (int) (clone $baseOrders)->where('delivery_type', 'delivery')->count();
            $pickupCount   = (int) (clone $baseOrders)->where('delivery_type', 'pickup')->count();

            $orderStats = [
                'today_orders'    => (int) (clone $todayOrdersQuery)->count(),
                'today_revenue'   => (float) (clone $todayOrdersQuery)->where('status', '!=', 'cancelled')->sum('total_amount'),
                'completed_today' => (int) (clone $todayOrdersQuery)->where('status', 'delivered')->count(),
                'total_orders'    => $totalOrdersCount,
                'total_revenue'   => $totalRevenue,
                'avg_ticket'      => $avgTicket,
                'new_count'       => $newCount,
                'preparing_count' => $preparingCount,
                'in_preparation'  => $newCount + $preparingCount,
                'ready_count'     => $readyCount,
                'delivered_count' => $deliveredCount,
                'by_channel'      => $channelCounts,
                'delivery_count'  => $deliveryCount,
                'pickup_count'    => $pickupCount,
            ];

            $recentOrders = RestaurantOrder::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['call' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderByDesc('id')
                ->take(8)
                ->get();
        }

        return view('home', [
            'isSuperAdmin'         => $isSuperAdmin,
            'availableTenants'     => $availableTenants,
            'currentTenant'        => $currentTenant,
            'isRestaurantTenant'   => $isRestaurantTenant,
            'tenantId'             => $tenantId,
            'todayCallsCount'      => $todayCallsCount,
            'todayVoicebotCalls'   => $todayVoicebotCalls,
            'todayHumanCalls'      => $todayHumanCalls,
            'avgDurationFormatted' => $avgDurationFormatted,
            'totalLeads'           => $totalLeads,
            'todayLeads'           => $todayLeads,
            'scheduledCallsCount'  => $scheduledCallsCount,
            'activeCampaignsCount' => $activeCampaignsCount,
            'totalCampaignsCount'  => $totalCampaignsCount,
            'dialerCampaigns'      => $dialerCampaigns,
            'agentsRealtime'       => $agentsRealtime,
            'upcomingAppointments' => $upcomingAppointments,
            'recentCalls'          => $recentCalls,
            'orderStats'           => $orderStats,
            'recentOrders'         => $recentOrders,
        ]);
    }
}
