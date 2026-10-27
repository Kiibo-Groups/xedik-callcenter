<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\OmniQueue;
use App\Models\OmniQueueVoiceSetting;
use App\Models\Script;
use App\Models\User;
use App\Services\OmniQueue\OmniQueueAsteriskMemberSyncService;
use App\Services\Voice\OmniQueueAsteriskSyncException;
use App\Services\Voice\OmniQueueAsteriskSyncService;
use App\Services\Voice\OmniQueueAsteriskNameResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class OmniQueueController extends Controller
{
    public function __construct(
        private readonly OmniQueueAsteriskNameResolver $queueNameResolver,
        private readonly OmniQueueAsteriskSyncService $asteriskSync,
        private readonly OmniQueueAsteriskMemberSyncService $asteriskMemberSync
    ) {}

    public function index()
    {
        $isSuperAdmin = auth()->user()->isSuperAdmin();
        $queues = OmniQueue::withCount('agents', 'emailAccounts', 'conversations')
                           ->with('tenant')
                           ->orderBy('name')
                           ->paginate(20);

        return view('omni_queues.index', compact('queues', 'isSuperAdmin'));
    }

    public function create()
    {
        $user = auth()->user();
        $selectedCampaignId = old('campaign_id');
        $agents    = $selectedCampaignId ? $this->getEligibleAgentsForCampaign((int) $selectedCampaignId) : collect();
        $campaignsQuery = Campaign::withoutGlobalScopes()->where('is_active', true);
        if (!$user->isSuperAdmin()) {
            $campaignsQuery->where('tenant_id', $user->tenant_id);
        }
        $campaigns = $campaignsQuery->orderBy('name')->get();
        $scripts   = Script::where('is_active', true)->orderBy('name')->get();

        $defaultTenantId = $user->isSuperAdmin() ? 2 : (int) $user->tenant_id;
        if ($selectedCampaignId) {
            $camp = Campaign::withoutGlobalScopes()->find($selectedCampaignId);
            if ($camp?->tenant_id) {
                $defaultTenantId = (int) $camp->tenant_id;
            }
        }

        return view('omni_queues.form', [
            'queue'     => new OmniQueue(),
            'agents'    => $agents,
            'campaigns' => $campaigns,
            'scripts'   => $scripts,
            'voiceSettings' => null,
            'queueAgents' => collect(),
            'ineligibleAssignedAgents' => collect(),
            'selectedCampaignId' => $selectedCampaignId,
            'tenants' => $user->isSuperAdmin() ? \App\Models\Tenant::orderBy('name')->get() : collect(),
            'defaultVoiceQueueName' => $this->queueNameResolver->defaultNameFor(
                $defaultTenantId,
                'queue'
            ),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->validateAgentAssignmentsForCampaign($request, $data['campaign_id'] ?? null);

        if (empty($data['tenant_id'])) {
            if (!empty($data['campaign_id'])) {
                $campaign = Campaign::withoutGlobalScopes()->find($data['campaign_id']);
                if ($campaign) {
                    $data['tenant_id'] = (int) $campaign->tenant_id;
                }
            }
            if (empty($data['tenant_id'])) {
                $data['tenant_id'] = $request->input('tenant_id')
                    ? (int) $request->input('tenant_id')
                    : (auth()->user()->tenant_id ? (int) auth()->user()->tenant_id : 2);
            }
        }

        [$queue, $voiceConfigurationChanged] = DB::transaction(function () use ($request, $data): array {
            $queue = OmniQueue::create($data);

            $voiceConfigurationChanged = $this->persistVoiceSettings($queue, $request);
            $this->syncAgents($queue, $request);

            return [$queue->fresh(), $voiceConfigurationChanged];
        });

        $syncResult = $this->synchronizeAsteriskAfterSave($queue, $voiceConfigurationChanged);
        $memberSyncResult = $this->synchronizeAsteriskMembersAfterSave($queue, $syncResult === 'failed');

        $redirect = redirect()->route('omni-queues.index');

        if ($syncResult === 'failed') {
            return $redirect->with('warning', 'Queue saved, but voice routing could not be configured. You can retry from this page.');
        }

        if ($memberSyncResult === 'failed') {
            return $redirect->with('warning', 'Queue saved, but voice membership could not be configured. You can retry from this page.');
        }

        if ($syncResult === 'synced') {
            return $redirect->with('success', 'Queue saved and voice routing configured.');
        }

        return $redirect->with('success', 'Queue created successfully.');
    }

    public function edit(OmniQueue $omniQueue)
    {
        $omniQueue->load('voiceSettings');
        $selectedCampaignId = old('campaign_id', $omniQueue->campaign_id);
        $queueAgents = $omniQueue->agents()->get();
        $eligibleAgents = $selectedCampaignId ? $this->getEligibleAgentsForCampaign((int) $selectedCampaignId) : collect();
        $ineligibleAssignedAgents = $queueAgents
            ->reject(fn(User $agent) => $eligibleAgents->contains('id', $agent->id))
            ->values();
        $agents = $eligibleAgents
            ->merge($ineligibleAssignedAgents)
            ->unique('id')
            ->values();
        $campaigns   = Campaign::withoutGlobalScopes()
            ->where('tenant_id', $omniQueue->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $scripts     = Script::where('is_active', true)->orderBy('name')->get();

        return view('omni_queues.form', [
            'queue'       => $omniQueue,
            'agents'      => $agents,
            'queueAgents' => $queueAgents,
            'ineligibleAssignedAgents' => $ineligibleAssignedAgents,
            'selectedCampaignId' => $selectedCampaignId,
            'campaigns'   => $campaigns,
            'scripts'     => $scripts,
            'voiceSettings' => $omniQueue->voiceSettings,
            'defaultVoiceQueueName' => $this->queueNameResolver->defaultName($omniQueue),
        ]);
    }

    public function update(Request $request, OmniQueue $omniQueue)
    {
        $data = $this->validated($request);
        $this->validateAgentAssignmentsForCampaign($request, $data['campaign_id'] ?? null);

        [$queue, $voiceConfigurationChanged] = DB::transaction(function () use ($request, $omniQueue, $data): array {
            $omniQueue->update($data);

            $queue = $omniQueue->fresh();
            $voiceConfigurationChanged = $this->persistVoiceSettings($queue, $request);
            $this->syncAgents($queue->fresh(), $request);

            return [$queue->fresh(), $voiceConfigurationChanged];
        });

        $syncResult = $this->synchronizeAsteriskAfterSave($queue, $voiceConfigurationChanged);
        $memberSyncResult = $this->synchronizeAsteriskMembersAfterSave($queue, $syncResult === 'failed');

        $redirect = redirect()->route('omni-queues.index');

        if ($syncResult === 'failed') {
            return $redirect->with('warning', 'Queue saved, but voice routing could not be configured. You can retry from this page.');
        }

        if ($memberSyncResult === 'failed') {
            return $redirect->with('warning', 'Queue saved, but voice membership could not be configured. You can retry from this page.');
        }

        if ($syncResult === 'synced') {
            return $redirect->with('success', 'Queue saved and voice routing configured.');
        }

        return $redirect->with('success', 'Queue updated successfully.');
    }

    public function destroy(OmniQueue $omniQueue)
    {
        $omniQueue->load('voiceSettings');
        $queueName = $omniQueue->voiceSettings?->asterisk_queue_name;
        $queueId = $omniQueue->id;
        $tenantId = $omniQueue->tenant_id;

        $deleted = $omniQueue->delete();

        if (!$deleted) {
            return redirect()->route('omni-queues.index')
                             ->with('error', 'Queue could not be deleted.');
        }

        $this->asteriskMemberSync->deleteQueueMembersForName($queueName, $queueId, $tenantId);

        return redirect()->route('omni-queues.index')
                         ->with('success', 'Queue deleted.');
    }

    public function syncAsterisk(OmniQueue $omniQueue)
    {
        abort_unless(auth()->user()?->hasPermission('manage_omni_queues'), 403);

        try {
            $this->asteriskSync->sync($omniQueue);
        } catch (OmniQueueAsteriskSyncException $e) {
            return back()->with('error', 'Voice routing could not be configured: ' . $e->getMessage());
        }

        $memberSyncResult = $this->asteriskMemberSync->syncQueueMembers($omniQueue);
        if (($memberSyncResult['failed'] ?? 0) > 0) {
            return back()->with('warning', 'Voice routing configured, but voice membership could not be configured.');
        }

        return back()->with('success', 'Voice routing configured successfully.');
    }

    public function agentsByCampaign(int $campaignId): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('manage_omni_queues'), 403);
        $this->assertCampaignVisible($campaignId);

        $agents = $this->getEligibleAgentsForCampaign($campaignId)
            ->map(fn($u) => [
                'id'   => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'voice_configured' => (bool) $u->voiceExtension,
            ]);

        return response()->json(['agents' => $agents]);
    }

    private function getEligibleAgentsForCampaign(int $campaignId): Collection
    {
        $user = auth()->user();
        $this->assertCampaignVisible($campaignId);

        $query = User::with(['role', 'voiceExtension'])
            ->where('is_superadmin', false)
            ->where('is_active', true)
            ->whereHas('campaigns', fn($q) => $q->withoutGlobalScopes()->where('campaigns.id', $campaignId))
            ->whereHas('role', fn($q) => $q->whereIn('name', ['agent', 'supervisor']))
            ->orderBy('name');

        if (!$user?->isSuperAdmin()) {
            $query->where('tenant_id', $user?->tenant_id);
        }

        return $query->get();
    }

    private function validated(Request $request): array
    {
        $tenantId = auth()->user()->isSuperAdmin() ? null : auth()->user()->tenant_id;

        $campaignExistsRule = Rule::exists('campaigns', 'id');
        if ($tenantId) {
            $campaignExistsRule->where('tenant_id', $tenantId);
        }

        $data = $request->validate([
            'tenant_id'        => 'nullable|integer|exists:tenants,id',
            'name'             => 'required|string|max:100',
            'type'             => 'required|in:email,chat,voice,omni',
            'assignment_mode'  => 'required|in:round_robin,least_busy,direct',
            'default_agent_id' => 'nullable|exists:users,id',
            'max_wait_minutes' => 'required|integer|min:1|max:1440',
            'active'           => 'boolean',
            'campaign_id'      => ['nullable', $campaignExistsRule],
            'script_id'        => 'nullable|exists:scripts,id',
        ]);

        $data['active'] = $request->boolean('active');

        // Limpiar default_agent_id si no es modo direct
        if ($data['assignment_mode'] !== 'direct') {
            $data['default_agent_id'] = null;
        }

        // If no campaign, script must also be null
        if (empty($data['campaign_id'])) {
            $data['script_id'] = null;
        }

        // Validate script belongs to the same campaign and tenant
        if (!empty($data['script_id']) && !empty($data['campaign_id'])) {
            $scriptQuery = Script::where('id', $data['script_id'])
                ->where('campaign_id', $data['campaign_id'])
                ->where('is_active', true);

            if ($tenantId) {
                $scriptQuery->where('tenant_id', $tenantId);
            }

            if (!$scriptQuery->exists()) {
                $data['script_id'] = null; // silently clear invalid cross-campaign script
            }
        }

        return $data;
    }

    private function synchronizeAsteriskAfterSave(
        OmniQueue $queue,
        bool $voiceConfigurationChanged
    ): ?string {
        $queue = $queue->fresh('voiceSettings');

        if (!$queue || !$queue->hasVoiceRoutingEnabled()) {
            return null;
        }

        $settings = $queue->voiceSettings;
        $needsSync = $voiceConfigurationChanged
            || in_array($settings?->sync_status, ['pending', 'failed'], true);

        if (!$needsSync) {
            return null;
        }

        try {
            $this->asteriskSync->sync($queue);

            return 'synced';
        } catch (OmniQueueAsteriskSyncException $e) {
            Log::warning('Omni queue Asterisk sync failed after save.', [
                'omni_queue_id' => $queue->id,
                'tenant_id' => $queue->tenant_id,
                'exception_class' => $e::class,
            ]);

            return 'failed';
        }
    }

    private function synchronizeAsteriskMembersAfterSave(OmniQueue $queue, bool $queueSyncFailed): ?string
    {
        if ($queueSyncFailed) {
            return null;
        }

        $result = $this->asteriskMemberSync->syncQueueMembers($queue);

        return ($result['failed'] ?? 0) > 0 ? 'failed' : null;
    }

    private function persistVoiceSettings(OmniQueue $queue, Request $request): bool
    {
        $queue->load('voiceSettings');
        $supportsVoice = in_array($queue->type, ['voice', 'omni'], true);

        if (!$supportsVoice) {
            if ($queue->voiceSettings) {
                return $this->updateVoiceSettings($queue->voiceSettings, [
                    'is_enabled' => false,
                ]);
            }
            return false;
        }

        $existing = $queue->voiceSettings;
        $tenantId = $queue->tenant_id ?: auth()->user()?->tenant_id;

        if (!$tenantId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'type' => 'Voice queue settings require a tenant.',
            ]);
        }

        $voiceData = $this->validatedVoiceSettings($request, $queue, $existing);
        $voiceData['tenant_id'] = (int) $tenantId;
        $voiceData['omni_queue_id'] = $queue->id;

        if ($queue->type === 'voice') {
            $voiceData['is_enabled'] = true;
        } elseif ($queue->type === 'omni') {
            $voiceData['is_enabled'] = $request->boolean('voice_is_enabled');
        }
        unset($voiceData['voice_is_enabled']);

        if (!$existing) {
            OmniQueueVoiceSetting::create($voiceData + [
                'sync_status' => 'pending',
                'sync_error' => null,
                'last_synced_at' => null,
            ]);
            return true;
        }

        return $this->updateVoiceSettings($existing, $voiceData);
    }

    private function validatedVoiceSettings(
        Request $request,
        OmniQueue $queue,
        ?OmniQueueVoiceSetting $existing
    ): array {
        $queueName = trim((string) $request->input(
            'asterisk_queue_name',
            $existing?->asterisk_queue_name ?: $this->queueNameResolver->defaultName($queue)
        ));

        if ($queueName === '') {
            $queueName = $this->queueNameResolver->defaultName($queue);
        } else {
            $queueName = $this->queueNameResolver->normalize($queueName);
        }

        if ($existing
            && $existing->sync_status === 'synced'
            && $existing->last_synced_at
            && $queueName !== $existing->asterisk_queue_name) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'asterisk_queue_name' => 'The internal voice queue identifier cannot be changed after voice routing has been configured.',
            ]);
        }

        $request->merge(['asterisk_queue_name' => $queueName]);

        return $request->validate([
            'voice_is_enabled' => 'nullable|boolean',
            'asterisk_queue_name' => [
                'required',
                'string',
                'max:128',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('omni_queue_voice_settings', 'asterisk_queue_name')
                    ->ignore($existing?->id),
            ],
            'strategy' => 'required|in:ringall,leastrecent,fewestcalls,random,rrmemory,linear,wrandom',
            'timeout' => 'required|integer|min:1|max:300',
            'retry' => 'required|integer|min:1|max:300',
            'wrapup_time' => 'required|integer|min:0|max:3600',
            'maxlen' => 'required|integer|min:0|max:10000',
            'music_on_hold' => 'required|string|max:128',
            'service_level' => 'required|integer|min:0|max:86400',
            'weight' => 'nullable|integer',
            'ring_in_use' => 'nullable|boolean',
            'autopause' => 'nullable|boolean',
        ]) + [
            'ring_in_use' => false,
            'autopause' => false,
        ];
    }

    private function updateVoiceSettings(OmniQueueVoiceSetting $settings, array $data): bool
    {
        $meaningful = [
            'is_enabled',
            'asterisk_queue_name',
            'strategy',
            'timeout',
            'retry',
            'wrapup_time',
            'maxlen',
            'music_on_hold',
            'service_level',
            'weight',
            'ring_in_use',
            'autopause',
        ];

        $updates = $data;
        $changed = false;

        foreach ($meaningful as $field) {
            if (array_key_exists($field, $data) && $settings->{$field} != $data[$field]) {
                $updates['sync_status'] = 'pending';
                $updates['sync_error'] = null;
                $changed = true;
                break;
            }
        }

        $settings->update($updates);

        return $changed;
    }

    private function syncAgents(OmniQueue $queue, Request $request): void
    {
        $agentsInput = $request->input('agents', []);

        $syncData = [];
        foreach ($agentsInput as $userId => $pivot) {
            if (empty($pivot['enabled'])) continue;

            $syncData[(int) $userId] = [
                'max_emails' => (int) ($pivot['max_emails'] ?? 3),
                'max_chats'  => (int) ($pivot['max_chats']  ?? 1),
                'priority'   => (int) ($pivot['priority']   ?? 0),
                'active'     => isset($pivot['active']) ? 1 : 0,
            ];
        }

        $queue->agents()->sync($syncData);
    }

    private function validateAgentAssignmentsForCampaign(Request $request, mixed $campaignId): void
    {
        $selectedAgentIds = collect($request->input('agents', []))
            ->filter(fn(array $pivot) => !empty($pivot['enabled']))
            ->keys()
            ->map(fn($id) => (int) $id)
            ->values();

        if ($selectedAgentIds->isEmpty()) {
            return;
        }

        $invalidPriorities = collect($request->input('agents', []))
            ->filter(fn(array $pivot) => !empty($pivot['enabled']))
            ->filter(function (array $pivot): bool {
                if (!array_key_exists('priority', $pivot) || $pivot['priority'] === null || $pivot['priority'] === '') {
                    return true;
                }

                return !OmniQueue::isRoutingPriorityValue($pivot['priority']);
            });

        if ($invalidPriorities->isNotEmpty()) {
            throw ValidationException::withMessages([
                'agents' => 'Select a valid routing priority for each assigned agent.',
            ]);
        }

        if (empty($campaignId)) {
            throw ValidationException::withMessages([
                'agents' => 'Select a campaign before assigning agents to this queue.',
            ]);
        }

        $eligibleIds = $this->getEligibleAgentsForCampaign((int) $campaignId)->pluck('id');
        $invalidIds = $selectedAgentIds->diff($eligibleIds);

        if ($invalidIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'agents' => 'One or more selected agents are not eligible for the selected campaign.',
            ]);
        }
    }

    private function assertCampaignVisible(int $campaignId): void
    {
        $user = auth()->user();
        $query = Campaign::withoutGlobalScopes()
            ->where('id', $campaignId)
            ->where('is_active', true);

        if (!$user?->isSuperAdmin()) {
            $query->where('tenant_id', $user?->tenant_id);
        }

        abort_unless($query->exists(), 404);
    }
}
