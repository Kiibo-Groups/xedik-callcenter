<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Script;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScriptController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts') || auth()->user()->isSuperAdmin(), 403);

        $scripts = Script::withoutGlobalScopes()
            ->when(!auth()->user()->isSuperAdmin(), fn ($q) => $q->where('tenant_id', auth()->user()->tenant_id))
            ->with(['campaign', 'tenant'])
            ->orderBy('tenant_id')
            ->orderBy('campaign_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $campaigns = $this->accessibleCampaignQuery()->orderBy('name')->get();
        $tenants   = auth()->user()->isSuperAdmin()
            ? Tenant::orderBy('name')->get()
            : collect();

        return view('scripts.index', compact('scripts', 'campaigns', 'tenants'));
    }

    public function create()
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts') || auth()->user()->isSuperAdmin(), 403);

        $campaigns   = $this->accessibleCampaignQuery()->orderBy('name')->get();
        $tenants     = auth()->user()->isSuperAdmin() ? Tenant::orderBy('name')->get() : collect();
        $waTemplates = \App\Models\WhatsappTemplate::withoutGlobalScopes()->where('is_active', true)->orderBy('display_name')->get();

        return view('scripts.form', [
            'script'      => new Script(),
            'campaigns'   => $campaigns,
            'tenants'     => $tenants,
            'waTemplates' => $waTemplates,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts') || auth()->user()->isSuperAdmin(), 403);

        $data = $this->validateScript($request);

        if (!empty($data['body'])) {
            $data['body'] = $this->sanitizeBody($data['body']);
        }

        $script = DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                $this->clearPreviousDefault(
                    (int) $data['campaign_id'],
                    $data['direction']
                );
            }
            return Script::withoutGlobalScopes()->create($data);
        });

        $this->syncWithElevenLabs($script);

        return redirect()->route('scripts.index')
            ->with('success', 'Script created and synced with ElevenLabs Voice AI successfully.');
    }

    public function edit(int $scriptId)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts') || auth()->user()->isSuperAdmin(), 403);

        $script = Script::withoutGlobalScopes()->findOrFail($scriptId);
        $this->authorizeTenant($script);

        // If script has an ElevenLabs Agent ID and its body is empty (or ?pull_elevenlabs=1), fetch live from ElevenLabs
        if (!empty($script->voicebot_agent_id) && (empty(trim(strip_tags((string) $script->body))) || request()->boolean('pull_elevenlabs'))) {
            $this->pullFromElevenLabs($script);
        }

        $campaigns   = $this->accessibleCampaignQuery()->orderBy('name')->get();
        $tenants     = auth()->user()->isSuperAdmin() ? Tenant::orderBy('name')->get() : collect();
        $waTemplates = \App\Models\WhatsappTemplate::withoutGlobalScopes()->where('is_active', true)->orderBy('display_name')->get();

        return view('scripts.form', [
            'script'      => $script,
            'campaigns'   => $campaigns,
            'tenants'     => $tenants,
            'waTemplates' => $waTemplates,
        ]);
    }

    public function update(Request $request, int $scriptId)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts') || auth()->user()->isSuperAdmin(), 403);

        $script = Script::withoutGlobalScopes()->findOrFail($scriptId);
        $this->authorizeTenant($script);

        $data = $this->validateScript($request, $script);

        if (!empty($data['body'])) {
            $data['body'] = $this->sanitizeBody($data['body']);
        }

        DB::transaction(function () use ($script, $data) {
            if ($data['is_default']) {
                $this->clearPreviousDefault(
                    (int) $data['campaign_id'],
                    $data['direction'],
                    $script->id
                );
            }
            $script->update($data);
        });

        $this->syncWithElevenLabs($script);

        return redirect()->route('scripts.index')
            ->with('success', 'Script updated and synced with ElevenLabs Voice AI successfully.');
    }

    public function destroy(int $scriptId)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts') || auth()->user()->isSuperAdmin(), 403);

        $script = Script::withoutGlobalScopes()->findOrFail($scriptId);
        $this->authorizeTenant($script);

        $script->delete();

        return redirect()->route('scripts.index')
            ->with('success', 'Script deleted.');
    }

    public function byCampaign(int $campaignId)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts') || auth()->user()->isSuperAdmin(), 403);

        $scripts = Script::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'direction', 'is_default']);

        return response()->json(['scripts' => $scripts]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function validateScript(Request $request, ?Script $script = null): array
    {
        $data = $request->validate([
            'tenant_id'   => 'nullable|integer|exists:tenants,id',
            'campaign_id' => 'nullable|integer|exists:campaigns,id',
            'name'        => 'required|string|max:150',
            'type'        => 'required|in:builder,url',
            'direction'   => 'required|in:inbound,outbound,both',
            'is_default'  => 'boolean',
            'sort_order'  => 'integer|min:0',
            'is_active'   => 'boolean',
            'body'        => 'nullable|string',
            'url'         => [
                'nullable',
                'string',
                'max:2048',
                'regex:/^https?:\/\//i',
            ],
            'url_target'                  => 'nullable|in:tab,panel,modal',
            'url_params'                  => 'nullable|array',
            'voicebot_agent_id'           => 'nullable|string|max:120',
            'voicebot_voice_id'           => 'nullable|string|max:120',
            'appointment_wa_confirmation' => 'nullable|boolean',
            'appointment_wa_reminder'     => 'nullable|boolean',
            'confirmation_wa_template_id' => 'nullable|integer',
            'reminder_wa_template_id'     => 'nullable|integer',
        ], [
            'url.regex' => 'The URL must start with http:// or https://',
        ]);

        // Resolve tenant_id and campaign_id seamlessly (especially when SuperAdmin selects a Tenant)
        $user = auth()->user();
        $tenantId = $user->isSuperAdmin()
            ? (int) ($data['tenant_id'] ?? 0)
            : (int) $user->tenant_id;

        if (!empty($data['campaign_id'])) {
            $campaign = Campaign::withoutGlobalScopes()->find((int) $data['campaign_id']);
            if ($campaign && (!$tenantId || $campaign->tenant_id === $tenantId)) {
                $tenantId = (int) $campaign->tenant_id;
            } elseif ($tenantId) {
                // Campaign belonged to a different tenant -> pick or create default campaign for selected tenant
                $data['campaign_id'] = null;
            }
        }

        if (!$tenantId && $script) {
            $tenantId = (int) $script->tenant_id;
        }

        if (empty($data['campaign_id']) && $tenantId) {
            $defaultCampaign = Campaign::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->first();

            if (!$defaultCampaign) {
                $tenantName = Tenant::where('id', $tenantId)->value('name') ?: 'Tenant';
                $defaultCampaign = Campaign::withoutGlobalScopes()->create([
                    'tenant_id'   => $tenantId,
                    'name'        => "{$tenantName} — General",
                    'description' => 'Default campaign created automatically for scripts.',
                    'is_active'   => true,
                ]);
            }
            $data['campaign_id'] = $defaultCampaign->id;
        }

        if (empty($data['campaign_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'campaign_id' => 'Please select a Tenant or Campaign.',
            ]);
        }

        // Cross-field: body required for builder (unless pulling from ElevenLabs), url required for url
        if ($data['type'] === 'builder' && empty(trim($data['body'] ?? '')) && empty($data['voicebot_agent_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'body' => 'The script body is required for builder type.',
            ]);
        }
        if ($data['type'] === 'url' && empty(trim($data['url'] ?? ''))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => 'A URL is required for url type.',
            ]);
        }

        $data['is_default'] = $request->boolean('is_default');
        $data['is_active']  = $request->boolean('is_active', true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['url_params'] = $this->sanitizeUrlParams($data['url_params'] ?? []);
        $data['tenant_id']  = $tenantId ?: (int) Campaign::withoutGlobalScopes()
            ->where('id', $data['campaign_id'])
            ->value('tenant_id');

        if ($data['type'] === 'url') {
            $data['body'] = null;
        } else {
            $data['url']        = null;
            $data['url_params'] = null;
            $data['url_target'] = 'panel';
        }

        return $data;
    }

    private function clearPreviousDefault(int $campaignId, string $direction, ?int $excludeId = null): void
    {
        $query = Script::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->where('is_default', true)
            ->where('direction', $direction);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $query->update(['is_default' => false]);
    }

    private function sanitizeBody(string $html): string
    {
        $allowed = '<h1><h2><h3><h4><h5><h6>'
            . '<p><div><span><br><hr><blockquote><pre><code>'
            . '<strong><b><em><i><u><s><del><ins><mark>'
            . '<ul><ol><li>'
            . '<table><thead><tbody><tfoot><tr><th><td>'
            . '<a><img><figure><figcaption>'
            . '<sub><sup>';

        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\\s+on\\w+\\s*=\\s*'[^']*'/i", '', $html);
        $html = preg_replace(
            '/(href|src|action)\s*=\s*["\']?\s*(javascript|vbscript)\s*:/i',
            '$1="#" data-blocked=',
            $html
        );

        return $html;
    }

    private function sanitizeUrlParams(?array $params): ?array
    {
        if (empty($params)) {
            return null;
        }

        $allowed = [
            'conversation_id', 'lead_id', 'campaign_id',
            'queue_id', 'channel', 'direction',
        ];

        return array_filter(
            $params,
            fn ($key) => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function authorizeTenant(Script $script): void
    {
        $user = auth()->user();
        if (!$user->isSuperAdmin() && $script->tenant_id !== $user->tenant_id) {
            abort(403);
        }
    }

    private function accessibleCampaignQuery()
    {
        $user = auth()->user();

        $query = Campaign::withoutGlobalScopes()
            ->where('is_active', true);

        if (!$user->isSuperAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        return $query;
    }

    private function resolveElevenLabsApiKey(): ?string
    {
        $apiKey = config('services.elevenlabs.api_key') ?: env('ELEVENLABS_API_KEY');
        if (!empty($apiKey)) {
            return $apiKey;
        }
        if (is_readable('/etc/systemd/system/xedik-voicebot.service')) {
            $svc = (string) @file_get_contents('/etc/systemd/system/xedik-voicebot.service');
            if (preg_match('/ELEVENLABS_API_KEY=([^\s"\']+)/', $svc, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    public function pullFromElevenLabs(Script $script): bool
    {
        $agentId = trim((string) $script->voicebot_agent_id);
        $apiKey  = $this->resolveElevenLabsApiKey();
        if (!$agentId || !$apiKey) {
            return false;
        }

        try {
            $resp = Http::withHeaders(['xi-api-key' => $apiKey])
                ->timeout(8)
                ->get("https://api.elevenlabs.io/v1/convai/agents/{$agentId}");

            if (!$resp->successful()) {
                return false;
            }

            $json         = $resp->json();
            $firstMessage = trim((string) ($json['conversation_config']['agent']['first_message'] ?? ''));
            $prompt       = trim((string) ($json['conversation_config']['agent']['prompt']['prompt'] ?? ''));
            $voiceId      = trim((string) ($json['conversation_config']['tts']['voice_id'] ?? ''));

            if ($prompt === '' && $firstMessage === '') {
                return false;
            }

            $htmlParts = [];
            if ($firstMessage !== '') {
                $htmlParts[] = '<p><strong>SALUDO INICIAL:</strong> "' . e($firstMessage) . '"</p>';
            }
            foreach (explode("\n", $prompt) as $line) {
                $htmlParts[] = '<p>' . e($line) . '</p>';
            }

            $script->body = implode("\n", $htmlParts);
            if ($voiceId !== '' && empty($script->voicebot_voice_id)) {
                $script->voicebot_voice_id = $voiceId;
            }
            $script->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning("pullFromElevenLabs failed for script #{$script->id}: " . $e->getMessage());
            return false;
        }
    }

    private function syncWithElevenLabs(Script $script): void
    {
        $agentId = trim((string) $script->voicebot_agent_id);
        if (!$agentId) {
            return;
        }

        // If body is empty when saving a script that has an agent ID, pull from ElevenLabs first
        if (empty(trim(strip_tags((string) $script->body)))) {
            $this->pullFromElevenLabs($script);
            return;
        }

        try {
            $cleanText = str_replace(['&nbsp;', '&#160;'], ' ', (string) $script->body);
            $cleanText = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $cleanText);
            $cleanText = html_entity_decode(strip_tags($cleanText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cleanText = trim(preg_replace("/[ \t]+/", " ", $cleanText));
            $cleanText = trim(preg_replace("/\n{3,}/", "\n\n", $cleanText));

            $firstMessage = null;
            if (preg_match('/SALUDO INICIAL:?\s*[\r\n]*["“]([^"”]+)["”]/i', $cleanText, $matches)) {
                $firstMessage = trim($matches[1]);
                // Remove SALUDO INICIAL line from the system prompt so it isn't duplicated
                $cleanText = trim(preg_replace('/SALUDO INICIAL:?\s*[\r\n]*["“][^"”]+["”]/i', '', $cleanText, 1));
            }

            $apiKey = $this->resolveElevenLabsApiKey();
            if (empty($apiKey)) {
                Log::warning("syncToElevenLabs: ELEVENLABS_API_KEY not found.");
                return;
            }

            $patchData = [
                'conversation_config' => [
                    'agent' => [
                        'prompt' => [
                            'prompt' => $cleanText,
                        ],
                    ],
                    'tts' => [
                        'agent_output_audio_format'  => 'pcm_8000',
                        'optimize_streaming_latency' => 1,
                    ],
                ],
                'platform_settings' => [
                    'overrides' => [
                        'conversation_config_override' => [
                            'agent' => [
                                'first_message' => true,
                            ],
                        ],
                    ],
                ],
            ];

            if (!empty($script->voicebot_voice_id)) {
                $patchData['conversation_config']['tts']['voice_id'] = $script->voicebot_voice_id;
            }

            if ($firstMessage) {
                $patchData['conversation_config']['agent']['first_message'] = $firstMessage;
            }

            Http::withHeaders([
                'xi-api-key'   => $apiKey,
                'Content-Type' => 'application/json',
            ])->patch("https://api.elevenlabs.io/v1/convai/agents/{$agentId}", $patchData);

            Log::info("Script #{$script->id} synced to ElevenLabs agent {$agentId}");
        } catch (\Throwable $e) {
            Log::error("Error syncing script to ElevenLabs: " . $e->getMessage());
        }
    }
}
