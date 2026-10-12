@extends('layouts.app')

@section('title', 'State Calling Hours Rules')
@section('page-title', 'State-Specific Calling Hours Rules')
@section('page-subtitle', $rules->total() . ' rules configured')

@section('header-actions')
    <a href="{{ route('admin.compliance.state-calling-rules.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Add Rule
    </a>
@endsection

@section('content')

@include('admin.voice._nav')

<div class="tip-box" style="margin-bottom:16px;">
    <i class="bi bi-info-circle tip-icon"></i>
    <div>
        State-specific calling-hour rules narrow the calling window for a state when active. Additional compliance
        controls such as DNC, consent, holidays, and frequency caps are managed separately.
        <br>
        Bulk rules can still be loaded with: <code class="cell-code">php artisan calling-state-rules:import storage/app/imports/us_state_calling_rules_2026.csv</code>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-funnel icon-primary"></i> Filters</span>
        <a href="{{ route('admin.compliance.state-calling-rules.index') }}" class="btn btn-outline btn-xs">
            <i class="bi bi-x-lg"></i> Clear
        </a>
    </div>
    <div style="padding:14px 16px;">
        <form method="GET">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-items:flex-end;">
                <div class="form-group mb-0">
                    <label class="form-label">State code</label>
                    <input type="text" name="state_code" class="form-control" value="{{ request('state_code') }}" placeholder="e.g. CA" maxlength="2">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="">All</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Source</label>
                    <input type="text" name="source" class="form-control" value="{{ request('source') }}" placeholder="e.g. CA Civil Code">
                </div>
                <div class="form-group mb-0">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-map icon-primary"></i> State Calling Hours Rules</span>
    </div>
    <div class="table-wrapper">
        @if($rules->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>State</th>
                    <th>State code</th>
                    <th>Window</th>
                    <th>Days</th>
                    <th>Active</th>
                    <th>Source</th>
                    <th>Source updated at</th>
                    <th>Updated at</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($rules as $rule)
                <tr>
                    <td class="cell-strong">{{ $rule->state_name ?? '—' }}</td>
                    <td><code class="cell-code">{{ $rule->state_code }}</code></td>
                    <td>{{ $rule->start_time }}–{{ $rule->end_time }}</td>
                    <td class="cell-muted">
                        @if(empty($rule->days_of_week))
                            Every day
                        @else
                            {{ collect($rule->days_of_week)->map(fn ($d) => ucfirst(strtolower($d)))->implode(', ') }}
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $rule->is_active ? 'badge-success' : 'badge-danger' }}">
                            <i class="bi {{ $rule->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $rule->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="cell-muted">{{ $rule->source ?? '—' }}</td>
                    <td class="cell-muted">{{ $rule->source_updated_at?->format('m/d/Y') ?? '—' }}</td>
                    <td class="cell-muted">{{ $rule->updated_at->format('m/d/Y H:i') }}</td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('admin.compliance.state-calling-rules.edit', $rule) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('admin.compliance.state-calling-rules.toggle', $rule) }}">
                                @csrf @method('POST')
                                <button type="submit" class="btn btn-outline btn-xs">
                                    <i class="bi {{ $rule->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                    {{ $rule->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.compliance.state-calling-rules.destroy', $rule) }}"
                                  class="delete-form" data-name="{{ $rule->state_code }} calling hours rule">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($rules->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $rules->links('vendor.pagination.custom') }}
        </div>
        @endif
        @else
        <div class="empty-state">
            <i class="bi bi-map"></i>
            <h3>No state calling-hour rules have been added yet.</h3>
            <p>You can import rules by CSV or add one manually.</p>
        </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
