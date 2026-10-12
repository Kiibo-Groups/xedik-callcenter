<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManagedStorageProfile;
use App\Models\StorageProviderCatalog;
use App\Models\StoragePlan;
use App\Models\Tenant;
use App\Models\TenantStorageSetting;
use App\Models\TenantStorageSubscription;
use App\Services\Storage\TenantStorageManager;
use App\Services\Storage\TenantStorageUsageService;
use Illuminate\Http\Request;

class TenantStorageSettingController extends Controller
{
    public function __construct(
        private readonly TenantStorageManager      $storageManager,
        private readonly TenantStorageUsageService $usageService,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeSuperAdmin();

        $tenants  = Tenant::orderBy('name')->get();
        $profiles = ManagedStorageProfile::active()->orderBy('name')->get();
        $providers = StorageProviderCatalog::active()->orderBy('name')->get();
        $plans    = StoragePlan::active()->orderBy('included_bytes')->get();

        $selectedTenantId = $request->integer('tenant_id') ?: $tenants->first()?->id;
        $setting  = $selectedTenantId
            ? TenantStorageSetting::where('tenant_id', $selectedTenantId)->first()
            : null;
        $usage    = $selectedTenantId ? $this->usageService->getUsageSummary($selectedTenantId) : null;
        $subs     = $selectedTenantId
            ? TenantStorageSubscription::with('plan')
                ->where('tenant_id', $selectedTenantId)
                ->orderBy('starts_at', 'desc')
                ->get()
            : collect();

        return view('storage.tenant-settings.index', compact(
            'tenants', 'profiles', 'providers', 'plans',
            'selectedTenantId', 'setting', 'usage', 'subs'
        ));
    }

    public function save(Request $request)
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'tenant_id'                   => 'required|exists:tenants,id',
            'provider_code'               => 'required|string|max:50',
            'ownership_type'              => 'required|in:crm_managed,tenant_owned',
            'managed_storage_profile_id'  => 'nullable|exists:managed_storage_profiles,id',
            'bucket'                      => 'nullable|string|max:255',
            'region'                      => 'nullable|string|max:50',
            'endpoint'                    => 'nullable|string|max:500',
            'base_path'                   => 'nullable|string|max:255',
            'access_key'                  => 'nullable|string',
            'secret_key'                  => 'nullable|string',
            'is_active'                   => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        $setting = TenantStorageSetting::firstOrNew(['tenant_id' => $data['tenant_id']]);
        $setting->fill($data);

        if (!empty($data['access_key'])) {
            $setting->access_key = $data['access_key'];
        }
        if (!empty($data['secret_key'])) {
            $setting->secret_key = $data['secret_key'];
        }

        $setting->save();
        $this->storageManager->clearCache((int) $data['tenant_id']);

        return redirect()->route('admin.storage.tenant-settings.index', ['tenant_id' => $data['tenant_id']])
            ->with('success', 'Storage configuration saved successfully.');
    }

    public function testConnection(Request $request)
    {
        $this->authorizeSuperAdmin();

        $tenantId = $request->integer('tenant_id');
        abort_if(!$tenantId, 422);

        $result = $this->storageManager->testConnection($tenantId);

        return response()->json($result);
    }

    public function addSubscription(Request $request)
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'tenant_id'      => 'required|exists:tenants,id',
            'storage_plan_id'=> 'required|exists:storage_plans,id',
            'starts_at'      => 'required|date',
            'ends_at'        => 'nullable|date|after:starts_at',
        ]);

        TenantStorageSubscription::create(array_merge($data, ['is_active' => true]));

        return redirect()->route('admin.storage.tenant-settings.index', ['tenant_id' => $data['tenant_id']])
            ->with('success', 'Storage subscription added.');
    }

    public function cancelSubscription(TenantStorageSubscription $subscription)
    {
        $this->authorizeSuperAdmin();

        $tenantId = $subscription->tenant_id;
        $subscription->update(['is_active' => false, 'ends_at' => now()]);

        return redirect()->route('admin.storage.tenant-settings.index', ['tenant_id' => $tenantId])
            ->with('success', 'Subscription cancelled.');
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
