@extends('layouts.app')
@section('title', 'Permissions')
@section('page-title', 'Permissions')
@section('page-subtitle', 'Manage system permissions')

@section('header-actions')
    <a href="{{ route('permissions.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Add Permission
    </a>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-key icon-primary"></i> Permissions</div>
    </div>
    <div class="table-wrapper">
        @foreach($permissions as $module => $perms)
        <div class="module-header">
            <span class="cell-code" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">
                {{ ucfirst($module) }}
            </span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Name</th>
                    <th>Route</th>
                    <th>Icon</th>
                    <th>Order</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($perms as $perm)
                <tr>
                    <td>
                        <i class="bi {{ $perm->icon }} icon-primary"></i>
                        {{ $perm->label }}
                    </td>
                    <td><span class="badge badge-secondary">{{ $perm->name }}</span></td>
                    <td class="cell-muted">{{ $perm->route }}</td>
                    <td class="cell-muted">{{ $perm->icon }}</td>
                    <td class="cell-muted">{{ $perm->sort_order }}</td>
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <a href="{{ route('permissions.edit', $perm) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            @if(!in_array($perm->name, ['manage_users', 'manage_roles', 'manage_permissions']))
                            <form method="POST" action="{{ route('permissions.destroy', $perm) }}"
                                  class="delete-form" data-entity="permission" data-name="{{ $perm->label }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endforeach
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush