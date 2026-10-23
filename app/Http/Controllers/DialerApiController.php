<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DialerApiController extends Controller
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('dialer.url'), '/');
    }

    /**
     * POST /dialer/call/{lead}
     * Inicia una llamada desde el dialer al teléfono del lead
     * Usa el phone_user_id del agente autenticado
     */
    public function makeCall(Lead $lead)
    {
        $user = auth()->user();

        if (empty($user->phone_user_id)) {
            return response()->json([
                'error' => 'Your account is not linked to a dialer agent. Ask your admin to set your Phone User ID.'
            ], 422);
        }

        if (empty($lead->phone)) {
            return response()->json(['error' => 'Lead has no phone number.'], 422);
        }

        // Solo el agente asignado o admin puede iniciar la llamada
        if (!$user->isAdmin() && $lead->assigned_to !== $user->id) {
            return response()->json(['error' => 'You are not assigned to this lead.'], 403);
        }

        try {
            $token = $this->getToken();

            if (!$token) {
                return response()->json(['error' => 'Could not authenticate with dialer.'], 503);
            }

            $phone        = preg_replace('/\D/', '', $lead->phone);
            $phoneUserId  = $user->phone_user_id;
            $url          = "{$this->baseUrl}/make/user/{$phoneUserId}/call/{$phone}";

            Log::info("Dialer makeCall: agent={$phoneUserId} phone={$phone}");

            $response = Http::withToken($token)->post($url);

            Log::info("Dialer makeCall response [{$response->status()}]: " . $response->body());

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Call initiated — your phone will ring shortly.',
                ]);
            }

            return response()->json([
                'error' => 'Dialer returned error: ' . $response->status()
            ], 502);

        } catch (\Exception $e) {
            Log::error("DialerApi makeCall error: " . $e->getMessage());
            return response()->json(['error' => 'Could not initiate call.'], 500);
        }
    }

    /**
     * GET /dialer/recording/{call}
     * Devuelve el audio de la grabación para el player
     */
    public function recording(Call $call)
    {
        if (empty($call->recording_id)) {
            return response()->json(['error' => 'No recording for this call'], 404);
        }

        // 1. Soporte para grabaciones de ElevenLabs Voicebot (conv_...)
        if (str_starts_with($call->recording_id, 'conv_')) {
            try {
                $apiKey = env('ELEVENLABS_API_KEY', 'sk_e4cbd19dec8a1303347c477783f6c7c3d850ffecb0be4437');
                $response = Http::withHeaders(['xi-api-key' => $apiKey])
                    ->get("https://api.elevenlabs.io/v1/convai/conversations/{$call->recording_id}/audio");

                if ($response->successful()) {
                    return response($response->body(), 200, [
                        'Content-Type'  => 'audio/mpeg',
                        'Accept-Ranges' => 'bytes',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Error descargando audio de ElevenLabs: " . $e->getMessage());
            }
        }

        // 2. Soporte para grabaciones locales de Asterisk
        $diskPath = "/var/spool/asterisk/monitor/{$call->recording_id}";
        if (!file_exists($diskPath) && file_exists("{$diskPath}.wav")) {
            $diskPath = "{$diskPath}.wav";
        }
        if (file_exists($diskPath)) {
            return response()->file($diskPath, [
                'Content-Type'  => 'audio/wav',
                'Accept-Ranges' => 'bytes',
            ]);
        }

        try {
            $token = $this->getToken();

            if (!$token) {
                return response()->json(['error' => 'Could not authenticate with dialer'], 503);
            }

            $url = "{$this->baseUrl}/recording/log/uid/{$call->recording_id}/num/1/file";

            $response = Http::withToken($token)
                ->withOptions(['allow_redirects' => false])
                ->get($url);

            if ($response->status() === 302 || $response->status() === 301) {
                return response()->json(['url' => $response->header('Location')]);
            }

            if ($response->successful()) {
                return response($response->body(), 200, [
                    'Content-Type'  => $response->header('Content-Type', 'audio/mpeg'),
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            return response()->json(['error' => 'Recording not found'], 404);

        } catch (\Exception $e) {
            Log::error("DialerApi recording error: " . $e->getMessage());
            return response()->json(['error' => 'Could not fetch recording'], 500);
        }
    }
    public function recordingByUid(string $uid)
    {
        // 1. Soporte para grabaciones de ElevenLabs Voicebot (conv_...)
        if (str_starts_with($uid, 'conv_')) {
            try {
                $apiKey = env('ELEVENLABS_API_KEY', 'sk_e4cbd19dec8a1303347c477783f6c7c3d850ffecb0be4437');
                $response = Http::withHeaders(['xi-api-key' => $apiKey])
                    ->get("https://api.elevenlabs.io/v1/convai/conversations/{$uid}/audio");

                if ($response->successful()) {
                    return response($response->body(), 200, [
                        'Content-Type'  => 'audio/mpeg',
                        'Accept-Ranges' => 'bytes',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Error descargando audio de ElevenLabs por UID: " . $e->getMessage());
            }
        }

        // 2. Soporte para grabaciones locales de Asterisk
        $diskPath = "/var/spool/asterisk/monitor/{$uid}";
        if (!file_exists($diskPath) && file_exists("{$diskPath}.wav")) {
            $diskPath = "{$diskPath}.wav";
        }
        if (file_exists($diskPath)) {
            return response()->file($diskPath, [
                'Content-Type'  => 'audio/wav',
                'Accept-Ranges' => 'bytes',
            ]);
        }

        try {
            $token = $this->getToken();

            if (!$token) {
                return response()->json(['error' => 'Could not authenticate with dialer'], 503);
            }

            $url = "{$this->baseUrl}/recording/log/uid/{$uid}/num/1/file";

            $response = Http::withToken($token)
                ->withOptions(['allow_redirects' => false])
                ->get($url);

            if ($response->status() === 302 || $response->status() === 301) {
                return redirect($response->header('Location'));
            }

            if ($response->successful()) {
                return response($response->body(), 200, [
                    'Content-Type'  => $response->header('Content-Type', 'audio/mpeg'),
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            return response()->json(['error' => 'Recording not found'], 404);
        } catch (\Exception $e) {
            Log::error("DialerApi recordingByUid error: " . $e->getMessage());
            return response()->json(['error' => 'Could not fetch recording'], 500);
        }
    }

    /**
     * Obtener token — cacheado 9 horas (expira en 10)
     */
    private function getToken(): ?string
    {
        return Cache::remember('dialer_api_token', 9 * 60 * 60, function () {
            try {
                $response = Http::post("{$this->baseUrl}/oauth/pass", [
                    'client_id'     => config('dialer.client_id'),
                    'client_secret' => config('dialer.client_secret'),
                    'username'      => config('dialer.username'),
                    'password'      => config('dialer.password'),
                ]);

                if ($response->successful()) {
                    Log::info('Dialer API token refreshed');
                    return $response->json('access_token');
                }

                Log::error('Dialer API auth failed: ' . $response->status());
                return null;

            } catch (\Exception $e) {
                Log::error('Dialer API auth error: ' . $e->getMessage());
                return null;
            }
        });
    }
}
