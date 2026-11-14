<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\LeadSource;
use App\Models\VoiceTenantSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $tenants = Tenant::withCount('users')->orderBy('name')->get();
        $permissions = \App\Models\Permission::grantable()->orderBy('module')->orderBy('sort_order')->get()->groupBy('module');
        return view('tenants.index', compact('tenants', 'permissions'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'name'                   => 'required|string|max:100',
            'slug'                   => ['required', 'string', 'max:100', 'unique:tenants,slug', 'regex:/^[a-z0-9\-]+$/'],
            'business_type'          => ['nullable', 'string', Rule::in(['call_center', 'restaurant'])],
            'kitchen_whatsapp_phone' => ['nullable', 'string', 'max:30'],
            'delivery_fee'           => ['nullable', 'string', 'max:30'],
            'estimated_time'         => ['nullable', 'string', 'max:50'],
            'permission_ids'         => 'nullable|array',
            'permission_ids.*'       => 'integer|exists:permissions,id',
        ]);

        $businessType = $validated['business_type'] ?? 'call_center';
        $settings = [
            'business_type'          => $businessType,
            'kitchen_whatsapp_phone' => trim((string) ($validated['kitchen_whatsapp_phone'] ?? '')),
            'delivery_fee'           => trim((string) ($validated['delivery_fee'] ?? '')),
            'estimated_time'         => trim((string) ($validated['estimated_time'] ?? '')),
        ];

        $tenant = Tenant::create([
            'name'      => $validated['name'],
            'slug'      => $validated['slug'],
            'is_active' => true,
            'settings'  => $settings,
        ]);

        $grantedPermissionIds = collect($validated['permission_ids'] ?? []);

        $this->seedTenantEntitlements($tenant->id, $grantedPermissionIds);
        $this->seedDefaultRoles($tenant->id, $grantedPermissionIds);
        $this->seedDefaultStatuses($tenant->id, $businessType);
        $this->seedDefaultSources($tenant->id);
        $this->seedVoiceSettings($tenant->id);

        if ($businessType === 'restaurant') {
            $this->seedRestaurantDefaults($tenant);
        }

        return back()->with('success', 'Tenant created successfully.');
    }

    /**
     * Registra en permission_tenant exactamente los permisos que el
     * superadmin eligió al crear el tenant — nada queda habilitado por
     * default.
     */
    private function seedTenantEntitlements(int $tenantId, \Illuminate\Support\Collection $permissionIds): void
    {
        $now = now();
        foreach ($permissionIds as $permissionId) {
            \Illuminate\Support\Facades\DB::table('permission_tenant')->insertOrIgnore([
                'tenant_id'     => $tenantId,
                'permission_id' => (int) $permissionId,
                'enabled_at'    => $now,
            ]);
        }
    }

    /**
     * Crea los roles admin y agent para un tenant nuevo.
     * - admin: exactamente los permisos que el superadmin otorgó al tenant
     * - agent: console, my leads, scheduled calls (intersectado con lo otorgado)
     */
    private function seedDefaultRoles(int $tenantId, \Illuminate\Support\Collection $grantedPermissionIds): void
    {
        $agentPermissionIds = \App\Models\Permission::whereIn('name', [
            'view_console',
            'view_my_leads',
            'view_scheduled_calls',
        ])->pluck('id');

        $rolesToSeed = [
            ['name' => 'admin', 'label' => 'Admin', 'permissions' => $grantedPermissionIds],
            ['name' => 'agent', 'label' => 'Agent', 'permissions' => $agentPermissionIds->intersect($grantedPermissionIds)],
        ];

        foreach ($rolesToSeed as $def) {
            $role = \App\Models\Role::withoutGlobalScopes()->firstOrCreate(
                ['name' => $def['name'], 'tenant_id' => $tenantId],
                ['label' => $def['label'], 'tenant_id' => $tenantId]
            );
            $role->permissions()->sync($def['permissions']);
        }
    }

    /**
     * Pantalla de superadmin para ver/editar qué permisos están habilitados
     * para un tenant en particular.
     */
    public function permissions(Tenant $tenant)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $permissions = \App\Models\Permission::grantable()->orderBy('module')->orderBy('sort_order')->get()->groupBy('module');
        $enabledIds  = $tenant->enabledPermissions()->pluck('permissions.id')->toArray();

        return view('tenants.permissions', compact('tenant', 'permissions', 'enabledIds'));
    }

    public function updatePermissions(Request $request, Tenant $tenant)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'permission_ids'   => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        $grantedIds = collect($validated['permission_ids'] ?? [])->map(fn($id) => (int) $id);
        $syncData = [];
        foreach ($grantedIds as $permissionId) {
            $syncData[$permissionId] = ['enabled_at' => now()];
        }
        $tenant->enabledPermissions()->sync($syncData);

        // Synchronize admin role of this tenant so admin gets all newly granted permissions
        $adminRole = \App\Models\Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'admin')
            ->first();

        if ($adminRole) {
            $adminRole->permissions()->sync($grantedIds);
        }

        // For other roles in this tenant (e.g. agent), remove permissions that were revoked from tenant
        $otherRoles = \App\Models\Role::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', '!=', 'admin')
            ->get();

        foreach ($otherRoles as $otherRole) {
            $currentPermIds = $otherRole->permissions()->pluck('permissions.id');
            $otherRole->permissions()->sync($currentPermIds->intersect($grantedIds));
        }

        return redirect()->route('tenants.permissions', $tenant)
            ->with('success', "Permissions updated for {$tenant->name}.");
    }

    /**
     * Crea los lead statuses default para un tenant nuevo.
     */
    private function seedDefaultStatuses(int $tenantId, string $businessType = 'call_center'): void
    {
        $statuses = $businessType === 'restaurant'
            ? [
                ['name' => 'Nuevo Pedido',    'slug' => 'new',            'color' => '#0891b2', 'bg_color' => '#cffafe', 'order' => 1, 'is_default' => true,  'is_active' => true],
                ['name' => 'En Preparación',  'slug' => 'in_preparation', 'color' => '#b45309', 'bg_color' => '#fef9c3', 'order' => 2, 'is_default' => false, 'is_active' => true],
                ['name' => 'En Reparto',      'slug' => 'out_delivery',   'color' => '#4f46e5', 'bg_color' => '#e0e7ff', 'order' => 3, 'is_default' => false, 'is_active' => true],
                ['name' => 'Entregado',       'slug' => 'delivered',      'color' => '#16a34a', 'bg_color' => '#dcfce7', 'order' => 4, 'is_default' => false, 'is_active' => true],
                ['name' => 'Cancelado',       'slug' => 'cancelled',      'color' => '#dc2626', 'bg_color' => '#fee2e2', 'order' => 5, 'is_default' => false, 'is_active' => true],
            ]
            : [
                ['name' => 'New',         'slug' => 'new',         'color' => '#0891b2', 'bg_color' => '#cffafe', 'order' => 1, 'is_default' => true,  'is_active' => true],
                ['name' => 'Contacted',   'slug' => 'contacted',   'color' => '#4f46e5', 'bg_color' => '#e0e7ff', 'order' => 2, 'is_default' => false, 'is_active' => true],
                ['name' => 'Qualified',   'slug' => 'qualified',   'color' => '#b45309', 'bg_color' => '#fef9c3', 'order' => 3, 'is_default' => false, 'is_active' => true],
                ['name' => 'Proposal',    'slug' => 'proposal',    'color' => '#15803d', 'bg_color' => '#dcfce7', 'order' => 4, 'is_default' => false, 'is_active' => true],
                ['name' => 'Negotiation', 'slug' => 'negotiation', 'color' => '#e2e8f0', 'bg_color' => '#1e293b', 'order' => 5, 'is_default' => false, 'is_active' => true],
                ['name' => 'Closed Won',  'slug' => 'closed_won',  'color' => '#16a34a', 'bg_color' => '#dcfce7', 'order' => 6, 'is_default' => false, 'is_active' => true],
                ['name' => 'Closed Lost', 'slug' => 'closed_lost', 'color' => '#dc2626', 'bg_color' => '#fee2e2', 'order' => 7, 'is_default' => false, 'is_active' => true],
            ];

        foreach ($statuses as $data) {
            \App\Models\LeadStatus::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $data['slug']],
                array_merge($data, ['tenant_id' => $tenantId])
            );
        }
    }

    private function seedRestaurantDefaults(Tenant $tenant): void
    {
        $campaign = \App\Models\Campaign::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'pedidos_' . str_replace('-', '_', $tenant->slug)],
            [
                'tenant_id'   => $tenant->id,
                'name'        => 'Pedidos ' . $tenant->name,
                'color'       => '#ea580c',
                'bg_color'    => '#ffedd5',
                'description' => 'Recepción de pedidos por WhatsApp, Redes Sociales y Teléfono con IA',
                'is_active'   => true,
                'is_default'  => true,
            ]
        );

        \App\Models\OmniQueue::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Cola Pedidos ' . $tenant->name],
            [
                'tenant_id'   => $tenant->id,
                'campaign_id' => $campaign->id,
                'name'        => 'Cola Pedidos ' . $tenant->name,
                'description' => 'Bandeja principal de atención y pedidos',
                'active'      => true,
            ]
        );
    }

    /**
     * Crea los lead sources default para un tenant nuevo.
     */
    private function seedDefaultSources(int $tenantId): void
    {
        $sources = [
            ['name' => 'Web',          'is_active' => true, 'order' => 1],
            ['name' => 'Phone',        'is_active' => true, 'order' => 2],
            ['name' => 'Dialer',       'is_active' => true, 'order' => 3],
            ['name' => 'Referral',     'is_active' => true, 'order' => 4],
            ['name' => 'Email',        'is_active' => true, 'order' => 5],
            ['name' => 'Social Media', 'is_active' => true, 'order' => 6],
            ['name' => 'Other',        'is_active' => true, 'order' => 7],
        ];

        foreach ($sources as $data) {
            \App\Models\LeadSource::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $data['name']],
                array_merge($data, ['tenant_id' => $tenantId])
            );
        }
    }

    /**
     * Creates a disabled VoiceTenantSetting record for a new tenant.
     */
    private function seedVoiceSettings(int $tenantId): void
    {
        VoiceTenantSetting::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'voice_enabled'          => false,
                'dialplan_context'       => "xedik_t{$tenantId}_agents",
                'outbound_context'       => "xedik_t{$tenantId}_out",
                'session_ttl_seconds'    => (int) config('voice.session_ttl_seconds', 28800),
                'max_sessions_per_agent' => (int) config('voice.max_sessions_per_agent', 1),
            ]
        );
    }

    public function update(Request $request, Tenant $tenant)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'name'                   => 'required|string|max:100',
            'slug'                   => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'business_type'          => ['nullable', 'string', Rule::in(['call_center', 'restaurant'])],
            'kitchen_whatsapp_phone' => ['nullable', 'string', 'max:30'],
            'delivery_fee'           => ['nullable', 'string', 'max:30'],
            'estimated_time'         => ['nullable', 'string', 'max:50'],
            'is_active'              => 'boolean',
        ]);

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['business_type']          = $validated['business_type'] ?? ($settings['business_type'] ?? 'call_center');
        $settings['kitchen_whatsapp_phone'] = trim((string) ($validated['kitchen_whatsapp_phone'] ?? ($settings['kitchen_whatsapp_phone'] ?? '')));
        $settings['delivery_fee']           = trim((string) ($validated['delivery_fee'] ?? ($settings['delivery_fee'] ?? '')));
        $settings['estimated_time']         = trim((string) ($validated['estimated_time'] ?? ($settings['estimated_time'] ?? '')));

        $tenant->update([
            'name'      => $validated['name'],
            'slug'      => $validated['slug'],
            'is_active' => $request->boolean('is_active', false),
            'settings'  => $settings,
        ]);

        if ($settings['business_type'] === 'restaurant') {
            $this->seedRestaurantDefaults($tenant);
        }

        return back()->with('success', 'Tenant updated successfully.');
    }

    public function destroy(Tenant $tenant)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        if ($tenant->users()->exists()) {
            return back()->with('error', 'Cannot delete a tenant that has users assigned.');
        }

        try {
            DB::transaction(function () use ($tenant) {
                // Reassign any users (such as superadmins) whose role_id points to roles of this tenant
                $tenantRoleIds = \App\Models\Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id');
                if ($tenantRoleIds->isNotEmpty()) {
                    $fallbackRole = \App\Models\Role::withoutGlobalScopes()
                        ->where('tenant_id', '!=', $tenant->id)
                        ->where('name', 'admin')
                        ->first();
                    if ($fallbackRole) {
                        \App\Models\User::whereIn('role_id', $tenantRoleIds)->update(['role_id' => $fallbackRole->id]);
                    }
                }

                $tenant->delete();
            });

            return back()->with('success', 'Tenant deleted successfully.');
        } catch (\Throwable $e) {
            Log::error('Tenant deletion failed: ' . $e->getMessage(), ['tenant_id' => $tenant->id, 'exception' => $e]);
            return back()->with('error', 'Could not delete tenant: ' . $e->getMessage());
        }
    }
}
