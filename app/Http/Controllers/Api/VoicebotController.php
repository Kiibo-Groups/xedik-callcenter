<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\DialerCampaign;
use App\Models\Lead;
use App\Models\LeadList;
use App\Services\Appointments\AppointmentNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class VoicebotController extends Controller
{
    /**
     * Registrar resultado de llamada del Voicebot IA y agendar cita en el CRM dinámicamente.
     */
    public function logCall(Request $request): JsonResponse
    {
        $phone = preg_replace('/[^0-9]/', '', (string)$request->input('phone', ''));
        $last10 = strlen($phone) >= 10 ? substr($phone, -10) : $phone;

        $leadId = $request->input('lead_id') ? (int)$request->input('lead_id') : null;
        $campaignId = $request->input('campaign_id') ? (int)$request->input('campaign_id') : null;
        $agentIdParam = $request->input('agent_id');

        $firstName = trim((string)$request->input('first_name', ''));
        $lastName = trim((string)$request->input('last_name', ''));
        $notes = trim((string)$request->input('notes', ''));
        $transcript = trim((string)$request->input('transcript', ''));
        $duration = (int)$request->input('duration', 0);
        $scheduledAtStr = $request->input('scheduled_at');
        $isScheduled = $request->boolean('is_scheduled');
        $recordingId = trim((string)$request->input('recording_id', ''));

        // 1. Resolver el Lead existente de forma dinámica
        $lead = null;
        if ($leadId) {
            $lead = Lead::withoutGlobalScopes()->find($leadId);
        }

        if (!$lead && !empty($last10)) {
            $lead = Lead::withoutGlobalScopes()
                ->where(function ($q) use ($last10) {
                    $q->where('phone', 'like', "%{$last10}%")
                      ->orWhere('phone_2', 'like', "%{$last10}%");
                })
                ->first();
        }

        // Resolver Tenant y Campaña
        $dialerCampaign = null;
        if ($campaignId) {
            $dialerCampaign = DialerCampaign::withoutGlobalScopes()->find($campaignId);
        }

        $tenantId = $lead?->tenant_id
            ?? $dialerCampaign?->tenant_id
            ?? 2;

        $assignedTo = $lead?->assigned_to
            ?? ($tenantId === 5 ? 18 : 10); // 18: Miguel Angel (FOU), 10: Agente (Xedik)

        // 2. Métricas y análisis de ElevenLabs si está disponible
        $sentimentLabel = 'Neutral';
        $frustrationScore = 0;
        $callSummary = '';
        $mins = floor($duration / 60);
        $secs = $duration % 60;
        $durFormatted = ($mins > 0 ? "{$mins}m " : "") . "{$secs}s";

        if (!empty($recordingId) && str_starts_with($recordingId, 'conv_')) {
            try {
                $apiKey = config('services.elevenlabs.api_key', env('ELEVENLABS_API_KEY', 'sk_e4cbd19dec8a1303347c477783f6c7c3d850ffecb0be4437'));
                $elResp = Http::withHeaders(['xi-api-key' => $apiKey])
                    ->timeout(5)
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

        // 3. Detección dinámica de Cita / Visita si el transcript lo menciona
        if (!$isScheduled && !empty($transcript)) {
            $lower = mb_strtolower($transcript);
            $scheduleWords = ['agend', 'cita', 'visita', 'viernes', 'lunes', 'martes', 'miércoles', 'miercoles', 'jueves', 'sábado', 'sabado', 'demostración', 'demostracion'];
            foreach ($scheduleWords as $w) {
                if (str_contains($lower, $w)) {
                    $isScheduled = true;
                    break;
                }
            }
        }

        // Parsear fecha y hora programada
        $scheduledAt = null;
        if ($isScheduled) {
            $scheduledAt = $this->parseDynamicScheduledDate($scheduledAtStr, $transcript);
        }

        // 4. Actualizar o crear Lead en el CRM
        if (!$lead) {
            if (empty($firstName) || $firstName === 'Prospecto') {
                if ($dialerCampaign && $dialerCampaign->lead_list_id) {
                    $ll = LeadList::withoutGlobalScopes()->find($dialerCampaign->lead_list_id);
                    if ($ll && !empty($ll->table_name) && Schema::hasTable($ll->table_name)) {
                        $row = DB::table($ll->table_name)->where('id', $leadId)->orWhere('phone', 'like', "%{$last10}")->first();
                        if ($row && !empty($row->custom_fields)) {
                            $cf = json_decode((string)$row->custom_fields, true);
                            if (is_array($cf)) {
                                $firstName = $cf['first_name'] ?? $cf['nombre'] ?? $firstName;
                                $lastName = $cf['last_name'] ?? $cf['apellido'] ?? $lastName;
                            }
                        }
                    }
                }
            }

            $lead = new Lead();
            $lead->tenant_id = $tenantId;
            $lead->first_name = $firstName ?: 'Prospecto';
            $lead->last_name = $lastName ?: 'Voz IA';
            $lead->phone = $phone ?: '8122140136';
            $lead->status = $isScheduled ? 'qualified' : 'contacted';
            $lead->source = 'AI Voicebot';
            $lead->campaign_id = $dialerCampaign?->campaign_id ?? ($tenantId === 5 ? 5 : 3);
            $lead->assigned_to = $assignedTo;
            $lead->notes = "[Llamada Voicebot | {$sentimentLabel} | {$durFormatted}]: " . ($callSummary ?: $notes);
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
            $leadNotes = "[Llamada Voicebot | {$sentimentLabel} | {$durFormatted}]: " . ($callSummary ?: ($notes ?: 'Llamada completada'));
            $lead->notes = ($lead->notes ? $lead->notes . "\n\n" : '') . $leadNotes;
            $lead->save();
        }

        $headerNotes = "📊 Resumen IA: " . ($callSummary ?: ($notes ?: 'Llamada Voicebot')) . "\n"
                     . "⏱ Duración: {$durFormatted} ({$duration} seg)\n"
                     . "😊 Sentimiento del cliente: {$sentimentLabel} | Frustración: {$frustrationScore}%\n"
                     . ($isScheduled ? "🗓️ CITA/VISITA AGENDADA: " . ($scheduledAt?->isoFormat('dddd D [de] MMMM [a las] h:mm A') ?? 'Pendiente') . "\n" : "")
                     . (!empty($recordingId) ? "🎧 Grabación disponible en reproductor\n\n" : "\n");

        // 5. Registrar llamada
        $call = new Call();
        $call->tenant_id = $tenantId;
        $call->lead_id = $lead->id;
        $call->agent_id = $assignedTo;
        $call->disposition = $isScheduled ? 'Customer' : 'Contacted';
        $call->direction = 'outbound';
        $call->called_at = Carbon::now();
        $call->duration = $duration;
        $call->recording_id = $recordingId ?: null;
        $call->dialer_campaign = (string)($campaignId ?: '');
        $call->notes = $headerNotes . ($notes ? "Detalles: {$notes}\n\n" : '') . ($transcript ? "Transcripción:\n{$transcript}" : '');
        $call->is_scheduled = $isScheduled;
        $call->appointment_type = $isScheduled ? 'visit' : null;
        $call->scheduled_at = $scheduledAt;
        $call->completed = false;
        $call->save();

        Log::info("Llamada Voicebot guardada exitosamente", [
            'call_id'      => $call->id,
            'lead_id'      => $lead->id,
            'tenant_id'    => $tenantId,
            'is_scheduled' => $isScheduled,
            'scheduled_at' => $scheduledAt?->toDateTimeString()
        ]);

        // 6. Si se agendó cita, disparar WhatsApp de confirmación inmediata
        $waSent = false;
        if ($isScheduled && $scheduledAt) {
            try {
                $notificationService = app(AppointmentNotificationService::class);
                $res = $notificationService->sendConfirmation($call);
                $waSent = (bool)($res['ok'] ?? false);
            } catch (\Throwable $e) {
                Log::error("Error al enviar confirmacion de WhatsApp: " . $e->getMessage());
            }
        }

        return response()->json([
            'success'      => true,
            'lead_id'      => $lead->id,
            'call_id'      => $call->id,
            'recording_id' => $call->recording_id,
            'is_scheduled' => $isScheduled,
            'scheduled_at' => $scheduledAt?->toDateTimeString(),
            'wa_sent'      => $waSent,
        ]);
    }

    /**
     * Parsear dinámicamente la fecha y hora de la cita a partir del string o transcripción.
     */
    private function parseDynamicScheduledDate(?string $dateStr, string $transcript): Carbon
    {
        if (!empty($dateStr)) {
            try {
                return Carbon::parse($dateStr);
            } catch (\Throwable $e) {}
        }

        $lower = mb_strtolower($transcript);
        $d = Carbon::now();

        // Mapear días de la semana
        $daysMap = [
            'lunes'     => Carbon::MONDAY,
            'martes'    => Carbon::TUESDAY,
            'miércoles' => Carbon::WEDNESDAY,
            'miercoles' => Carbon::WEDNESDAY,
            'jueves'    => Carbon::THURSDAY,
            'viernes'   => Carbon::FRIDAY,
            'sábado'    => Carbon::SATURDAY,
            'sabado'    => Carbon::SATURDAY,
            'domingo'   => Carbon::SUNDAY,
        ];

        $targetDayOfWeek = null;
        foreach ($daysMap as $name => $dayVal) {
            if (str_contains($lower, $name)) {
                $targetDayOfWeek = $dayVal;
                break;
            }
        }

        if ($targetDayOfWeek !== null) {
            $daysUntil = ($targetDayOfWeek - $d->dayOfWeek + 7) % 7;
            if ($daysUntil === 0) $daysUntil = 7; // Próximo día
            $d->addDays($daysUntil);
        } elseif (str_contains($lower, 'mañana') || str_contains($lower, 'manana')) {
            $d->addDay();
        } elseif (str_contains($lower, 'pasado mañana') || str_contains($lower, 'pasado manana')) {
            $d->addDays(2);
        } else {
            $d->addDays(2); // Default 2 días adelante
        }

        // Mapear hora
        $hour = 16; // Default 4:00 PM
        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm|de la mañana|de la tarde|de la noche)/i', $lower, $matches)) {
            $h = (int)$matches[1];
            $meridiem = strtolower($matches[3] ?? '');
            if ((str_contains($meridiem, 'pm') || str_contains($meridiem, 'tarde') || str_contains($meridiem, 'noche')) && $h < 12) {
                $h += 12;
            }
            $hour = $h;
        } elseif (str_contains($lower, '4:') || str_contains($lower, 'cuatro')) {
            $hour = 16;
        } elseif (str_contains($lower, '5:') || str_contains($lower, 'cinco')) {
            $hour = 17;
        } elseif (str_contains($lower, '10:') || str_contains($lower, 'diez')) {
            $hour = 10;
        } elseif (str_contains($lower, '11:') || str_contains($lower, 'once')) {
            $hour = 11;
        } elseif (str_contains($lower, '12:') || str_contains($lower, 'doce')) {
            $hour = 12;
        }

        $d->setHour($hour)->setMinute(0)->setSecond(0);
        return $d;
    }

    /**
     * Obtener el contexto y nombre del Lead para personalizar el saludo y prompt de ElevenLabs en tiempo real.
     */
    public function getLeadContext(Request $request): JsonResponse
    {
        $leadId = $request->input('lead_id') ? (int)$request->input('lead_id') : null;
        $campaignId = $request->input('campaign_id') ? (int)$request->input('campaign_id') : null;
        $rawPhone = (string)($request->input('phone') ?: $request->input('caller') ?: '');
        $phoneDigits = preg_replace('/[^\d]/', '', $rawPhone);
        $last10 = strlen($phoneDigits) >= 10 ? substr($phoneDigits, -10) : $phoneDigits;

        $firstName = null;
        $lastName = null;
        $companyName = null;
        $foundLeadId = null;

        // 1. Buscar en la lista dinámica de la campaña de marcación
        if (!empty($campaignId)) {
            $campaign = DialerCampaign::withoutGlobalScopes()->find($campaignId);
            if ($campaign && $campaign->lead_list_id) {
                $leadList = LeadList::withoutGlobalScopes()->find($campaign->lead_list_id);
                if ($leadList && !empty($leadList->table_name) && Schema::hasTable($leadList->table_name)) {
                    $row = null;
                    if (!empty($leadId)) {
                        $row = DB::table($leadList->table_name)->where('id', $leadId)->first();
                    }
                    if (!$row && !empty($last10)) {
                        $row = DB::table($leadList->table_name)->where('phone', 'like', "%{$last10}")->first();
                    }

                    if ($row) {
                        $foundLeadId = $row->id;
                        if (!empty($row->custom_fields)) {
                            $custom = is_array($row->custom_fields) ? $row->custom_fields : json_decode((string)$row->custom_fields, true);
                            if (is_array($custom)) {
                                $firstName = $custom['first_name'] ?? $custom['nombre'] ?? $custom['name'] ?? null;
                                $lastName = $custom['last_name'] ?? $custom['apellido'] ?? null;
                                $companyName = $custom['company'] ?? $custom['empresa'] ?? null;
                            }
                        }
                        if (empty($firstName) && isset($row->name)) {
                            $firstName = $row->name;
                        }
                    }
                }
            }
        }

        // 2. Si no se encontró en la lista dinámica, buscar en la tabla general de leads
        if (empty($firstName)) {
            $lead = null;
            if (!empty($leadId)) {
                $lead = Lead::withoutGlobalScopes()->find($leadId);
            }
            if (!$lead && !empty($last10)) {
                $lead = Lead::withoutGlobalScopes()->where('phone', 'like', "%{$last10}")->first();
            }

            if ($lead) {
                $foundLeadId = $lead->id;
                $firstName = $lead->first_name;
                $lastName = $lead->last_name;
            }
        }

        if (!empty($firstName)) {
            $cleanFirst = ucfirst(mb_strtolower(trim((string)$firstName)));
            $cleanLast = $lastName ? ucfirst(mb_strtolower(trim((string)$lastName))) : '';
            $fullName = trim("{$cleanFirst} {$cleanLast}");

            return response()->json([
                'found'        => true,
                'first_name'   => $cleanFirst,
                'last_name'    => $cleanLast,
                'full_name'    => $fullName,
                'company'      => $companyName,
                'phone'        => $last10,
                'lead_id'      => $foundLeadId,
                'campaign_id'  => $campaignId,
            ]);
        }

        return response()->json([
            'found'        => false,
            'first_name'   => '',
            'last_name'    => '',
            'full_name'    => '',
            'company'      => '',
            'phone'        => $last10,
            'lead_id'      => $leadId,
            'campaign_id'  => $campaignId,
        ]);
    }
}
