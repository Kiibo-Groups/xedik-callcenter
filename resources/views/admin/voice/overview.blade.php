@extends('layouts.app')

@section('title', 'Voice Admin')
@section('page-title', 'Voice Administration')
@section('page-subtitle', 'Asterisk Realtime & SIP provisioning')

@section('content')

@include('admin.voice._nav')

{{-- Asterisk Health --}}
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-card">
        <div class="stat-icon {{ $asteriskHealth['configured'] ? 'blue' : 'yellow' }}">
            <i class="bi bi-gear-fill"></i>
        </div>
        <div>
            <div class="stat-label">Asterisk Config</div>
            <div class="stat-value" style="font-size:14px;">
                {{ $asteriskHealth['configured'] ? 'Configured' : 'Not set' }}
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon {{ $asteriskHealth['connection_available'] ? 'green' : 'yellow' }}">
            <i class="bi bi-plug-fill"></i>
        </div>
        <div>
            <div class="stat-label">DB Connection</div>
            <div class="stat-value" style="font-size:14px;">
                {{ $asteriskHealth['connection_available'] ? 'Available' : 'Unavailable' }}
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon {{ $asteriskHealth['required_tables_exist'] ? 'green' : 'yellow' }}">
            <i class="bi bi-table"></i>
        </div>
        <div>
            <div class="stat-label">ps_* Tables</div>
            <div class="stat-value" style="font-size:14px;">
                {{ $asteriskHealth['required_tables_exist'] ? 'Present' : 'Missing' }}
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-building-fill"></i></div>
        <div>
            <div class="stat-label">Tenants with Voice</div>
            <div class="stat-value">{{ $tenantsWithVoice->count() }} / {{ $tenants->count() }}</div>
        </div>
    </div>
</div>

{{-- Extension Stats --}}
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div>
            <div class="stat-label">Provisioned Extensions</div>
            <div class="stat-value">{{ $extensionStats['provisioned'] ?? 0 }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="bi bi-hourglass-split"></i></div>
        <div>
            <div class="stat-label">Pending Provisioning</div>
            <div class="stat-value">{{ $extensionStats['pending'] ?? 0 }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-x-circle-fill"></i></div>
        <div>
            <div class="stat-label">Failed Provisioning</div>
            <div class="stat-value">{{ $extensionStats['failed'] ?? 0 }}</div>
        </div>
    </div>
</div>

{{-- Tenant Voice Status --}}
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-building icon-primary"></i> Tenant Voice Status</span>
        <a href="{{ route('admin.voice.tenant-settings.index') }}" class="btn btn-outline btn-sm">
            <i class="bi bi-gear"></i> Manage Settings
        </a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Voice</th>
                    <th>Status</th>
                    <th>Extensions</th>
                    <th>Default Gateway</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($tenants as $tenant)
                @php $vs = $tenant->voiceTenantSetting; @endphp
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
                        @if($vs)
                            @php $status = $vs->voiceStatus(); @endphp
                            @if($status === 'ready')
                                <span class="badge badge-success">Ready</span>
                            @elseif($status === 'incomplete')
                                <span class="badge badge-warning">Incomplete</span>
                            @else
                                <span class="badge badge-secondary">Disabled</span>
                            @endif
                        @else
                            <span class="badge badge-secondary">—</span>
                        @endif
                    </td>
                    <td>
                        {{ $tenant->voiceExtensions()->where('is_active', true)->count() }} active
                    </td>
                    <td>
                        @if($vs?->voiceGateway)
                            <code class="cell-code">{{ $vs->voiceGateway->asterisk_endpoint_id }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('admin.voice.tenant-settings.index') }}?tenant={{ $tenant->id }}"
                               class="btn btn-outline btn-xs">
                                <i class="bi bi-gear"></i>
                            </a>
                            <a href="{{ route('admin.voice.extension-ranges.index') }}?tenant_id={{ $tenant->id }}"
                               class="btn btn-outline btn-xs">
                                <i class="bi bi-123"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@if(!$asteriskHealth['configured'])
<div class="tip-box" style="margin-top:16px;">
    <i class="bi bi-exclamation-triangle-fill tip-icon"></i>
    <div>
        <strong>Asterisk Realtime not configured.</strong>
        Set <code>ASTERISK_REALTIME_CONNECTION</code>, <code>VOICE_REALM</code>, and
        <code>VOICE_WSS_SERVER</code> in your <code>.env</code> file to enable SIP provisioning.
    </div>
</div>
@endif

@endsection
