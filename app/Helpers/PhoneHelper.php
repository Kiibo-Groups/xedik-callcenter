<?php
declare(strict_types=1);

namespace App\Helpers;

class PhoneHelper
{
    /**
     * Normalize a phone number to its last N significant digits for DB storage
     * and cross-comparison.  Returns only digits; never E.164.
     *
     * Examples (MX 10-digit):
     *   "521XXXXXXXXXX" (13d) → "XXXXXXXXXX"
     *   "52XXXXXXXXXX"  (12d) → "XXXXXXXXXX"
     *   "1XXXXXXXXXX"   (11d, US) → "XXXXXXXXXX"
     *   "XXXXXXXXXX"    (10d) → "XXXXXXXXXX"
     *
     * Safe to call on partial strings, SIP URI user parts, or display values.
     * Always returns digits only; returns the raw cleaned input if no rule matches.
     */
    public static function normalizeForSearch(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone);
        if ($clean === '') {
            return '';
        }

        $rules = [
            ['prefix' => '521', 'digits' => 13, 'keep' => 10],
            ['prefix' => '52',  'digits' => 12, 'keep' => 10],
            ['prefix' => '1',   'digits' => 11, 'keep' => 10],
        ];

        foreach ($rules as $r) {
            if (str_starts_with($clean, $r['prefix']) && strlen($clean) === $r['digits']) {
                return substr($clean, -$r['keep']);
            }
        }

