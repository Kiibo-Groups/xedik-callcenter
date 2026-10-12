<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Detección aproximada GSM-7 vs Unicode y conteo de segmentos SMS.
 * GSM-7: 160 chars en 1 segmento, 153 concatenado (7 reservados para el header UDH).
 * Unicode (UCS-2): 70 chars en 1 segmento, 67 concatenado.
 */
class SmsSegmentHelper
{
    private const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM7_EXTENDED = "^{}\\[~]|€";

    public static function isGsm7(string $text): bool
    {
        foreach (mb_str_split($text) as $char) {
            if (!str_contains(self::GSM7_BASIC, $char) && !str_contains(self::GSM7_EXTENDED, $char)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{encoding:string,length:int,segments:int}
     */
    public static function analyze(string $text): array
    {
        if ($text === '') {
            return ['encoding' => 'gsm7', 'length' => 0, 'segments' => 0];
        }

        $isGsm7 = self::isGsm7($text);
        $length = self::effectiveLength($text, $isGsm7);

        $singleLimit = $isGsm7 ? 160 : 70;
        $concatLimit = $isGsm7 ? 153 : 67;

        $segments = $length <= $singleLimit ? 1 : (int) ceil($length / $concatLimit);

        return [
            'encoding' => $isGsm7 ? 'gsm7' : 'ucs2',
            'length'   => $length,
            'segments' => $segments,
        ];
    }

    private static function effectiveLength(string $text, bool $isGsm7): int
    {
        if (!$isGsm7) {
            return mb_strlen($text);
        }

        $len = 0;
        foreach (mb_str_split($text) as $char) {
            // Caracteres GSM-7 extendidos ocupan 2 posiciones (escape + char).
            $len += str_contains(self::GSM7_EXTENDED, $char) ? 2 : 1;
        }

        return $len;
    }
}
