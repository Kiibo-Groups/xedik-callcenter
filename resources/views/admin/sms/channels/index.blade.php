@extends('layouts.app')

@section('title', 'SMS Channels')
@section('page-title', 'SMS — Channels')
@section('page-subtitle', 'SMS numbers assigned to tenants')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-circle-fill"></i> Add SMS Channel
    </button>
@endsection

@section('content')

@include('admin.sms._nav')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-chat-dots-fill icon-primary"></i> SMS Channels</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Display Name</th>
                    <th>Number</th>
                    <th>Type</th>
                    <th>Provider Account</th>
                    <th>Capabilities</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($channels as $channel)
                <tr>
                    <td><span class="cell-strong">{{ $channel->tenant?->name ?? '—' }}</span></td>
                    <td>{{ $channel->display_name }}</td>
                    <td><code class="cell-code">{{ $channel->address }}</code></td>
                    <td class="cell-muted">{{ str_replace('_', ' ', $channel->address_type) }}</td>
                    <td class="cell-muted">{{ $channel->providerAccount?->display_name ?? '—' }}</td>
                    <td class="cell-muted">
                        {{ $channel->supportsP2p() ? 'P2P' : '' }}{{ $channel->supportsP2p() && $channel->supportsA2p() ? ' + ' : '' }}{{ $channel->supportsA2p() ? 'A2P' : '' }}
                    </td>
                    <td>
                        <span class="badge {{ $channel->is_active ? 'badge-success' : 'badge-danger' }} toggle-active-channel"
                              data-id="{{ $channel->id }}" style="cursor:pointer" title="Click to toggle">
                            <i class="bi {{ $channel->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $channel->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs"
                                onclick='openEditChannel({{ $channel->id }}, {{ json_encode([
                                    "tenant_id"                => $channel->tenant_id,
                                    "sms_provider_account_id"  => $channel->sms_provider_account_id,
                                    "display_name"             => $channel->display_name,
                                    "address"                  => $channel->address,
                                    "address_type"             => $channel->address_type,
                                    "country"                  => $channel->country,
                                    "supports_p2p"             => $channel->supportsP2p(),
                                    "supports_a2p"             => $channel->supportsA2p(),
                                ]) }})'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('admin.sms.channels.destroy', $channel) }}"
                                  class="delete-form" data-name="{{ $channel->display_name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px;">
                        No SMS channels configured yet.
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
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add SMS Channel</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.sms.channels.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tenant <span class="required-star">*</span></label>
                    <select name="tenant_id" class="form-control" required>
                        <option value="">— Select tenant —</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Provider Account <span class="required-star">*</span></label>
                    <select name="sms_provider_account_id" class="form-control" required>
                        <option value="">— Select provider account —</option>
                        @foreach($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->display_name }} ({{ $acc->provider }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Display Name <span class="required-star">*</span></label>
                    <input type="text" name="display_name" class="form-control" required placeholder="e.g. Sales SMS">
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Number <span class="required-star">*</span></label>
                        <input type="text" name="address" class="form-control" required placeholder="e.g. 16028481763">
                        <div class="form-hint">Digits only, in the format your provider expects as source/destination.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="address_type" class="form-control">
                            <option value="long_code">Long code</option>
                            <option value="toll_free">Toll-free</option>
                            <option value="short_code">Short code</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <input type="text" name="country" class="form-control" maxlength="5" placeholder="MX / US">
                </div>
                <div class="form-row cols-2">
                    <div class="check-row">
                        <input type="checkbox" name="supports_p2p" id="createP2p" value="1" checked>
                        <label class="check-label" for="createP2p">Supports P2P</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="supports_a2p" id="createA2p" value="1">
                        <label class="check-label" for="createA2p">Supports A2P</label>
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
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit SMS Channel</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editChannelForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tenant <span class="required-star">*</span></label>
                    <select name="tenant_id" id="editChannelTenant" class="form-control" required>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Provider Account <span class="required-star">*</span></label>
                    <select name="sms_provider_account_id" id="editChannelAccount" class="form-control" required>
                        @foreach($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->display_name }} ({{ $acc->provider }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Display Name <span class="required-star">*</span></label>
                    <input type="text" name="display_name" id="editChannelDisplayName" class="form-control" required>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Number <span class="required-star">*</span></label>
                        <input type="text" name="address" id="editChannelAddress" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="address_type" id="editChannelAddressType" class="form-control">
                            <option value="long_code">Long code</option>
                            <option value="toll_free">Toll-free</option>
                            <option value="short_code">Short code</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <input type="text" name="country" id="editChannelCountry" class="form-control" maxlength="5">
                </div>
                <div class="form-row cols-2">
                    <div class="check-row">
                        <input type="checkbox" name="supports_p2p" id="editP2p" value="1">
                        <label class="check-label" for="editP2p">Supports P2P</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="supports_a2p" id="editA2p" value="1">
                        <label class="check-label" for="editA2p">Supports A2P</label>
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
window.SmsChannelsAdminConfig = {
    routes: {
        base:   '{{ rtrim(config("app.url"), "/") }}/admin/sms/channels',
        toggle: '{{ rtrim(config("app.url"), "/") }}/admin/sms/channels/{id}/toggle',
    }
};
</script>
<script src="{{ asset('js/sms-channels-admin.js') }}"></script>
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
