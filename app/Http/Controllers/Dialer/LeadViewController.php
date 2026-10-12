<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dialer;

use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Models\LeadList;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LeadViewController extends Controller
{
    /**
     * GET /dialer/lead-view?phone=...&lead_list_id=...&token=...&view=html|json
     *
     * view=html (default) → editable form page (screen pop)
     * view=json           → returns lead data as JSON (for programmatic use)
     */
    public function show(Request $request): View|JsonResponse
    {
        /** @var AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');
        $viewMode    = $request->query('view', 'html');
        $asJson      = $viewMode === 'json';

        $phone      = trim($request->query('phone', ''));
        $leadListId = (int) $request->query('lead_list_id', 0);

        if ($phone === '' || $leadListId === 0) {
            if ($asJson) {
                return response()->json(['success' => false, 'error' => 'Missing phone or lead_list_id parameter.'], 422);
            }
            return $this->htmlView(null, null, [], $phone, $accessToken->token, false, 'Missing phone or lead_list_id parameter.');
        }

        $leadList = LeadList::withoutGlobalScope(TenantScope::class)
            ->where('id', $leadListId)
            ->where('tenant_id', $accessToken->tenant_id)
            ->first();

        if ($leadList === null) {
            if ($asJson) {
                return response()->json(['success' => false, 'error' => 'Lead list not found.'], 404);
            }
            return $this->htmlView(null, null, [], $phone, $accessToken->token, false, 'Lead list not found.');
        }

        $lead = DB::table($leadList->table_name)->where('phone', $phone)->first();

        if ($lead === null) {
            if ($asJson) {
                return response()->json(['success' => false, 'error' => 'Lead not found.'], 404);
            }
            return $this->htmlView(null, $leadList, [], $phone, $accessToken->token, false, null);
        }

        $customFields = [];
        if (!empty($lead->custom_fields)) {
            $decoded = json_decode($lead->custom_fields, true);
            if (is_array($decoded)) $customFields = $decoded;
        }

        if ($asJson) {
            return response()->json([
                'success'       => true,
                'phone'         => $lead->phone,
                'status'        => $lead->status,
                'custom_fields' => $customFields,
            ]);
        }

        $saved = $request->query('saved') === '1';
        return $this->htmlView($lead, $leadList, $customFields, $phone, $accessToken->token, $saved, null);
    }

    /**
     * POST /dialer/lead-view/save
     */
    public function save(Request $request): RedirectResponse
    {
        /** @var AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');

        $phone      = trim($request->input('phone', ''));
        $leadListId = (int) $request->input('lead_list_id', 0);
        $token      = $accessToken->token;

        $redirectBase = route('dialer.lead-view') . "?phone={$phone}&lead_list_id={$leadListId}&token={$token}";

        if ($phone === '' || $leadListId === 0) {
            return redirect($redirectBase . '&error=missing_params');
        }

        $leadList = LeadList::withoutGlobalScope(TenantScope::class)
            ->where('id', $leadListId)
            ->where('tenant_id', $accessToken->tenant_id)
            ->first();

        if ($leadList === null) {
            return redirect($redirectBase . '&error=not_found');
        }

        $lead = DB::table($leadList->table_name)->where('phone', $phone)->first();
        if ($lead === null) {
            return redirect($redirectBase . '&error=not_found');
        }

        $updates  = [];
        $incoming = $request->input('custom_fields', []);
        if (!empty($incoming) && is_array($incoming)) {
            $existing = [];
            if (!empty($lead->custom_fields)) {
                $decoded = json_decode($lead->custom_fields, true);
                if (is_array($decoded)) $existing = $decoded;
            }
            $sanitized = array_map(fn($v) => strip_tags(trim((string) $v)), $incoming);
            $updates['custom_fields'] = json_encode(array_merge($existing, $sanitized), JSON_UNESCAPED_UNICODE);
        }

        if (!empty($updates)) {
            DB::table($leadList->table_name)->where('phone', $phone)->update($updates);
        }

        return redirect($redirectBase . '&saved=1');
    }

    private function htmlView(
        ?object $lead,
        ?object $leadList,
        array   $customFields,
        string  $phone,
        string  $token,
        bool    $saved,
        ?string $error
    ): View {
        return view('dialer.lead_view', compact(
            'lead', 'leadList', 'customFields', 'phone', 'token', 'saved', 'error'
        ));
    }
}
