<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OmniQueue;
use App\Models\SmsTemplate;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vista operativa compartida por tenant-admin y superadmin (patrón idéntico a
 * SmsChannelController): un superadmin no tiene tenant_id propio, así que para
 * crear/editar debe elegir explícitamente en qué tenant actúa (?tenant_id=) —
 * de lo contrario, un INSERT con tenant_id=null revienta la constraint NOT NULL.
 */
class SmsTemplateController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_sms_templates'), 403);

        $isSuperAdmin = auth()->user()->isSuperAdmin();

        if ($isSuperAdmin) {
            $tenants          = Tenant::withoutGlobalScopes()->orderBy('name')->get();
            $selectedTenantId = $request->integer('tenant_id') ?: null;

            $templates = $selectedTenantId
                ? SmsTemplate::withoutGlobalScopes()->where('tenant_id', $selectedTenantId)->with('omniQueue')->orderBy('name')->get()
                : collect();
            $queues = $selectedTenantId
                ? OmniQueue::withoutGlobalScopes()->where('tenant_id', $selectedTenantId)->where('active', true)->whereIn('type', ['chat', 'omni'])->orderBy('name')->get()
                : collect();
        } else {
            $tenants          = collect();
            $selectedTenantId = auth()->user()->tenant_id;
            $templates        = SmsTemplate::with('omniQueue')->orderBy('name')->get();
            $queues           = OmniQueue::active()->whereIn('type', ['chat', 'omni'])->orderBy('name')->get();
        }

        $selectedTenant = $selectedTenantId ? $tenants->firstWhere('id', $selectedTenantId) : null;

        return view('sms_templates.index', compact('templates', 'queues', 'isSuperAdmin', 'tenants', 'selectedTenantId', 'selectedTenant'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_sms_templates'), 403);

        $tenantId = $this->resolveTenantId($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('sms_templates', 'name')->where('tenant_id', $tenantId),
            ],
            'omni_queue_id' => 'nullable|exists:omni_queues,id',
            'body'          => 'required|string|max:1600',
        ]);

        $this->assertQueueBelongsToTenant($data['omni_queue_id'] ?? null, $tenantId);

        SmsTemplate::create([
            'tenant_id'     => $tenantId,
            'omni_queue_id' => $data['omni_queue_id'] ?? null,
            'name'          => $data['name'],
            'body'          => $data['body'],
            'is_active'     => true,
        ]);

        return back()->with('success', "Template \"{$data['name']}\" created.");
    }

    public function update(Request $request, SmsTemplate $smsTemplate)
    {
        abort_unless(auth()->user()->hasPermission('manage_sms_templates'), 403);

        // El tenant del template ya existente es la fuente de verdad — nunca
        // auth()->user()->tenant_id (sería null para un superadmin editando el
        // template de un tenant ajeno, y rompería el scope de unicidad).
        $tenantId = $smsTemplate->tenant_id;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('sms_templates', 'name')->where('tenant_id', $tenantId)->ignore($smsTemplate->id),
            ],
            'omni_queue_id' => 'nullable|exists:omni_queues,id',
            'body'          => 'required|string|max:1600',
        ]);

        $this->assertQueueBelongsToTenant($data['omni_queue_id'] ?? null, $tenantId);

        $smsTemplate->update([
            'omni_queue_id' => $data['omni_queue_id'] ?? null,
            'name'          => $data['name'],
            'body'          => $data['body'],
        ]);

        return back()->with('success', "Template \"{$smsTemplate->name}\" updated.");
    }

    public function toggleActive(SmsTemplate $smsTemplate)
    {
        abort_unless(auth()->user()->hasPermission('manage_sms_templates'), 403);
        $smsTemplate->update(['is_active' => !$smsTemplate->is_active]);

        return response()->json(['success' => true, 'is_active' => $smsTemplate->is_active]);
    }

    public function destroy(SmsTemplate $smsTemplate)
    {
        abort_unless(auth()->user()->hasPermission('manage_sms_templates'), 403);
        $name = $smsTemplate->name;
        $smsTemplate->delete();

        return back()->with('success', "Template \"{$name}\" deleted.");
    }

    /**
     * Tenant-admin: siempre su propio tenant. Superadmin: el tenant_id que
     * eligió en el selector "Acting as tenant" — sin selección, 422 explícito
     * en vez de dejar caer un tenant_id=null hasta la constraint de BD.
     */
    private function resolveTenantId(Request $request): int
    {
        if (!auth()->user()->isSuperAdmin()) {
            return (int) auth()->user()->tenant_id;
        }

        $tenantId = $request->integer('tenant_id');
        abort_if(!$tenantId, 422, 'Select a tenant before creating a template.');

        return $tenantId;
    }

    private function assertQueueBelongsToTenant(?int $omniQueueId, int $tenantId): void
    {
        if (!$omniQueueId) {
            return;
        }

        $belongsToSameTenant = OmniQueue::withoutGlobalScopes()
            ->where('id', $omniQueueId)
            ->where('tenant_id', $tenantId)
            ->exists();

        abort_unless($belongsToSameTenant, 422, 'That queue does not belong to the selected tenant.');
    }
}
