@extends('layouts.app')

@section('title', 'Email Accounts')
@section('page-title', 'Email Accounts')
@section('page-subtitle', 'Manage IMAP/SMTP accounts connected to the agent console')

@section('header-actions')
<a href="{{ route('email-accounts.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> New Account
</a>
@endsection

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-envelope icon-primary"></i> Email Accounts</span>
    </div>
    <div class="table-wrapper">
        @if($accounts->count())
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>Incoming (IMAP)</th>
                    <th>Outgoing (SMTP)</th>
                    <th>Queue</th>
                    <th>Last Checked</th>
                    <th>Status</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts as $account)
                <tr>
                    <td class="cell-strong">{{ $account->name }}</td>
                    <td class="cell-muted">{{ $account->email }}</td>
                    <td style="font-size:13px;">
                        {{ $account->imap_host }}:{{ $account->imap_port }}
                        <span class="badge" style="background:#dbeafe;color:#1d4ed8;">{{ $account->imap_security }}</span>
                    </td>
                    <td style="font-size:13px;">
                        {{ $account->smtp_host }}:{{ $account->smtp_port }}
                        <span class="badge" style="background:#ede9fe;color:#6d28d9;">{{ $account->smtp_security }}</span>
                    </td>
                    <td>
                        @if($account->omniQueue)
                        <span class="badge" style="background:#e0e7ff;color:#3730a3;">{{ $account->omniQueue->name }}</span>
                        @else
                        <span class="cell-muted" style="font-style:italic;">—</span>
                        @endif
                    </td>
                    <td class="cell-muted">
                        {{ $account->last_checked_at?->diffForHumans() ?? 'Never' }}
                    </td>
                    <td>
                        @if($account->active)
                        <span class="badge badge-success">Active</span>
                        @else
                        <span class="badge badge-secondary">Inactive</span>
                        @endif
                    </td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $account->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <a href="{{ route('email-accounts.edit', $account) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('email-accounts.destroy', $account) }}"
                                style="display:inline"
                                class="delete-form" data-entity="email account" data-name="{{ $account->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-envelope"></i>
            <h3>No email accounts yet</h3>
            <p>Add the first one to start receiving emails.</p>
        </div>
        @endif
    </div>

    @if($accounts->hasPages())
    <div style="padding:16px 20px;border-top:1px solid var(--border);">
        {{ $accounts->links('vendor.pagination.custom') }}
    </div>
    @endif
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush