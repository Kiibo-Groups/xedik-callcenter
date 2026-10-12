<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\VoiceExtension;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VoiceExtensionProvisioningService
{
    public function __construct(
        private readonly AsteriskRealtimeService $asteriskRealtime
    ) {}

    public function generateEndpointId(
        VoiceExtension $extension
    ): string {
        $prefix = (string) config(
            'voice.stable_endpoint_prefix',
            'v'
        );

        $safeExtension = preg_replace(
            '/[^0-9A-Za-z]/',
            '',
            (string) $extension->extension
        );

        if (blank($safeExtension)) {
            throw new RuntimeException(
                'The voice extension cannot generate a valid endpoint ID.'
            );
        }

        return sprintf(
            '%s%s_u%s_%s',
            $prefix,
            $extension->tenant_id,
            $extension->user_id,
            $safeExtension
        );
    }

    public function provision(
        VoiceExtension $extension
    ): VoiceExtension {
        if (!$extension->is_active) {
            throw new RuntimeException(
                'The voice extension is inactive.'
            );
        }

        if (!$extension->tenant_id || !$extension->user_id) {
            throw new RuntimeException(
                'The voice extension must belong to a tenant and user.'
            );
        }

        $endpointId = $this->generateEndpointId($extension);

        try {
            $this->asteriskRealtime->ensureStableEndpoint(
                $extension,
                $endpointId,
                $this->makeInvalidPassword()
            );

            $extension->forceFill([
                'sip_username' => $endpointId,
                'pjsip_endpoint' => $endpointId,
                'pjsip_auth' => $endpointId,
                'pjsip_aor' => $endpointId,
                'pjsip_provisioned_at' => now(),
                'sip_invalidated_at' => now(),
                'sip_provisioning_status' => 'provisioned',
                'sip_provisioning_error' => null,
            ])->save();

            return $extension->fresh();
        } catch (Throwable $exception) {
            $extension->forceFill([
                'sip_provisioning_status' => 'failed',
                'sip_provisioning_error' => Str::limit(
                    $exception->getMessage(),
                    1000,
                    ''
                ),
            ])->save();

            throw $exception;
        }
    }

    private function makeInvalidPassword(): string
    {
        return 'invalid_' . rtrim(
            strtr(
                base64_encode(random_bytes(32)),
                '+/',
                '-_'
            ),
            '='
        );
    }
}