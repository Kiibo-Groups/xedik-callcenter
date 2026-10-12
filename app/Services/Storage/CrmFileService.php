<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\CrmFile;
use App\Models\FileAccessLog;
use App\Models\TenantStorageSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class CrmFileService
{
    // Tamaño máximo por archivo: 50 MB
    private const MAX_FILE_BYTES = 52_428_800;

    // Categorías permitidas
    private const VALID_CHANNELS = ['whatsapp', 'email', 'webchat', 'manual', 'system'];
    private const VALID_CATEGORIES = [
        'images', 'audio', 'video', 'documents',
        'attachments', 'inline_images', 'raw', 'stickers', 'other',
    ];

    // Mapa de category a segmento de path en el object_key (guiones para el path, snake para la BD)
    private const CATEGORY_PATH_SEGMENT = [
        'inline_images' => 'inline-images',
    ];

    public function __construct(
        private readonly TenantStorageManager $storageManager,
        private readonly TenantStorageUsageService $usageService,
    ) {}

    /**
     * Sube un archivo al storage del tenant y crea el registro en crm_files.
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
     *     uploaded_by?: int|null,
     * } $metadata
     */
    public function uploadFile(int $tenantId, UploadedFile|string $file, array $metadata): CrmFile
    {
        [$content, $originalFilename, $mimeType, $sizeBytes, $extension] = $this->resolveFileData($file);

        $this->validateFileSize($sizeBytes);
        $this->usageService->assertCanUpload($tenantId, $sizeBytes);

        $channel  = $metadata['channel'];
        $category = $metadata['category'];
        $context  = [
            'conversation_id' => $metadata['conversation_id'] ?? null,
            'message_id'      => $metadata['message_id'] ?? null,
        ];

        $objectKey     = $this->generateObjectKey($tenantId, $channel, $category, $context, $extension);
        $storedFilename = basename($objectKey);

        $disk = $this->storageManager->resolveDiskForTenant($tenantId);
        $disk->put($objectKey, $content);

        $setting = $this->storageManager->getActiveSetting($tenantId);
        [$provider, $ownershipType, $profileId, $bucket] = $this->resolveStorageInfo($tenantId, $setting);

        $crmFile = CrmFile::create([
            'tenant_id'                 => $tenantId,
            'conversation_id'           => $metadata['conversation_id'] ?? null,
            'message_id'                => $metadata['message_id'] ?? null,
            'related_type'              => $metadata['related_type'] ?? null,
            'related_id'                => $metadata['related_id'] ?? null,
            'channel'                   => $channel,
            'direction'                 => $metadata['direction'] ?? null,
            'source'                    => $metadata['source'],
            'category'                  => $category,
            'storage_provider'          => $provider,
            'storage_ownership_type'    => $ownershipType,
            'managed_storage_profile_id' => $profileId,
            'bucket'                    => $bucket,
            'object_key'                => $objectKey,
            'original_filename'         => $originalFilename,
            'stored_filename'           => $storedFilename,
            'extension'                 => $extension,
            'mime_type'                 => $mimeType,
            'size_bytes'                => $sizeBytes,
            'checksum_sha256'           => hash('sha256', $content),
            'visibility'                => 'private',
            'status'                    => 'active',
            'uploaded_by'               => $metadata['uploaded_by'] ?? null,
        ]);

        $this->logAccess($crmFile, 'upload', $metadata['uploaded_by'] ?? null);

        return $crmFile;
    }

    /**
     * Genera el object_key para un archivo.
     *
     * Para archivos de canal (con conversation_id + message_id):
     *   tenants/{tenant_id}/conversations/{conv_id}/messages/{msg_id}/{channel}/{category}/{yyyy}/{mm}/{uuid}.{ext}
     *
     * Para archivos de entidad (sin message_id):
     *   tenants/{tenant_id}/entities/{entity_type}/{entity_id}/{category}/{yyyy}/{mm}/{uuid}.{ext}
     *
     * Para archivos de sistema:
     *   tenants/{tenant_id}/system/{category}/{yyyy}/{mm}/{uuid}.{ext}
     */
    public function generateObjectKey(
        int     $tenantId,
        string  $channel,
        string  $category,
        array   $context,
        ?string $extension,
    ): string {
        $uuid      = Str::uuid()->toString();
        $year      = now()->format('Y');
        $month     = now()->format('m');
        $filename  = $extension ? "{$uuid}.{$extension}" : $uuid;
        $catSegment = self::CATEGORY_PATH_SEGMENT[$category] ?? $category;

        $base = $this->resolveBasePath($tenantId);

        if (!empty($context['conversation_id']) && !empty($context['message_id'])) {
            $convId = $context['conversation_id'];
            $msgId  = $context['message_id'];
            return "{$base}tenants/{$tenantId}/conversations/{$convId}/messages/{$msgId}/{$channel}/{$catSegment}/{$year}/{$month}/{$filename}";
        }

        if (!empty($context['entity_type']) && !empty($context['entity_id'])) {
            $entityType = $context['entity_type'];
            $entityId   = $context['entity_id'];
            return "{$base}tenants/{$tenantId}/entities/{$entityType}/{$entityId}/{$catSegment}/{$year}/{$month}/{$filename}";
        }

        return "{$base}tenants/{$tenantId}/system/{$catSegment}/{$year}/{$month}/{$filename}";
    }

    public function assertObjectKeyBelongsToTenant(int $tenantId, string $objectKey): void
    {
        $base        = $this->resolveBasePath($tenantId);
        $expectedRoot = "{$base}tenants/{$tenantId}/";

        if (!str_starts_with($objectKey, $expectedRoot)) {
            throw new \InvalidArgumentException(
                "Object key no pertenece al tenant {$tenantId}."
            );
        }

        // Protección contra path traversal
        if (str_contains($objectKey, '..')) {
            throw new \InvalidArgumentException('Object key inválido.');
        }
    }

    public function generateTemporaryUrl(int $fileId, ?int $userId, int $ttlMinutes = 10): string
    {
        $crmFile = CrmFile::findOrFail($fileId);

        $this->assertObjectKeyBelongsToTenant($crmFile->tenant_id, $crmFile->object_key);

        $disk      = $this->storageManager->resolveDiskForTenant($crmFile->tenant_id);
        $expiresAt = now()->addMinutes($ttlMinutes);

        $url = $disk->temporaryUrl($crmFile->object_key, $expiresAt);

        $this->logAccess($crmFile, 'temporary_url', $userId);

        return $url;
    }

    public function softDeleteFile(int $fileId, ?int $userId): void
    {
        $crmFile = CrmFile::findOrFail($fileId);
        $this->logAccess($crmFile, 'delete', $userId);
        $crmFile->update(['status' => 'deleted']);
        $crmFile->delete();
    }

    public function normalizeCategoryFromMimeType(string $mimeType, ?string $extension = null): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'images';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
        ], true)) {
            return 'documents';
        }
        if ($extension === 'eml' || $mimeType === 'message/rfc822') {
            return 'raw';
        }

        return 'other';
    }

    private function resolveFileData(UploadedFile|string $file): array
    {
        if ($file instanceof UploadedFile) {
            $content         = file_get_contents($file->getRealPath());
            $originalFilename = $file->getClientOriginalName();
            $mimeType        = $file->getMimeType() ?? 'application/octet-stream';
            $sizeBytes       = $file->getSize();
            $extension       = strtolower($file->getClientOriginalExtension()) ?: null;
        } else {
            // $file es ruta local (para migración)
            $content         = file_get_contents($file);
            $originalFilename = basename($file);
            $mimeType        = mime_content_type($file) ?: 'application/octet-stream';
            $sizeBytes       = strlen($content);
            $extension       = strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: null;
        }

        return [$content, $originalFilename, $mimeType, $sizeBytes, $extension];
    }

    private function validateFileSize(int $sizeBytes): void
    {
        if ($sizeBytes > self::MAX_FILE_BYTES) {
            $maxMb = self::MAX_FILE_BYTES / (1024 ** 2);
            throw new \RuntimeException("El archivo excede el tamaño máximo permitido de {$maxMb} MB.");
        }
    }

    private function resolveBasePath(int $tenantId): string
    {
        $setting = $this->storageManager->getActiveSetting($tenantId);

        if (!$setting) {
            return '';
        }

        if ($setting->isCrmManaged() && $setting->managed_storage_profile_id) {
            $basePath = $setting->managedProfile?->base_path;
        } else {
            $basePath = $setting->base_path;
        }

        return $basePath ? rtrim($basePath, '/') . '/' : '';
    }

    private function resolveStorageInfo(int $tenantId, ?TenantStorageSetting $setting): array
    {
        if (!$setting) {
            $profile = \App\Models\ManagedStorageProfile::where('is_default', true)
                ->where('is_active', true)
                ->first();

            return [
                'cloudflare_r2',
                'crm_managed',
                $profile?->id,
                $profile?->bucket ?? config('filesystems.disks.r2.bucket'),
            ];
        }

        if ($setting->isCrmManaged()) {
            $profile = $setting->managedProfile;
            return [
                $profile?->provider_code ?? $setting->provider_code,
                'crm_managed',
                $profile?->id,
                $profile?->bucket ?? $setting->bucket,
            ];
        }

        return [
            $setting->provider_code,
            'tenant_owned',
            null,
            $setting->bucket,
        ];
    }

    private function logAccess(CrmFile $crmFile, string $action, ?int $userId): void
    {
        FileAccessLog::create([
            'tenant_id'   => $crmFile->tenant_id,
            'crm_file_id' => $crmFile->id,
            'user_id'     => $userId,
            'action'      => $action,
            'ip_address'  => request()?->ip(),
            'user_agent'  => request()?->userAgent(),
        ]);
    }
}
