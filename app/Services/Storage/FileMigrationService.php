<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\CrmFile;
use App\Models\FileAccessLog;
use Illuminate\Support\Facades\Storage;

class FileMigrationService
{
    public function __construct(
        private readonly TenantStorageManager     $storageManager,
        private readonly CrmFileService           $fileService,
        private readonly TenantStorageUsageService $usageService,
    ) {}

    /**
     * Migra un archivo local al storage del tenant.
     *
     * @param  array{
     *     channel: string,
     *     category: string,
     *     source: string,
     *     direction?: string,
     *     conversation_id?: int|null,
     *     message_id?: int|null,
     *     related_type?: string|null,
     *     related_id?: int|null,
     * } $metadata
     */
    public function migrateLocalFile(
        int    $tenantId,
        string $localPath,
        array  $metadata,
        bool   $dryRun = false,
        bool   $ignoreLimit = false,
    ): array {
        $absolutePath = Storage::disk('local')->path($localPath);

        if (!file_exists($absolutePath)) {
            return ['status' => 'skipped', 'reason' => 'Archivo no encontrado', 'path' => $localPath];
        }

        $content   = file_get_contents($absolutePath);
        $sizeBytes = strlen($content);
        $mimeType  = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) ?: null;
        $checksum  = hash('sha256', $content);

        // Verificar si ya fue migrado
        $alreadyMigrated = CrmFile::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('migrated_from_path', $localPath)
            ->whereIn('status', ['active', 'archived'])
            ->exists();

        if ($alreadyMigrated) {
            return ['status' => 'skipped', 'reason' => 'Ya migrado', 'path' => $localPath];
        }

        if (!$ignoreLimit) {
            try {
                $this->usageService->assertCanUpload($tenantId, $sizeBytes);
            } catch (\RuntimeException $e) {
                return ['status' => 'skipped', 'reason' => $e->getMessage(), 'path' => $localPath];
            }
        }

        if ($dryRun) {
            return [
                'status'       => 'dry_run',
                'path'         => $localPath,
                'size_bytes'   => $sizeBytes,
                'mime_type'    => $mimeType,
                'extension'    => $extension,
                'category'     => $this->fileService->normalizeCategoryFromMimeType($mimeType, $extension),
            ];
        }

        try {
            $objectKey  = $this->fileService->generateObjectKey(
                $tenantId,
                $metadata['channel'],
                $metadata['category'] ?? $this->fileService->normalizeCategoryFromMimeType($mimeType, $extension),
                [
                    'conversation_id' => $metadata['conversation_id'] ?? null,
                    'message_id'      => $metadata['message_id'] ?? null,
                ],
                $extension,
            );

            $this->fileService->assertObjectKeyBelongsToTenant($tenantId, $objectKey);

            $disk = $this->storageManager->resolveDiskForTenant($tenantId);
            $disk->put($objectKey, $content);

            $setting  = $this->storageManager->getActiveSetting($tenantId);
            $provider = $setting?->provider_code ?? 'cloudflare_r2';
            $bucket   = $setting?->bucket
                ?? $setting?->managedProfile?->bucket
                ?? config('filesystems.disks.r2.bucket');

            $crmFile = CrmFile::create([
                'tenant_id'           => $tenantId,
                'conversation_id'     => $metadata['conversation_id'] ?? null,
                'message_id'          => $metadata['message_id'] ?? null,
                'related_type'        => $metadata['related_type'] ?? null,
                'related_id'          => $metadata['related_id'] ?? null,
                'channel'             => $metadata['channel'],
                'direction'           => $metadata['direction'] ?? null,
                'source'              => $metadata['source'] ?? 'migration',
                'category'            => $metadata['category'] ?? $this->fileService->normalizeCategoryFromMimeType($mimeType, $extension),
                'storage_provider'    => $provider,
                'bucket'              => $bucket,
                'object_key'          => $objectKey,
                'original_filename'   => basename($localPath),
                'stored_filename'     => basename($objectKey),
                'extension'           => $extension,
                'mime_type'           => $mimeType,
                'size_bytes'          => $sizeBytes,
                'checksum_sha256'     => $checksum,
                'visibility'          => 'private',
                'status'              => 'active',
                'migrated_from_path'  => $localPath,
                'migrated_at'         => now(),
            ]);

            FileAccessLog::create([
                'tenant_id'   => $tenantId,
                'crm_file_id' => $crmFile->id,
                'user_id'     => null,
                'action'      => 'migrate',
                'ip_address'  => null,
                'user_agent'  => 'crm:files:migrate-to-storage',
            ]);

            return [
                'status'       => 'migrated',
                'crm_file_id'  => $crmFile->id,
                'object_key'   => $objectKey,
                'size_bytes'   => $sizeBytes,
                'path'         => $localPath,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage(), 'path' => $localPath];
        }
    }
}
