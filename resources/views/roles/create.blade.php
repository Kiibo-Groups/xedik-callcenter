@extends('layouts.app')
@section('title', 'Create Role')
@section('page-title', 'Create Role')
@section('page-subtitle', 'Add a new role')

@section('content')
<div class="card" style="max-width:700px;">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-shield-plus" style="color:var(--primary);margin-right:6px;"></i> New Role</div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('roles.store') }}">
            @csrf
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Name (slug) <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="supervisor" value="{{ old('name') }}">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Lowercase, no spaces. e.g. supervisor, qa</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Label <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="label" class="form-control" placeholder="Supervisor" value="{{ old('label') }}">
                </div>
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
                            <input type="checkbox" name="permissions[]" value="{{ $perm->id }}">
                            <i class="bi {{ $perm->icon }}"></i> {{ $perm->label }}
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check"></i> Create Role</button>
                <a href="{{ route('roles.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection