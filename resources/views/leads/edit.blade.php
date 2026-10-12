@extends('layouts.app')

@section('title', 'Edit Lead')
@section('page-title', 'Edit Lead')
@section('page-subtitle', $lead->full_name)

@section('header-actions')
    <a href="{{ route('leads.show', $lead) }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Lead
    </a>
@endsection

@section('content')

@if($errors->any())
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>Please fix the following errors:</strong>
            <ul style="margin-top:4px;padding-left:16px">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('leads.fullUpdate', $lead) }}">
    @csrf
    @method('PUT')

    <div style="display:grid;grid-template-columns:1fr 340px;gap:20px">

        {{-- LEFT COLUMN --}}
        <div>
            {{-- PERSONAL INFO --}}
            <div class="card" style="margin-bottom:20px">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-person" style="color:var(--primary)"></i> Personal Information</span>
                </div>
                <div class="card-body">
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control"
                                   value="{{ old('first_name', $lead->first_name) }}" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control"
                                   value="{{ old('last_name', $lead->last_name) }}" required>
                        </div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="{{ old('email', $lead->email) }}" placeholder="email@example.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Source</label>
                            <select name="source" class="form-control">
                                <option value="">— Select source —</option>
                                @foreach($sources as $src)
                                    <option value="{{ $src->name }}" {{ old('source', $lead->source) == $src->name ? 'selected' : '' }}>
                                        {{ $src->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="{{ old('phone', $lead->phone) }}" placeholder="(555) 000-0000">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Secondary Phone</label>
                            <input type="text" name="phone_2" class="form-control"
                                   value="{{ old('phone_2', $lead->phone_2) }}" placeholder="(555) 000-0000">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ADDRESS --}}
            <div class="card" style="margin-bottom:20px">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-geo-alt" style="color:var(--primary)"></i> Address</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Street Address</label>
                        <input type="text" name="address" class="form-control"
                               value="{{ old('address', $lead->address) }}" placeholder="123 Main St">
                    </div>
                    <div class="form-row cols-3">
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control"
                                   value="{{ old('city', $lead->city) }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">State</label>
                            <input type="text" name="state" class="form-control"
                                   value="{{ old('state', $lead->state) }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">ZIP</label>
                            <input type="text" name="zip" class="form-control"
                                   value="{{ old('zip', $lead->zip) }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Country</label>
                        <input type="text" name="country" class="form-control"
                               value="{{ old('country', $lead->country) }}" placeholder="US">
                    </div>
                </div>
            </div>

            {{-- NOTES --}}
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-sticky" style="color:var(--primary)"></i> Notes</span>
                </div>
                <div class="card-body">
                    <div class="form-group" style="margin-bottom:0">
                        <textarea name="notes" class="form-control" rows="5"
                                  placeholder="Any relevant notes about this lead…">{{ old('notes', $lead->notes) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT COLUMN --}}
        <div>
            {{-- STATUS & ASSIGNMENT --}}
            <div class="card" style="margin-bottom:16px">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-sliders" style="color:var(--primary)"></i> Status & Assignment</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-control">
                            @foreach($statuses as $s)
                                <option value="{{ $s->slug }}" {{ old('status', $lead->status) == $s->slug ? 'selected' : '' }}>
                                    {{ $s->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Campaign --}}
                    <div class="form-group">
                        <label class="form-label">Campaign</label>
                        <select name="campaign_id" class="form-control">
                            <option value="">— No Campaign —</option>
                            @foreach($campaigns as $c)
                                <option value="{{ $c->id }}"
                                    {{ old('campaign_id', $lead->campaign_id) == $c->id ? 'selected' : '' }}>
                                    {{ $c->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @if(Auth::user()->isAdmin())
                    <div class="form-group">
                        <label class="form-label">Assigned Agent</label>
                        <select name="assigned_to" class="form-control">
                            <option value="">— Unassigned —</option>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}"
                                    {{ old('assigned_to', $lead->assigned_to) == $agent->id ? 'selected' : '' }}>
                                    {{ $agent->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Value ($)</label>
                        <input type="number" name="value" class="form-control"
                               value="{{ old('value', $lead->value) }}" placeholder="0.00" step="0.01" min="0">
                    </div>
                </div>
            </div>

            {{-- SAVE ACTIONS --}}
            <div class="card">
                <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                        <i class="bi bi-save"></i> Save Changes
                    </button>
                    <a href="{{ route('leads.show', $lead) }}"
                       class="btn btn-outline" style="width:100%;justify-content:center">
                        <i class="bi bi-x"></i> Cancel
                    </a>
                </div>
            </div>

            {{-- QUICK INFO --}}
            <div style="margin-top:16px;padding:14px;background:var(--bg-th);border-radius:var(--radius);border:1px solid var(--border);font-size:12px;color:var(--text-muted)">
                <div style="margin-bottom:6px"><i class="bi bi-clock" style="margin-right:4px"></i>Created: {{ $lead->created_at->format('M d, Y H:i') }}</div>
                <div><i class="bi bi-pencil" style="margin-right:4px"></i>Updated: {{ $lead->updated_at->format('M d, Y H:i') }}</div>
            </div>
        </div>

    </div>
</form>

@endsection