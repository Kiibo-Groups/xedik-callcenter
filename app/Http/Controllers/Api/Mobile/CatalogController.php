<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\LeadStatus;
use App\Models\Scopes\TenantScope;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /** GET /api/mobile/lead-statuses */
    public function leadStatuses(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');

        $statuses = LeadStatus::where('tenant_id', $user->tenant_id)
            ->active()
            ->get(['id', 'slug', 'name', 'color', 'bg_color', 'is_won', 'is_lost']);

        return response()->json([
            'success' => true,
            'data'    => $statuses,
        ]);
    }

    /** GET /api/mobile/campaigns — campañas asignadas al agente */
    public function campaigns(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mobile_user');

        // Superadmin ve todas; agente sólo las que tiene asignadas
        if ($user->is_superadmin) {
            $campaigns = Campaign::where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'color', 'bg_color']);
        } else {
            $campaigns = $user->campaigns()
                ->where('campaigns.is_active', true)
                ->orderBy('name')
                ->get(['campaigns.id', 'campaigns.name', 'campaigns.color', 'campaigns.bg_color']);
        }

        return response()->json([
            'success' => true,
            'data'    => $campaigns,
        ]);
    }

    /** GET /api/mobile/tags — todos los tags del tenant */
    public function tags(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('mobile_tenant_id');

        $tags = Tag::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'bg_color']);

        return response()->json([
            'success' => true,
            'data'    => $tags,
        ]);
    }
}
