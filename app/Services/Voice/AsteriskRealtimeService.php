<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Models\VoiceExtension;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class AsteriskRealtimeService
{
    public function isConfigured(): bool
    {
        return filled(config('voice.asterisk_realtime_connection'));
    }

    public function connectionIsAvailable(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            return $this->connection()->getPdo() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function requiredTablesExist(): bool
    {
        if (!$this->connectionIsAvailable()) {
            return false;
        }

        try {
            $schema = $this->connection()->getSchemaBuilder();

            return $schema->hasTable($this->endpointsTable())
                && $schema->hasTable($this->authsTable())
                && $schema->hasTable($this->aorsTable());
        } catch (Throwable) {
            return false;
        }
    }

    public function health(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'connection_available' => $this->connectionIsAvailable(),
            'required_tables_exist' => $this->requiredTablesExist(),
            'connection' => $this->connectionName(),
            'tables' => [
                'endpoints' => $this->endpointsTable(),
                'auths' => $this->authsTable(),
                'aors' => $this->aorsTable(),
            ],
        ];
    }
    public function ensureStableEndpoint(VoiceExtension $extension, string $endpointId, string $password): void
    {
        if (!$this->requiredTablesExist()) {
            throw new \RuntimeException(
                'Asterisk Realtime connection or required tables are unavailable.'
            );
        }

        $context = $extension->dialplan_context
            ?: $extension->tenant?->voiceTenantSetting?->dialplan_context;

        if (blank($context)) {
            throw new \RuntimeException(
                'No dialplan context is configured for this voice extension.'
            );
        }

        $callerIdNumber = $extension->caller_id_number
            ?: $extension->extension;

        $callerIdName = $extension->caller_id_name
            ?: $extension->display_name
            ?: $extension->user?->name;

        $callerId = $callerIdName
            ? sprintf('"%s" <%s>', $callerIdName, $callerIdNumber)
            : $callerIdNumber;

        $this->connection()->transaction(function () use (
            $endpointId,
            $password,
            $context,
            $callerId
        ): void {
            $this->connection()
                ->table($this->aorsTable())
                ->updateOrInsert(
                    ['id' => $endpointId],
                    [
                        'max_contacts' => '1',
                        'default_expiration' => '60',
                        'maximum_expiration' => '3600',
                        'remove_existing' => 'yes',
                        'qualify_frequency' => '30',
                        'authenticate_qualify' => 'no',
                    ]
                );

            $this->connection()
                ->table($this->authsTable())
                ->updateOrInsert(
                    ['id' => $endpointId],
                    [
                        'auth_type' => 'userpass',
                        'username' => $endpointId,
                        'password' => $password,
                    ]
                );

            $this->connection()
                ->table($this->endpointsTable())
                ->updateOrInsert(
                    ['id' => $endpointId],
                    [
                        'transport' => config(
                            'voice.asterisk_transport',
                            'wss'
                        ),
                        'aors' => $endpointId,
                        'auth' => $endpointId,
                        'context' => $context,
                        'disallow' => 'all',
                        'allow' => 'opus,ulaw',
                        'webrtc' => 'yes',
                        'use_avpf' => 'yes',
                        'media_encryption' => 'dtls',
                        'dtls_auto_generate_cert' => 'yes',
                        'ice_support' => 'yes',
                        'rtcp_mux' => 'yes',
                        'rewrite_contact' => 'yes',
                        'force_rport' => 'yes',
                        'direct_media' => 'no',
                        'rtp_symmetric' => 'yes',
                        'media_use_received_transport' => 'yes',
                        'callerid' => $callerId,
                        'dtmf_mode' => 'rfc4733',
                        'allow_subscribe' => 'no',
                        'device_state_busy_at' => '1',
                        'aggregate_mwi' => 'no',
                    ]
                );
        });
    }
    /**
     * Update ONLY ps_endpoints.callerid for an already-provisioned endpoint.
     *
     * This is the minimal Asterisk sync needed after a personal Caller ID change:
     * it touches no other fields, no auths, no aors, and does not rotate passwords
     * or change pjsip_provisioned_at.
     *
     * Throws if:
     *  - The Asterisk connection is unavailable.
     *  - The extension has no pjsip_endpoint set (not yet provisioned).
     *  - The ps_endpoints row does not exist (needs full re-provision first).
     */
    public function updateEndpointCallerId(VoiceExtension $extension): void
    {
        if (!$this->requiredTablesExist()) {
            throw new \RuntimeException(
                'Asterisk Realtime connection or required tables are unavailable.'
            );
        }

        $endpointId = $extension->pjsip_endpoint;
        if (blank($endpointId)) {
            throw new \RuntimeException(
                'Voice extension has no provisioned endpoint ID. Provision the extension first.'
            );
        }

        $callerIdNumber = $extension->caller_id_number ?: $extension->extension;

        $callerIdName = $extension->caller_id_name
            ?: $extension->display_name
            ?: $extension->user?->name;

        $callerId = $callerIdName
            ? sprintf('"%s" <%s>', $callerIdName, $callerIdNumber)
            : $callerIdNumber;

        $rowsAffected = $this->connection()
            ->table($this->endpointsTable())
            ->where('id', $endpointId)
            ->update(['callerid' => $callerId]);

        if ($rowsAffected === 0) {
            throw new \RuntimeException(
                "Asterisk endpoint [{$endpointId}] does not exist. Re-provision the extension first."
            );
        }
    }

    /**
     * Remove all PJSIP Realtime rows for an endpoint (deprovision on user deletion).
     * Deletes from ps_endpoints, ps_auths, ps_aors in a single transaction.
     * Throws if Asterisk connection is unavailable.
     */
    public function deprovisionEndpoint(string $endpointId): void
    {
        if (!$this->requiredTablesExist()) {
            throw new \RuntimeException(
                'Asterisk Realtime connection or required tables are unavailable.'
            );
        }

        $this->connection()->transaction(function () use ($endpointId): void {
            $this->connection()->table($this->endpointsTable())->where('id', $endpointId)->delete();
            $this->connection()->table($this->authsTable())->where('id', $endpointId)->delete();
            $this->connection()->table($this->aorsTable())->where('id', $endpointId)->delete();
        });
    }

    public function updateAuthPassword(
        string $authId,
        string $username,
        string $password,
        ?VoicePerformanceMeasurement $performance = null
    ): void {
        $totalStartedAt = hrtime(true);
        $queryCompletedAt = null;

        try {
            try {
                $connectionStartedAt = hrtime(true);
                $connection = VoicePerformanceMeasurement::capture(
                    $performance,
                    'realtime_connection_acquire_ms',
                    function (): ?ConnectionInterface {
                        if (!$this->connectionIsAvailable()) {
                            return null;
                        }

                        return $this->connection();
                    }
                );
                $performance?->recordDuration(
                    'asterisk_realtime_connection_ms',
                    (hrtime(true) - $connectionStartedAt) / 1_000_000
                );

                $performance?->recordDuration(
                    'asterisk_table_checks_ms',
                    0.0
                );
                $performance?->addContext([
                    'realtime_table_metadata_check_skipped' => true,
                ]);
            } catch (Throwable) {
                $connection = null;
            }

            if (!$connection) {
                throw new \RuntimeException(
                    'Asterisk Realtime connection is unavailable.'
                );
            }

            $query = VoicePerformanceMeasurement::capture(
                $performance,
                'realtime_before_query_ms',
                fn () => $connection
                    ->table($this->authsTable())
                    ->where('id', $authId)
            );

            $endpointValidationStartedAt = hrtime(true);
            $rowsAffected = VoicePerformanceMeasurement::capture(
                $performance,
                'realtime_query_ms',
                fn (): int => $query->update([
                    'auth_type' => 'userpass',
                    'username' => $username,
                    'password' => $password,
                ])
            );
            $performance?->recordDuration(
                'endpoint_provision_or_validation_ms',
                (hrtime(true) - $endpointValidationStartedAt) / 1_000_000
            );

            if ($rowsAffected !== 1) {
                throw new \RuntimeException(
                    "Asterisk auth row [{$authId}] was not updated."
                );
            }
            $queryCompletedAt = hrtime(true);

            $performance?->addContext([
                'realtime_connection_name' => $this->connectionName(),
                'realtime_operation_type' => 'update_ps_auths',
                'realtime_rows_affected' => $rowsAffected,
            ]);
        } finally {
            if ($queryCompletedAt !== null) {
                $performance?->recordDuration(
                    'realtime_after_query_ms',
                    (hrtime(true) - $queryCompletedAt) / 1_000_000
                );
            }

            $performance?->recordSince(
                'realtime_total_ms',
                $totalStartedAt
            );
        }
    }
    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    private function connectionName(): string
    {
        return (string) config(
            'voice.asterisk_realtime_connection',
            'asterisk_realtime'
        );
    }

    private function endpointsTable(): string
    {
        return (string) config(
            'voice.asterisk_tables.endpoints',
            'ps_endpoints'
        );
    }

    private function authsTable(): string
    {
        return (string) config(
            'voice.asterisk_tables.auths',
            'ps_auths'
        );
    }

    private function aorsTable(): string
    {
        return (string) config(
            'voice.asterisk_tables.aors',
            'ps_aors'
        );
    }
}
