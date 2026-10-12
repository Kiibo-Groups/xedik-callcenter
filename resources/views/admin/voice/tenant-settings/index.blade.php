@extends('layouts.app')

@section('title', 'Voice — Tenant Settings')
@section('page-title', 'Voice Administration')
@section('page-subtitle', 'Tenant voice configuration')

@section('content')

@include('admin.voice._nav')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-gear-fill icon-primary"></i> Tenant Voice Settings</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Voice</th>
                    <th>Preview</th>
                    <th>Status</th>
                    <th>Default Caller ID</th>
                    <th>Default Gateway</th>
                    <th>TTL (s)</th>
                    <th>Max Sessions</th>
                    <th>Dialplan Contexts</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($tenants as $tenant)
                @php $vs = $tenant->voiceTenantSetting; $status = $vs?->voiceStatus() ?? 'disabled'; @endphp
                <tr>
                    <td class="cell-strong">{{ $tenant->name }}</td>
                    <td>
                        @if($vs?->voice_enabled)
                            <span class="badge badge-success"><i class="bi bi-check-circle-fill badge-icon"></i> Enabled</span>
                        @else
                            <span class="badge badge-secondary">Disabled</span>
                        @endif
                    </td>
                    <td>
                        @if($vs?->preview_dialer_enabled)
                            <span class="badge badge-success"><i class="bi bi-check-circle-fill badge-icon"></i> Enabled</span>
                        @else
                            <span class="badge badge-secondary">Disabled</span>
                        @endif
                    </td>
                    <td>
                        @if($status === 'ready')
                            <span class="badge badge-success">Ready</span>
                        @elseif($status === 'incomplete')
                            <span class="badge badge-warning" title="Voice enabled but no active ranges configured">Incomplete</span>
                        @else
                            <span class="badge badge-secondary">Disabled</span>
                        @endif
                    </td>
                    <td>
                        @if($vs?->default_caller_id)
                            <code class="cell-code">{{ $vs->default_caller_id }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($vs?->voiceGateway)
                            <span class="cell-strong" style="font-size:12px;">{{ $vs->voiceGateway->name }}</span>
                            <div><code class="cell-code" style="font-size:10px;">{{ $vs->voiceGateway->asterisk_endpoint_id }}</code></div>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td class="cell-muted">{{ $vs?->session_ttl_seconds ?? 28800 }}</td>
                    <td class="cell-muted">{{ $vs?->max_sessions_per_agent ?? 1 }}</td>
                    <td>
                        @if($vs)
                            <code class="cell-code" style="font-size:10px;display:block;">{{ $vs->agentsContextName() }}</code>
                            <code class="cell-code" style="font-size:10px;display:block;margin-top:2px;">{{ $vs->outboundContextName() }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn btn-outline btn-xs"
                            onclick="openSettingsModal({{ $tenant->id }}, '{{ $tenant->name }}', {{ json_encode(['voice_enabled' => (bool)$vs?->voice_enabled, 'preview_dialer_enabled' => (bool)$vs?->preview_dialer_enabled, 'default_caller_id' => $vs?->default_caller_id, 'voice_gateway_id' => $vs?->voice_gateway_id, 'session_ttl_seconds' => $vs?->session_ttl_seconds ?? 28800, 'max_sessions_per_agent' => $vs?->max_sessions_per_agent ?? 1]) }})">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="settingsModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <span class="modal-title"><i class="bi bi-gear-fill modal-icon"></i> Voice Settings: <span id="modalTenantName"></span></span>
            <button class="modal-close" onclick="closeModal('settingsModal')">&times;</button>
        </div>
        <form method="POST" id="settingsForm" action="">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group check-row">
                    <input type="checkbox" id="voice_enabled" name="voice_enabled" value="1" style="width:16px;height:16px;">
                    <label class="check-label" for="voice_enabled">Enable Voice for this tenant</label>
                </div>

                <div class="form-group check-row">
                    <input type="checkbox" id="preview_dialer_enabled" name="preview_dialer_enabled" value="1" style="width:16px;height:16px;">
                    <label class="check-label" for="preview_dialer_enabled">Enable Preview Dialer for this tenant</label>
                </div>

                <div class="form-group">
                    <label class="form-label">Default Caller ID <span class="cell-muted">(optional)</span></label>
                    <input type="text" class="form-control" name="default_caller_id" id="modal_caller_id"
                           placeholder="+15551234567" maxlength="40">
                    <div class="form-hint">Used when no campaign override is set. E.164 or 10-digit format.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Default Gateway <span class="cell-muted">(optional)</span></label>
                    <select class="form-control" name="voice_gateway_id" id="modal_gateway_id">
                        <option value="">— inherit / none —</option>
                        @foreach($gateways as $gw)
                        <option value="{{ $gw->id }}" data-global="{{ $gw->isGlobal() ? '1' : '0' }}">
                            {{ $gw->name }}
                            @if($gw->isGlobal()) (global) @endif
                            — {{ $gw->asterisk_endpoint_id }}
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Session TTL (seconds)</label>
                        <input type="number" class="form-control" name="session_ttl_seconds" id="modal_ttl"
                               min="3600" max="86400" value="28800">
                        <div class="form-hint">3600–86400 (1h–24h)</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Sessions / Agent</label>
                        <input type="number" class="form-control" name="max_sessions_per_agent" id="modal_max_sessions"
                               min="1" max="5" value="1">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('settingsModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Settings</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function openSettingsModal(tenantId, tenantName, data) {
    document.getElementById('modalTenantName').textContent = tenantName;
    document.getElementById('settingsForm').action = '/admin/voice/tenant-settings/' + tenantId;
    document.getElementById('voice_enabled').checked = !!data.voice_enabled;
    document.getElementById('preview_dialer_enabled').checked = !!data.preview_dialer_enabled;
    document.getElementById('modal_caller_id').value = data.default_caller_id || '';
    document.getElementById('modal_gateway_id').value = data.voice_gateway_id || '';
    document.getElementById('modal_ttl').value = data.session_ttl_seconds || 28800;
    document.getElementById('modal_max_sessions').value = data.max_sessions_per_agent || 1;
    document.getElementById('settingsModal').classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>
@endpush

@endsection
