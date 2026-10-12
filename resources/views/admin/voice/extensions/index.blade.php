@extends('layouts.app')

@section('title', 'Voice — Extensions')
@section('page-title', 'Voice Administration')
@section('page-subtitle', 'SIP extensions across all tenants')

@section('content')

@include('admin.voice._nav')

{{-- Filters --}}
<div class="filters-bar">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <select name="tenant_id" class="form-control" style="width:200px;" onchange="this.form.submit()">
            <option value="">All tenants</option>
            @foreach($tenants as $t)
            <option value="{{ $t->id }}" {{ request('tenant_id') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
            @endforeach
        </select>
        <select name="status" class="form-control" style="width:160px;" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <option value="pending"     {{ request('status') === 'pending'     ? 'selected' : '' }}>Pending</option>
            <option value="provisioned" {{ request('status') === 'provisioned' ? 'selected' : '' }}>Provisioned</option>
            <option value="failed"      {{ request('status') === 'failed'      ? 'selected' : '' }}>Failed</option>
        </select>
        @if(request('tenant_id') || request('status'))
        <a href="{{ route('admin.voice.extensions.index') }}" class="btn btn-secondary btn-sm">Clear</a>
        @endif
    </form>
    <span class="cell-muted" style="margin-left:auto;">{{ $extensions->count() }} extension{{ $extensions->count() !== 1 ? 's' : '' }}</span>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-headset icon-primary"></i> Extensions</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>User</th>
                    <th>Ext.</th>
                    <th>Endpoint ID</th>
                    <th>Status</th>
                    <th>Active</th>
                    <th>Provisioned</th>
                    <th>Error</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($extensions as $ext)
                <tr>
                    <td class="cell-strong" style="font-size:12px;">{{ $ext->tenant?->name ?? '—' }}</td>
                    <td>
                        <div class="cell-strong">{{ $ext->user?->name ?? '(deleted)' }}</div>
                        <div class="cell-muted" style="font-size:11px;">{{ $ext->user?->email }}</div>
                    </td>
                    <td><code class="cell-code">{{ $ext->extension }}</code></td>
                    <td><code class="cell-code" style="font-size:10px;">{{ $ext->pjsip_endpoint ?? '—' }}</code></td>
                    <td>
                        @if($ext->sip_provisioning_status === 'provisioned')
                            <span class="badge badge-success">Provisioned</span>
                        @elseif($ext->sip_provisioning_status === 'pending')
                            <span class="badge badge-warning">Pending</span>
                        @elseif($ext->sip_provisioning_status === 'failed')
                            <span class="badge badge-danger">Failed</span>
                        @else
                            <span class="badge badge-secondary">{{ $ext->sip_provisioning_status }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $ext->is_active ? 'badge-success' : 'badge-secondary' }}">
                            {{ $ext->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="cell-muted" style="font-size:11px;">
                        {{ $ext->pjsip_provisioned_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td style="max-width:180px;">
                        @if($ext->sip_provisioning_error)
                            <span class="cell-muted" style="font-size:11px;word-break:break-all;" title="{{ $ext->sip_provisioning_error }}">
                                {{ Str::limit($ext->sip_provisioning_error, 60) }}
                            </span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="table-actions">
                            {{-- Retry Provisioning: only for active extensions with failed/pending status --}}
                            @if($ext->is_active && in_array($ext->sip_provisioning_status, ['failed', 'pending']))
                            <form method="POST" action="{{ route('admin.voice.extensions.reprovision', $ext) }}">
                                @csrf
                                <button type="submit" class="btn btn-warning btn-xs" title="Retry provisioning in Asterisk">
                                    <i class="bi bi-arrow-clockwise"></i> Retry Prov.
                                </button>
                            </form>
                            @endif

                            {{-- Retry Deprovisioning: only for deprovision_failed (released from user, Asterisk cleanup pending) --}}
                            @if($ext->sip_provisioning_status === 'deprovision_failed')
                            <form method="POST" action="{{ route('admin.voice.extensions.retry-deprovision', $ext) }}">
                                @csrf
                                <button type="submit" class="btn btn-xs"
                                    style="background:#7c3aed;color:white;"
                                    title="Retry removing endpoint/auth/aor from Asterisk">
                                    <i class="bi bi-trash3"></i> Retry Deprov.
                                </button>
                            </form>
                            @endif

                            {{-- Activate/Deactivate: not available for released/deprovision_failed --}}
                            @if(!in_array($ext->sip_provisioning_status, ['released', 'deprovision_failed']))
                            <form method="POST" action="{{ route('admin.voice.extensions.toggle', $ext) }}">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-xs {{ $ext->is_active ? 'btn-secondary' : 'btn-success' }}"
                                        title="{{ $ext->is_active ? 'Deactivate extension' : 'Activate and re-provision' }}">
                                    <i class="bi {{ $ext->is_active ? 'bi-pause-fill' : 'bi-play-fill' }}"></i>
                                    {{ $ext->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                            @else
                            <span class="cell-muted" style="font-size:11px;">
                                @if($ext->sip_provisioning_status === 'released')
                                    Released — Free Extension (future)
                                @else
                                    Deprov. pending
                                @endif
                            </span>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">No extensions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
