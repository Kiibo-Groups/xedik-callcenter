<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallingStateRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin catalog for State-Specific Calling Hours Rules.
 *
 * Global reference data (not tenant-scoped), same as calling_area_codes —
 * superadmin only. rule_type is always 'telemarketing_calling_hours';
 * not exposed in the normal form.
 */
class StateCallingRuleController extends Controller
{
    private const RULE_TYPE = 'telemarketing_calling_hours';
    private const VALID_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function index(Request $request)
    {
        $this->authorizeSuperAdmin();

        $query = CallingStateRule::query()->where('rule_type', self::RULE_TYPE);

        if ($stateCode = trim((string) $request->input('state_code'))) {
            $query->where('state_code', 'like', '%' . strtoupper($stateCode) . '%');
        }

        $status = $request->input('status');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('is_active', $status === 'active');
        }

        if ($source = trim((string) $request->input('source'))) {
            $query->where('source', 'like', '%' . $source . '%');
        }

        $rules = $query->orderBy('state_code')->paginate(25)->withQueryString();

        return view('admin.compliance.state_calling_rules.index', compact('rules'));
    }

    public function create()
    {
        $this->authorizeSuperAdmin();

        return view('admin.compliance.state_calling_rules.form', ['rule' => new CallingStateRule()]);
    }

    public function store(Request $request)
    {
        $this->authorizeSuperAdmin();

        $data = $this->validated($request);
        $data['rule_type'] = self::RULE_TYPE;

        CallingStateRule::create($data);

        return redirect()->route('admin.compliance.state-calling-rules.index')
            ->with('success', "State calling rule for {$data['state_code']} created.");
    }

    public function edit(CallingStateRule $rule)
    {
        $this->authorizeSuperAdmin();

        return view('admin.compliance.state_calling_rules.form', compact('rule'));
    }

    public function update(Request $request, CallingStateRule $rule)
    {
        $this->authorizeSuperAdmin();

        $data = $this->validated($request, $rule);
        $data['rule_type'] = self::RULE_TYPE;

        $rule->update($data);

        return redirect()->route('admin.compliance.state-calling-rules.index')
            ->with('success', "State calling rule for {$data['state_code']} updated.");
    }

    public function toggle(CallingStateRule $rule)
    {
        $this->authorizeSuperAdmin();

        $rule->update(['is_active' => !$rule->is_active]);

        return back()->with(
            'success',
            "State calling rule for {$rule->state_code} " . ($rule->is_active ? 'activated' : 'deactivated') . '.'
        );
    }

    public function destroy(CallingStateRule $rule)
    {
        $this->authorizeSuperAdmin();

        $stateCode = $rule->state_code;
        $rule->delete();

        return redirect()->route('admin.compliance.state-calling-rules.index')
            ->with('success', "State calling rule for {$stateCode} deleted.");
    }

    private function validated(Request $request, ?CallingStateRule $rule = null): array
    {
        // Normalize submitted day values to lowercase before the `in:` check
        // runs, so e.g. "MON" is treated the same as "mon".
        if ($request->has('days_of_week')) {
            $request->merge([
                'days_of_week' => array_map(
                    fn ($day) => strtolower(trim((string) $day)),
                    (array) $request->input('days_of_week', [])
                ),
            ]);
        }

        $validated = $request->validate([
            'state_code' => [
                'required', 'string', 'regex:/^[A-Za-z]{2}$/',
                Rule::unique('calling_state_rules', 'state_code')
                    ->where('rule_type', self::RULE_TYPE)
                    ->ignore($rule?->id),
            ],
            'state_name'         => ['nullable', 'string', 'max:100'],
            'start_time'         => ['required', 'date_format:H:i'],
            'end_time'           => ['required', 'date_format:H:i', 'after:start_time'],
            'days_of_week'       => ['nullable', 'array'],
            'days_of_week.*'     => ['string', Rule::in(self::VALID_DAYS)],
            'is_active'          => ['nullable', 'boolean'],
            'source'             => ['nullable', 'string', 'max:255'],
            'source_url'         => ['nullable', 'url', 'max:255'],
            'source_updated_at'  => ['nullable', 'date'],
            'notes'              => ['nullable', 'string', 'max:2000'],
        ], [
            'end_time.after' => 'End time must be later than start time. Overnight windows are not supported.',
            'days_of_week.*.in' => 'Days must be one of: mon, tue, wed, thu, fri, sat, sun.',
        ]);

        $validated['state_code'] = strtoupper($validated['state_code']);
        $validated['is_active']  = $request->boolean('is_active');

        // Store null (not []) when no days are selected — days_of_week=null
        // means "applies every day" per CallingHoursComplianceService.
        $days = $validated['days_of_week'] ?? [];
        $validated['days_of_week'] = $days !== [] ? array_values(array_unique($days)) : null;

        return $validated;
    }

    private function authorizeSuperAdmin(): void
    {
        abort_unless(auth()->user()?->is_superadmin, 403);
    }
}
