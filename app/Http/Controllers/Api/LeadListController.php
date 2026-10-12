<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Models\LeadList;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadListController extends Controller
{
    // ── POST /api/lead-lists/{id}/leads ──────────────────────────────────────

    public function store(Request $request, int $leadListId): JsonResponse
    {
        /** @var AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');

        $leadList = $this->resolveList($leadListId, $accessToken->tenant_id);
        if ($leadList === null) {
            return response()->json(['success' => false, 'error' => 'Lead list not found.'], 404);
        }
        if ($leadList->status === 'archived') {
            return response()->json(['success' => false, 'error' => 'Lead list is archived.'], 422);
        }

        [$phone, $err] = $this->parsePhone($request->input('phone', ''));
        if ($err) return response()->json(['success' => false, 'error' => $err], 422);

        $table    = $leadList->table_name;
        $tenantId = $accessToken->tenant_id;

        if ($this->isDnc($phone, $tenantId)) {
            return response()->json(['success' => false, 'error' => 'Phone is on DNC list.', 'skipped' => 'dnc'], 422);
        }
        if (DB::table($table)->where('phone', $phone)->exists()) {
            return response()->json(['success' => false, 'error' => 'Phone already exists in this list.', 'skipped' => 'duplicate'], 409);
        }

        $record = ['phone' => $phone, 'status' => 'NEW'];
        $cf     = $this->sanitizeCustomFields($request->input('custom_fields', []));
        if ($cf !== null) $record['custom_fields'] = $cf;

        DB::table($table)->insert($record);
        $leadId = (int) DB::getPdo()->lastInsertId();

        $leadList->increment('total_leads');
        if ($leadList->status === 'draft') {
            $leadList->update(['status' => 'ready']);
        }

        return response()->json(['success' => true, 'lead_id' => $leadId, 'phone' => $phone]);
    }

    // ── PUT /api/lead-lists/{id}/leads/{phone} ───────────────────────────────

    public function update(Request $request, int $leadListId, string $phoneRaw): JsonResponse
    {
        /** @var AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');

        $leadList = $this->resolveList($leadListId, $accessToken->tenant_id);
        if ($leadList === null) {
            return response()->json(['success' => false, 'error' => 'Lead list not found.'], 404);
        }

        [$phone, $err] = $this->parsePhone($phoneRaw);
        if ($err) return response()->json(['success' => false, 'error' => $err], 422);

        $table = $leadList->table_name;
        $lead  = DB::table($table)->where('phone', $phone)->first();
        if ($lead === null) {
            return response()->json(['success' => false, 'error' => 'Lead not found.'], 404);
        }

        $incoming = $request->input('custom_fields', []);
        if (empty($incoming) || !is_array($incoming)) {
            return response()->json(['success' => false, 'error' => 'custom_fields is required and must be an object.'], 422);
        }

        $existing = [];
        if (!empty($lead->custom_fields)) {
            $decoded = json_decode($lead->custom_fields, true);
            if (is_array($decoded)) $existing = $decoded;
        }

        $sanitized = array_map(fn($v) => strip_tags(trim((string) $v)), $incoming);
        $merged    = array_merge($existing, $sanitized);

        DB::table($table)->where('phone', $phone)->update([
            'custom_fields' => json_encode($merged, JSON_UNESCAPED_UNICODE),
        ]);

        return response()->json(['success' => true, 'phone' => $phone]);
    }

    // ── DELETE /api/lead-lists/{id}/leads/{phone} ────────────────────────────

    public function destroy(Request $request, int $leadListId, string $phoneRaw): JsonResponse
    {
        /** @var AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');

        $leadList = $this->resolveList($leadListId, $accessToken->tenant_id);
        if ($leadList === null) {
            return response()->json(['success' => false, 'error' => 'Lead list not found.'], 404);
        }

        [$phone, $err] = $this->parsePhone($phoneRaw);
        if ($err) return response()->json(['success' => false, 'error' => $err], 422);

        $table   = $leadList->table_name;
        $deleted = DB::table($table)->where('phone', $phone)->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'error' => 'Lead not found.'], 404);
        }

        $leadList->decrement('total_leads');

        return response()->json(['success' => true, 'phone' => $phone]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveList(int $id, int $tenantId): ?LeadList
    {
        return LeadList::withoutGlobalScope(TenantScope::class)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    private function parsePhone(mixed $raw): array
    {
        $raw   = trim((string) $raw);
        $phone = preg_replace('/\D/', '', $raw);

        if ($raw === '')        return [null, 'phone is required.'];
        if (strlen($phone) < 7) return [null, 'Invalid phone number.'];

        return [$phone, null];
    }

    private function isDnc(string $phone, int $tenantId): bool
    {
        return DB::table('dialer_dnc')
            ->where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->exists();
    }

    private function sanitizeCustomFields(mixed $input): ?string
    {
        if (empty($input) || !is_array($input)) return null;
        $sanitized = array_map(fn($v) => strip_tags(trim((string) $v)), $input);
        return json_encode($sanitized, JSON_UNESCAPED_UNICODE);
    }
}
