@extends('layouts.app')

@php
    $isEdit = $rule->exists;
@endphp

@section('title', $isEdit ? 'Edit State Calling Rule' : 'Add State Calling Rule')
@section('page-title', $isEdit ? 'Edit State Calling Rule' : 'Add State Calling Rule')
@section('page-subtitle', 'State-Specific Calling Hours Rules')

@section('header-actions')
    <a href="{{ route('admin.compliance.state-calling-rules.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back
    </a>
@endsection

@section('content')

@include('admin.voice._nav')

@php
    // Same HH:mm 24-hour select pattern as the campaign Compliance tab —
    // never a native browser time input (AM/PM is locale-dependent).
    $timeOptions = [];
    for ($h = 0; $h < 24; $h++) {
        foreach ([0, 15, 30, 45] as $m) {
            $timeOptions[] = sprintf('%02d:%02d', $h, $m);
        }
    }
    $timeOptions[] = '23:59';

    $startTime = old('start_time', $rule->start_time ?? '08:00');
    $endTime   = old('end_time', $rule->end_time ?? '20:00');

    $dayLabels = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];
    $selectedDays = array_map('strtolower', old('days_of_week', $rule->days_of_week ?? []) ?? []);
@endphp

<div class="tip-box" style="margin-bottom:20px;">
    <i class="bi bi-info-circle tip-icon"></i>
    <div>
        State-specific calling-hour rules narrow the calling window for a state when active. Additional compliance
        controls such as DNC, consent, holidays, and frequency caps are managed separately.
    </div>
</div>

<div class="card" style="max-width:640px;">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-map icon-primary"></i>
            {{ $isEdit ? 'Edit ' . $rule->state_code . ' Calling Rule' : 'New State Calling Rule' }}
        </span>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ $isEdit ? route('admin.compliance.state-calling-rules.update', $rule) : route('admin.compliance.state-calling-rules.store') }}">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">State code <span class="required-star">*</span></label>
                    <input type="text" name="state_code" class="form-control" maxlength="2"
                           style="text-transform:uppercase;" placeholder="CA"
                           value="{{ old('state_code', $rule->state_code) }}" required>
                    @error('state_code')
                        <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                    @enderror
                    <div class="form-hint">Exactly 2 letters, e.g. CA, NY, AZ.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">State name</label>
                    <input type="text" name="state_name" class="form-control" placeholder="California"
                           value="{{ old('state_name', $rule->state_name) }}">
                </div>
            </div>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Start time <span class="required-star">*</span></label>
                    <select name="start_time" class="form-control">
                        @foreach($timeOptions as $t)
                        <option value="{{ $t }}" {{ $t === $startTime ? 'selected' : '' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                    @error('start_time')
                        <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">End time <span class="required-star">*</span></label>
                    <select name="end_time" class="form-control">
                        @foreach($timeOptions as $t)
                        <option value="{{ $t }}" {{ $t === $endTime ? 'selected' : '' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                    @error('end_time')
                        <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="form-hint mb-16">End time must be later than start time. Overnight windows are not supported.</div>

            <div class="form-group mb-16">
                <label class="form-label">Days of week</label>
                <div style="display:flex;flex-wrap:wrap;gap:12px;">
                    @foreach($dayLabels as $value => $label)
                    <div class="check-row" style="width:auto;">
                        <input type="checkbox" name="days_of_week[]" id="day{{ $value }}" value="{{ $value }}" class="form-check"
                               {{ in_array($value, $selectedDays, true) ? 'checked' : '' }}>
                        <label for="day{{ $value }}" class="form-label mb-0">{{ $label }}</label>
                    </div>
                    @endforeach
                </div>
                @error('days_of_week')
                    <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                @enderror
                @error('days_of_week.*')
                    <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                @enderror
                <div class="form-hint">If no days are selected, this rule applies every day.</div>
            </div>

            <div class="form-group mb-16">
                <div class="check-row">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="isActive" value="1" class="form-check"
                           {{ old('is_active', $rule->is_active ?? true) ? 'checked' : '' }}>
                    <label for="isActive" class="form-label mb-0">Active</label>
                </div>
                <div class="form-hint">Only active rules are used by the Compliance guard.</div>
            </div>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Source</label>
                    <input type="text" name="source" class="form-control" placeholder="e.g. CA Civil Code 1770"
                           value="{{ old('source', $rule->source) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Source updated at</label>
                    <input type="date" name="source_updated_at" class="form-control"
                           value="{{ old('source_updated_at', optional($rule->source_updated_at)->format('Y-m-d')) }}">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Source URL</label>
                <input type="text" name="source_url" class="form-control" placeholder="https://example.gov/statute"
                       value="{{ old('source_url', $rule->source_url) }}">
                @error('source_url')
                    <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group mb-0">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3">{{ old('notes', $rule->notes) }}</textarea>
            </div>

            <div style="display:flex;gap:8px;margin-top:20px;">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> {{ $isEdit ? 'Save Changes' : 'Create Rule' }}
                </button>
                <a href="{{ route('admin.compliance.state-calling-rules.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
