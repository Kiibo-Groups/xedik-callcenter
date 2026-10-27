@extends('layouts.app')

@section('title', 'Conversational Automation')
@section('page-title', 'Conversational Automation')
@section('page-subtitle', 'AI/n8n forwarding per channel — WhatsApp, Facebook, Instagram, Chat, SMS')

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title"><i class="bi bi-robot icon-primary"></i> Automation Channels</span>
        @if($isSuperAdmin && $tenants->count() > 1)
        <div style="display:flex;align-items:center;gap:8px;">
            <label class="form-label" style="margin:0;font-size:12px;color:var(--text-muted);font-weight:600;">Tenant:</label>
            <select class="form-control form-control-sm" style="width:auto;min-width:200px;" onchange="window.location.href='{{ route('admin.conversation-automation.index') }}?tenant_id=' + this.value;">
                @foreach($tenants as $t)
                    <option value="{{ $t->id }}" {{ $tenantId == $t->id ? 'selected' : '' }}>{{ $t->name }} (ID: {{ $t->id }})</option>
                @endforeach
            </select>
        </div>
        @endif
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Channel</th>
                    <th>Status</th>
                    <th>Webhook URL</th>
                    <th>Auth</th>
                    <th>Timeout</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($channels as $c)
                <tr data-channel-row="{{ $c['channel'] }}">
                    <td class="cell-strong" style="text-transform:capitalize;">
                        @php
                            $chIcon = match($c['channel']) {
                                'whatsapp'  => 'bi-whatsapp',
                                'facebook'  => 'bi-facebook',
                                'instagram' => 'bi-instagram',
                                'sms'       => 'bi-chat-text',
                                default     => 'bi-chat-fill',
                            };
                        @endphp
                        <i class="bi {{ $chIcon }} icon-primary"></i> {{ $c['channel'] }}
                    </td>
                    <td>
                        @if($c['enabled'])
                            <span class="badge badge-success">Enabled</span>
                        @else
                            <span class="badge badge-secondary">Disabled</span>
                        @endif
                    </td>
                    <td class="cell-code" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        {{ $c['webhook_url'] ?? '—' }}
                    </td>
                    <td class="cell-muted">
                        {{ $c['auth_type'] === 'api_key' ? 'API Key' . ($c['has_api_key'] ? ' (set)' : ' (missing)') : 'None' }}
                    </td>
                    <td class="cell-muted">{{ $c['timeout_ms'] }} ms</td>
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <button type="button" class="btn btn-outline btn-xs js-edit-automation-channel"
                                data-channel="{{ $c['channel'] }}"
                                data-enabled="{{ $c['enabled'] ? '1' : '0' }}"
                                data-webhook-url="{{ $c['webhook_url'] }}"
                                data-auth-type="{{ $c['auth_type'] }}"
                                data-has-api-key="{{ $c['has_api_key'] ? '1' : '0' }}"
                                data-timeout-ms="{{ $c['timeout_ms'] }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="tip-box mb-16">
    <i class="bi bi-info-circle tip-icon"></i>
    A brand-new conversation only starts AI-controlled if the channel is enabled here at the moment it arrives.
    Existing conversations are never re-activated automatically after a human takes over.
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="automationChannelModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <span class="modal-title"><i class="bi bi-robot modal-icon"></i> Automation: <span id="automationModalChannel"></span></span>
            <button class="modal-close" onclick="closeModal('automationChannelModal')">&times;</button>
        </div>
        <form method="POST" id="automationChannelForm" action="">
            @csrf @method('PUT')
            <input type="hidden" name="tenant_id" value="{{ $tenantId }}">
            <div class="modal-body">
                <div class="form-group check-row">
                    <input type="checkbox" id="automation_enabled" name="enabled" value="1" style="width:16px;height:16px;">
                    <label class="check-label" for="automation_enabled">Enable automation for this channel</label>
                </div>

                <div class="form-group">
                    <label class="form-label">Webhook URL</label>
                    <input type="url" class="form-control" name="webhook_url" id="automation_webhook_url"
                           placeholder="https://automate.example.com/webhook/...">
                </div>

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Auth Type</label>
                        <select class="form-control" name="auth_type" id="automation_auth_type">
                            <option value="none">None</option>
                            <option value="api_key">API Key (X-API-Key header)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Timeout (ms)</label>
                        <input type="number" class="form-control" name="timeout_ms" id="automation_timeout_ms"
                               min="1000" max="30000" value="5000">
                    </div>
                </div>

                <div class="form-group" id="automation_api_key_group">
                    <label class="form-label">
                        API Key
                        <span id="automation_api_key_hint" class="cell-muted" style="font-size:11px;"></span>
                    </label>
                    <input type="password" class="form-control" name="api_key" id="automation_api_key"
                           placeholder="Leave blank to keep current" autocomplete="new-password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('automationChannelModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
    window.ConversationAutomationConfig = {
        updateUrlBase: @json(url('admin/conversation-automation')),
    };
</script>
<script src="{{ asset('js/conversation-automation.js') }}"></script>
@endpush
