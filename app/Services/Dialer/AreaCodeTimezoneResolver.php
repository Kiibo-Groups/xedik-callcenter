<?php

declare(strict_types=1);

namespace App\Services\Dialer;

use App\Models\CallingAreaCode;

/**
 * Resolves a NANP phone number to its IANA timezone via the calling_area_codes
 * reference table. Only rows with status=in_service and is_active=true are
 * used for dialing decisions — "planned"/inactive rows are import-only.
 */
class AreaCodeTimezoneResolver
{
    // Request-level cache: one instance of this service is shared across every
    // candidate evaluated within a single claim()/eligibilityFor() call, so a
    // campaign with many leads sharing an area code hits the DB once per code,
    // not once per lead. Cleared automatically when the request ends (this
    // service is not bound as a singleton).
    private array $rowCache = [];

    /**
     * Extract the 3-digit NANP area code from a US phone number.
     * Accepts +1XXXXXXXXXX, 1XXXXXXXXXX, XXXXXXXXXX. Returns null otherwise.
     */
    public static function parseAreaCode(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1, 3);
        }

        if (strlen($digits) === 10) {
            return substr($digits, 0, 3);
        }

        return null;
    }

    /**
     * Look up the IANA timezone for an area code. Returns null when the area
     * code is unknown, inactive, or not in_service.
     */
    public function timezoneForAreaCode(string $areaCode): ?string
    {
        return $this->rowForAreaCode($areaCode)?->time_zone;
    }

    /**
     * Look up the full dialing-relevant row (timezone + state) for an area
     * code. Returns null under the same conditions as timezoneForAreaCode().
     * Used by CallingHoursComplianceService for the Phase 2 state-specific
     * guard, which needs state_code alongside the timezone.
     */
    public function rowForAreaCode(string $areaCode): ?object
    {
        if (array_key_exists($areaCode, $this->rowCache)) {
            return $this->rowCache[$areaCode];
        }

        return $this->rowCache[$areaCode] = CallingAreaCode::query()
            ->where('area_code', $areaCode)
            ->where('is_active', true)
            ->where('status', 'in_service')
            ->first(['time_zone', 'state_code']);
    }

    /**
     * Convenience: parse the phone and resolve its timezone in one call.
     */
    public function timezoneForPhone(string $phone): ?string
    {
        $areaCode = self::parseAreaCode($phone);

        return $areaCode ? $this->timezoneForAreaCode($areaCode) : null;
    }
}
