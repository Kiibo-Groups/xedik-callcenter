@extends('layouts.app')

@section('title', 'Voice Caller IDs')
@section('page-title', 'Voice — Caller IDs')
@section('page-subtitle', 'Authorized outbound numbers per tenant')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-circle-fill"></i> Add Caller ID
    </button>
@endsection

@section('content')

@include('admin.voice._nav')

<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-telephone-outbound-fill icon-primary"></i> Caller IDs
        </span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Number</th>
                    <th>Name</th>
                    <th>Gateway</th>
                    <th>Verified</th>
                    <th>Active</th>
                    <th>Extensions using</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($callerIds as $cid)
                <tr>
                    <td><span class="cell-strong">{{ $cid->tenant?->name ?? '—' }}</span></td>
                    <td><code class="cell-code">{{ $cid->number }}</code></td>
                    <td>
                        @if($cid->name)
                            {{ $cid->name }}
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($cid->voiceGateway)
                            <span class="cell-muted">{{ $cid->voiceGateway->name }}</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($cid->is_verified)
                            <span class="badge badge-success"><i class="bi bi-check-circle-fill badge-icon"></i> Yes</span>
                        @else
                            <span class="badge badge-warning"><i class="bi bi-exclamation-circle-fill badge-icon"></i> No</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $cid->is_active ? 'badge-success' : 'badge-danger' }} toggle-active-cid"
                              data-id="{{ $cid->id }}" style="cursor:pointer" title="Click to toggle">
                            <i class="bi {{ $cid->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $cid->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <span class="cell-muted">{{ $cid->voiceExtensions()->count() }}</span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs"
                                onclick="openEdit({{ $cid->id }}, {{ json_encode([
                                    'voice_gateway_id' => $cid->voice_gateway_id,
                                    'name'             => $cid->name,
                                    'number'           => $cid->number,
                                    'is_active'        => $cid->is_active,
                                    'is_verified'      => $cid->is_verified,
                                ]) }})">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST"
                                  action="{{ route('admin.voice.caller-ids.destroy', $cid) }}"
                                  class="delete-form" data-name="{{ $cid->number }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px;">
                        No Caller IDs configured yet.
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
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Caller ID</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.voice.caller-ids.store') }}">
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
                    <label class="form-label">Number <span class="required-star">*</span></label>
                    <input type="text" name="number" class="form-control" required
                           placeholder="e.g. 6025551000" maxlength="40">
                    <div class="form-hint">Digits only. Use E.164 (+1...) only if needed by the carrier.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Name / Label</label>
                    <input type="text" name="name" class="form-control"
                           placeholder="e.g. Sales DID" maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label">Gateway</label>
                    <select name="voice_gateway_id" class="form-control">
                        <option value="">— None —</option>
                        @foreach($gateways as $gw)
                            <option value="{{ $gw->id }}">{{ $gw->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row cols-2">
                    <div class="check-row">
                        <input type="checkbox" name="is_active" id="createIsActive" value="1" checked>
                        <label class="check-label" for="createIsActive">Active</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="is_verified" id="createIsVerified" value="1" checked>
                        <label class="check-label" for="createIsVerified">Verified</label>
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
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Caller ID</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Number <span class="required-star">*</span></label>
                    <input type="text" name="number" id="editNumber" class="form-control" required maxlength="40">
                </div>
                <div class="form-group">
                    <label class="form-label">Name / Label</label>
                    <input type="text" name="name" id="editName" class="form-control" maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label">Gateway</label>
                    <select name="voice_gateway_id" id="editGateway" class="form-control">
                        <option value="">— None —</option>
                        @foreach($gateways as $gw)
                            <option value="{{ $gw->id }}">{{ $gw->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row cols-2">
                    <div class="check-row">
                        <input type="checkbox" name="is_active" id="editIsActive" value="1">
                        <label class="check-label" for="editIsActive">Active</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="is_verified" id="editIsVerified" value="1">
                        <label class="check-label" for="editIsVerified">Verified</label>
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
window.VoiceCallerIdsConfig = {
    routes: {
        base: '{{ rtrim(config("app.url"), "/") }}/admin/voice/caller-ids',
        toggle: '{{ rtrim(config("app.url"), "/") }}/admin/voice/caller-ids/{id}/toggle',
    }
};
</script>
<script src="{{ asset('js/voice-caller-ids.js') }}?v={{ time() }}"></script>
@endpush
