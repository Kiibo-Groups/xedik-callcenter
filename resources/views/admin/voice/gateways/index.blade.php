@extends('layouts.app')

@section('title', 'Voice — Gateways')
@section('page-title', 'Voice Administration')
@section('page-subtitle', 'Logical SIP gateway references')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-lg"></i> Add Gateway
    </button>
@endsection

@section('content')

@include('admin.voice._nav')

<div class="tip-box" style="margin-bottom:16px;">
    <i class="bi bi-info-circle-fill tip-icon"></i>
    <div>Gateways are <strong>logical references</strong> to Asterisk PJSIP endpoints.
         No SIP credentials are stored here — configure those directly in Asterisk.
         A <strong>global</strong> gateway (no tenant) is available to all tenants.</div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-hdd-network-fill icon-primary"></i> Gateways</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Scope</th>
                    <th>Asterisk Endpoint</th>
                    <th>Prefix</th>
                    <th>Normalization</th>
                    <th>Default</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($gateways as $gw)
                <tr>
                    <td class="cell-strong">{{ $gw->name }}</td>
                    <td>
                        @if($gw->isGlobal())
                            <span class="badge badge-primary">Global</span>
                        @else
                            <span class="badge badge-secondary">{{ $gw->tenant?->name ?? '—' }}</span>
                        @endif
                    </td>
                    <td><code class="cell-code">{{ $gw->asterisk_endpoint_id }}</code></td>
                    <td>
                        @if($gw->hidden_prefix)
                            <code class="cell-code">{{ $gw->hidden_prefix }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td class="cell-muted" style="font-size:11px;">
                        @if($gw->country_code)
                            +{{ $gw->country_code }} / {{ $gw->national_number_length ?? '?' }}d
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($gw->is_default)
                            <span class="badge badge-primary"><i class="bi bi-star-fill badge-icon"></i> Default</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <button class="toggle-btn {{ $gw->is_active ? 'active' : '' }}"
                            onclick="toggleGateway({{ $gw->id }}, this)">
                            <span></span>
                        </button>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs"
                                onclick="openEdit({{ $gw->id }}, {{ json_encode(['tenant_id' => $gw->tenant_id, 'name' => $gw->name, 'asterisk_endpoint_id' => $gw->asterisk_endpoint_id, 'hidden_prefix' => $gw->hidden_prefix, 'country_code' => $gw->country_code, 'national_number_length' => $gw->national_number_length, 'is_default' => $gw->is_default]) }})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('admin.voice.gateways.destroy', $gw) }}"
                                  class="delete-form" data-name="{{ $gw->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No gateways configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- CREATE MODAL --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:540px;">
        <div class="modal-header">
            <span class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Gateway</span>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.voice.gateways.store') }}">
            @csrf
            @include('admin.voice.gateways._form', ['gw' => null, 'tenants' => $tenants])
        </form>
    </div>
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:540px;">
        <div class="modal-header">
            <span class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Gateway</span>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm" action="">
            @csrf @method('PUT')
            @include('admin.voice.gateways._form', ['gw' => null, 'tenants' => $tenants, 'isEdit' => true])
        </form>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/voice-admin.js') }}"></script>
<script>
window.VoiceAdminConfig = {
    gatewayToggleUrl: '/admin/voice/gateways/{id}/toggle',
    gatewayUpdateUrl: '/admin/voice/gateways/{id}',
    csrf: '{{ csrf_token() }}'
};
</script>
@if($errors->any())
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if(old('_gateway_id'))
        var editForm = document.getElementById('editForm');
        var cfg = window.VoiceAdminConfig || {};
        if (editForm && cfg.gatewayUpdateUrl) {
            editForm.action = cfg.gatewayUpdateUrl.replace('{id}', '{{ old('_gateway_id') }}');
        }
        openModal('editModal');
    @else
        openModal('createModal');
    @endif
});
</script>
@endif
@endpush

@endsection
