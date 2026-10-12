@extends('layouts.app')

@section('title', 'Users')
@section('page-title', 'User Management')
@section('page-subtitle', $users->count() . ' users registered')

@section('header-actions')
    <a href="{{ route('users.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus-fill"></i> Add User
    </a>
@endsection

@push('styles')
<style>
.user-cell      { display: flex; align-items: center; gap: 10px; }
.user-avatar    { width: 36px; height: 36px; border-radius: 50%; color: white; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.user-name-row  { display: flex; align-items: center; gap: 6px; }
.user-name      { font-weight: 600; color: var(--text-strong); }
.super-badge    { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 700; background: linear-gradient(135deg,#f59e0b,#d97706); color: white; padding: 1px 6px; border-radius: 20px; letter-spacing: .4px; }
</style>
@endpush

@php
    $adminCount = $users->filter(fn($u) => $u->isAdmin())->count();
    $agentCount = $users->filter(fn($u) => $u->isAgent())->count();
@endphp

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Users</div><div class="stat-value">{{ $users->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-shield-fill"></i></div>
        <div><div class="stat-label">Admins</div><div class="stat-value">{{ $adminCount }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-headset"></i></div>
        <div><div class="stat-label">Agents</div><div class="stat-value">{{ $agentCount }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-people icon-primary"></i> Users</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    @if($isSuperAdmin) <th>Tenant</th> @endif
                    <th>Phone User ID</th>
                    <th>Role</th>
                    <th>Leads</th>
                    <th>Calls Made</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr>
                    <td>
                        <div class="user-cell">
                            <div class="user-avatar" style="background:{{ $user->isAdmin() ? '#8b5cf6' : 'var(--primary)' }}">
                                {{ strtoupper(substr($user->name,0,1)) }}
                            </div>
                            <div>
                                <div class="user-name-row">
                                    <span class="user-name">{{ $user->name }}</span>
                                    @if($user->isSuperAdmin())
                                        <span class="super-badge" title="Superadmin — no puede ser eliminado">
                                            <i class="bi bi-star-fill" style="font-size:9px"></i> SUPER
                                        </span>
                                    @endif
                                </div>
                                <div class="cell-muted">{{ $user->email }}</div>
                            </div>
                        </div>
                    </td>
                    @if($isSuperAdmin)
                    <td>
                        @if($user->tenant)
                            <span class="cell-strong">{{ $user->tenant->name }}</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    @endif
                    <td>
                        @if($user->phone_user_id)
                            <code class="cell-code">{{ $user->phone_user_id }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $user->isAdmin() ? 'badge-primary' : 'badge-secondary' }}">
                            <i class="bi {{ $user->isAdmin() ? 'bi-shield-fill' : 'bi-headset' }} badge-icon"></i>
                            {{ ucfirst($user->role?->name) }}
                        </span>
                    </td>
                    <td><span class="cell-strong">{{ $user->leads_count }}</span></td>
                    <td><span class="cell-strong">{{ $user->calls_count }}</span></td>
                    <td>
                        @if($user->isSuperAdmin())
                            <span class="badge badge-success">
                                <i class="bi bi-check-circle-fill badge-icon"></i> Active
                            </span>
                        @else
                            <span class="badge {{ $user->is_active ? 'badge-success' : 'badge-danger' }} toggle-active"
                                  data-user-id="{{ $user->id }}" style="cursor:pointer" title="Click to toggle">
                                <i class="bi {{ $user->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endif
                    </td>
                    <td class="cell-muted">{{ $user->created_at->format('M d, Y') }}</td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            @if($user->isLocked())
                            <form method="POST" action="{{ route('users.unlock', $user) }}" style="display:inline;">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-xs"
                                    style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;"
                                    title="Account locked — click to unlock">
                                    <i class="bi bi-lock-fill"></i> Unlock
                                </button>
                            </form>
                            @endif
                            @if(!$user->isSuperAdmin() && $user->id !== Auth::id())
                            <form method="POST" action="{{ route('users.destroy', $user) }}"
                                  class="delete-form" data-name="{{ $user->name }}">
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
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/users.js') }}"></script>
@endpush
