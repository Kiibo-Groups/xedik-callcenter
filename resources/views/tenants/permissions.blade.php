@extends('layouts.app')

@section('title', 'Tenant Permissions')
@section('page-title', 'Permissions — ' . $tenant->name)
@section('page-subtitle', 'Choose exactly what this tenant is entitled to use')

@section('header-actions')
    <a href="{{ route('tenants.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Tenants
    </a>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-shield-lock icon-primary"></i> {{ $tenant->name }} — Granted Permissions</span>
    </div>
    <div class="card-body">
        <div class="tip-box" style="margin-bottom:20px;">
            <i class="bi bi-info-circle tip-icon"></i>
            <div>
                Only what you check here is available for <strong>{{ $tenant->name }}</strong>'s admin to see and assign
                to their roles — anything unchecked is completely invisible to them, not just disabled. Unchecking a
                permission that a role already uses removes it silently, with no notification to the tenant.
            </div>
        </div>

        <form method="POST" action="{{ route('tenants.permissions.update', $tenant) }}">
            @csrf @method('PUT')

            @foreach($permissions as $module => $perms)
            <div style="margin-bottom:16px;">
                <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
                    {{ ucfirst($module) }}
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    @foreach($perms as $perm)
                    <label style="display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:13px;">
                        <input type="checkbox" name="permission_ids[]" value="{{ $perm->id }}"
                            {{ in_array($perm->id, $enabledIds) ? 'checked' : '' }}>
                        <i class="bi {{ $perm->icon }}"></i> {{ $perm->label }}
                    </label>
                    @endforeach
                </div>
            </div>
            @endforeach

            <div style="display:flex;gap:8px;margin-top:20px;">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Permissions</button>
                <a href="{{ route('tenants.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
