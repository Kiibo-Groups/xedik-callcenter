<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Campaign;
use App\Models\LeadStatus;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $user         = Auth::user();
        $isSuperAdmin = (bool) $user->is_superadmin;
        $isAdmin      = $user->isAdmin() || $isSuperAdmin;

        $campaigns = $isAdmin
            ? Campaign::where('is_active', true)->orderBy('name')->get()
            : $user->campaigns()->where('is_active', true)->orderBy('name')->get();

        $query = Lead::with(['agent', 'campaign', 'lastCall'])
            ->when($isSuperAdmin, fn($q) => $q->with('tenant'))
            ->orderBy('updated_at', 'desc');

        if (!$isAdmin) {
            $campaignIds = $campaigns->pluck('id');
            $query->where(function($q) use ($campaignIds, $user) {
                $q->whereIn('campaign_id', $campaignIds)
                  ->orWhere(function($q2) use ($user) {
                      $q2->whereNull('campaign_id')->where('assigned_to', $user->id);
                  });
            });
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($campaignId = $request->get('campaign_id')) {
            $query->where('campaign_id', $campaignId);
        }

        if ($isAdmin && $agentId = $request->get('agent_id')) {
            $query->where('assigned_to', $agentId);
        }

        $leads    = $query->paginate(25)->withQueryString();
        $statuses = LeadStatus::where('is_active', true)->orderBy('order')->get()->unique('slug');
        $agents   = $isAdmin ? User::where('is_active', true)->orderBy('name')->get() : collect();

        // Stats filtrados por campaña del agente
        $statsQuery = Lead::query();
        if (!$isAdmin) {
            $statsQuery->whereIn('campaign_id', $campaigns->pluck('id'));
        }
        $totalLeads = (clone $statsQuery)->count();
        $newLeads   = (clone $statsQuery)->where('status', 'new')->count();
        $closedWon  = (clone $statsQuery)->where('status', 'closed_won')->count();
        $scheduled  = \App\Models\Call::where('is_scheduled', true)->where('completed', false)
            ->when(!$isAdmin, function($q) use ($campaigns) {
                $q->whereHas('lead', fn($l) => $l->whereIn('campaign_id', $campaigns->pluck('id')));
            })->count();

        $leadListsQuery = \App\Models\LeadList::query()->where('status', '!=', 'archived');
        if (!$isSuperAdmin && $user->tenant_id) {
            $leadListsQuery->where('tenant_id', $user->tenant_id);
        }
        $leadLists = $leadListsQuery->orderBy('name')->get(['id', 'name', 'tenant_id']);

        return view('leads.index', compact('leads', 'statuses', 'agents', 'campaigns',
            'totalLeads', 'newLeads', 'closedWon', 'scheduled', 'isSuperAdmin', 'leadLists'));
    }

    public function myLeads(Request $request)
    {
        $user = Auth::user();

        $campaigns = $user->isAdmin()
            ? Campaign::where('is_active', true)->orderBy('name')->get()
            : $user->campaigns()->where('is_active', true)->orderBy('name')->get();

        $query = Lead::with(['agent', 'campaign', 'lastCall'])
            ->where('assigned_to', $user->id)
            ->orderBy('updated_at', 'desc');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($campaignId = $request->get('campaign_id')) {
            $query->where('campaign_id', $campaignId);
        }

        $leads    = $query->paginate(25)->withQueryString();
        $statuses = LeadStatus::where('is_active', true)->orderBy('sort_order')->get();
        $agents   = collect();

        // Stats solo de los leads asignados a este agente
        $statsBase  = Lead::where('assigned_to', $user->id);
        $totalLeads = (clone $statsBase)->count();
        $newLeads   = (clone $statsBase)->where('status', 'new')->count();
        $closedWon  = (clone $statsBase)->where('status', 'closed_won')->count();
        $scheduled  = \App\Models\Call::where('is_scheduled', true)->where('completed', false)
            ->whereHas('lead', fn($l) => $l->where('assigned_to', $user->id))
            ->count();

        $pageTitle    = 'My Leads';
        $filterRoute  = 'leads.my';
        $isSuperAdmin = false; // agents/admins using myLeads are never superadmin
        return view('leads.index', compact('leads', 'statuses', 'agents', 'campaigns',
            'totalLeads', 'newLeads', 'closedWon', 'scheduled', 'pageTitle', 'filterRoute', 'isSuperAdmin'));
    }

    public function create()
    {
        $user = Auth::user();
        $isSuperAdmin = $user->isSuperAdmin();
        $isAdmin = $user->isAdmin() || $isSuperAdmin;

        $statuses  = LeadStatus::where('is_active', true)->orderBy('order')->get()->unique('slug');
        $sources   = LeadSource::where('is_active', true)->orderBy('order')->get()->unique('name');
        $agents    = $isAdmin ? User::where('is_active', true)->orderBy('name')->get() : collect();
        $campaigns = $isAdmin
            ? Campaign::where('is_active', true)->orderBy('name')->get()
            : $user->campaigns()->where('is_active', true)->orderBy('name')->get();
        $tenants   = $isSuperAdmin ? \App\Models\Tenant::where('is_active', true)->orderBy('name')->get() : collect();

        return view('leads.create', compact('statuses', 'sources', 'agents', 'campaigns', 'tenants', 'isSuperAdmin'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $isSuperAdmin = $user->isSuperAdmin();

        $rules = [
            'first_name'  => 'required|string|max:100',
            'last_name'   => 'required|string|max:100',
            'email'       => 'nullable|email|max:150',
            'phone'       => 'nullable|string|max:30',
            'phone_2'     => 'nullable|string|max:30',
            'address'     => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:100',
            'state'       => 'nullable|string|max:100',
            'zip'         => 'nullable|string|max:20',
            'country'     => 'nullable|string|max:100',
            'source'      => 'nullable|string|max:100',
            'notes'       => 'nullable|string',
            'status'      => 'required|string',
            'value'       => 'nullable|numeric|min:0',
            'assigned_to' => 'nullable|exists:users,id',
            'campaign_id' => 'nullable|exists:campaigns,id',
        ];

        if ($isSuperAdmin) {
            $rules['tenant_id'] = 'nullable|exists:tenants,id';
        }

        $validated = $request->validate($rules);

        if (!$user->isAdmin() && !$isSuperAdmin) {
            $validated['assigned_to'] = $user->id;
        }

        // Determine tenant_id
        $tenantId = $user->tenant_id;
        if (empty($tenantId)) {
            if (!empty($validated['tenant_id'])) {
                $tenantId = (int) $validated['tenant_id'];
            } elseif (!empty($validated['campaign_id'])) {
                $campaign = Campaign::withoutGlobalScopes()->find($validated['campaign_id']);
                $tenantId = $campaign?->tenant_id;
            } elseif (!empty($validated['assigned_to'])) {
                $assignedUser = User::find($validated['assigned_to']);
                $tenantId = $assignedUser?->tenant_id;
            }

            if (empty($tenantId)) {
                $tenantId = \App\Models\Tenant::where('is_active', true)->value('id') ?? 2;
            }
        }

        $validated['tenant_id'] = $tenantId;

        $lead = Lead::create($validated);

        return redirect()->route('leads.show', $lead)
            ->with('success', 'Lead created successfully.');
    }

    public function show(Lead $lead)
    {
        $user = Auth::user();
        $isAdmin = $user->isAdmin() || $user->isSuperAdmin();

        if (!$isAdmin) {
            $campaignIds = $user->campaigns()->pluck('campaigns.id');
            $canAccess = $lead->assigned_to === $user->id
                || ($lead->campaign_id && $campaignIds->contains($lead->campaign_id));
            abort_unless($canAccess, 403);
        }

        $lead->load(['agent', 'campaign', 'calls.agent']);
        $statuses = LeadStatus::where('is_active', true)->orderBy('order')->get()->unique('slug');
        $agents   = $isAdmin
            ? User::where('is_active', true)->orderBy('name')->get()
            : collect();

        $convItems = \App\Models\ConversationReport::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->where('lead_id', $lead->id)
            ->get()
            ->map(fn($r) => (object) [
                'type'                  => 'conversation',
                'date'                  => $r->created_at,
                'id'                    => $r->id,
                'channel'               => $r->channel,
                'direction'             => $r->direction,
                'subject'               => $r->subject,
                'first_message_preview' => $r->first_message_preview,
                'disposition'           => $r->disposition,
                'disp_style'            => null,
                'notes'                 => $r->notes,
                'agent_name'            => $r->agent_name,
                'message_count'         => $r->message_count,
                'duration_seconds'      => $r->duration_seconds,
            ]);

        $callItems = $lead->calls->where('is_scheduled', false)
            ->map(fn($c) => (object) [
                'type'             => 'call',
                'date'             => $c->called_at,
                'id'               => $c->id,
                'channel'          => 'phone',
                'direction'        => $c->direction ?? 'outbound',
                'disposition'      => $c->disposition_label,
                'disp_style'       => $c->disposition_badge_style,
                'notes'            => $c->notes,
                'agent_name'       => $c->agent?->name ?? '—',
                'duration_seconds' => $c->duration ?? 0,
                'duration_fmt'     => $c->duration ? $c->duration_formatted : null,
                'recording_id'     => $c->recording_id,
                'dialer_campaign'  => $c->dialer_campaign,
                'uniqueid'         => $c->uniqueid,
            ]);

        $history      = $convItems->concat($callItems)->sortByDesc('date')->values();
        $dispositions = \App\Models\DialerDisposition::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('leads.show', compact('lead', 'statuses', 'agents', 'history', 'dispositions'));
    }

    public function conversationThread(int $reportId): \Illuminate\Http\JsonResponse
    {
        $user   = Auth::user();
        $report = \App\Models\ConversationReport::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->findOrFail($reportId);

        abort_unless($user->isSuperAdmin() || $report->tenant_id === $user->tenant_id, 403);

        $report->load('messageReports');
        $messages = $report->messageReports->map(fn($m) => [
            'id'         => $m->id,
            'direction'  => $m->direction,
            'from_email' => $m->from_email,
            'to_email'   => $m->to_email,
            'from_name'  => $m->from_name,
            'body_html'  => $m->body_html,
            'body_text'  => $m->body_text,
            'sent_at'    => $m->sent_at?->format('M d, Y · g:i:s A'),
        ])->toArray();

        if (empty($messages) && $report->conversation_id) {
            $conv = \App\Models\Conversation::with('messages')->find($report->conversation_id);
            if ($conv) {
                $messages = $conv->messages->map(fn($m) => [
                    'id'         => $m->id,
                    'direction'  => $m->direction,
                    'from_email' => $m->from_email,
                    'to_email'   => $m->to_email,
                    'from_name'  => $m->from_name,
                    'body_html'  => $m->body_html,
                    'body_text'  => $m->body_text,
                    'sent_at'    => $m->sent_at?->format('M d, Y · g:i:s A'),
                ])->toArray();
            }
        }

        return response()->json([
            'conversation' => [
                'id'          => $report->conversation_id,
                'subject'     => $report->subject,
                'from_email'  => $report->contact_email,
                'from_name'   => $report->lead_name,
                'status'      => 'resolved',
                'channel'     => $report->channel,
                'disposition' => $report->disposition,
                'agent_name'  => $report->agent_name,
                'resolved_at' => $report->resolved_at?->format('M d, Y · g:i A'),
            ],
            'messages'          => $messages,
            'messages_archived' => empty($messages),
        ]);
    }

    public function edit(Lead $lead)
    {
        $user = Auth::user();
        $isAdmin = $user->isAdmin() || $user->isSuperAdmin();

        if (!$isAdmin) {
            $campaignIds = $user->campaigns()->pluck('campaigns.id');
            $canAccess = $lead->assigned_to === $user->id
                || ($lead->campaign_id && $campaignIds->contains($lead->campaign_id));
            abort_unless($canAccess, 403);
        }

        $statuses  = LeadStatus::where('is_active', true)->orderBy('order')->get()->unique('slug');
        $sources   = LeadSource::where('is_active', true)->orderBy('order')->get()->unique('name');
        $agents    = $isAdmin ? User::where('is_active', true)->orderBy('name')->get() : collect();
        $campaigns = $isAdmin
            ? Campaign::where('is_active', true)->orderBy('name')->get()
            : $user->campaigns()->where('is_active', true)->orderBy('name')->get();

        return view('leads.edit', compact('lead', 'statuses', 'sources', 'agents', 'campaigns'));
    }

    public function fullUpdate(Request $request, Lead $lead)
    {
        $user = Auth::user();
        $isAdmin = $user->isAdmin() || $user->isSuperAdmin();

        if (!$isAdmin) {
            $campaignIds = $user->campaigns()->pluck('campaigns.id');
            $canAccess = $lead->assigned_to === $user->id
                || ($lead->campaign_id && $campaignIds->contains($lead->campaign_id));
            abort_unless($canAccess, 403);
        }

        $validated = $request->validate([
            'first_name'  => 'required|string|max:100',
            'last_name'   => 'required|string|max:100',
            'email'       => 'nullable|email|max:150',
            'phone'       => 'nullable|string|max:30',
            'phone_2'     => 'nullable|string|max:30',
            'address'     => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:100',
            'state'       => 'nullable|string|max:100',
            'zip'         => 'nullable|string|max:20',
            'country'     => 'nullable|string|max:100',
            'source'      => 'nullable|string|max:100',
            'notes'       => 'nullable|string',
            'status'      => 'required|string',
            'value'       => 'nullable|numeric|min:0',
            'assigned_to' => 'nullable|exists:users,id',
            'campaign_id' => 'nullable|exists:campaigns,id',
        ]);

        if (!$isAdmin) {
            unset($validated['assigned_to']);
        }

        $lead->update($validated);

        return redirect()->route('leads.show', $lead)
            ->with('success', 'Lead updated successfully.');
    }

    public function update(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'status'      => 'sometimes|string',
            'assigned_to' => 'sometimes|nullable|exists:users,id',
            'campaign_id' => 'sometimes|nullable|exists:campaigns,id',
        ]);

        // No pisar assigned_to si no viene en el request
        if (!$request->has('assigned_to')) {
            unset($validated['assigned_to']);
        }

        $lead->update($validated);

        return response()->json(['success' => true]);
    }

    public function destroy(Lead $lead)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin() || $user->isSuperAdmin() || $lead->assigned_to === $user->id, 403);
        $lead->calls()->delete();
        $lead->delete();
        return redirect()->route('leads.index')->with('success', 'Lead deleted successfully.');
    }

    public function bulkAddToList(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin() || $user->isSuperAdmin() || $user->hasPermission('manage_lead_lists'), 403);

        $request->validate([
            'lead_ids' => 'required|array|min:1',
            'lead_ids.*' => 'integer',
            'lead_list_id' => 'required|integer',
        ]);

        $leadList = \App\Models\LeadList::findOrFail($request->integer('lead_list_id'));
        if (!$user->isSuperAdmin() && $leadList->tenant_id != $user->tenant_id) {
            abort(403);
        }

        $leadsQuery = Lead::whereIn('id', $request->input('lead_ids'));
        if (!$user->isSuperAdmin()) {
            $leadsQuery->where('tenant_id', $user->tenant_id);
        }
        $leads = $leadsQuery->get();

        if ($leads->isEmpty()) {
            return back()->with('error', 'No se encontraron prospectos válidos seleccionados.');
        }

        $table = $leadList->table_name;
        $tenantId = (int) $leadList->tenant_id;

        $insertedCount = 0;
        $dncSkipped = 0;
        $duplicateSkipped = 0;
        $noPhoneSkipped = 0;

        foreach ($leads as $lead) {
            $rawPhone = (string) $lead->phone;
            $phone = preg_replace('/[^\d+]/', '', $rawPhone);
            if (strlen($phone) < 7) {
                $noPhoneSkipped++;
                continue;
            }

            // Check DNC
            $isDnc = \Illuminate\Support\Facades\DB::table('dialer_dnc')
                ->where('tenant_id', $tenantId)
                ->where('phone', $phone)
                ->exists();
            if ($isDnc) {
                $dncSkipped++;
                continue;
            }

            // Check duplicate in table
            $exists = \Illuminate\Support\Facades\DB::table($table)->where('phone', $phone)->exists();
            if ($exists) {
                $duplicateSkipped++;
                continue;
            }

            $custom = [
                'first_name' => $lead->first_name ?? '',
                'last_name' => $lead->last_name ?? '',
                'email' => $lead->email ?? '',
                'city' => $lead->city ?? '',
                'crm_lead_id' => (string) $lead->id,
            ];

            \Illuminate\Support\Facades\DB::table($table)->insert([
                'phone' => $phone,
                'status' => 'NEW',
                'called_count' => 0,
                'custom_fields' => json_encode($custom, JSON_UNESCAPED_UNICODE),
            ]);

            $insertedCount++;
        }

        if ($insertedCount > 0) {
            $leadList->increment('total_leads', $insertedCount);
            if ($leadList->status === 'draft') {
                $leadList->update(['status' => 'ready']);
            }
        }

        $msg = "{$insertedCount} prospecto(s) agregados exitosamente a la lista '{$leadList->name}'.";
        $omitted = [];
        if ($duplicateSkipped > 0) $omitted[] = "{$duplicateSkipped} ya existían";
        if ($dncSkipped > 0) $omitted[] = "{$dncSkipped} en lista DNC";
        if ($noPhoneSkipped > 0) $omitted[] = "{$noPhoneSkipped} sin teléfono válido";

        if (!empty($omitted)) {
            $msg .= " (" . implode(', ', $omitted) . ")";
        }

        return back()->with('success', $msg);
    }
}
