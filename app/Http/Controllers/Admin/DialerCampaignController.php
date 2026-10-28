<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\DialerCampaign;
use App\Models\DialerCampaignCallerId;
use App\Models\LeadList;
use App\Models\OmniQueue;
use App\Models\QueueDial;
use App\Models\Script;
use App\Models\Tenant;
use App\Models\VoiceCallerId;
use App\Services\CampaignFilterService;
use App\Services\Dialer\CallingHoursComplianceService;
use App\Services\DialerTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DialerCampaignController extends Controller
{
    public function __construct(
        private DialerTableService $tableService,
        private CampaignFilterService $filterService,
        private CallingHoursComplianceService $complianceService,
    ) {}

    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $tenants = $isSuperAdmin
            ? Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        $campaigns    = DialerCampaign::with(['tenant', 'campaign', 'leadList', 'script'])->orderBy('created_at', 'desc')->get();
        $crmCampaigns = Campaign::where('is_active', true)->orderBy('name')->get(['id', 'tenant_id', 'name']);
        $leadLists    = LeadList::whereIn('status', ['ready', 'draft'])->orderBy('name')->get(['id', 'tenant_id', 'name', 'total_leads', 'status']);
        $scripts      = Script::where('is_active', true)
            ->whereIn('direction', ['outbound', 'both'])
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'campaign_id', 'name', 'direction']);

        // Voice-eligible queues (Preview operational identity). Filtered client-side by
        // the selected Campaign; tenant scoping via the global scope (superadmin sees all).
        $voiceQueues  = OmniQueue::voiceEligible()
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'campaign_id', 'name']);

        // Stats en tiempo real para cada campaña activa
        $stats = [];
        foreach ($campaigns as $dc) {
            $ll = $dc->leadList;
            $s  = [
                'dialing'   => 0,
                'pending'   => 0,
                'new'       => 0,
                'answer'    => 0,
                'no_answer' => 0,
                'busy'      => 0,
                'failed'    => 0,
                'total'     => 0,
            ];
            if ($ll) {
                // queue_dial stats
                $s['dialing'] = DB::table('queue_dial')
                    ->where('dialer_campaign_id', $dc->id)->where('status', 'dialing')->count();
                $s['pending'] = DB::table('queue_dial')
                    ->where('dialer_campaign_id', $dc->id)->where('status', 'pending')->count();

                // lead table stats
                try {
                    $counts = DB::table($ll->table_name)
                        ->selectRaw('status, COUNT(*) as total')
                        ->groupBy('status')
                        ->pluck('total', 'status');

                    $s['new']       = $counts['NEW']         ?? 0;
                    $s['answer']    = ($counts['ANSWER']   ?? 0) + ($counts['ANSWERED']   ?? 0);
                    $s['no_answer'] = $counts['NO_ANSWER'] ?? 0;
                    $s['busy']      = $counts['BUSY']      ?? 0;
                    $s['failed']    = ($counts['FAILED']   ?? 0) + ($counts['CONGESTION'] ?? 0) + ($counts['REJECTED'] ?? 0);
                    $s['total']     = $counts->sum();
                } catch (\Throwable $_) {}
            }
            $stats[$dc->id] = $s;
        }

        return view('dialer_campaigns.index', compact('campaigns', 'crmCampaigns', 'leadLists', 'scripts', 'voiceQueues', 'stats', 'isSuperAdmin', 'tenants'));
    }

    public function edit(DialerCampaign $dialerCampaign)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        $user         = auth()->user();
        $tenantId     = (int) $dialerCampaign->tenant_id;
        $isSuperAdmin = $user->isSuperAdmin();
        $tenants      = $isSuperAdmin
            ? Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        $crmCampaigns = Campaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name']);

        $leadLists = LeadList::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['ready', 'draft'])
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'total_leads', 'status']);

        $scripts = Script::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('direction', ['outbound', 'both'])
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'campaign_id', 'name', 'direction']);

        // Voice-eligible queues for this campaign's tenant (Preview operational identity).
        $voiceQueues = OmniQueue::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->voiceEligible()
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'campaign_id', 'name']);

        // Caller IDs used as personal CID by any extension in this tenant
        $personalCallerIdIds = DB::table('voice_extensions')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('personal_caller_id_id')
            ->pluck('personal_caller_id_id')
            ->unique()
            ->values()
            ->all();

        // Eligible: active + verified + not used as personal CID
        $eligibleCallerIds = VoiceCallerId::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_verified', true)
            ->whereNotIn('id', $personalCallerIdIds)
            ->orderBy('name')
            ->orderBy('number')
            ->get();

        // Currently assigned caller IDs for this campaign (keyed by voice_caller_id_id)
        $assignedIds = DB::table('dialer_campaign_caller_ids')
            ->where('dialer_campaign_id', $dialerCampaign->id)
            ->where('is_active', true)
            ->pluck('voice_caller_id_id')
            ->all();

        $filterConfig     = $dialerCampaign->filter_config ?? [];
        $availableFields  = $dialerCampaign->leadList
            ? $this->filterService->extractAvailableFields($dialerCampaign->leadList)
            : [];
        $complianceConfig = $dialerCampaign->compliance_config['calling_hours'] ?? [];

        return view('dialer_campaigns.edit', compact(
            'dialerCampaign',
            'crmCampaigns',
            'leadLists',
            'scripts',
            'voiceQueues',
            'eligibleCallerIds',
            'assignedIds',
            'isSuperAdmin',
            'tenants',
            'filterConfig',
            'availableFields',
            'complianceConfig',
        ));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        DialerCampaign::create($this->validatedCampaignPayload($request));

        return back()->with('success', 'Dialer campaign created.');
    }

    public function update(Request $request, DialerCampaign $dialerCampaign)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if ($dialerCampaign->isRunning()) {
            return back()->with('error', 'Cannot edit a running campaign.');
        }

        $dialerCampaign->update($this->validatedCampaignPayload($request, $dialerCampaign));

        // If request came from the edit page, redirect back to it
        if ($request->has('_from_edit_page')) {
            return redirect()
                ->route('dialer-campaigns.edit', $dialerCampaign)
                ->with('success', 'Campaign updated.');
        }

        return back()->with('success', 'Campaign updated.');
    }

    public function updateCallerIds(Request $request, DialerCampaign $dialerCampaign): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        $tenantId = (int) $dialerCampaign->tenant_id;

        if (!auth()->user()->isSuperAdmin() && (int) auth()->user()->tenant_id !== $tenantId) {
            abort(403);
        }

        if ($dialerCampaign->isRunning()) {
            return redirect()
                ->route('dialer-campaigns.edit', ['dialerCampaign' => $dialerCampaign->id, 'tab' => 'caller-id'])
                ->with('error', 'Cannot change Caller ID assignments while the campaign is running.');
        }

        $validated = $request->validate([
            'caller_id_ids'   => ['nullable', 'array'],
            'caller_id_ids.*' => ['integer'],
        ]);

        $requestedIds = array_map('intval', $validated['caller_id_ids'] ?? []);

        if (!empty($requestedIds)) {
            // IDs used as personal CID — excluded from pool
            $personalIds = DB::table('voice_extensions')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('personal_caller_id_id')
                ->pluck('personal_caller_id_id')
                ->unique()
                ->all();

            // Validate each requested ID is eligible
            $eligibleCount = VoiceCallerId::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('is_verified', true)
                ->whereNotIn('id', $personalIds)
                ->whereIn('id', $requestedIds)
                ->count();

            if ($eligibleCount !== count($requestedIds)) {
                return back()->with('error', 'One or more Caller IDs are invalid or not eligible for campaign assignment.');
            }
        }

        DB::transaction(function () use ($dialerCampaign, $tenantId, $requestedIds): void {
            // Deactivate all existing assignments for this campaign
            DB::table('dialer_campaign_caller_ids')
                ->where('dialer_campaign_id', $dialerCampaign->id)
                ->update(['is_active' => false, 'updated_at' => now()]);

            // Reactivate or create for each selected ID
            foreach ($requestedIds as $cidId) {
                $existing = DB::table('dialer_campaign_caller_ids')
                    ->where('dialer_campaign_id', $dialerCampaign->id)
                    ->where('voice_caller_id_id', $cidId)
                    ->first();

                if ($existing) {
                    DB::table('dialer_campaign_caller_ids')
                        ->where('id', $existing->id)
                        ->update(['is_active' => true, 'updated_at' => now()]);
                } else {
                    DB::table('dialer_campaign_caller_ids')->insert([
                        'tenant_id'          => $tenantId,
                        'dialer_campaign_id' => $dialerCampaign->id,
                        'voice_caller_id_id' => $cidId,
                        'is_active'          => true,
                        'last_used_at'       => null,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }
            }
        });

        $count = count($requestedIds);
        return redirect()
            ->route('dialer-campaigns.edit', ['dialerCampaign' => $dialerCampaign->id, 'tab' => 'caller-id'])
            ->with('success', $count > 0
                ? "Caller ID pool updated: {$count} number(s) assigned."
                : 'Caller ID pool cleared.');
    }

    public function updateFilters(Request $request, DialerCampaign $dialerCampaign): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if ($dialerCampaign->isRunning()) {
            return redirect()
                ->route('dialer-campaigns.edit', ['dialerCampaign' => $dialerCampaign->id, 'tab' => 'filters'])
                ->with('error', 'Cannot edit filters while the campaign is running.');
        }

        $raw = [
            'enabled'    => (bool) $request->input('enabled'),
            'statuses'   => (array) ($request->input('statuses') ?? []),
            'match'      => (string) ($request->input('match') ?? 'all'),
            'conditions' => json_decode((string) ($request->input('conditions_json') ?? '[]'), true) ?? [],
        ];

        $normalized = $this->filterService->normalize($raw);
        $toSave     = ($normalized['enabled'] ?? false) ? $normalized : null;

        $dialerCampaign->update(['filter_config' => $toSave]);

        return redirect()
            ->route('dialer-campaigns.edit', ['dialerCampaign' => $dialerCampaign->id, 'tab' => 'filters'])
            ->with('success', 'Filters saved.');
    }

    public function testFilters(Request $request, DialerCampaign $dialerCampaign): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        $rawConfig = $request->input('config');
        if (!is_array($rawConfig)) {
            $rawConfig = [];
        }

        $result = $this->filterService->test($dialerCampaign, $rawConfig);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    // ── Compliance (Federal Calling Hours Guard) ────────────────────────────────

    public function updateCompliance(Request $request, DialerCampaign $dialerCampaign): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if ($dialerCampaign->isRunning()) {
            return redirect()
                ->route('dialer-campaigns.edit', ['dialerCampaign' => $dialerCampaign->id, 'tab' => 'compliance'])
                ->with('error', 'Cannot edit compliance settings while the campaign is running.');
        }

        $validated = $request->validate([
            'enabled'                 => ['nullable', 'boolean'],
            'start_time'              => ['required_if:enabled,1', 'nullable', 'date_format:H:i'],
            'end_time'                => ['required_if:enabled,1', 'nullable', 'date_format:H:i'],
            'unknown_timezone_policy' => ['required_if:enabled,1', 'nullable', Rule::in(['skip', 'allow', 'use_campaign_timezone'])],
            'campaign_timezone'       => ['nullable', 'string', 'max:64'],
            'rule_mode'               => ['nullable', Rule::in(['federal_baseline', 'federal_plus_state'])],
            'unknown_state_policy'    => ['nullable', Rule::in(['federal_baseline', 'skip'])],
        ]);

        $enabled  = $request->boolean('enabled');
        $policy   = $validated['unknown_timezone_policy'] ?? 'skip';
        $ruleMode = $validated['rule_mode'] ?? 'federal_baseline';
        $unknownStatePolicy = $validated['unknown_state_policy'] ?? 'federal_baseline';

        if ($enabled && $policy === 'use_campaign_timezone') {
            $tz = $validated['campaign_timezone'] ?? null;
            if (!$tz || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                return redirect()
                    ->route('dialer-campaigns.edit', ['dialerCampaign' => $dialerCampaign->id, 'tab' => 'compliance'])
                    ->with('error', 'Campaign timezone must be a valid IANA timezone (e.g. America/Phoenix) when the unknown-timezone policy is "use_campaign_timezone".');
            }
        }

        $toSave = $enabled ? [
            'calling_hours' => [
                'enabled'                 => true,
                'rule_mode'               => $ruleMode,
                'start_time'              => $validated['start_time'],
                'end_time'                => $validated['end_time'],
                'timezone_strategy'       => 'phone_area_code',
                'unknown_timezone_policy' => $policy,
                'campaign_timezone'       => $policy === 'use_campaign_timezone' ? $validated['campaign_timezone'] : null,
                'state_strategy'          => $ruleMode === 'federal_plus_state' ? 'area_code_state' : null,
                'unknown_state_policy'    => $ruleMode === 'federal_plus_state' ? $unknownStatePolicy : null,
            ],
        ] : null;

        $dialerCampaign->update(['compliance_config' => $toSave]);

        return redirect()
            ->route('dialer-campaigns.edit', ['dialerCampaign' => $dialerCampaign->id, 'tab' => 'compliance'])
            ->with('success', 'Compliance settings saved.');
    }

    public function testCompliance(Request $request, DialerCampaign $dialerCampaign): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        $validated = $request->validate([
            'phone'  => ['required', 'string'],
            'config' => ['nullable', 'array'],
        ]);

        $config = $validated['config'] ?? ($dialerCampaign->compliance_config['calling_hours'] ?? []);
        $probe  = new DialerCampaign(['compliance_config' => ['calling_hours' => $config]]);

        $decision = $this->complianceService->evaluate($probe, $validated['phone']);

        return response()->json(['ok' => true] + $decision);
    }

    public function refreshStats(DialerCampaign $dialerCampaign): RedirectResponse|JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        $ll      = $dialerCampaign->leadList;
        $wantsJson = request()->expectsJson();

        if (!$ll) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'error' => 'Campaign has no lead list assigned.'], 422);
            }
            return redirect()->route('dialer-campaigns.index')
                ->with('error', 'Campaign has no lead list assigned.');
        }

        try {
            $counts = DB::table($ll->table_name)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');
        } catch (\Throwable $_) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'error' => 'Lead list table not found. Cannot refresh stats.'], 422);
            }
            return redirect()->route('dialer-campaigns.index')
                ->with('error', 'Lead list table not found. Cannot refresh stats.');
        }

        if (!$wantsJson) {
            return redirect()->route('dialer-campaigns.index')
                ->with('success', 'Campaign stats refreshed.');
        }

        $ans      = (int)(($counts['ANSWER']    ?? 0) + ($counts['ANSWERED']   ?? 0));
        $na       = (int) ($counts['NO_ANSWER'] ?? 0);
        $busy     = (int) ($counts['BUSY']      ?? 0);
        $fail     = (int)(($counts['FAILED']    ?? 0) + ($counts['CONGESTION'] ?? 0) + ($counts['REJECTED'] ?? 0));
        $new      = (int) ($counts['NEW']       ?? 0);
        $total    = (int)  $counts->sum();
        $progress = $total > 0 ? (int) round(($total - $new) / $total * 100) : 0;

        return response()->json([
            'ok'       => true,
            'message'  => 'Campaign stats refreshed.',
            'campaign' => [
                'id'         => $dialerCampaign->id,
                'status'     => $dialerCampaign->status,
                'lead_count' => $total,
                'stats'      => compact('ans', 'na', 'busy', 'fail', 'new', 'progress'),
                'stats_text' => "{$ans} ans · {$na} n/a · {$busy} busy · {$fail} fail · {$new} new ({$progress}%)",
            ],
        ]);
    }

    public function destroy(DialerCampaign $dialerCampaign)
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if ($dialerCampaign->isRunning() || $dialerCampaign->isPaused()) {
            return back()->with('error', 'Stop the campaign before deleting it.');
        }

        DB::table('queue_dial')->where('dialer_campaign_id', $dialerCampaign->id)->delete();
        $dialerCampaign->delete();

        return back()->with('success', 'Campaign deleted.');
    }

    // ── Play ──────────────────────────────────────────────────────────────────

    public function play(DialerCampaign $dialerCampaign): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if (!in_array($dialerCampaign->status, ['idle', 'paused', 'scheduled'], true)) {
            return response()->json(['error' => 'Campaign must be idle, paused, or scheduled to start.'], 422);
        }

        $res = app(\App\Services\Dialer\DialerSchedulerService::class)->startCampaign($dialerCampaign);
        if (!($res['ok'] ?? false)) {
            return response()->json(['error' => $res['error'] ?? 'Failed to start campaign.'], 422);
        }

        return response()->json($res);
    }

    // ── Reset ────────────────────────────────────────────────────────────────

    public function reset(DialerCampaign $dialerCampaign): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if ($dialerCampaign->status !== 'completed') {
            return response()->json(['error' => 'Only completed campaigns can be reset.'], 422);
        }

        DB::table('queue_dial')->where('dialer_campaign_id', $dialerCampaign->id)->delete();
        $dialerCampaign->update(['status' => 'idle', 'completed_at' => null]);

        return response()->json(['ok' => true]);
    }

    // ── Pause ─────────────────────────────────────────────────────────────────

    public function pause(DialerCampaign $dialerCampaign): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if (!$dialerCampaign->isRunning()) {
            return response()->json(['error' => 'Campaign is not running.'], 422);
        }

        $dialerCampaign->update(['status' => 'paused']);

        return response()->json(['ok' => true]);
    }

    // ── Stop ──────────────────────────────────────────────────────────────────

    public function stop(DialerCampaign $dialerCampaign): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('manage_dialer_campaigns'), 403);

        if (!in_array($dialerCampaign->status, ['running', 'paused', 'scheduled'], true)) {
            return response()->json(['error' => 'Campaign is not active.'], 422);
        }

        if ($dialerCampaign->dialer_type !== 'preview') {
            DB::table('queue_dial')
                ->where('dialer_campaign_id', $dialerCampaign->id)
                ->where('status', 'pending')
                ->delete();
        }

        $dialerCampaign->update(['status' => 'idle']);

        return response()->json(['ok' => true]);
    }

    private function playPreview(DialerCampaign $dialerCampaign): JsonResponse
    {
        if (!in_array($dialerCampaign->status, ['idle', 'paused'], true)) {
            return response()->json(['error' => 'invalid_status'], 422);
        }

        $tenantId = (int) $dialerCampaign->tenant_id;

        $previewEnabled = DB::table('voice_tenant_settings')
            ->where('tenant_id', $tenantId)
            ->value('preview_dialer_enabled');

        if (!$previewEnabled) {
            return response()->json(['error' => 'preview_dialer_disabled'], 422);
        }

        $leadList = $dialerCampaign->leadList;
        if (!$leadList || (int) $leadList->tenant_id !== $tenantId || !$this->tableService->isPreviewSchemaPrepared($leadList)) {
            return response()->json([
                'error' => 'preview_schema_not_prepared',
                'message' => $leadList
                    ? "Run: php artisan preview-dialer:prepare-lead-lists --lead-list-id={$leadList->id}"
                    : 'Campaign has no lead list assigned.',
            ], 422);
        }

        if (!$this->isValidPreviewScript($dialerCampaign->script_id, $dialerCampaign->campaign_id, $tenantId)) {
            return response()->json(['error' => 'invalid_preview_script'], 422);
        }

        $campaign = $dialerCampaign->campaign;
        if (!$campaign || (int) $campaign->tenant_id !== $tenantId) {
            return response()->json(['error' => 'invalid_campaign'], 422);
        }

        if ($campaign->users()->count() < 1) {
            return response()->json(['error' => 'no_agents_assigned'], 422);
        }

        $updates = ['status' => 'running', 'completed_at' => null];
        if ($dialerCampaign->started_at === null) {
            $updates['started_at'] = now();
        }

        $dialerCampaign->update($updates);

        return response()->json([
            'ok' => true,
            'preview' => true,
            'message' => $dialerCampaign->wasChanged('started_at')
                ? 'Preview campaign started.'
                : 'Preview campaign resumed.',
        ]);
    }

    private function validatedCampaignPayload(Request $request, ?DialerCampaign $existing = null): array
    {
        $tenantId = $this->resolveTenantId($request);
        $dialerType = (string) $request->input('dialer_type', $existing?->dialer_type ?? 'power');
        $isPreview = $dialerType === 'preview';

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'campaign_id' => [
                'required',
                Rule::exists('campaigns', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'lead_list_id' => [
                $isPreview ? 'required' : 'nullable',
                Rule::exists('lead_lists', 'id')->where('tenant_id', $tenantId),
            ],
            'dialer_type' => ['required', Rule::in(['power', 'preview'])],
            'lead_order_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'script_id' => [
                $isPreview ? 'required' : 'nullable',
                Rule::exists('scripts', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'omni_queue_id' => [
                $isPreview ? 'required' : 'nullable',
                Rule::exists('omni_queues', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('campaign_id', (int) $request->input('campaign_id')),
            ],
            'offer_timeout_seconds' => [$isPreview ? 'required' : 'nullable', 'integer', 'min:10', 'max:3600'],
            'caller_id' => [$isPreview ? 'nullable' : 'required', 'nullable', 'string', 'max:20'],
            'context' => [$isPreview ? 'nullable' : 'required', 'nullable', 'string', 'max:100'],
            'voicebot_agent_id' => ['nullable', 'string', 'max:120'],
            'max_retries' => ['required', 'integer', 'min:1', 'max:100'],
            'dial_block_size' => [$isPreview ? 'nullable' : 'required', 'nullable', 'integer', 'min:1', 'max:100'],
            'scheduled_at' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date'],
            'daily_start_time' => ['nullable', 'string'],
            'daily_end_time' => ['nullable', 'string'],
        ]);

        if ($existing && $existing->dialer_type !== $validated['dialer_type'] && !$existing->isIdle()) {
            throw ValidationException::withMessages([
                'dialer_type' => 'Dialer type can only be changed while the campaign is idle.',
            ]);
        }

        $scheduledAt = !empty($validated['scheduled_at']) ? \Carbon\Carbon::parse($validated['scheduled_at']) : null;
        $scheduledEndAt = !empty($validated['scheduled_end_at']) ? \Carbon\Carbon::parse($validated['scheduled_end_at']) : null;
        $dailyStartTime = !empty($validated['daily_start_time']) ? $validated['daily_start_time'] : null;
        $dailyEndTime = !empty($validated['daily_end_time']) ? $validated['daily_end_time'] : null;

        $status = $existing?->status ?? 'idle';
        if ($scheduledAt && $scheduledAt->isFuture() && in_array($status, ['idle', 'scheduled'], true)) {
            $status = 'scheduled';
        } elseif (!$scheduledAt && $status === 'scheduled') {
            $status = 'idle';
        }

        if ($isPreview) {
            if (!$this->isValidPreviewScript((int) $validated['script_id'], (int) $validated['campaign_id'], $tenantId)) {
                throw ValidationException::withMessages([
                    'script_id' => 'invalid_preview_script',
                ]);
            }

            if (!$this->isEligiblePreviewQueue((int) $validated['omni_queue_id'], (int) $validated['campaign_id'], $tenantId)) {
                throw ValidationException::withMessages([
                    'omni_queue_id' => 'The selected Queue is not voice-eligible for this campaign. Configure a Queue with Voice settings enabled first.',
                ]);
            }

            $leadList = LeadList::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find((int) $validated['lead_list_id']);

            if (!$leadList || !$this->tableService->isPreviewSchemaPrepared($leadList)) {
                throw ValidationException::withMessages([
                    'lead_list_id' => 'preview_schema_not_prepared. Run: php artisan preview-dialer:prepare-lead-lists --lead-list-id=' . ($leadList?->id ?? 'ID'),
                ]);
            }

            return [
                'name' => $validated['name'] ?? null,
                'campaign_id' => (int) $validated['campaign_id'],
                'omni_queue_id' => (int) $validated['omni_queue_id'],
                'lead_list_id' => (int) $validated['lead_list_id'],
                'lead_order_direction' => $validated['lead_order_direction'] ?? 'asc',
                'script_id' => (int) $validated['script_id'],
                'dialer_type' => 'preview',
                'tenant_id' => $tenantId,
                'caller_id' => $existing?->caller_id ?: 'preview',
                'context' => $existing?->context ?: 'preview',
                'voicebot_agent_id' => $validated['voicebot_agent_id'] ?? $existing?->voicebot_agent_id,
                'max_retries' => (int) $validated['max_retries'],
                'dial_block_size' => $existing?->dial_block_size ?: 1,
                'offer_timeout_seconds' => (int) $validated['offer_timeout_seconds'],
                'status' => $status,
                'started_at' => $existing?->started_at,
                'scheduled_at' => $scheduledAt,
                'scheduled_end_at' => $scheduledEndAt,
                'daily_start_time' => $dailyStartTime,
                'daily_end_time' => $dailyEndTime,
            ];
        }

        return [
            'name' => $validated['name'] ?? null,
            'campaign_id' => (int) $validated['campaign_id'],
            'lead_list_id' => $validated['lead_list_id'] ?? null,
            'lead_order_direction' => $validated['lead_order_direction'] ?? $existing?->lead_order_direction ?? 'asc',
            'script_id' => $validated['script_id'] ?? null,
            'dialer_type' => 'power',
            'tenant_id' => $tenantId,
            'caller_id' => (string) $validated['caller_id'],
            'context' => (string) $validated['context'],
            'voicebot_agent_id' => $validated['voicebot_agent_id'] ?? $existing?->voicebot_agent_id,
            'max_retries' => (int) $validated['max_retries'],
            'dial_block_size' => (int) $validated['dial_block_size'],
            'offer_timeout_seconds' => (int) ($validated['offer_timeout_seconds'] ?? $existing?->offer_timeout_seconds ?? 60),
            'status' => $status,
            'started_at' => $existing?->started_at,
            'scheduled_at' => $scheduledAt,
            'scheduled_end_at' => $scheduledEndAt,
            'daily_start_time' => $dailyStartTime,
            'daily_end_time' => $dailyEndTime,
        ];
    }

    private function isValidPreviewScript(?int $scriptId, int $campaignId, int $tenantId): bool
    {
        if (!$scriptId) {
            return false;
        }

        return Script::withoutGlobalScopes()
            ->where('id', $scriptId)
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->where('is_active', true)
            ->whereIn('direction', ['outbound', 'both'])
            ->exists();
    }

    /**
     * The selected Queue must belong to the campaign + tenant and be voice-eligible
     * (active, voice-capable, enabled Voice settings with asterisk_queue_name).
     * Reuses OmniQueue::scopeVoiceEligible() — the single source of that criteria.
     */
    private function isEligiblePreviewQueue(?int $queueId, int $campaignId, int $tenantId): bool
    {
        if (!$queueId) {
            return false;
        }

        return OmniQueue::withoutGlobalScopes()
            ->where('id', $queueId)
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->voiceEligible()
            ->exists();
    }

    private function resolveTenantId(Request $request): int
    {
        $user = auth()->user();

        if (!$user->isSuperAdmin()) {
            return (int) $user->tenant_id;
        }

        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')->where('is_active', true)],
        ]);

        return (int) $validated['tenant_id'];
    }
}
