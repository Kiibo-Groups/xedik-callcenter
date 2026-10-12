<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * Fuente única de verdad para exponer sent_at al frontend de la consola —
 * carga inicial, refetch y realtime deben pasar por aquí para no volver a
 * divergir en formato (esa divergencia fue la causa del separador de fecha
 * duplicado y de la hora inconsistente entre canales). El frontend nunca debe
 * derivar la hora "oficial" con Date/getFullYear/getMonth/getDate/toLocale*
 * — esos dependen de la timezone del navegador del agente, no del tenant.
 */
class MessageTimePresenter
{
    /**
     * Timezone IANA del tenant, leída de tenants.settings->timezone (JSON,
     * sin migración nueva — mismo patrón que otros ajustes por tenant en esta
     * tabla). Fallback a config('app.timezone') si el tenant no la configuró
     * o el valor guardado no es un identificador de timezone válido.
     */
    public static function resolveTenantTimezone(?Tenant $tenant): string
    {
        $settings = $tenant?->settings;
        $tz = is_array($settings) ? ($settings['timezone'] ?? null) : null;

        if (is_string($tz) && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return $tz;
        }

        return config('app.timezone', 'America/Hermosillo');
    }

    /**
     * @return array{sent_at_iso: ?string, sent_at_time: ?string, sent_at_date: ?string, sent_at_day: ?string, timezone: string}
     */
    public static function present(?CarbonInterface $sentAt, string $timezone): array
    {
        if ($sentAt === null) {
            return [
                'sent_at_iso'  => null,
                'sent_at_time' => null,
                'sent_at_date' => null,
                'sent_at_day'  => null,
                'timezone'     => $timezone,
            ];
        }

        $local = $sentAt->copy()->setTimezone($timezone);

        return [
            'sent_at_iso'  => $local->format('Y-m-d\TH:i:sP'),
            'sent_at_time' => $local->format('g:i A'),
            'sent_at_date' => $local->format('M j, Y'),
            'sent_at_day'  => $local->format('Y-m-d'),
            'timezone'     => $timezone,
        ];
    }
}
