<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dialer;

use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Models\LeadList;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LeadAddController extends Controller
{
    /**
     * GET /dialer/lead-add?lead_list_id={id}&token={token}&fields=first_name,last_name,company,...
     *
     * Renders a screen-pop form to add a new lead to a lead list.
     * ?fields= controls which custom_fields inputs are shown (comma-separated snake_case keys).
     * After a successful POST the page redirects back here with added=1&lead_id={id}.
     */
    public function show(Request $request): View
    {
        /** @var AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');
        $leadListId  = (int) $request->query('lead_list_id', 0);
        $fieldsParam = trim($request->query('fields', ''));
        $fields      = $this->parseFields($fieldsParam);

        $leadList = null;
        $pageError = null;

        if ($leadListId === 0) {
            $pageError = 'Missing lead_list_id parameter.';
        } else {
            $leadList = LeadList::withoutGlobalScope(TenantScope::class)
                ->where('id', $leadListId)
                ->where('tenant_id', $accessToken->tenant_id)
                ->first();

            if ($leadList === null) {
                $pageError = 'Lead list not found.';
            } elseif ($leadList->status === 'archived') {
                $pageError = 'Lead list is archived and is not accepting new leads.';
            }
        }

        $added  = $request->query('added') === '1';
        $leadId = $added ? (int) $request->query('lead_id', 0) : null;

        $addError = match ($request->query('error')) {
            'dnc'       => 'This phone number is on the Do Not Call list.',
            'duplicate' => 'This phone number already exists in this list.',
            'invalid'   => 'Invalid phone number. Please enter at least 7 digits.',
            'archived'  => 'Lead list is archived and is not accepting new leads.',
            'not_found' => 'Lead list not found.',
            default     => null,
        };

        // Pre-fill phone on validation error so the agent doesn't retype it.
        $phoneValue = ($addError !== null) ? trim($request->query('phone', '')) : '';

        $token = $accessToken->token;

        return view('dialer.lead_add', compact(
            'leadList', 'fields', 'fieldsParam', 'leadListId',
            'token', 'added', 'leadId', 'pageError', 'addError', 'phoneValue',
        ));
    }

    /**
     * POST /dialer/lead-add
     *
     * Validates, creates the lead, then redirects back to the GET with result params.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');
        $leadListId  = (int) $request->input('lead_list_id', 0);
        $fieldsParam = $request->input('fields', '');
        $token       = $accessToken->token;

        $base = route('dialer.lead-add')
              . '?lead_list_id=' . $leadListId
              . '&token=' . urlencode($token)
              . '&fields=' . urlencode($fieldsParam);

        if ($leadListId === 0) {
            return redirect($base . '&error=not_found');
        }

        $leadList = LeadList::withoutGlobalScope(TenantScope::class)
            ->where('id', $leadListId)
            ->where('tenant_id', $accessToken->tenant_id)
            ->first();

        if ($leadList === null) {
            return redirect($base . '&error=not_found');
        }

        if ($leadList->status === 'archived') {
            return redirect($base . '&error=archived');
        }

        $rawPhone = trim($request->input('phone', ''));
        $phone    = preg_replace('/\D/', '', $rawPhone);

        if ($phone === '' || strlen($phone) < 7) {
            return redirect($base . '&error=invalid&phone=' . urlencode($rawPhone));
        }

        if (DB::table('dialer_dnc')
            ->where('tenant_id', $accessToken->tenant_id)
            ->where('phone', $phone)
            ->exists()
        ) {
            return redirect($base . '&error=dnc&phone=' . urlencode($phone));
        }

        if (DB::table($leadList->table_name)->where('phone', $phone)->exists()) {
            return redirect($base . '&error=duplicate&phone=' . urlencode($phone));
        }

        $record    = ['phone' => $phone, 'status' => 'NEW'];
        $fieldKeys = $this->parseFields($fieldsParam);
        $values    = $request->input('custom_fields', []);

        if (!empty($fieldKeys) && is_array($values)) {
            $cf = [];
            foreach ($fieldKeys as $i => $key) {
                $cf[$key] = strip_tags(trim((string) ($values[$i] ?? '')));
            }
            $record['custom_fields'] = json_encode($cf, JSON_UNESCAPED_UNICODE);
        }

        DB::table($leadList->table_name)->insert($record);
        $leadId = (int) DB::getPdo()->lastInsertId();

        $leadList->increment('total_leads');
        if ($leadList->status === 'draft') {
            $leadList->update(['status' => 'ready']);
        }

        return redirect($base . '&added=1&lead_id=' . $leadId);
    }

    /**
     * Parses the ?fields= param preserving the original key names (case + spaces).
     * Excludes "phone" (case-insensitive) since it is always its own field.
     * Keys are only trimmed — case, spaces, and punctuation are preserved so
     * the stored JSON matches the headers used by the lead list importer.
     *
     * @return string[]
     */
    private function parseFields(string $fieldsParam): array
    {
        if ($fieldsParam === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(fn($f) => trim($f), explode(',', $fieldsParam)),
            fn($f) => $f !== '' && strcasecmp($f, 'phone') !== 0,
        ));
    }
}
