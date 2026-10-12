<?php

declare(strict_types=1);

namespace App\Services\Sms\Contracts;

use App\Models\SmsChannel;
use App\Models\SmsProviderAccount;
use App\Services\Sms\DTO\InboundSmsMessage;
use App\Services\Sms\DTO\OutboundSmsMessage;
use App\Services\Sms\DTO\OutboundSmsResult;
use App\Services\Sms\DTO\SmsStatusUpdate;

/**
 * Contrato que cualquier adaptador de proveedor SMS debe implementar
 * (DIDWW hoy; Twilio/Telnyx/SMS Masivos MX después). SmsService y los
 * controladores solo dependen de esta interfaz, nunca de un adapter concreto.
 */
interface SmsProviderInterface
{
    /** Clave usada en config/sms.php y en la ruta /api/webhooks/sms/{provider}/... */
    public static function providerKey(): string;

    /** Nombre visible en selects de UI (Add Provider Account, etc). */
    public static function label(): string;

    public function send(SmsChannel $channel, SmsProviderAccount $account, OutboundSmsMessage $message): OutboundSmsResult;

    /**
     * $account es opcional: se resuelve DESPUÉS de parsear el payload (el "to"
     * identifica el canal/tenant). Un proveedor futuro que necesite validar una
     * firma con el secreto de la cuenta antes de confiar en el payload puede
     * recibirla si el caller ya la conoce por contexto de ruta.
     */
    public function parseInbound(array $payload, ?SmsProviderAccount $account = null): InboundSmsMessage;

    public function parseStatusCallback(array $payload, ?SmsProviderAccount $account = null): SmsStatusUpdate;
}
