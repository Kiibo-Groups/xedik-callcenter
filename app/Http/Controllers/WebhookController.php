<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Models\Campaign;
use App\Models\LeadStatus;
use App\Models\DialerDisposition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class WebhookController extends Controller
{
    public function call(Request $request)
    {
        $payload = $request->all();

        Log::info('Webhook received', ['payload' => $payload]);

        if (empty($payload['uniqueid'])) {
            return response()->json(['error' => 'uniqueid required'], 422);
        }

        $dialerDisposition = trim($payload['disposition'] ?? $payload['disposition_code'] ?? '');

        if (empty($dialerDisposition)) {
            Log::info('Webhook: empty disposition — ignored', ['uniqueid' => $payload['uniqueid'] ?? null]);
            return response()->json(['success' => true, 'processed' => false, 'reason' => 'empty disposition']);
        }

        $phone = preg_replace('/\D/', '', $payload['phonenumber'] ?? '');

        if (empty($phone)) {
            return response()->json(['success' => true, 'processed' => false, 'reason' => 'empty phone']);
        }

        // Enrich desde dl_54_leads (bandera de prueba — no afecta el flujo cuando no viene)
        $enrichEnabled = $request->boolean('enrich_from_leadlist');
        $leadListData  = $enrichEnabled ? $this->enrichFromLeadList($phone) : null;

        // Deduplicar: mismo uniqueid + disposition + campaign → ignorar
        $uniqueid       = $payload['uniqueid'];
        $dialerCampaign = $payload['campaign'] ?? null;
        $isDuplicate = \App\Models\Call::where('uniqueid', $uniqueid)
            ->where('disposition', $dialerDisposition)
            ->where('dialer_campaign', $dialerCampaign)
            ->exists();
        if ($isDuplicate) {
            Log::info("Webhook: duplicate uniqueid={$uniqueid} disposition={$dialerDisposition} — ignored");
            return response()->json(['success' => true, 'processed' => false, 'reason' => 'duplicate']);
        }

        // Resolver campaña antes de buscar el lead para hacer match correcto
        $campaignId = null;
        $tenantId   = null;
        if ($dialerCampaign) {
            $campaign   = Campaign::matchFromDialer($dialerCampaign);
            $campaignId = $campaign?->id;
            $tenantId   = $campaign?->tenant_id;
            if (!$campaignId) {
                $default    = Campaign::getDefault();
                $campaignId = $default?->id;
                $tenantId   = $default?->tenant_id;
            }
        }
        // Fallback si no hay campaña asociada
        $tenantId ??= 1;

        $config = DialerDisposition::findByName($dialerDisposition, $tenantId);

        // Buscar lead por teléfono + campaña primero, luego solo por teléfono
        $lead = null;
        if ($campaignId) {
            $lead = Lead::where(function($q) use ($phone) {
                $q->where('phone', $phone)->orWhere('phone_2', $phone);
            })->where('campaign_id', $campaignId)->first();
        }
        if (!$lead) {
            $lead = Lead::where('phone', $phone)->orWhere('phone_2', $phone)->first();
        }

        if (!$lead && (!$config || !$config->is_active)) {
            Log::info("Webhook: '{$dialerDisposition}' not active and lead doesn't exist — ignored");
            return response()->json([
                'success'   => true,
                'processed' => false,
                'reason'    => "lead not found and '{$dialerDisposition}' is not in the active whitelist",
            ]);
        }
        
        // Resolver agente
        $agentId = null;

        if (($payload['source'] ?? '') === 'AI Dialer') {

            // Forzar agente 3133
            $agent = User::where('phone_user_id', '3133')->first();
            $agentId = $agent?->id;

            Log::info("Webhook: AI Dialer detected — forcing agent 3133");
        } else {

            if (!empty($payload['product_2'])) {
                $agent   = User::where('phone_user_id', $payload['product_2'])->first();
                $agentId = $agent?->id;

                if (!$agentId) {
                    Log::warning("Webhook: no agent found for phone_user_id='{$payload['product_2']}'");
                }
            }
        }
        /*$agentId = null;
        
        if (!empty($payload['product_2'])) {
            $agent   = User::where('phone_user_id', $payload['product_2'])->first();
            $agentId = $agent?->id;
            if (!$agentId) {
                Log::warning("Webhook: no agent found for phone_user_id='{$payload['product_2']}'");
            }
        }*/

        if ($campaignId) {
            if (isset($campaign)) {
                Log::info("Webhook: campaign matched '{$campaign->name}' for dialer campaign '{$dialerCampaign}'");
            } else {
                Log::info("Webhook: using default campaign for '{$dialerCampaign}'");
            }
        }

        // Fecha de llamada
        $calledAt = now();
        if (!empty($payload['init_calldate'])) {
            try {
                $calledAt = Carbon::parse($payload['init_calldate']);
            } catch (\Exception $e) {
                $calledAt = now();
            }
        }

        $duration  = max(0, (int)($payload['duration_call'] ?? 0));
        $isNewLead = !$lead;

        if (!$lead) {
            // Crear nuevo lead con campaña y tenant
            $lead = Lead::create([
                'tenant_id'   => $tenantId,
                'first_name'  => $leadListData['first_name'] ?? ($payload['first_name'] ?? 'Unknown'),
                'last_name'   => $leadListData['last_name']  ?? ($payload['last_name']  ?? $phone),
                'phone'       => $phone,
                'email'       => $leadListData['email'] ?? $this->clean($payload['email'] ?? null),
                'address'     => $this->clean($payload['address']  ?? null),
                'city'        => $this->clean($payload['city']     ?? null),
                'state'       => $this->clean($payload['state']    ?? null),
                'zip'         => $this->clean($payload['zip_code'] ?? null),
                'source'      => $payload['source'] ?? 'Dialer',
                'status'      => LeadStatus::getDefault($tenantId),
                'assigned_to' => $agentId,
                'campaign_id' => $campaignId,
                'notes'       => "Created from dialer. Campaign: " . ($dialerCampaign ?? 'N/A'),
            ]);
            Log::info("Webhook: new lead #{$lead->id} created" .
                ($campaignId ? " — campaign_id={$campaignId}" : ' — no campaign'));
            if ($leadListData) {
                Log::info("Webhook enrich: new lead #{$lead->id} populated from dl_54_leads", array_filter([
                    'first_name' => $leadListData['first_name'],
                    'last_name'  => $leadListData['last_name'],
                    'email'      => $leadListData['email'],
                ], fn($v) => $v !== null));
            }
        } else {
            // Lead existente → asignar agente si no tiene
            if ($agentId && !$lead->assigned_to) {
                $lead->update(['assigned_to' => $agentId]);
            }
            // Asignar campaña si aún no tiene una
            if ($campaignId && !$lead->campaign_id) {
                $lead->update(['campaign_id' => $campaignId]);
                Log::info("Webhook: campaign assigned to existing lead #{$lead->id} — campaign_id={$campaignId}");
            }
            // Enrich: sincronizar datos desde dl_54_leads si la bandera está activa
            if ($leadListData) {
                $enrichUpdates = array_filter([
                    'first_name' => $leadListData['first_name'],
                    'last_name'  => $leadListData['last_name'],
                    'email'      => $leadListData['email'],
                ], fn($v) => $v !== null);

                if ($enrichUpdates) {
                    $lead->update($enrichUpdates);
                    Log::info("Webhook enrich: updated lead #{$lead->id} from dl_54_leads", $enrichUpdates);
                }
            }
            Log::info("Webhook: existing lead #{$lead->id} — logging call");
        }

        $call = Call::create([
            'tenant_id'    => $tenantId,
            'lead_id'      => $lead->id,
            'agent_id'     => $agentId,
            'disposition'  => $dialerDisposition,
            'direction'    => strtolower($payload['media_type'] ?? 'outbound') === 'inbound' ? 'inbound' : 'outbound',
            'called_at'    => $calledAt,
            'duration'     => $duration,
            'notes'        => implode(' | ', array_filter([
                !empty($payload['comments'])   ? $payload['comments']               : null,
                !empty($payload['campaign'])   ? "Campaign: {$payload['campaign']}" : null,
                !empty($payload['uniqueid'])   ? "UniqueID: {$payload['uniqueid']}" : null,
                !empty($payload['media_type']) ? "Type: {$payload['media_type']}"   : null,
            ])),
            'is_scheduled' => false,
            'completed'    => true,
            'recording_id'   => $this->clean($payload['recording_id'] ?? null),
            'uniqueid'        => $uniqueid,
            'dialer_campaign' => $dialerCampaign,
        ]);

        // Agendar callback
        $isCallbackDisp = $config && $config->is_active && strtolower($config->name) === 'callback';

        if ($isCallbackDisp) {
            try {
                if (!empty($payload['callback_datetime'])) {
                    $scheduledAt = Carbon::parse($payload['callback_datetime']);
                } else {
                    $scheduledAt = $calledAt->copy()->addMinutes(30);
                    Log::info("Webhook: callback_datetime missing for lead #{$lead->id} — fallback init_calldate + 30min ({$scheduledAt})");
                }

                Call::create([
                    'tenant_id'    => $tenantId,
                    'lead_id'      => $lead->id,
                    'agent_id'     => $agentId,
                    'disposition'  => 'callback',
                    'called_at'    => $calledAt,
                    'scheduled_at' => $scheduledAt,
                    'duration'     => 0,
                    'is_scheduled' => true,
                    'completed'    => false,
                    'direction'    => strtolower($payload['media_type'] ?? 'outbound') === 'inbound' ? 'inbound' : 'outbound',
                    'notes'        => empty($payload['callback_datetime'])
                                        ? "Auto-scheduled: no datetime provided, based on call time + 30 min."
                                        : null,
                ]);
                Log::info("Webhook: callback scheduled for lead #{$lead->id} at {$scheduledAt}");
            } catch (\Exception $e) {
                Log::error("Webhook: could not schedule callback - " . $e->getMessage());
            }
        }

        return response()->json([
            'success'   => true,
            'processed' => true,
            'lead_id'   => $lead->id,
            'call_id'   => $call->id,
            'new_lead'  => $isNewLead,
        ]);
    }

    private function clean(?string $value): ?string
    {
        if (empty($value) || strtoupper(trim($value)) === 'NONE') {
            return null;
        }
        return $value;
    }

    private function blankToNull(?string $value): ?string
    {
        return ($value !== null && trim($value) !== '') ? $value : null;
    }

    /**
     * Busca first_name, last_name, email en dl_54_leads por teléfono.
     *
     * - Devuelve null si no hay fila, si el JSON es inválido, o ante cualquier excepción.
     * - Strings vacíos → null, para que el fallback al payload siga activo (via ??).
     * - Nunca lanza excepción: el webhook jamás debe romperse por este enrichment.
     */
    private function enrichFromLeadList(string $phone): ?array
    {
        try {
            $row = DB::table('dl_54_leads')->where('phone', $phone)->first();

            if (!$row) {
                Log::info("Webhook enrich: no row in dl_54_leads for phone={$phone}");
                return null;
            }

            $fields = json_decode($row->custom_fields ?? '', true);

            if (!is_array($fields)) {
                Log::warning("Webhook enrich: custom_fields is not valid JSON for phone={$phone}");
                return null;
            }

            return [
                'first_name' => $this->blankToNull($fields['First Name'] ?? null),
                'last_name'  => $this->blankToNull($fields['Last Name']  ?? null),
                'email'      => $this->blankToNull($fields['Email']       ?? null),
            ];
        } catch (\Throwable $e) {
            Log::error("Webhook enrich: exception for phone={$phone} — " . $e->getMessage());
            return null;
        }
    }
}
