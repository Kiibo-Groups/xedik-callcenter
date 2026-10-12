@extends('layouts.app')

@section('title', 'Storage Provider Catalog')
@section('page-title', 'Storage Provider Catalog')
@section('page-subtitle', 'Supported storage driver types')

@section('content')
@include('admin.storage._nav')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-grid-fill"></i></div>
        <div>
            <div class="stat-label">Total Providers</div>
            <div class="stat-value">{{ $providers->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div>
            <div class="stat-label">Active</div>
            <div class="stat-value">{{ $providers->where('is_active', true)->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-link-45deg"></i></div>
        <div>
            <div class="stat-label">Signed URLs</div>
            <div class="stat-value">{{ $providers->where('supports_temporary_urls', true)->count() }}</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-grid icon-primary"></i> Storage Providers</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Driver</th>
                    <th style="text-align:center">Requires Endpoint</th>
                    <th style="text-align:center">Requires Region</th>
                    <th style="text-align:center">Signed URLs</th>
                    <th style="text-align:center">Private Buckets</th>
                    <th style="text-align:center">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($providers as $provider)
                <tr>
                    <td>
                        <div class="cell-strong">{{ $provider->name }}</div>
                        <div class="cell-muted">{{ $provider->code }}</div>
                    </td>
                    <td><span class="badge badge-secondary">{{ $provider->driver }}</span></td>
                    <td style="text-align:center">
                        @if($provider->requires_endpoint)
                            <i class="bi bi-check-circle-fill" style="color:var(--success)"></i>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td style="text-align:center">
                        @if($provider->requires_region)
                            <i class="bi bi-check-circle-fill" style="color:var(--success)"></i>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td style="text-align:center">
                        @if($provider->supports_temporary_urls)
                            <i class="bi bi-check-circle-fill" style="color:var(--success)"></i>
                        @else
                            <i class="bi bi-x-circle-fill" style="color:var(--danger)"></i>
                        @endif
                    </td>
                    <td style="text-align:center">
                        @if($provider->supports_private_buckets)
                            <i class="bi bi-check-circle-fill" style="color:var(--success)"></i>
                        @else
                            <i class="bi bi-x-circle-fill" style="color:var(--danger)"></i>
                        @endif
                    </td>
                    <td style="text-align:center">
                        <span class="badge {{ $provider->is_active ? 'badge-success' : 'badge-danger' }} toggle-provider"
                            data-id="{{ $provider->id }}"
                            data-url="{{ route('admin.storage.providers.toggle', $provider) }}"
                            style="cursor:pointer" title="Click to toggle">
                            <i class="bi {{ $provider->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $provider->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <i class="bi bi-grid"></i>
                            <h3>No providers registered</h3>
                            <p>Run <code>php artisan db:seed --class=StorageProviderCatalogSeeder</code></p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="tip-box">
    <i class="bi bi-lightbulb tip-icon"></i>
    <div>
        The provider catalog is <strong>read-only</strong> — it defines which storage types are supported by the CRM.
        To configure credentials, create a <strong>Managed Profile</strong> (for CRM-managed storage)
        or configure storage directly on each tenant (for tenant-owned storage).
    </div>
</div>

@endsection

@push('scripts')
<script>
var _csrf = '{{ csrf_token() }}';
document.querySelectorAll('.toggle-provider').forEach(function (badge) {
    badge.addEventListener('click', function () {
        var url  = this.dataset.url;
        var self = this;
        fetch(url, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': _csrf, 'Accept': 'application/json' },
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.is_active) {
                    self.className = 'badge badge-success toggle-provider';
                    self.innerHTML = '<i class="bi bi-check-circle-fill badge-icon"></i> Active';
                } else {
                    self.className = 'badge badge-danger toggle-provider';
                    self.innerHTML = '<i class="bi bi-x-circle-fill badge-icon"></i> Inactive';
                }
                if (typeof showToast === 'function') showToast('Provider updated.', 'success');
            })
            .catch(function () { alert('Network error.'); });
    });
});
</script>
@endpush
