@extends('layouts.app')

@section('title', 'Tenant Storage Settings')
@section('page-title', 'Tenant Storage Settings')
@section('page-subtitle', 'Per-tenant storage configuration and quota management')

@section('content')
@include('storage._nav')

{{-- Tenant selector --}}
<div class="card" style="margin-bottom:16px;">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-building icon-primary"></i> Select Tenant</span>
    </div>
    <div style="padding:16px;">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;">
            <div style="flex:1;max-width:360px;">
                <select name="tenant_id" class="form-control" onchange="this.form.submit()">
                    @foreach($tenants as $t)
                        <option value="{{ $t->id }}" {{ $selectedTenantId == $t->id ? 'selected' : '' }}>
                            {{ $t->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

@if($selectedTenantId)

{{-- Usage stats --}}
@if($usage)
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-hdd-fill"></i></div>
        <div>
            <div class="stat-label">Used</div>
            <div class="stat-value">{{ number_format($usage['used_gb'], 3) }} GB</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-hdd-stack-fill"></i></div>
        <div>
            <div class="stat-label">Allowed</div>
            <div class="stat-value">{{ number_format($usage['allowed_gb'], 2) }} GB</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-files"></i>
        </div>
        <div>
            <div class="stat-label">Active Files</div>
            <div class="stat-value">{{ number_format($usage['files_count']) }}</div>
        </div>
    </div>
    <div class="stat-card">
        @php
            $statusIcon  = match($usage['status']) { 'blocked' => 'bi-slash-circle-fill', 'critical' => 'bi-exclamation-triangle-fill', 'warning' => 'bi-exclamation-circle-fill', default => 'bi-check-circle-fill' };
            $statusColor = match($usage['status']) { 'blocked','critical' => 'orange', 'warning' => 'orange', default => 'green' };
        @endphp
        <div class="stat-icon {{ $statusColor }}"><i class="bi {{ $statusIcon }}"></i></div>
        <div>
            <div class="stat-label">Usage</div>
            <div class="stat-value">{{ $usage['usage_percent'] }}%</div>
        </div>
    </div>
</div>

{{-- Progress bar --}}
<div class="card" style="margin-bottom:16px;">
    <div style="padding:12px 16px;">
        @php
            $pct      = min($usage['usage_percent'], 100);
            $barColor = match($usage['status']) { 'blocked','critical' => '#dc3545', 'warning' => '#f59e0b', default => '#22c55e' };
        @endphp
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
            <span class="cell-muted">{{ number_format($usage['used_gb'],3) }} GB used of {{ number_format($usage['allowed_gb'],2) }} GB allowed</span>
            <span class="badge {{ match($usage['status']) { 'blocked' => 'badge-danger','critical' => 'badge-danger','warning' => 'badge-warning',default => 'badge-success' } }}">
                {{ strtoupper($usage['status']) }}
            </span>
        </div>
        <div style="background:var(--border-color);border-radius:8px;height:10px;overflow:hidden;">
            <div style="background:{{ $barColor }};height:100%;width:{{ $pct }}%;transition:width 0.4s;border-radius:8px;"></div>
        </div>
    </div>
</div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:flex-start;">

    {{-- Storage Config --}}
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="bi bi-gear icon-primary"></i> Storage Configuration</span>
            <button type="button" class="btn btn-outline btn-xs" id="btnTestConn"
                data-tenant="{{ $selectedTenantId }}">
                <i class="bi bi-wifi"></i> Test Connection
            </button>
        </div>
        <div style="padding:16px;">
            @if($setting && $setting->last_connection_test_at)
            <div id="connStatusBanner" style="padding:8px 12px;border-radius:6px;margin-bottom:16px;font-size:13px;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;
                background:{{ $setting->last_connection_status === 'success' ? 'var(--success-light,#f0fdf4)' : 'var(--danger-light,#fef2f2)' }};
                border:1px solid {{ $setting->last_connection_status === 'success' ? '#bbf7d0' : '#fecaca' }};
                color:{{ $setting->last_connection_status === 'success' ? '#166534' : '#991b1b' }};"
                data-status="{{ $setting->last_connection_status }}">
                <span>
                    <strong>{{ strtoupper($setting->last_connection_status) }}</strong>
                    — {{ $setting->last_connection_test_at->format('m/d/Y H:i') }}
                    @if($setting->last_connection_error)
                        <br><span style="font-size:12px;">{{ $setting->last_connection_error }}</span>
                    @endif
                </span>
                <button type="button" onclick="document.getElementById('connStatusBanner').remove()"
                    style="background:none;border:none;cursor:pointer;font-size:16px;line-height:1;opacity:.6;padding:0;color:inherit;">&times;</button>
            </div>
            @endif

            <form method="POST" action="{{ route('admin.storage.tenant-settings.save') }}" id="storageSettingForm">
                @csrf
                <input type="hidden" name="tenant_id" value="{{ $selectedTenantId }}">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Ownership</label>
                        <select name="ownership_type" id="ownershipType" class="form-control">
                            <option value="crm_managed" {{ ($setting?->ownership_type ?? 'crm_managed') === 'crm_managed' ? 'selected' : '' }}>CRM Managed</option>
                            <option value="tenant_owned" {{ ($setting?->ownership_type) === 'tenant_owned' ? 'selected' : '' }}>Tenant Owned</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Provider</label>
                        <select name="provider_code" class="form-control">
                            @foreach($providers as $prov)
                                <option value="{{ $prov->code }}" {{ ($setting?->provider_code) === $prov->code ? 'selected' : '' }}>{{ $prov->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div id="managedSection">
                    <div class="form-group">
                        <label class="form-label">Managed Profile</label>
                        <select name="managed_storage_profile_id" class="form-control">
                            <option value="">— Use system default profile —</option>
                            @foreach($profiles as $prof)
                                <option value="{{ $prof->id }}" {{ ($setting?->managed_storage_profile_id) == $prof->id ? 'selected' : '' }}>
                                    {{ $prof->name }} ({{ $prof->environment }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div id="ownedSection" style="display:none;">
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Bucket</label>
                            <input type="text" name="bucket" class="form-control" value="{{ $setting?->bucket }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Region</label>
                            <input type="text" name="region" class="form-control" value="{{ $setting?->region ?? 'auto' }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Endpoint</label>
                        <input type="text" name="endpoint" class="form-control" value="{{ $setting?->endpoint }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Base Path</label>
                        <input type="text" name="base_path" class="form-control" value="{{ $setting?->base_path }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Access Key ID</label>
                        <input type="text" name="access_key" class="form-control" autocomplete="off"
                            placeholder="{{ $setting?->access_key_encrypted ? '••••••• (leave blank to keep)' : '' }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Secret Access Key</label>
                        <input type="password" name="secret_key" class="form-control" autocomplete="off"
                            placeholder="{{ $setting?->secret_key_encrypted ? '••••••• (leave blank to keep)' : '' }}">
                    </div>
                </div>

                <div class="check-row" style="margin-bottom:16px;">
                    <input type="checkbox" name="is_active" id="settingActive" value="1"
                        {{ ($setting?->is_active ?? true) ? 'checked' : '' }}>
                    <label for="settingActive">Active</label>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="bi bi-save"></i> Save Configuration
                </button>
            </form>
        </div>
    </div>

    {{-- Subscriptions --}}
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="bi bi-box-seam icon-primary"></i> Storage Subscriptions</span>
            <button class="btn btn-primary" onclick="openModal('addPlanModal')">
                <i class="bi bi-plus-circle-fill"></i> Add Plan
            </button>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Storage</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subs as $sub)
                    <tr>
                        <td><div class="cell-strong">{{ $sub->plan->name ?? '—' }}</div></td>
                        <td><span class="cell-muted">{{ number_format(($sub->plan->included_bytes ?? 0) / (1024**3), 0) }} GB</span></td>
                        <td><span class="cell-muted">{{ $sub->starts_at->format('m/d/Y') }}</span></td>
                        <td><span class="cell-muted">{{ $sub->ends_at?->format('m/d/Y') ?? '∞' }}</span></td>
                        <td>
                            <span class="badge {{ $sub->is_active ? 'badge-success' : 'badge-danger' }}">
                                {{ $sub->is_active ? 'Active' : 'Cancelled' }}
                            </span>
                        </td>
                        <td>
                            @if($sub->is_active)
                            <form method="POST" action="{{ route('admin.storage.tenant-settings.subscriptions.cancel', $sub) }}"
                                class="delete-form" data-name="this subscription">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" style="text-align:center;padding:20px;">
                            <span class="cell-muted">1 GB base quota included. No additional subscriptions.</span>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

{{-- Add plan modal --}}
<div class="modal-overlay" id="addPlanModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Storage Plan</div>
            <button class="modal-close" onclick="closeModal('addPlanModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.storage.tenant-settings.subscriptions.add') }}">
            @csrf
            <input type="hidden" name="tenant_id" value="{{ $selectedTenantId }}">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Plan <span class="required-star">*</span></label>
                    <select name="storage_plan_id" class="form-control" required>
                        @foreach($plans->where('price_monthly', '>', 0) as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} — ${{ $p->price_monthly }}/mo</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Start Date <span class="required-star">*</span></label>
                        <input type="date" name="starts_at" class="form-control" value="{{ today()->toDateString() }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date <span class="cell-muted">(optional)</span></label>
                        <input type="date" name="ends_at" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addPlanModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Add Plan</button>
            </div>
        </form>
    </div>
</div>

@endif
@endsection

@push('scripts')
@php $testConnUrl = route('admin.storage.tenant-settings.test-connection'); @endphp
<script>
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
window.TenantStorageConfig = {
    csrf:    '{{ csrf_token() }}',
    testUrl: '{{ $testConnUrl }}',
};
</script>
<script src="{{ asset('js/tenant-storage.js') }}"></script>
@endpush
