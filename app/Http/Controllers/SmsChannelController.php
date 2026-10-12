<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OmniQueue;
use App\Models\SmsChannel;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Vista operativa: el tenant-admin ve el número SMS que superadmin le asignó
 * y lo liga a una omnichannel queue de SU tenant. No expone credenciales ni la
 * URL técnica del proveedor (eso vive en SmsProviderAccount / Admin\SmsChannelController).
 *
 * Un superadmin puede entrar aquí también (hasPermission() siempre es true para
 * is_superadmin) — como TenantScope se salta para él, necesita elegir explícitamente
 * en qué tenant actúa (?tenant_id=) para poder ver/editar la queue "en contexto"
 * de ese tenant; sin selección no ve canales ni queues de nadie.
 */
class SmsChannelController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_sms_channels'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();

        if ($isSuperAdmin) {
            $tenants          = Tenant::withoutGlobalScopes()->orderBy('name')->get();
            $selectedTenantId = $request->integer('tenant_id') ?: null;

            $channels = $selectedTenantId
                ? SmsChannel::withoutGlobalScopes()->where('tenant_id', $selectedTenantId)->with('omniQueue')->orderBy('display_name')->get()
                : collect();
            $queues = $selectedTenantId
                ? OmniQueue::withoutGlobalScopes()->where('tenant_id', $selectedTenantId)->where('active', true)->whereIn('type', ['chat', 'omni'])->orderBy('name')->get()
                : collect();
        } else {
            $tenants          = collect();
            $selectedTenantId = auth()->user()->tenant_id;
            $channels         = SmsChannel::with('omniQueue')->orderBy('display_name')->get();
            $queues           = OmniQueue::active()->whereIn('type', ['chat', 'omni'])->orderBy('name')->get();
        }

        $selectedTenant = $selectedTenantId ? $tenants->firstWhere('id', $selectedTenantId) : null;

        return view('sms_channels.index', compact('channels', 'queues', 'isSuperAdmin', 'tenants', 'selectedTenantId', 'selectedTenant'));
    }

    public function update(Request $request, SmsChannel $smsChannel)
    {
        abort_unless(auth()->user()->hasPermission('manage_sms_channels'), 403);

        $data = $request->validate([
            'omni_queue_id' => 'nullable|exists:omni_queues,id',
        ]);

        // Defensa contra IDOR: el queue elegido debe pertenecer al MISMO tenant
        // que el canal, sin importar si quien envía el request es el tenant-admin
        // dueño del canal o un superadmin actuando en su contexto — ambos pasan
        // por la misma validación.
        if (!empty($data['omni_queue_id'])) {
            $belongsToSameTenant = OmniQueue::withoutGlobalScopes()
                ->where('id', $data['omni_queue_id'])
                ->where('tenant_id', $smsChannel->tenant_id)
                ->exists();

            abort_unless($belongsToSameTenant, 422, 'That queue does not belong to this channel\'s tenant.');
        }

        $smsChannel->update(['omni_queue_id' => $data['omni_queue_id'] ?? null]);

        return back()->with('success', "Queue updated for \"{$smsChannel->display_name}\".");
    }
}
