<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EmailTemplateController extends Controller
{
    // ── Admin CRUD ────────────────────────────────────────────────────────────

    public function index()
    {
        abort_unless(Auth::user()->hasPermission('manage_email_templates'), 403);

        $isSuperadmin = Auth::user()->is_superadmin;

        $query = $isSuperadmin
            ? EmailTemplate::withoutGlobalScopes()->with('tenant')->orderBy('tenant_id')->orderBy('name')
            : EmailTemplate::orderBy('name');

        $templates = $query->get();

        return view('email-templates.index', compact('templates', 'isSuperadmin'));
    }

    public function create()
    {
        abort_unless(Auth::user()->hasPermission('manage_email_templates'), 403);

        $tenants = Auth::user()->is_superadmin
            ? Tenant::orderBy('name')->get(['id', 'name'])
            : collect();

        $leadLists    = LeadList::orderBy('name')->get(['id', 'name']);
        $template     = new EmailTemplate();
        $isSuperadmin = Auth::user()->is_superadmin;

        return view('email-templates.form', compact('template', 'tenants', 'isSuperadmin', 'leadLists'));
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless(Auth::user()->hasPermission('manage_email_templates'), 403);

        $isSuperadmin = Auth::user()->is_superadmin;
        $varSource    = $request->input('variable_source', 'leads');

        $rules = [
            'name'            => 'required|string|max:150',
            'subject'         => 'required|string|max:255',
            'body'            => 'required|string',
            'variable_source' => 'nullable|string|in:leads,leadlist',
            'leadlist_id'     => $varSource === 'leadlist'
                ? 'required|integer|exists:lead_lists,id'
                : 'nullable|integer|exists:lead_lists,id',
        ];
        if ($isSuperadmin) {
            $rules['tenant_id'] = 'required|exists:tenants,id';
        }

        $validated      = $request->validate($rules);
        $variableSource = $validated['variable_source'] ?? 'leads';
        $leadlistId     = $variableSource === 'leadlist' ? ($validated['leadlist_id'] ?? null) : null;

        if ($error = $this->detectUnknownVariables($validated['subject'], $validated['body'], $variableSource)) {
            return back()->withErrors(['body' => $error])->withInput();
        }

        EmailTemplate::withoutGlobalScopes()->create([
            'name'            => $validated['name'],
            'subject'         => $validated['subject'],
            'body'            => $validated['body'],
            'variable_source' => $variableSource,
            'leadlist_id'     => $leadlistId,
            'created_by'      => Auth::id(),
            'tenant_id'       => $isSuperadmin ? $validated['tenant_id'] : Auth::user()->tenant_id,
        ]);

        return redirect()->route('email-templates.index')
            ->with('success', "Template \"{$validated['name']}\" created.");
    }

    public function edit(EmailTemplate $emailTemplate)
    {
        abort_unless(Auth::user()->hasPermission('manage_email_templates'), 403);

        $isSuperadmin = Auth::user()->is_superadmin;

        if ($isSuperadmin) {
            $emailTemplate = EmailTemplate::withoutGlobalScopes()->findOrFail($emailTemplate->id);
        }

        $tenants = $isSuperadmin
            ? Tenant::orderBy('name')->get(['id', 'name'])
            : collect();

        $leadLists = LeadList::orderBy('name')->get(['id', 'name']);
        $template  = $emailTemplate;

        return view('email-templates.form', compact('template', 'tenants', 'isSuperadmin', 'leadLists'));
    }

    public function update(Request $request, EmailTemplate $emailTemplate): \Illuminate\Http\RedirectResponse
    {
        abort_unless(Auth::user()->hasPermission('manage_email_templates'), 403);

        $isSuperadmin = Auth::user()->is_superadmin;

        if ($isSuperadmin) {
            $emailTemplate = EmailTemplate::withoutGlobalScopes()->findOrFail($emailTemplate->id);
        }

        $varSource = $request->input('variable_source', 'leads');

        $rules = [
            'name'            => 'required|string|max:150',
            'subject'         => 'required|string|max:255',
            'body'            => 'required|string',
            'variable_source' => 'nullable|string|in:leads,leadlist',
            'leadlist_id'     => $varSource === 'leadlist'
                ? 'required|integer|exists:lead_lists,id'
                : 'nullable|integer|exists:lead_lists,id',
        ];
        if ($isSuperadmin) {
            $rules['tenant_id'] = 'required|exists:tenants,id';
        }

        $validated      = $request->validate($rules);
        $variableSource = $validated['variable_source'] ?? 'leads';
        $leadlistId     = $variableSource === 'leadlist' ? ($validated['leadlist_id'] ?? null) : null;

        if ($error = $this->detectUnknownVariables($validated['subject'], $validated['body'], $variableSource)) {
            return back()->withErrors(['body' => $error])->withInput();
        }

        $data = [
            'name'            => $validated['name'],
            'subject'         => $validated['subject'],
            'body'            => $validated['body'],
            'variable_source' => $variableSource,
            'leadlist_id'     => $leadlistId,
        ];
        if ($isSuperadmin) {
            $data['tenant_id'] = $validated['tenant_id'];
        }

        $emailTemplate->update($data);

        return redirect()->route('email-templates.index')
            ->with('success', "Template \"{$emailTemplate->name}\" updated.");
    }

    public function destroy(EmailTemplate $emailTemplate): \Illuminate\Http\RedirectResponse
    {
        abort_unless(Auth::user()->hasPermission('manage_email_templates'), 403);

        if (Auth::user()->is_superadmin) {
            $emailTemplate = EmailTemplate::withoutGlobalScopes()->findOrFail($emailTemplate->id);
        }

        $name = $emailTemplate->name;
        $emailTemplate->delete();

        return redirect()->route('email-templates.index')
            ->with('success', "Template \"{$name}\" deleted.");
    }

    // ── JSON list for compose modal (any authenticated agent) ─────────────────

    public function listForCompose(): JsonResponse
    {
        $templates = EmailTemplate::orderBy('name')
            ->get(['id', 'name', 'subject']);

        return response()->json(['templates' => $templates]);
    }

    // ── Render template variables for compose ─────────────────────────────────

    public function render(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $request->validate([
            'lead_id' => 'nullable|integer',
        ]);

        $lead = $request->filled('lead_id')
            ? Lead::find($request->integer('lead_id'))
            : null;

        $subject = $this->resolveForCompose($emailTemplate->subject, $lead);
        $body    = $this->resolveForCompose($emailTemplate->body, $lead);

        return response()->json(['subject' => $subject, 'body' => $body]);
    }

    // ── LeadList fields for variable picker (AJAX) ────────────────────────────

    public function leadListFields(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->hasPermission('manage_email_templates'), 403);

        $request->validate(['lead_list_id' => 'required|integer']);

        $leadList = LeadList::findOrFail($request->integer('lead_list_id'));

        return response()->json($this->getLeadListFields($leadList));
    }

    // ── Variable validator ────────────────────────────────────────────────────

    private function detectUnknownVariables(string $subject, string $body, string $variableSource = 'leads'): ?string
    {
        $systemVars = ['agent.name', 'agent.email', 'agent.phone', 'agent.campaign', 'date.today'];
        $leadVars   = [
            'lead.first_name', 'lead.last_name', 'lead.email', 'lead.phone',
            'lead.phone_2', 'lead.address', 'lead.city', 'lead.state',
            'lead.zip', 'lead.country', 'lead.status', 'lead.source',
            'lead.value', 'lead.notes',
        ];

        $combined = $subject . ' ' . strip_tags($body);
        preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', $combined, $matches);

        foreach ($matches[1] as $raw) {
            $var = trim($raw);

            if (in_array($var, $systemVars, true)) continue;
            if ($variableSource === 'leads' && in_array($var, $leadVars, true)) continue;
            if ($variableSource === 'leadlist' && str_starts_with($var, 'leadlist.')) continue;

            $supported  = array_merge($systemVars, $variableSource === 'leads' ? $leadVars : []);
            $suggestion = $this->suggestVariable($var, $supported);
            $msg = "Unknown variable: {{{$var}}}."
                . ($suggestion ? " Did you mean {{{$suggestion}}}?" : '')
                . ' Use the variable chips below instead of typing manually.';
            return $msg;
        }

        return null;
    }

    private function suggestVariable(string $unknown, array $supported): ?string
    {
        $best      = null;
        $bestScore = PHP_INT_MAX;

        foreach ($supported as $candidate) {
            $dist = levenshtein(strtolower($unknown), strtolower($candidate));
            if ($dist < $bestScore) {
                $bestScore = $dist;
                $best      = $candidate;
            }
        }

        return ($bestScore <= 4) ? $best : null;
    }

    // ── Variable resolver ─────────────────────────────────────────────────────

    // Resolves agent.* and date.today always; lead.* only when lead is provided.
    // Without lead, {{lead.*}} are left as literal placeholders for JS in-place replacement.
    private function resolveForCompose(string $text, ?Lead $lead): string
    {
        $user = Auth::user();

        $systemMap = [
            '{{agent.name}}'     => $user->name,
            '{{agent.email}}'    => $user->email,
            '{{agent.phone}}'    => $user->phone_user_id,
            '{{agent.campaign}}' => $user->campaigns()->first()?->name,
            '{{date.today}}'     => now()->format('F j, Y'),
        ];

        foreach ($systemMap as $placeholder => $value) {
            $text = str_replace($placeholder, ($value !== null && $value !== '') ? $value : '—', $text);
        }

        if ($lead !== null) {
            $leadMap = [
                '{{lead.first_name}}' => $lead->first_name,
                '{{lead.last_name}}'  => $lead->last_name,
                '{{lead.email}}'      => $lead->email,
                '{{lead.phone}}'      => $lead->phone,
                '{{lead.phone_2}}'    => $lead->phone_2,
                '{{lead.address}}'    => $lead->address,
                '{{lead.city}}'       => $lead->city,
                '{{lead.state}}'      => $lead->state,
                '{{lead.zip}}'        => $lead->zip,
                '{{lead.country}}'    => $lead->country,
                '{{lead.status}}'     => $lead->status,
                '{{lead.source}}'     => $lead->source,
                '{{lead.value}}'      => $lead->value !== null ? (string) $lead->value : null,
                '{{lead.notes}}'      => $lead->notes,
            ];

            foreach ($leadMap as $placeholder => $value) {
                $text = str_replace($placeholder, ($value !== null && $value !== '') ? $value : '—', $text);
            }
        }

        return $text;
    }

    private function resolveVariables(string $text, Lead $lead): string
    {
        $user = Auth::user();

        $map = [
            '{{lead.first_name}}' => $lead->first_name,
            '{{lead.last_name}}'  => $lead->last_name,
            '{{lead.email}}'      => $lead->email,
            '{{lead.phone}}'      => $lead->phone,
            '{{lead.phone_2}}'    => $lead->phone_2,
            '{{lead.address}}'    => $lead->address,
            '{{lead.city}}'       => $lead->city,
            '{{lead.state}}'      => $lead->state,
            '{{lead.zip}}'        => $lead->zip,
            '{{lead.country}}'    => $lead->country,
            '{{lead.status}}'     => $lead->status,
            '{{lead.source}}'     => $lead->source,
            '{{lead.value}}'      => $lead->value !== null ? (string) $lead->value : null,
            '{{lead.notes}}'      => $lead->notes,
            '{{agent.name}}'      => $user->name,
            '{{agent.email}}'     => $user->email,
            '{{agent.phone}}'     => $user->phone_user_id,
            '{{agent.campaign}}'  => $user->campaigns()->first()?->name,
            '{{date.today}}'      => now()->format('F j, Y'),
        ];

        foreach ($map as $placeholder => $value) {
            $resolved = ($value !== null && $value !== '') ? $value : '—';
            $text     = str_replace($placeholder, $resolved, $text);
        }

        return $text;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getLeadListFields(LeadList $leadList): array
    {
        $fixed      = ['phone', 'status'];
        $customKeys = collect();

        DB::table($leadList->table_name)
            ->whereNotNull('custom_fields')
            ->limit(50)
            ->pluck('custom_fields')
            ->each(function (string $cf) use (&$customKeys): void {
                $decoded = json_decode($cf, true);
                if (is_array($decoded)) {
                    $customKeys = $customKeys->merge(array_keys($decoded));
                }
            });

        return [
            'fixed'  => $fixed,
            'custom' => $customKeys->unique()->sort()->values()->all(),
        ];
    }
}
