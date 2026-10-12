<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\Scopes\TenantScope;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    // ── POST /api/mobile/leads ────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');
        $user     = $request->attributes->get('mobile_user');

        $validated = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'nullable|string|max:100',
            'email'           => 'nullable|email|max:200',
            'phone'           => 'nullable|string|max:30',
            'status'          => 'nullable|string|max:50',
            'campaign_id'     => 'nullable|integer',
            'source'          => 'nullable|string|max:100',
            'conversation_id' => 'nullable|integer',
        ]);

        $lead = Lead::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id'   => $tenantId,
            'first_name'  => $validated['first_name'],
            'last_name'   => $validated['last_name'] ?? '',
            'email'       => $validated['email'] ?? null,
            'phone'       => $validated['phone'] ?? null,
            'status'      => $validated['status'] ?? 'new',
            'campaign_id' => $validated['campaign_id'] ?? null,
            'source'      => $validated['source'] ?? 'PWA',
            'assigned_to' => $user->id,
        ]);

        if (! empty($validated['conversation_id'])) {
            Conversation::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('id', $validated['conversation_id'])
                ->update(['lead_id' => $lead->id]);
        }

        $lead->load(['statusModel:id,slug,name,bg_color,color', 'campaign:id,name', 'tags:id,name,bg_color,color']);

        return response()->json([
            'success' => true,
            'lead'    => $this->formatLead($lead, detail: true),
        ], 201);
    }

    // ── GET /api/mobile/leads ─────────────────────────────────────────────────
    // Query: mine=1  status=  search=  page=  per_page=

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');
        $user     = $request->attributes->get('mobile_user');

        $query = Lead::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->with(['statusModel:id,slug,name,bg_color,color', 'campaign:id,name', 'tags:id,name,bg_color,color'])
            ->orderBy('created_at', 'desc');

        if ($request->boolean('mine')) {
            $query->where('assigned_to', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $q = '%' . $request->query('search') . '%';
            $query->where(function ($qb) use ($q): void {
                $qb->where('first_name', 'like', $q)
                   ->orWhere('last_name', 'like', $q)
                   ->orWhere('email', 'like', $q)
                   ->orWhere('phone', 'like', $q);
            });
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $paginated->map(fn ($l) => $this->formatLead($l)),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    // ── GET /api/mobile/leads/{id} ────────────────────────────────────────────

    public function show(Request $request, int $id): JsonResponse
    {
        $lead = $this->resolveLead($id, $request->attributes->get('mobile_tenant_id'));

        return response()->json([
            'success' => true,
            'lead'    => $this->formatLead($lead, detail: true),
        ]);
    }

    // ── PUT /api/mobile/leads/{id} ────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $lead = $this->resolveLead($id, $request->attributes->get('mobile_tenant_id'));

        $validated = $request->validate([
            'first_name'  => 'sometimes|string|max:100',
            'last_name'   => 'sometimes|string|max:100',
            'email'       => 'sometimes|nullable|email|max:200',
            'phone'       => 'sometimes|nullable|string|max:30',
            'phone_2'     => 'sometimes|nullable|string|max:30',
            'address'     => 'sometimes|nullable|string|max:255',
            'city'        => 'sometimes|nullable|string|max:100',
            'state'       => 'sometimes|nullable|string|max:100',
            'zip'         => 'sometimes|nullable|string|max:20',
            'country'     => 'sometimes|nullable|string|max:100',
            'status'      => 'sometimes|nullable|string|max:50',
            'source'      => 'sometimes|nullable|string|max:100',
            'value'       => 'sometimes|nullable|numeric',
            'notes'       => 'sometimes|nullable|string',
            'campaign_id' => 'sometimes|nullable|integer',
        ]);

        $lead->update($validated);

        return response()->json([
            'success' => true,
            'lead'    => $this->formatLead($lead->fresh(['statusModel', 'campaign', 'tags']), detail: true),
        ]);
    }

    // ── PUT /api/mobile/leads/{id}/tags ───────────────────────────────────────

    public function syncTags(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');
        $user     = $request->attributes->get('mobile_user');
        $lead     = $this->resolveLead($id, $tenantId);

        $request->validate([
            'tag_ids'    => 'array',
            'tag_ids.*'  => 'integer',
            'new_tags'   => 'array',
            'new_tags.*' => 'string|max:50',
        ]);

        $tagIds = collect($request->input('tag_ids', []));

        foreach ($request->input('new_tags', []) as $name) {
            $slug = Tag::makeSlug((string) $name);
            $tag  = Tag::withoutGlobalScope(TenantScope::class)
                ->firstOrCreate(
                    ['tenant_id' => $tenantId, 'slug' => $slug],
                    ['name' => trim((string) $name), 'created_by' => $user->id],
                );
            $tagIds->push($tag->id);
        }

        $lead->tags()->sync($tagIds->unique()->values()->toArray());
        $lead->load('tags:id,name,bg_color,color');

        return response()->json([
            'success' => true,
            'tags'    => $lead->tags->map(fn ($t) => [
                'id'       => $t->id,
                'name'     => $t->name,
                'bg_color' => $t->bg_color,
                'color'    => $t->color,
            ])->values(),
        ]);
    }

    // ── GET /api/mobile/leads/{id}/notes ──────────────────────────────────────

    public function notes(Request $request, int $id): JsonResponse
    {
        $lead = $this->resolveLead($id, $request->attributes->get('mobile_tenant_id'));

        $notes = LeadNote::where('lead_id', $lead->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'body'       => $n->body,
                'author'     => $n->author ?? 'Agent',
                'created_at' => $n->created_at,
            ]);

        return response()->json(['success' => true, 'notes' => $notes]);
    }

    // ── POST /api/mobile/leads/{id}/notes ─────────────────────────────────────

    public function addNote(Request $request, int $id): JsonResponse
    {
        $lead = $this->resolveLead($id, $request->attributes->get('mobile_tenant_id'));
        $user = $request->attributes->get('mobile_user');

        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $note = LeadNote::create([
            'lead_id' => $lead->id,
            'body'    => $request->input('body'),
            'author'  => $user->name,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'note'    => [
                'id'         => $note->id,
                'body'       => $note->body,
                'author'     => $note->author,
                'created_at' => $note->created_at,
            ],
        ]);
    }

    // ── GET /api/mobile/leads/{id}/history ────────────────────────────────────

    public function history(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');
        $lead     = $this->resolveLead($id, $tenantId);

        $conversations = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $lead->id)
            ->orderBy('wait_since', 'desc')
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'channel'    => $c->channel,
                'status'     => $c->status,
                'subject'    => $c->subject,
                'direction'  => $c->direction,
                'wait_since' => $c->wait_since,
                'resolved_at' => $c->resolved_at,
            ]);

        return response()->json(['success' => true, 'history' => $conversations]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveLead(int $id, int $tenantId): Lead
    {
        return Lead::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->with(['statusModel:id,slug,name,bg_color,color', 'campaign:id,name', 'tags:id,name,bg_color,color'])
            ->findOrFail($id);
    }

    private function formatLead(Lead $l, bool $detail = false): array
    {
        $base = [
            'id'           => $l->id,
            'first_name'   => $l->first_name,
            'last_name'    => $l->last_name,
            'email'        => $l->email,
            'phone'        => $l->phone,
            'status'       => $l->status,
            'status_label' => $l->statusModel?->name ?? $l->status,
            'status_bg'    => $l->statusModel?->bg_color ?? '#f1f5f9',
            'status_color' => $l->statusModel?->color ?? '#64748b',
            'campaign_id'  => $l->campaign_id,
            'campaign_name' => $l->campaign?->name,
            'assigned_to'  => $l->assigned_to,
            'source'       => $l->source,
            'tags'         => $l->tags?->map(fn ($t) => [
                'id'       => $t->id,
                'name'     => $t->name,
                'bg_color' => $t->bg_color,
                'color'    => $t->color,
            ])->values()->toArray() ?? [],
        ];

        if ($detail) {
            $base += [
                'phone_2'  => $l->phone_2,
                'address'  => $l->address,
                'city'     => $l->city,
                'state'    => $l->state,
                'zip'      => $l->zip,
                'country'  => $l->country,
                'value'    => $l->value,
                'notes'    => $l->notes,
                'created_at' => $l->created_at,
                'updated_at' => $l->updated_at,
            ];
        }

        return $base;
    }
}
