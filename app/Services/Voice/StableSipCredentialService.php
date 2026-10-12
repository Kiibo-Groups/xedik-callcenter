<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\VoiceExtension;
use RuntimeException;

class StableSipCredentialService
{
    public function __construct(
        private readonly AsteriskRealtimeService $asteriskRealtime
    ) {}

    public function rotatePassword(
        VoiceExtension $extension,
        ?VoicePerformanceMeasurement $performance = null
    ): array {
        $startedAt = hrtime(true);

        try {
            if (!$extension->is_active) {
                throw new RuntimeException(
                    'The voice extension is inactive.'
                );
            }

            if (
                blank($extension->sip_username)
                || blank($extension->pjsip_auth)
            ) {
                throw new RuntimeException(
                    'The voice extension is not provisioned.'
                );
            }

            $password = $this->makePassword();

            VoicePerformanceMeasurement::capture(
                $performance,
                'asterisk_realtime_update_ms',
                fn () => $this->asteriskRealtime->updateAuthPassword(
                    (string) $extension->pjsip_auth,
                    (string) $extension->sip_username,
                    $password,
                    $performance
                )
            );

            $extension->forceFill([
                'sip_password_rotated_at' => now(),
                'sip_invalidated_at' => null,
            ])->save();

            return [
                'extension' => $extension->fresh(),
                'password' => $password,
            ];
        } finally {
            $performance?->recordSince(
                'credential_rotation_total_ms',
                $startedAt
            );
        }
    }

    public function invalidatePassword(
        VoiceExtension $extension
    ): void {
        if (
            blank($extension->sip_username)
            || blank($extension->pjsip_auth)
        ) {
            return;
        }

        $invalidPassword = $this->makeInvalidPassword();

        $this->asteriskRealtime->updateAuthPassword(
            (string) $extension->pjsip_auth,
            (string) $extension->sip_username,
            $invalidPassword
        );

        $extension->forceFill([
            'sip_invalidated_at' => now(),
        ])->save();
    }

    private function makePassword(): string
    {
        return rtrim(
            strtr(
                base64_encode(random_bytes(32)),
                '+/',
                '-_'
            ),
            '='
        );
    }

    private function makeInvalidPassword(): string
    {
        return 'invalid_' . $this->makePassword();
    }
}
