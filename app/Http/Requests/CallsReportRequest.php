<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('view_calls_report');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'view_type' => ['nullable', Rule::in(['detailed', 'agent_summary'])],
            'direction' => ['nullable', Rule::in(['all', 'INBOUND', 'OUTBOUND', 'inbound', 'outbound'])],
            'result' => ['nullable', Rule::in(['all', 'answered', 'no_answer', 'abandoned', 'caller_hung_up', 'agent_hung_up', 'busy', 'timeout', 'cancel', 'failed', 'congestion', 'unknown'])],
            'disposition' => ['nullable', 'string', 'max:120'],
            'campaign_id' => ['nullable', 'integer'],
            'queue' => ['nullable', 'string', 'max:120'],
            'agent_id' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:80'],
            'ani' => ['nullable', 'string', 'max:80'],
            'dnis' => ['nullable', 'string', 'max:80'],
            'linkedid' => ['nullable', 'string', 'max:80'],
            'call_uuid' => ['nullable', 'string', 'max:36'],
            'conversation_id' => ['nullable', 'integer'],
            'call_mode' => ['nullable', 'string', 'max:20'],
            'answered' => ['nullable', Rule::in(['all', 'answered', 'unanswered'])],
            'duration_min' => ['nullable', 'integer', 'min:0'],
            'duration_max' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'from' => $validated['from'] ?? now()->toDateString(),
            'to' => $validated['to'] ?? now()->toDateString(),
            'view_type' => $validated['view_type'] ?? 'detailed',
            'direction' => strtoupper((string) ($validated['direction'] ?? 'all')),
            'result' => $validated['result'] ?? 'all',
            'disposition' => $validated['disposition'] ?? null,
            'campaign_id' => $validated['campaign_id'] ?? null,
            'queue' => $validated['queue'] ?? null,
            'agent_id' => $validated['agent_id'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'ani' => $validated['ani'] ?? null,
            'dnis' => $validated['dnis'] ?? null,
            'linkedid' => $validated['linkedid'] ?? null,
            'call_uuid' => $validated['call_uuid'] ?? null,
            'conversation_id' => $validated['conversation_id'] ?? null,
            'call_mode' => $validated['call_mode'] ?? null,
            'answered' => $validated['answered'] ?? 'all',
            'duration_min' => $validated['duration_min'] ?? null,
            'duration_max' => $validated['duration_max'] ?? null,
        ];
    }
}
