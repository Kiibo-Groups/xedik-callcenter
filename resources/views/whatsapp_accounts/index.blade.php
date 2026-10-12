@extends('layouts.app')

@section('title', 'WhatsApp Accounts')
@section('page-title', 'WhatsApp Accounts')
@section('page-subtitle', 'Manage WhatsApp Business API accounts connected to the agent console')

@section('header-actions')
<a href="{{ route('whatsapp-accounts.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> New Account
</a>
@endsection

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-whatsapp icon-primary"></i> WhatsApp Accounts</span>
    </div>
    <div class="table-wrapper">
        @if($accounts->count())
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone Number</th>
                    <th>Phone Number ID</th>
                    <th>WABA ID</th>
                    <th>Queue</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts as $account)
                <tr>
                    <td class="cell-strong">{{ $account->display_name }}</td>
                    <td style="font-size:13px;">{{ $account->phone_number }}</td>
                    <td class="cell-code">{{ $account->phone_number_id }}</td>
                    <td class="cell-code">{{ $account->waba_id }}</td>
                    <td>
                        @if($account->omniQueue)
                            <span class="badge" style="background:#e0e7ff;color:#3730a3;">{{ $account->omniQueue->name }}</span>
                        @else
                            <span class="cell-muted" style="font-style:italic;">—</span>
                        @endif
                    </td>
                    @if($isSuperAdmin)
                    <td class="cell-muted">{{ $account->tenant?->name ?? '—' }}</td>
                    @endif
                    <td>
                        @if($account->is_active)
                            <span class="badge badge-success">Active</span>
                        @else
                            <span class="badge badge-secondary">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <a href="{{ route('whatsapp-accounts.edit', $account) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('whatsapp-accounts.destroy', $account) }}"
                                  class="delete-form" data-name="{{ $account->display_name }}" data-entity="WhatsApp account"
                                  style="display:inline;">
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
            <i class="bi bi-whatsapp"></i>
            <h3>No WhatsApp accounts yet</h3>
            <p>Add the first account to start receiving WhatsApp messages.</p>
        </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
