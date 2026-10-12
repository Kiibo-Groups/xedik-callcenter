<?php /** @var \Illuminate\Support\Collection $accounts */ ?>
@extends('layouts.app')

@section('title', 'SMS Provider Accounts')
@section('page-title', 'SMS — Provider Accounts')
@section('page-subtitle', 'Technical connections to SMS providers (DIDWW, Twilio, Telnyx, ...)')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-circle-fill"></i> Add Provider Account
    </button>
@endsection

@section('content')

@include('admin.sms._nav')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-hdd-network-fill icon-primary"></i> Provider Accounts</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Provider</th>
                    <th>Display Name</th>
                    <th>Region</th>
                    <th>Channels using</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($accounts as $account)
                <tr>
                    <td><span class="cell-strong">{{ $account->tenant?->name ?? 'Shared (all tenants)' }}</span></td>
                    <td><code class="cell-code">{{ $account->provider }}</code></td>
                    <td>{{ $account->display_name }}</td>
                    <td class="cell-muted">{{ $account->region ?? '—' }}</td>
                    <td class="cell-muted">{{ $account->channels()->count() }}</td>
                    <td>
                        <span class="badge {{ $account->is_active ? 'badge-success' : 'badge-danger' }} toggle-active-provider"
                              data-id="{{ $account->id }}" style="cursor:pointer" title="Click to toggle">
                            <i class="bi {{ $account->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $account->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs"
                                onclick='openEditProvider({{ $account->id }}, {{ json_encode([
                                    "tenant_id"    => $account->tenant_id,
                                    "provider"     => $account->provider,
                                    "display_name" => $account->display_name,
                                    "region"       => $account->region,
                                    "outbound_url" => $account->settings["outbound_url"] ?? "",
                                ]) }})'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('admin.sms.provider-accounts.destroy', $account) }}"
                                  class="delete-form" data-name="{{ $account->display_name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px;">
                        No SMS provider accounts configured yet.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- CREATE MODAL --}}
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Provider Account</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.sms.provider-accounts.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tenant</label>
                    <select name="tenant_id" class="form-control">
                        <option value="">— Shared (all tenants) —</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Provider <span class="required-star">*</span></label>
                    <select name="provider" class="form-control" required>
                        @foreach($providers as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Display Name <span class="required-star">*</span></label>
                    <input type="text" name="display_name" class="form-control" required placeholder="e.g. DIDWW Main Account">
                </div>
                <div class="form-group">
                    <label class="form-label">Region</label>
                    <input type="text" name="region" class="form-control" placeholder="e.g. US/MX">
                </div>
                <div class="form-group">
                    <label class="form-label">Outbound URL <span class="required-star">*</span></label>
                    <input type="text" name="outbound_url" class="form-control" required
                           placeholder="https://sms-out.didww.com/outbound_messages">
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Username <span class="required-star">*</span></label>
                        <input type="text" name="username" class="form-control" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span class="required-star">*</span></label>
                        <input type="password" name="password" class="form-control" required autocomplete="new-password">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Add</button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Provider Account</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editProviderForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tenant</label>
                    <select name="tenant_id" id="editProviderTenant" class="form-control">
                        <option value="">— Shared (all tenants) —</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Provider <span class="required-star">*</span></label>
                    <select name="provider" id="editProviderProvider" class="form-control" required>
                        @foreach($providers as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Display Name <span class="required-star">*</span></label>
                    <input type="text" name="display_name" id="editProviderDisplayName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Region</label>
                    <input type="text" name="region" id="editProviderRegion" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Outbound URL <span class="required-star">*</span></label>
                    <input type="text" name="outbound_url" id="editProviderOutboundUrl" class="form-control" required>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:var(--text-muted);font-size:11px;">(leave blank to keep current)</span></label>
                        <input type="text" name="username" class="form-control" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span style="color:var(--text-muted);font-size:11px;">(leave blank to keep current)</span></label>
                        <input type="password" name="password" class="form-control" autocomplete="new-password">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.SmsProviderAccountsConfig = {
    routes: {
        base:   '{{ rtrim(config("app.url"), "/") }}/admin/sms/provider-accounts',
        toggle: '{{ rtrim(config("app.url"), "/") }}/admin/sms/provider-accounts/{id}/toggle',
    }
};
</script>
<script src="{{ asset('js/sms-provider-accounts.js') }}"></script>
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
