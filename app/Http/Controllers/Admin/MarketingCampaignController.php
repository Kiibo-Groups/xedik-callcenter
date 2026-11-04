<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\LeadList;
use App\Models\MarketingCampaign;
use App\Models\SmsChannel;
use App\Models\SmsTemplate;
use App\Models\Tenant;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTemplate;
use App\Services\Marketing\MarketingCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MarketingCampaignController extends Controller
{
    public function __construct(
        private readonly MarketingCampaignService $marketingService,
    ) {}

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user->isSuperAdmin()
            || $user->hasPermission('manage_marketing_campaigns')
            || $user->hasPermission('manage_dialer_campaigns'),
            403
        );
    }

    public function index()
    {
        $this->authorizeAccess();

        $user         = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        $tenants = $isSuperAdmin
            ? Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        $campaigns = MarketingCampaign::with([
            'tenant', 'campaign', 'leadList',
            'emailAccount', 'smsChannel', 'whatsappAccount',
            'emailTemplate', 'smsTemplate', 'whatsappTemplate',
        ])->orderBy('created_at', 'desc')->get();

        $catalogs = $this->loadFormCatalogs($isSuperAdmin ? null : (int) $user->tenant_id);

        $stats = [];
        foreach ($campaigns as $mc) {
            $stats[$mc->id] = $this->marketingService->refreshCounters($mc, false);
        }

        return view('marketing_campaigns.index', array_merge([
            'campaigns'    => $campaigns,
            'stats'        => $stats,
            'isSuperAdmin' => $isSuperAdmin,
            'tenants'      => $tenants,
        ], $catalogs));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess();

        $user         = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        $validated = $this->validateCampaign($request, $isSuperAdmin);
        $tenantId  = $isSuperAdmin ? (int) $validated['tenant_id'] : (int) $user->tenant_id;

        $status = 'idle';
        if (!empty($validated['scheduled_at']) && now()->lt($validated['scheduled_at'])) {
            $status = 'scheduled';
        }

        MarketingCampaign::withoutGlobalScopes()->create([
            'tenant_id'            => $tenantId,
            'name'                 => $validated['name'],
            'campaign_id'          => $validated['campaign_id'] ?? null,
            'lead_list_id'         => $validated['lead_list_id'] ?? null,
            'channel'              => $validated['channel'],
            'email_account_id'     => $validated['email_account_id'] ?? null,
            'sms_channel_id'       => $validated['sms_channel_id'] ?? null,
            'whatsapp_account_id'  => $validated['whatsapp_account_id'] ?? null,
            'email_template_id'    => $validated['email_template_id'] ?? null,
            'sms_template_id'      => $validated['sms_template_id'] ?? null,
            'whatsapp_template_id' => $validated['whatsapp_template_id'] ?? null,
            'custom_subject'       => $validated['custom_subject'] ?? null,
            'custom_body'          => $validated['custom_body'] ?? null,
            'batch_size'           => (int) ($validated['batch_size'] ?? 20),
            'delay_seconds'        => (int) ($validated['delay_seconds'] ?? 2),
            'max_retries'          => (int) ($validated['max_retries'] ?? 1),
            'open_conversation'    => $request->boolean('open_conversation', true),
            'status'               => $status,
            'scheduled_at'         => $validated['scheduled_at'] ?: null,
            'scheduled_end_at'     => $validated['scheduled_end_at'] ?: null,
            'daily_start_time'     => $validated['daily_start_time'] ?: null,
            'daily_end_time'       => $validated['daily_end_time'] ?: null,
        ]);

        return redirect()->route('marketing-campaigns.index')
            ->with('success', "Campaña de Marketing \"{$validated['name']}\" creada correctamente.");
    }

    public function edit(MarketingCampaign $marketingCampaign)
    {
        $this->authorizeAccess();

        $user         = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        $tenants = $isSuperAdmin
            ? Tenant::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        $catalogs = $this->loadFormCatalogs($isSuperAdmin ? null : (int) $marketingCampaign->tenant_id);

        $queueItems = DB::table('marketing_campaign_queue')
            ->where('marketing_campaign_id', $marketingCampaign->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $stats = $this->marketingService->refreshCounters($marketingCampaign, false);

        return view('marketing_campaigns.edit', array_merge([
            'marketingCampaign' => $marketingCampaign,
            'queueItems'        => $queueItems,
            'stats'             => $stats,
            'isSuperAdmin'      => $isSuperAdmin,
            'tenants'           => $tenants,
        ], $catalogs));
    }

    public function update(Request $request, MarketingCampaign $marketingCampaign): RedirectResponse
    {
        $this->authorizeAccess();

        if ($marketingCampaign->isRunning()) {
            return back()->with('error', 'Pausa o detén la campaña antes de editarla.');
        }

        $user         = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        $validated = $this->validateCampaign($request, $isSuperAdmin);
        $tenantId  = $isSuperAdmin ? (int) $validated['tenant_id'] : (int) $marketingCampaign->tenant_id;

        $status = $marketingCampaign->status;
        if (!empty($validated['scheduled_at']) && now()->lt($validated['scheduled_at']) && in_array($status, ['idle', 'scheduled'], true)) {
            $status = 'scheduled';
        } elseif (empty($validated['scheduled_at']) && $status === 'scheduled') {
            $status = 'idle';
        }

        $marketingCampaign->update([
            'tenant_id'            => $tenantId,
            'name'                 => $validated['name'],
            'campaign_id'          => $validated['campaign_id'] ?? null,
            'lead_list_id'         => $validated['lead_list_id'] ?? null,
            'channel'              => $validated['channel'],
            'email_account_id'     => $validated['email_account_id'] ?? null,
            'sms_channel_id'       => $validated['sms_channel_id'] ?? null,
            'whatsapp_account_id'  => $validated['whatsapp_account_id'] ?? null,
            'email_template_id'    => $validated['email_template_id'] ?? null,
            'sms_template_id'      => $validated['sms_template_id'] ?? null,
            'whatsapp_template_id' => $validated['whatsapp_template_id'] ?? null,
            'custom_subject'       => $validated['custom_subject'] ?? null,
            'custom_body'          => $validated['custom_body'] ?? null,
            'batch_size'           => (int) ($validated['batch_size'] ?? 20),
            'delay_seconds'        => (int) ($validated['delay_seconds'] ?? 2),
            'max_retries'          => (int) ($validated['max_retries'] ?? 1),
            'open_conversation'    => $request->boolean('open_conversation', false),
            'status'               => $status,
            'scheduled_at'         => $validated['scheduled_at'] ?: null,
            'scheduled_end_at'     => $validated['scheduled_end_at'] ?: null,
            'daily_start_time'     => $validated['daily_start_time'] ?: null,
            'daily_end_time'       => $validated['daily_end_time'] ?: null,
        ]);

        return redirect()->route('marketing-campaigns.index')
            ->with('success', "Campaña \"{$marketingCampaign->name}\" actualizada.");
    }

    public function destroy(MarketingCampaign $marketingCampaign): RedirectResponse
    {
        $this->authorizeAccess();

        if (in_array($marketingCampaign->status, ['running', 'paused'], true)) {
            return back()->with('error', 'Detén la campaña antes de eliminarla.');
        }

        DB::table('marketing_campaign_queue')
            ->where('marketing_campaign_id', $marketingCampaign->id)
            ->delete();

        $name = $marketingCampaign->name;
        $marketingCampaign->delete();

        return redirect()->route('marketing-campaigns.index')
            ->with('success', "Campaña \"{$name}\" eliminada.");
    }

    // ── Actions (Play / Pause / Stop / Reset / Stats) ─────────────────────────

    public function play(MarketingCampaign $marketingCampaign): JsonResponse
    {
        $this->authorizeAccess();

        $result = $this->marketingService->startCampaign($marketingCampaign);
        if (!($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        // Dispatch first batch immediately so small campaigns finish right away
        try {
            $this->marketingService->dispatchBatch($marketingCampaign->fresh());
        } catch (\Throwable $_) {}

        return response()->json($result);
    }

    public function pause(MarketingCampaign $marketingCampaign): JsonResponse
    {
        $this->authorizeAccess();

        $marketingCampaign->update(['status' => 'paused']);

        return response()->json(['ok' => true]);
    }

    public function stop(MarketingCampaign $marketingCampaign): JsonResponse
    {
        $this->authorizeAccess();

        DB::table('marketing_campaign_queue')
            ->where('marketing_campaign_id', $marketingCampaign->id)
            ->whereIn('status', ['pending', 'sending'])
            ->delete();

        $marketingCampaign->update([
            'status'       => 'idle',
            'completed_at' => null,
        ]);

        $this->marketingService->refreshCounters($marketingCampaign, false);

        return response()->json(['ok' => true]);
    }

    public function reset(MarketingCampaign $marketingCampaign): JsonResponse
    {
        $this->authorizeAccess();

        DB::table('marketing_campaign_queue')
            ->where('marketing_campaign_id', $marketingCampaign->id)
            ->delete();

        $marketingCampaign->update([
            'status'        => 'idle',
            'started_at'    => null,
            'completed_at'  => null,
            'total_queued'  => 0,
            'sent_count'    => 0,
            'failed_count'  => 0,
            'skipped_count' => 0,
        ]);

        return response()->json(['ok' => true]);
    }

    public function refreshStats(MarketingCampaign $marketingCampaign): RedirectResponse|JsonResponse
    {
        $this->authorizeAccess();

        if ($marketingCampaign->isRunning()) {
            try {
                $this->marketingService->dispatchBatch($marketingCampaign);
            } catch (\Throwable $_) {}
        }

        $stats = $this->marketingService->refreshCounters($marketingCampaign, true);

        if (request()->expectsJson()) {
            return response()->json(['ok' => true, 'stats' => $stats, 'status' => $marketingCampaign->fresh()->status]);
        }

        return back()->with('success', 'Estadísticas actualizadas.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateCampaign(Request $request, bool $isSuperAdmin): array
    {
        $rules = [
            'name'                 => ['required', 'string', 'max:150'],
            'channel'              => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'campaign_id'          => ['nullable', 'integer', 'exists:campaigns,id'],
            'lead_list_id'         => ['nullable', 'integer', 'exists:lead_lists,id'],
            'email_account_id'     => ['nullable', 'integer', 'exists:email_accounts,id'],
            'sms_channel_id'       => ['nullable', 'integer', 'exists:sms_channels,id'],
            'whatsapp_account_id'  => ['nullable', 'integer', 'exists:whatsapp_accounts,id'],
            'email_template_id'    => ['nullable', 'integer', 'exists:email_templates,id'],
            'sms_template_id'      => ['nullable', 'integer', 'exists:sms_templates,id'],
            'whatsapp_template_id' => ['nullable', 'integer', 'exists:whatsapp_templates,id'],
            'custom_subject'       => ['nullable', 'string', 'max:255'],
            'custom_body'          => ['nullable', 'string', 'max:10000'],
            'batch_size'           => ['required', 'integer', 'min:1', 'max:200'],
            'delay_seconds'        => ['nullable', 'integer', 'min:1', 'max:3600'],
            'max_retries'          => ['required', 'integer', 'min:1', 'max:10'],
            'scheduled_at'         => ['nullable', 'date'],
            'scheduled_end_at'     => ['nullable', 'date'],
            'daily_start_time'     => ['nullable'],
            'daily_end_time'       => ['nullable'],
        ];

        if ($isSuperAdmin) {
            $rules['tenant_id'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $request->validate($rules);
    }

    private function loadFormCatalogs(?int $tenantId): array
    {
        $scope = fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q;

        return [
            'crmCampaigns' => Campaign::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name']),
            'leadLists' => LeadList::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->whereIn('status', ['ready', 'draft'])
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'total_leads', 'status']),
            'emailAccounts' => EmailAccount::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'email']),
            'smsChannels' => SmsChannel::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->where('is_active', true)
                ->orderBy('display_name')
                ->get(['id', 'tenant_id', 'display_name', 'address']),
            'whatsappAccounts' => WhatsappAccount::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->where('is_active', true)
                ->orderBy('display_name')
                ->get(['id', 'tenant_id', 'display_name', 'phone_number']),
            'emailTemplates' => EmailTemplate::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'subject']),
            'smsTemplates' => SmsTemplate::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'body']),
            'whatsappTemplates' => WhatsappTemplate::withoutGlobalScopes()
                ->when($tenantId, $scope)
                ->where('is_active', true)
                ->orderBy('display_name')
                ->get(['id', 'tenant_id', 'name', 'display_name', 'language', 'body_preview']),
        ];
    }
}

