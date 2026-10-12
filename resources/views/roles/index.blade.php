@extends('layouts.app')
@section('title', 'Roles')
@section('page-title', 'Roles')
@section('page-subtitle', 'Manage roles and permissions')

@section('header-actions')
    <a href="{{ route('roles.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Add Role
    </a>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-shield icon-primary"></i> Roles</div>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Name</th>
                    <th>Permissions</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($roles as $role)
                <tr>
                    <td class="cell-strong">{{ $role->label }}</td>
                    <td><span class="badge badge-secondary">{{ $role->name }}</span></td>
                    <td><span class="badge badge-primary">{{ $role->permissions_count }} permissions</span></td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $role->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <a href="{{ route('roles.edit', $role) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            @if(!in_array($role->name, ['admin', 'agent']))
                            <form method="POST" action="{{ route('roles.destroy', $role) }}"
                                  class="delete-form" data-entity="role" data-name="{{ $role->label }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="{{ $isSuperAdmin ? 5 : 4 }}" style="text-align:center;color:var(--text-muted);padding:40px;">No roles found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush