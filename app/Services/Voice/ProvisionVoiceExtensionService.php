<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\VoiceExtension;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thin orchestrator: load VoiceExtension by ID, guard idempotency, delegate
 * to VoiceExtensionProvisioningService.  Propagates exceptions so
 * admin/HTTP callers can wrap it in try/catch and handle failures gracefully.
 *
 * All provisioning is synchronous — called via provisionSync() in controllers.
 * Does NOT depend on the queue subsystem.
 */
class ProvisionVoiceExtensionService
{
    public function __construct(
        private readonly VoiceExtensionProvisioningService $provisioner
    ) {}

    /**
     * Find the extension and provision it synchronously.
     *
     * Returns silently if the extension is not found or is inactive (idempotent).
     * Throws on Asterisk/provisioning failure — VoiceExtensionProvisioningService
     * already marks the extension as 'failed' before re-throwing.
     *
     * @throws Throwable
     */
    public function provision(int $extensionId): void
    {
        $extension = VoiceExtension::withoutGlobalScopes()->find($extensionId);

        if (!$extension) {
            Log::info("ProvisionVoiceExtensionService: extension {$extensionId} not found, skipping.");
            return;
        }

        if (!$extension->is_active) {
            Log::info(
                "ProvisionVoiceExtensionService: extension {$extensionId}"
                . " ({$extension->extension}) is inactive, skipping."
            );
            return;
        }

        $this->provisioner->provision($extension);

        Log::info(
            "ProvisionVoiceExtensionService: provisioned extension {$extension->extension}"
            . " (tenant {$extension->tenant_id}, user {$extension->user_id})"
        );
    }
}
