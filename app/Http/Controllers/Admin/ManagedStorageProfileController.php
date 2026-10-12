<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManagedStorageProfile;
use App\Models\StorageProviderCatalog;
use App\Services\Storage\TenantStorageManager;
use Illuminate\Http\Request;

class ManagedStorageProfileController extends Controller
{
    public function __construct(private readonly TenantStorageManager $storageManager) {}

    public function index()
    {
        $this->authorizeSuperAdmin();

        $profiles  = ManagedStorageProfile::orderBy('environment')->orderBy('name')->get();
        $providers = StorageProviderCatalog::active()->orderBy('name')->get();

        return view('storage.profiles.index', compact('profiles', 'providers'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'code'         => 'required|string|max:50|unique:managed_storage_profiles,code',
            'name'         => 'required|string|max:150',
            'provider_code'=> 'required|string|max:50',
            'environment'  => 'required|in:dev,staging,production',
            'bucket'       => 'required|string|max:255',
            'region'       => 'required|string|max:50',
            'endpoint'     => 'nullable|string|max:500',
            'base_path'    => 'nullable|string|max:255',
            'access_key'   => 'required|string',
            'secret_key'   => 'required|string',
            'is_default'   => 'boolean',
            'is_active'    => 'boolean',
        ]);

        $data['is_active']  = $request->boolean('is_active');
        $data['is_default'] = $request->boolean('is_default');

        if ($data['is_default']) {
            ManagedStorageProfile::where('is_default', true)->update(['is_default' => false]);
        }

        $profile = new ManagedStorageProfile($data);
        $profile->access_key = $data['access_key'];
        $profile->secret_key = $data['secret_key'];
        $profile->save();

        return redirect()->route('admin.storage.profiles.index')
            ->with('success', 'Profile created successfully.');
    }

    public function update(Request $request, ManagedStorageProfile $profile)
    {
        $this->authorizeSuperAdmin();

        $data = $request->validate([
            'name'         => 'required|string|max:150',
            'provider_code'=> 'required|string|max:50',
            'environment'  => 'required|in:dev,staging,production',
            'bucket'       => 'required|string|max:255',
            'region'       => 'required|string|max:50',
            'endpoint'     => 'nullable|string|max:500',
            'base_path'    => 'nullable|string|max:255',
            'access_key'   => 'nullable|string',
            'secret_key'   => 'nullable|string',
            'is_default'   => 'boolean',
            'is_active'    => 'boolean',
        ]);

        $data['is_active']  = $request->boolean('is_active');
        $data['is_default'] = $request->boolean('is_default');

        if ($data['is_default']) {
            ManagedStorageProfile::where('id', '!=', $profile->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $profile->fill($data);

        if (!empty($data['access_key'])) {
            $profile->access_key = $data['access_key'];
        }
        if (!empty($data['secret_key'])) {
            $profile->secret_key = $data['secret_key'];
        }

        $profile->save();
        $this->storageManager->clearCache(0);

        return redirect()->route('admin.storage.profiles.index')
            ->with('success', 'Profile updated successfully.');
    }

    public function testConnection(ManagedStorageProfile $profile)
    {
        $this->authorizeSuperAdmin();

        $result = $this->storageManager->testProfileConnection($profile);

        return response()->json($result);
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
