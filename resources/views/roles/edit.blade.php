@extends('layouts.app')
@section('title', 'Edit Role')
@section('page-title', 'Edit Role')
@section('page-subtitle', 'Update role permissions')

@section('content')
<div class="card" style="max-width:700px;">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-shield-check" style="color:var(--primary);margin-right:6px;"></i> Edit Role — {{ $role->label }}</div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('roles.update', $role) }}">
            @csrf @method('PUT')
            <div class="form-group">
                <label class="form-label">Label <span style="color:#dc2626;">*</span></label>
                <input type="text" name="label" class="form-control" value="{{ old('label', $role->label) }}">
            </div>

            <div class="form-group">
                <label class="form-label">Permissions</label>
                @foreach($permissions as $module => $perms)
                <div style="margin-bottom:12px;">
                    <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
                        {{ ucfirst($module) }}
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        @foreach($perms as $perm)
                        <label style="display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:13px;">
                            <input type="checkbox" name="permissions[]" value="{{ $perm->id }}"
                                {{ in_array($perm->id, $rolePermissions) ? 'checked' : '' }}>
                            <i class="bi {{ $perm->icon }}"></i> {{ $perm->label }}
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check"></i> Save Changes</button>
                <a href="{{ route('roles.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection