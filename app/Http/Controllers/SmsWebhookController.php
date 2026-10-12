<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Sms\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Webhook delgado — nunca conoce un adaptador de proveedor concreto. Solo
 * resuelve el {provider} de la URL y delega a SmsService, que sí sabe cómo
 * pedirle al SmsProviderManager el adaptador correcto (DIDWW hoy, Twilio/
 * Telnyx/SMS Masivos MX después sin tocar este archivo).
 * SIEMPRE responde 200 rápido — incluidos providers desconocidos y callbacks
 * no correlacionados — para no generar reintentos infinitos del lado del proveedor.
 */
class SmsWebhookController extends Controller
{
    public function __construct(private readonly SmsService $smsService) {}

    public function inbound(Request $request, string $provider)
    {
        if ($this->rateLimited($request, "sms_inbound:{$provider}")) {
            return response('OK', 200);
        }

        try {
            $this->smsService->handleInbound($provider, $request->all());
        } catch (\Throwable $e) {
            Log::error("SmsWebhookController: inbound error for provider={$provider} — " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('OK', 200);
    }

    public function status(Request $request, string $provider)
    {
        if ($this->rateLimited($request, "sms_status:{$provider}")) {
            return response('OK', 200);
        }

        try {
            $this->smsService->handleStatusCallback($provider, $request->all());
        } catch (\Throwable $e) {
            Log::error("SmsWebhookController: status callback error for provider={$provider} — " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('OK', 200);
    }

    private function rateLimited(Request $request, string $keyPrefix): bool
    {
        $key = $keyPrefix . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 500)) {
            Log::warning("SmsWebhookController: rate limit exceeded for {$keyPrefix}", ['ip' => $request->ip()]);
            return true;
        }
        RateLimiter::hit($key, 60);
        return false;
    }
}
