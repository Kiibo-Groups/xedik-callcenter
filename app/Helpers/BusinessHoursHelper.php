<?php
declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;

class BusinessHoursHelper
{
    /**
     * El formato almacenado en DB es:
     * ['mon' => ['open' => '07:00', 'close' => '17:00', 'enabled' => '1'], ...]
     * Días cerrados pueden ser null O tener enabled='0'.
     */
    public static function isOnline(array $hours, string $timezone): bool
    {
        if (empty($hours)) return true;

        $now = Carbon::now($timezone);
        $key = strtolower(substr($now->englishDayOfWeek, 0, 3));

        $dayData = $hours[$key] ?? null;

        if (empty($dayData)) return false;
        if (($dayData['enabled'] ?? '1') === '0') return false;

        [$openH,  $openM]  = array_map('intval', explode(':', $dayData['open']  ?? '00:00'));
        [$closeH, $closeM] = array_map('intval', explode(':', $dayData['close'] ?? '00:00'));
        $openTotal  = $openH  * 60 + $openM;
        $closeTotal = $closeH * 60 + $closeM;
        $currentTotal = (int) $now->format('G') * 60 + (int) $now->format('i');

        return $currentTotal >= $openTotal && $currentTotal < $closeTotal;
    }
}
