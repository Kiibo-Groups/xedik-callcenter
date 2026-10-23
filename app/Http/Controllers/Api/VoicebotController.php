<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoicebotController extends Controller
{
    /**
     * Registrar resultado de llamada del Voicebot IA y agendar cita en el CRM
     */
    public function logCall(Request $request): JsonResponse
    {
        $phone = preg_replace('/[^0-9]/', '', (string)$request->input('phone', ''));
        $last10 = strlen($phone) >= 10 ? substr($phone, -10) : $phone;
        
        $firstName = trim((string)$request->input('first_name', ''));
        $lastName = trim((string)$request->input('last_name', ''));
        $notes = trim((string)$request->input('notes', ''));
        $transcript = trim((string)$request->input('transcript', ''));
        $duration = (int)$request->input('duration', 0);
        $scheduledAtStr = $request->input('scheduled_at');
        $isScheduled = $request->boolean('is_scheduled');
        $recordingId = trim((string)$request->input('recording_id', ''));
        $tenantId = 2; // Tenant 2: Xedik / ITSAAR

        // 1. Obtener métricas de sentimiento, frustración y resumen si viene recording_id de ElevenLabs
        $sentimentLabel = 'Neutral';
        $frustrationScore = 0;
        $callSummary = '';
        $mins = floor($duration / 60);
        $secs = $duration % 60;
        $durFormatted = ($mins > 0 ? "{$mins}m " : "") . "{$secs}s";

        if (!empty($recordingId) && str_starts_with($recordingId, 'conv_')) {
            try {
                $apiKey = env('ELEVENLABS_API_KEY', 'sk_e4cbd19dec8a1303347c477783f6c7c3d850ffecb0be4437');
                $elResp = Http::withHeaders(['xi-api-key' => $apiKey])
                    ->timeout(4)
                    ->get("https://api.elevenlabs.io/v1/convai/conversations/{$recordingId}");

                if ($elResp->successful()) {
                    $convData = $elResp->json();
                    $analysis = $convData['analysis'] ?? [];
                    $sentiment = $analysis['sentiment_analysis'] ?? [];
                    $sentimentLabel = ucfirst($sentiment['overall_label'] ?? 'Neutral');
                    $frustrationScore = round(($sentiment['overall_frustration_score'] ?? 0) * 100);
                    $callSummary = $analysis['transcript_summary'] ?? ($analysis['call_summary_title'] ?? '');
                }
            } catch (\Throwable $e) {
                Log::warning("No se pudo obtener analisis de ElevenLabs", ['error' => $e->getMessage()]);
            }
        }

        $headerNotes = "📊 Resumen IA: " . ($callSummary ?: ($notes ?: 'Llamada comercial Marto')) . "\n"
                     . "⏱ Duración: {$durFormatted} ({$duration} seg)\n"
                     . "😊 Sentimiento del cliente: {$sentimentLabel} | Nivel de frustración: {$frustrationScore}%\n"
                     . (!empty($recordingId) ? "🎧 Grabación: Disponible en el reproductor\n\n" : "\n");

        // 2. Buscar o crear Lead
        $lead = null;
        if (!empty($last10)) {
            $lead = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($last10) {
                    $q->where('phone', 'like', "%{$last10}%")
                      ->orWhere('phone_2', 'like', "%{$last10}%");
                })
                ->first();
        }

        if (!$lead) {
            $lead = new Lead();
            $lead->tenant_id = $tenantId;
            $lead->first_name = $firstName ?: 'Prospecto';
            $lead->last_name = $lastName ?: 'Voz IA';
            $lead->phone = $phone ?: '8122140136';
            $lead->status = $isScheduled ? 'qualified' : 'contacted';
            $lead->source = 'AI Voicebot';
            $lead->campaign_id = 3; // ITSAAR Sales
            $lead->assigned_to = 10; // Ariel Quezada
            $lead->notes = "[Llamada IA Marto | {$sentimentLabel} | {$durFormatted}]: " . ($callSummary ?: $notes);
            $lead->save();
        } else {
            if (!empty($firstName) && ($lead->first_name === 'Prospecto' || empty($lead->first_name))) {
                $lead->first_name = $firstName;
                if (!empty($lastName)) {
                    $lead->last_name = $lastName;
                }
            }
            if ($isScheduled) {
                $lead->status = 'qualified';
            }
            $lead->notes = ($lead->notes ? $lead->notes . "\n\n" : '') . "[Llamada IA Marto | {$sentimentLabel} | {$durFormatted}]: " . ($callSummary ?: $notes);
            $lead->save();
        }

        // 3. Parsear fecha de cita si aplica
        $scheduledAt = null;
        if ($isScheduled && !empty($scheduledAtStr)) {
            try {
                $scheduledAt = Carbon::parse($scheduledAtStr);
            } catch (\Throwable $e) {
                Log::warning("No se pudo parsear fecha programada: {$scheduledAtStr}", ['error' => $e->getMessage()]);
                $scheduledAt = Carbon::now()->addDays(2)->setHour(16)->setMinute(0)->setSecond(0);
            }
        }

        // 4. Registrar llamada con recording_id
        $call = new Call();
        $call->tenant_id = $tenantId;
        $call->lead_id = $lead->id;
        $call->agent_id = 10;
        $call->disposition = $isScheduled ? 'Qualified' : 'Contacted';
        $call->direction = 'inbound';
        $call->called_at = Carbon::now();
        $call->duration = $duration;
        $call->recording_id = $recordingId ?: null;
        $call->notes = $headerNotes . ($notes ? "Detalles: {$notes}\n\n" : '') . "Transcripción:\n" . $transcript;
        $call->is_scheduled = $isScheduled;
        $call->scheduled_at = $scheduledAt;
        $call->completed = false;
        $call->save();

        Log::info("Llamada Voicebot registrada exitosamente en CRM", [
            'lead_id' => $lead->id,
            'call_id' => $call->id,
            'recording_id' => $call->recording_id,
            'sentiment' => $sentimentLabel,
            'is_scheduled' => $isScheduled,
            'scheduled_at' => $scheduledAt?->toDateTimeString()
        ]);

        return response()->json([
            'success' => true,
            'lead_id' => $lead->id,
            'call_id' => $call->id,
            'recording_id' => $call->recording_id,
            'sentiment' => $sentimentLabel,
            'is_scheduled' => $isScheduled,
            'scheduled_at' => $scheduledAt?->toDateTimeString(),
        ]);
    }
}
