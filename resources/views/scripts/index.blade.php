@extends('layouts.app')

@section('title', 'Scripts')
@section('page-title', 'Scripts')
@section('page-subtitle', $scripts->count() . ' scripts configured')

@section('header-actions')
    <a href="{{ route('scripts.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle-fill"></i> Add Script
    </a>
@endsection

@section('content')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-file-text-fill"></i></div>
        <div><div class="stat-label">Total</div><div class="stat-value">{{ $scripts->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Active</div><div class="stat-value">{{ $scripts->where('is_active', true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-star-fill"></i></div>
        <div><div class="stat-label">Defaults</div><div class="stat-value">{{ $scripts->where('is_default', true)->count() }}</div></div>
    </div>
</div>

{{-- Tenant & Campaign filter --}}
<div class="filters-bar" style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;">
    @if(auth()->user()->isSuperAdmin() && isset($tenants) && $tenants->isNotEmpty())
        <select id="filterTenant" class="form-control" style="width:220px;" onchange="applyScriptFilters()">
            <option value="">All tenants</option>
            @foreach($tenants as $t)
                <option value="{{ $t->id }}">{{ $t->name }}</option>
            @endforeach
        </select>
    @endif

    <select id="filterCampaign" class="form-control" style="width:220px;" onchange="applyScriptFilters()">
        <option value="">All campaigns</option>
        @foreach($campaigns as $c)
            <option value="{{ $c->id }}" data-tenant="{{ $c->tenant_id }}">{{ $c->name }}</option>
        @endforeach
    </select>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-file-text icon-primary"></i> Scripts</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Tenant</th>
                    <th>Campaign</th>
                    <th>Voice AI Agent</th>
                    <th>Type</th>
                    <th>Direction</th>
                    <th>Default</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($scripts as $script)
                <tr data-campaign="{{ $script->campaign_id }}" data-tenant="{{ $script->tenant_id }}">
                    <td>
                        <div class="cell-strong">{{ $script->name }}</div>
                        @if($script->url)
                            <div class="cell-muted" style="font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <i class="bi bi-link-45deg"></i> {{ $script->url }}
                            </div>
                        @endif
                    </td>
                    <td>
                        @if($script->tenant)
                            <span class="badge badge-info">
                                <i class="bi {{ $script->tenant->isRestaurant() ? 'bi-shop' : 'bi-building' }}"></i>
                                {{ $script->tenant->name }}
                            </span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($script->campaign)
                            <span class="badge badge-secondary">{{ $script->campaign->name }}</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($script->voicebot_agent_id)
                            <span class="badge badge-primary" style="font-family:monospace;font-size:11px;">
                                <i class="bi bi-robot"></i> {{ \Illuminate\Support\Str::limit($script->voicebot_agent_id, 22) }}
                            </span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $script->type === 'builder' ? 'badge-primary' : 'badge-info' }}">
                            <i class="bi {{ $script->type === 'builder' ? 'bi-pencil-square' : 'bi-link' }}"></i>
                            {{ $script->type === 'builder' ? 'Builder' : 'URL' }}
                        </span>
                    </td>
                    <td>
                        @php
                            $dirBadge = match($script->direction) {
                                'inbound'  => 'badge-success',
                                'outbound' => 'badge-warning',
                                default    => 'badge-secondary',
                            };
                        @endphp
                        <span class="badge {{ $dirBadge }}">{{ ucfirst($script->direction) }}</span>
                    </td>
                    <td>
                        @if($script->is_default)
                            <i class="bi bi-star-fill" style="color:#f59e0b;" title="Default script for this campaign/direction"></i>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $script->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $script->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('scripts.edit', $script->id) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('scripts.destroy', $script->id) }}"
                                  class="delete-form" data-name="{{ $script->name }}">
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
                    <td colspan="9">
                        <div class="empty-state">
                            <i class="bi bi-file-text"></i>
                            <h3>No scripts yet</h3>
                            <p>Create scripts to guide agents or configure ElevenLabs Voice AI prompts.</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
<script>
function applyScriptFilters() {
    const tenantEl = document.getElementById('filterTenant');
    const tenantId = tenantEl ? tenantEl.value : '';
    const campaignId = document.getElementById('filterCampaign').value;

    document.querySelectorAll('tbody tr[data-campaign]').forEach(row => {
        const matchTenant = !tenantId || row.dataset.tenant === tenantId;
        const matchCamp   = !campaignId || row.dataset.campaign === campaignId;
        row.style.display = (matchTenant && matchCamp) ? '' : 'none';
    });
}
</script>
@endpush

@endsection
