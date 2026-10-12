<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\CrmFile;
use App\Models\TenantStorageSubscription;
use App\Models\TenantStorageUsageSnapshot;

class TenantStorageUsageService
{
    // 1 GB incluido en todos los tenants
    private const BASE_BYTES = 1_073_741_824;

    private const WARN_THRESHOLD     = 80.0;
    private const CRITICAL_THRESHOLD = 95.0;

    public function getUsedBytes(int $tenantId): int
    {
        return (int) CrmFile::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->sum('size_bytes');
    }

    public function getAllowedBytes(int $tenantId): int
    {
        $extra = TenantStorageSubscription::with('plan')
            ->where('tenant_id', $tenantId)
            ->active()
            ->get()
            ->sum(fn ($sub) => $sub->plan->included_bytes ?? 0);

        return self::BASE_BYTES + (int) $extra;
    }

    public function getUsagePercent(int $tenantId): float
    {
        $allowed = $this->getAllowedBytes($tenantId);
        if ($allowed === 0) {
            return 100.0;
        }
        return round(($this->getUsedBytes($tenantId) / $allowed) * 100, 2);
    }

    public function getUsageSummary(int $tenantId): array
    {
        $usedBytes    = $this->getUsedBytes($tenantId);
        $allowedBytes = $this->getAllowedBytes($tenantId);
        $percent      = $allowedBytes > 0
            ? round(($usedBytes / $allowedBytes) * 100, 2)
            : 100.0;

        return [
            'used_bytes'      => $usedBytes,
            'allowed_bytes'   => $allowedBytes,
            'usage_percent'   => $percent,
            'used_gb'         => round($usedBytes / (1024 ** 3), 3),
            'allowed_gb'      => round($allowedBytes / (1024 ** 3), 2),
            'files_count'     => $this->getActiveFilesCount($tenantId),
            'status'          => $this->resolveStatus($percent),
        ];
    }

    public function shouldWarn(int $tenantId): bool
    {
        $percent = $this->getUsagePercent($tenantId);
        return $percent >= self::WARN_THRESHOLD;
    }

    public function shouldBlockUpload(int $tenantId, int $incomingBytes): bool
    {
        $used    = $this->getUsedBytes($tenantId);
        $allowed = $this->getAllowedBytes($tenantId);
        return ($used + $incomingBytes) > $allowed;
    }

    /**
     * Lanza excepción si el upload supera el límite del tenant.
     * Los archivos existentes siguen descargando aunque estén sobre el límite.
     */
    public function assertCanUpload(int $tenantId, int $incomingBytes): void
    {
        if ($this->shouldBlockUpload($tenantId, $incomingBytes)) {
            throw new \RuntimeException(
                'El tenant ha alcanzado su límite de almacenamiento. No se pueden subir nuevos archivos.'
            );
        }
    }

    public function createSnapshot(int $tenantId): TenantStorageUsageSnapshot
    {
        $usedBytes    = $this->getUsedBytes($tenantId);
        $allowedBytes = $this->getAllowedBytes($tenantId);
        $percent      = $allowedBytes > 0
            ? round(($usedBytes / $allowedBytes) * 100, 2)
            : 100.0;

        return TenantStorageUsageSnapshot::updateOrCreate(
            ['tenant_id' => $tenantId, 'snapshot_date' => today()->toDateString()],
            [
                'used_bytes'         => $usedBytes,
                'allowed_bytes'      => $allowedBytes,
                'usage_percent'      => $percent,
                'active_files_count' => $this->getActiveFilesCount($tenantId),
            ]
        );
    }

    private function getActiveFilesCount(int $tenantId): int
    {
        return (int) CrmFile::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();
    }

    private function resolveStatus(float $percent): string
    {
        if ($percent >= 100.0) {
            return 'blocked';
        }
        if ($percent >= self::CRITICAL_THRESHOLD) {
            return 'critical';
        }
        if ($percent >= self::WARN_THRESHOLD) {
            return 'warning';
        }
        return 'ok';
    }
}
