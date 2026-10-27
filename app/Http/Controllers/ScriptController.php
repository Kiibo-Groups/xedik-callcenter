<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Script;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScriptController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts'), 403);

        $scripts = Script::withoutGlobalScopes()
            ->whereIn('campaign_id', $this->accessibleCampaignQuery()->select('id'))
            ->with('campaign')
            ->orderBy('campaign_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $campaigns = $this->accessibleCampaignQuery()->orderBy('name')->get();

        return view('scripts.index', compact('scripts', 'campaigns'));
    }

    public function create()
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts'), 403);

        $campaigns = $this->accessibleCampaignQuery()->orderBy('name')->get();

        return view('scripts.form', [
            'script'    => new Script(),
            'campaigns' => $campaigns,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts'), 403);

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
            return Script::create($data);
        });

        $this->syncWithElevenLabs($script);

        return redirect()->route('scripts.index')
            ->with('success', 'Script created and synced with Voicebot IA successfully.');
    }

    public function edit(Script $script)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts'), 403);
        $this->authorizeTenant($script);

        $campaigns = $this->accessibleCampaignQuery()->orderBy('name')->get();

        return view('scripts.form', [
            'script'    => $script,
            'campaigns' => $campaigns,
        ]);
    }

    public function update(Request $request, Script $script)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts'), 403);
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
            ->with('success', 'Script updated and synced with Voicebot IA successfully.');
    }

    public function destroy(Script $script)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts'), 403);
        $this->authorizeTenant($script);

        $script->delete();

        return redirect()->route('scripts.index')
            ->with('success', 'Script deleted.');
    }

    /**
     * AJAX: return active scripts for a given campaign (for OmniQueue form).
     */
    public function byCampaign(int $campaignId)
    {
        abort_unless(auth()->user()->hasPermission('manage_scripts'), 403);

        $tenantId = auth()->user()->tenant_id;

        $scripts = Script::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'direction', 'is_default']);

        return response()->json(['scripts' => $scripts]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function validateScript(Request $request, ?Script $script = null): array
    {
        $campaignIds = $this->accessibleCampaignQuery()->pluck('id')->all();

        $data = $request->validate([
            'campaign_id' => [
                'required',
                Rule::in($campaignIds),
            ],
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
            'url_target'  => 'nullable|in:tab,panel,modal',
            'url_params'  => 'nullable|array',
        ], [
            'url.regex' => 'The URL must start with http:// or https://',
        ]);

        // Cross-field: body required for builder, url required for url
        if ($data['type'] === 'builder' && empty(trim($data['body'] ?? ''))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'body' => 'The script body is required for builder type.',
            ]);
        }
        if ($data['type'] === 'url' && empty(trim($data['url'] ?? ''))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => 'A URL is required for url type.',
            ]);
        }
        if ($data['type'] === 'url' && empty($data['url_target'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url_target' => 'The URL target is required for URL scripts.',
            ]);
        }

        $data['is_default'] = $request->boolean('is_default');
        $data['is_active']  = $request->boolean('is_active', true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['url_params'] = $this->sanitizeUrlParams($data['url_params'] ?? []);
        $data['tenant_id']  = (int) Campaign::withoutGlobalScopes()
            ->where('id', $data['campaign_id'])
            ->value('tenant_id');

        // Clear irrelevant fields per type
        if ($data['type'] === 'url') {
            $data['body'] = null;
        } else {
            $data['url']        = null;
            $data['url_params'] = null;
            $data['url_target'] = 'panel';
        }

        return $data;
    }

    /**
     * When marking a script as default, clear existing defaults for the same
     * (campaign, direction) pair within the same tenant.
     * Also clears 'both' defaults when setting a specific direction and vice-versa.
     */
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

    /**
     * Strip dangerous HTML from Jodit-generated body content.
     * Allows common formatting elements while removing script tags,
     * event handlers, and dangerous URL schemes.
     */
    private function sanitizeBody(string $html): string
    {
        // Allowed tags (Jodit produces these)
        $allowed = '<h1><h2><h3><h4><h5><h6>'
            . '<p><div><span><br><hr><blockquote><pre><code>'
            . '<strong><b><em><i><u><s><del><ins><mark>'
            . '<ul><ol><li>'
            . '<table><thead><tbody><tfoot><tr><th><td>'
            . '<a><img><figure><figcaption>'
            . '<sub><sup>';

        $html = strip_tags($html, $allowed);

        // Remove all event handlers (on*)
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\\s+on\\w+\\s*=\\s*'[^']*'/i", '', $html);

        // Replace javascript:/vbscript: in href and src
        $html = preg_replace(
            '/(href|src|action)\s*=\s*["\']?\s*(javascript|vbscript)\s*:/i',
            '$1="#" data-blocked=',
            $html
        );

        return $html;
    }

    /**
     * Only allow whitelisted URL param keys.
     */
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
            fn($key) => in_array($key, $allowed, true),
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

    private function syncWithElevenLabs(Script $script): void
    {
        // Check if script belongs to FOU (tenant_id = 5) or Xedik
        $agentId = null;
        if ($script->tenant_id == 5 || (int) $script->campaign_id === 5) {
            $agentId = 'agent_2101m5xst5cgf1gv86jxhf2an2nd'; // FOU Carlos
        } elseif ($script->tenant_id == 2 && str_contains(strtolower($script->name), 'ia')) {
            $agentId = 'agent_0001m5h3zv32egsb2mfmgnd6a395'; // Xedik Marto
        }

        if (!$agentId || empty($script->body)) {
            return;
        }

        try {
            // Convert HTML to clean plain text
            $cleanText = str_replace(['&nbsp;', '&#160;'], ' ', $script->body);
            $cleanText = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $cleanText);
            $cleanText = html_entity_decode(strip_tags($cleanText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cleanText = trim(preg_replace("/[ \t]+/", " ", $cleanText));
            $cleanText = trim(preg_replace("/\n{3,}/", "\n\n", $cleanText));

            // Extract first message if present in text (e.g. SALUDO INICIAL: "...")
            $firstMessage = null;
            if (preg_match('/SALUDO INICIAL:?\s*[\r\n]*["“]([^"”]+)["”]/i', $cleanText, $matches)) {
                $firstMessage = trim($matches[1]);
            }

            $apiKey = config('services.elevenlabs.api_key', env('ELEVENLABS_API_KEY', 'sk_e4cbd19dec8a1303347c477783f6c7c3d850ffecb0be4437'));

            $patchData = [
                'conversation_config' => [
                    'agent' => [
                        'prompt' => [
                            'prompt' => $cleanText,
                        ],
                    ],
                    'tts' => [
                        'agent_output_audio_format' => 'pcm_8000',
                        'optimize_streaming_latency' => 2,
                    ],
                ],
            ];

            if ($firstMessage) {
                $patchData['conversation_config']['agent']['first_message'] = $firstMessage;
            }

            \Illuminate\Support\Facades\Http::withHeaders([
                'xi-api-key'   => $apiKey,
                'Content-Type' => 'application/json',
            ])->patch("https://api.elevenlabs.io/v1/convai/agents/{$agentId}", $patchData);

            \Illuminate\Support\Facades\Log::info("Script #{$script->id} synced to ElevenLabs agent {$agentId}");
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Error syncing script to ElevenLabs: " . $e->getMessage());
        }
    }
}

