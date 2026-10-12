<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ManagedStorageProfile;
use App\Models\TenantStorageSetting;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class TenantStorageManager
{
    /** @var array<int, FilesystemAdapter> */
    private array $diskCache = [];

    public function resolveDiskForTenant(int $tenantId): FilesystemAdapter
    {
        if (isset($this->diskCache[$tenantId])) {
            return $this->diskCache[$tenantId];
        }

        $setting = TenantStorageSetting::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        $disk = $setting
            ? $this->buildDiskFromSetting($setting)
            : $this->buildDefaultDisk();

        $this->diskCache[$tenantId] = $disk;

        return $disk;
    }

    public function getActiveSetting(int $tenantId): ?TenantStorageSetting
    {
        return TenantStorageSetting::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();
    }

    public function testConnection(int $tenantId): array
    {
        try {
            $disk   = $this->resolveDiskForTenant($tenantId);
            $year   = now()->format('Y');
            $month  = now()->format('m');
            $testKey = "tenants/{$tenantId}/system/test/{$year}/{$month}/" . \Str::uuid() . '.txt';

            $disk->put($testKey, 'xedik-crm-connection-test');
            $disk->delete($testKey);

            $this->updateConnectionStatus($tenantId, 'success', null);

            return ['success' => true, 'message' => 'Connection successful.'];
        } catch (\Throwable $e) {
            $this->updateConnectionStatus($tenantId, 'failed', $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function testProfileConnection(ManagedStorageProfile $profile): array
    {
        try {
            $disk    = $this->buildDiskFromProfile($profile);
            $testKey = 'crm-system/test/' . \Str::uuid() . '.txt';

            $disk->put($testKey, 'xedik-crm-profile-test');
            $disk->delete($testKey);

            return ['success' => true, 'message' => 'Connection successful.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function buildDiskFromSetting(TenantStorageSetting $setting): FilesystemAdapter
    {
        if ($setting->isCrmManaged() && $setting->managed_storage_profile_id) {
            $profile = $setting->managedProfile;
            return $this->buildDiskFromProfile($profile);
        }

        return $this->buildDiskFromConfig(
            bucket:   $setting->bucket,
            region:   $setting->region ?? 'auto',
            endpoint: $setting->endpoint,
            key:      $setting->getAccessKeyDecrypted(),
            secret:   $setting->getSecretKeyDecrypted(),
        );
    }

    public function buildDiskFromProfile(ManagedStorageProfile $profile): FilesystemAdapter
    {
        return $this->buildDiskFromConfig(
            bucket:   $profile->bucket,
            region:   $profile->region,
            endpoint: $profile->endpoint,
            key:      $profile->getAccessKeyDecrypted(),
            secret:   $profile->getSecretKeyDecrypted(),
        );
    }

    private function buildDiskFromConfig(
        string  $bucket,
        string  $region,
        ?string $endpoint,
        string  $key,
        string  $secret,
    ): FilesystemAdapter {
        $config = [
            'driver' => 's3',
            'key'    => $key,
            'secret' => $secret,
            'region' => $region,
            'bucket' => $bucket,
            'use_path_style_endpoint' => false,
            'throw'  => true,
        ];

        if ($endpoint) {
            $config['endpoint'] = $endpoint;
        }

        return Storage::build($config);
    }

    private function buildDefaultDisk(): FilesystemAdapter
    {
        $profile = ManagedStorageProfile::where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($profile) {
            return $this->buildDiskFromProfile($profile);
        }

        // Fallback al disco r2 del config (variables de entorno)
        return Storage::disk('r2');
    }

    private function updateConnectionStatus(int $tenantId, string $status, ?string $error): void
    {
        TenantStorageSetting::where('tenant_id', $tenantId)->update([
            'last_connection_test_at' => now(),
            'last_connection_status'  => $status,
            'last_connection_error'   => $error,
        ]);
    }

    public function clearCache(int $tenantId): void
    {
        unset($this->diskCache[$tenantId]);
    }
}
