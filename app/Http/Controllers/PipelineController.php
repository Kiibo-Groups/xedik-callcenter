<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PipelineController extends Controller
{
    public function index(Request $request)
    {
        $user  = Auth::user();
        $scope = $request->get('scope') === 'mine' ? 'mine' : 'all';

        $campaigns = $user->isAdmin()
            ? Campaign::where('is_active', true)->orderBy('name')->get()
            : $user->campaigns()->where('is_active', true)->orderBy('name')->get();

        $campaignId = $request->get('campaign_id');
        $tagIds     = array_values(array_filter(array_map('intval', (array) $request->get('tag_ids', []))));

        $stages = LeadStatus::active()->where('show_in_pipeline', true)->get();

        $leadsQuery = Lead::with(['agent', 'campaign', 'tags']);

        if ($scope === 'mine') {
            $leadsQuery->where('assigned_to', $user->id);
        } elseif (!$user->isAdmin()) {
            $campaignIds = $campaigns->pluck('id');
            $leadsQuery->where(function ($q) use ($campaignIds, $user) {
                $q->whereIn('campaign_id', $campaignIds)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->whereNull('campaign_id')->where('assigned_to', $user->id);
                  });
            });
        }

        if ($campaignId) {
            $leadsQuery->where('campaign_id', $campaignId);
        }

        if (!empty($tagIds)) {
            $leadsQuery->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds));
        }

        // Solo leads cuyo status está visible en el Pipeline (show_in_pipeline=true) —
        // de lo contrario $totalLeads y demás KPIs del Summary incluyen leads que no
        // tienen columna/card en el board (ej. "Contacted" con show_in_pipeline=false).
        $leadsQuery->whereIn('status', $stages->pluck('slug'));

        $tags         = \App\Models\Tag::orderBy('name')->get();
        $leadStatuses = \App\Models\LeadStatus::active()->get(['slug', 'name', 'bg_color', 'color'])->toArray();
        $leads        = $leadsQuery->orderBy('updated_at', 'desc')->get();

        $leadsByStatus = $leads->groupBy('status');

        $wonSlugs  = LeadStatus::where('is_won', true)->pluck('slug');
        $lostSlugs = LeadStatus::where('is_lost', true)->pluck('slug');

        $totalLeads = $leads->count();
        $totalValue = (float) $leads->sum('value');
        $openValue  = (float) $leads->whereNotIn('status', $wonSlugs->merge($lostSlugs))->sum('value');
        $wonValue   = (float) $leads->whereIn('status', $wonSlugs)->sum('value');
        $wonCount   = $leads->whereIn('status', $wonSlugs)->count();
        $conversion = $totalLeads > 0 ? (int) round(($wonCount / $totalLeads) * 100) : 0;
        $avgTicket  = $totalLeads > 0 ? $totalValue / $totalLeads : 0.0;

        $stageDistribution = $stages->map(function ($stage) use ($leadsByStatus) {
            return [
                'slug'  => $stage->slug,
                'label' => $stage->name,
                'count' => $leadsByStatus->get($stage->slug, collect())->count(),
                'color' => $stage->bg_color,
            ];
        })->values();

        // Conteo global de leads por status (sin filtros de scope/campaign) —
        // usado para deshabilitar "Delete Status" si el status tiene leads,
        // misma regla que StatusController::destroy().
        $statusLeadsCounts = Lead::select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('pipeline.index', [
            'stages'        => $stages,
            'leadsByStatus' => $leadsByStatus,
            'campaigns'     => $campaigns,
            'tags'          => $tags,
            'tagIds'        => $tagIds,
            'scope'         => $scope,
            'campaignId'    => $campaignId,
            'totalLeads'    => $totalLeads,
            'totalValue'    => $totalValue,
            'openValue'     => $openValue,
            'wonValue'      => $wonValue,
            'wonCount'      => $wonCount,
            'conversion'    => $conversion,
            'avgTicket'     => $avgTicket,
            'wonSlugs'      => $wonSlugs,
            'lostSlugs'     => $lostSlugs,
            'stageDistribution'  => $stageDistribution,
            'leadStatuses'       => $leadStatuses,
            'canManageStatuses'  => $user->hasPermission('manage_lead_statuses'),
            'statusLeadsCounts'  => $statusLeadsCounts,
        ]);
    }

    /**
     * Reordena las columnas del Pipeline (LeadStatus con show_in_pipeline=true).
     *
     * Recibe el nuevo orden del subconjunto visible y lo aplica sobre la
     * lista completa de statuses del tenant (ordenada por 'order' actual),
     * preservando la posición relativa de los statuses ocultos del Pipeline.
     */
    public function reorderStatuses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct'],
        ]);

        $all = LeadStatus::orderBy('order')->get();

        $order       = collect($validated['order'])->map(fn ($id) => (int) $id);
        $pipelineIds = $all->where('show_in_pipeline', true)->pluck('id')->sort()->values()->all();
        $newOrder    = $order->sort()->values()->all();

        if ($pipelineIds !== $newOrder) {
            return response()->json([
                'ok'      => false,
                'message' => 'Invalid status set.',
            ], 422);
        }

        $queue = $order;

        $newOrderIds = $all->map(function ($status) use (&$queue) {
            return $status->show_in_pipeline ? $queue->shift() : $status->id;
        });

        foreach ($newOrderIds as $index => $id) {
            LeadStatus::where('id', $id)->update(['order' => $index + 1]);
        }

        return response()->json(['ok' => true]);
    }

    public function updateStage(Request $request, Lead $lead): JsonResponse
    {
        $validSlugs = LeadStatus::active()->pluck('slug');

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . $validSlugs->implode(',')],
        ]);

        $lead->update(['status' => $validated['status']]);

        // Broadcast(new LeadStageChanged($lead, $validated['status']));

        return response()->json([
            'ok'     => true,
            'status' => $validated['status'],
        ]);
    }
}