        // If it's already 10 digits or no rule matched, return as-is
        return $clean;
    }

    public static function formatDisplay(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone);

        $prefixes = [
            ['prefix' => '521', 'digits' => 13], // México celular
            ['prefix' => '52',  'digits' => 12], // México fijo
            ['prefix' => '1',   'digits' => 11], // Estados Unidos
            // ['prefix' => '34', 'digits' => 11], // España
            // ['prefix' => '57', 'digits' => 12], // Colombia
        ];

        foreach ($prefixes as $p) {
            if (str_starts_with($clean, $p['prefix']) && strlen($clean) === $p['digits']) {
                return substr($clean, strlen($p['prefix']));
            }
        }

        return $clean;
    }

    /**
     * Normalize a phone value for ANI/DNIS reporting.
     *
     * This is stricter than normalizeForSearch(): it removes a leading NANP
     * country code only when the value is exactly 1 + 10 digits. International
     * values are preserved as digits and are not truncated to a suffix.
     */
    public static function normalizeNanpForReporting(null|string|int $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $clean = preg_replace('/\D/', '', (string) $phone);
        if ($clean === '') {
            return '';
        }

        if (strlen($clean) === 11 && str_starts_with($clean, '1')) {
            return substr($clean, 1);
        }

        return $clean;
    }

    /**
     * Normalize queue-log ANI/DID values to the national 10-digit format used
     * by current US/MX reporting.
     */
    public static function normalizeTelephonyReportingNumber(null|string|int $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return substr($digits, 2);
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            return substr($digits, -10);
        }

        return null;
    }

    /**
     * Compone el destino final para envío SMS: country_calling_code + national_number,
     * fórmula genérica — SIN ninguna regla de "521 México móvil" (esa convención
     * es específica de WhatsApp/Meta, no de SMS/DIDWW). Nunca antepone un "1"
     * salvo que el country code seleccionado sea literalmente "1" (US/CA).
     *
     * Tolera que $nationalNumber llegue con el country code ya pegado al frente
     * (el usuario pegó un número completo, con o sin '+') y lo desduplica en vez
     * de concatenar de nuevo — nunca antepone dígitos que el usuario no escribió.
     * También reduce el legacy "52" + "1" + 10 dígitos (13 en total, la
     * convención de WhatsApp para móviles MX) a "52" + 10 si llega por error
     * en el campo de número nacional.
     *
     * @throws \InvalidArgumentException si el country code no es solo dígitos,
     *         o si el número nacional no puede reducirse al largo esperado para
     *         un country code conocido (hoy: "1" y "52", ambos 10 dígitos).
     */
    public static function composeInternationalDestination(string $countryCode, string $nationalNumber): string
    {
        $countryCode = trim($countryCode);
        if ($countryCode === '' || !ctype_digit($countryCode)) {
            throw new \InvalidArgumentException("Invalid country code: '{$countryCode}'.");
        }

        // Quita espacios, guiones, paréntesis y '+' de una sola vez.
        $digits = preg_replace('/\D/', '', $nationalNumber) ?? '';

        $expectedLength = match ($countryCode) {
            '1', '52' => 10,
            default => null,
        };

        if ($expectedLength !== null) {
            // Legacy MX móvil (52 + 1 + 10 = 13 dígitos, convención de WhatsApp) —
            // se acepta como entrada heredada y se reduce a 52 + 10; nunca se
            // vuelve a generar este formato.
            if ($countryCode === '52' && strlen($digits) === 13 && str_starts_with($digits, '521')) {
                $digits = substr($digits, 3);
            }
            // El national number ya trae el country code pegado al frente
            // (paste de un número completo, con o sin '+' — el '+' ya se limpió).
            elseif (strlen($digits) === strlen($countryCode) + $expectedLength && str_starts_with($digits, $countryCode)) {
                $digits = substr($digits, strlen($countryCode));
            }

            // Salvaguarda final: si aún sobran dígitos, quedarse con los últimos
            // N (nunca anteponer nada) — el resultado nunca debe salir más largo
            // que lo esperado.
            if (strlen($digits) > $expectedLength) {
                $digits = substr($digits, -$expectedLength);
            }

            if (strlen($digits) !== $expectedLength) {
                throw new \InvalidArgumentException(
                    "Expected a {$expectedLength}-digit national number for country code {$countryCode}, got " . strlen($digits) . ' digits.'
                );
            }
        }

        return $countryCode . $digits;
    }

    /**
     * Resuelve el country calling code ("52"/"1") de un SmsChannel para
     * recomponer el destination en un reply — el reply nunca recibe el país
     * del request (a diferencia de New SMS, donde el agente lo elige en el
     * modal), así que debe derivarlo del propio canal:
     *
     * 1) `sms_channels.country` (ISO "MX"/"US"/"CA") si el superadmin lo
     *    configuró — mapeado a su calling code.
     * 2) Si no está configurado, se deriva del propio `sms_channels.address`
     *    (el número real del canal ya contiene su country code como
     *    prefijo — mismo criterio de longitud/prefijo que normalizeForSearch()) —
     *    esto hace que canales ya existentes sin `country` configurado
     *    sigan funcionando sin requerir un paso manual de administración.
     *
     * @throws \InvalidArgumentException si ninguna de las dos fuentes permite
     *         determinar el calling code — nunca adivina un país por defecto.
     */
    public static function callingCodeForChannel(string $channelAddress, ?string $isoCountry = null): string
    {
        $isoMap = ['MX' => '52', 'US' => '1', 'CA' => '1'];
        $iso = strtoupper(trim((string) $isoCountry));
        if (isset($isoMap[$iso])) {
            return $isoMap[$iso];
        }

        $digits = preg_replace('/\D/', '', $channelAddress) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return '52';
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '1';
        }

        throw new \InvalidArgumentException(
            "Cannot determine SMS calling code for channel address '{$channelAddress}' — configure sms_channels.country."
        );
    }

    public static function formatNanpDisplay(null|string|int $phone): string
    {
        $normalized = self::normalizeNanpForReporting($phone);
        if ($normalized === null || $normalized === '') {
            return '';
        }

        if (preg_match('/^\d{10}$/', $normalized)) {
            return sprintf(
                '(%s) %s-%s',
                substr($normalized, 0, 3),
                substr($normalized, 3, 3),
                substr($normalized, 6)
            );
        }

        return $normalized;
    }
}
