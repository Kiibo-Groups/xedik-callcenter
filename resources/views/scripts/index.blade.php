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

{{-- Campaign filter --}}
<div class="filters-bar" style="margin-bottom:16px;">
    <select id="filterCampaign" class="form-control" style="width:220px;" onchange="filterByCampaign(this.value)">
        <option value="">All campaigns</option>
        @foreach($campaigns as $c)
            <option value="{{ $c->id }}">{{ $c->name }}</option>
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
                    <th>Campaign</th>
                    <th>Type</th>
                    <th>Direction</th>
                    <th>Default</th>
                    <th>Sort</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($scripts as $script)
                <tr data-campaign="{{ $script->campaign_id }}">
                    <td>
                        <div class="cell-strong">{{ $script->name }}</div>
                        @if($script->url)
                            <div class="cell-muted" style="font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <i class="bi bi-link-45deg"></i> {{ $script->url }}
                            </div>
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
                    <td class="cell-muted">{{ $script->sort_order }}</td>
                    <td>
                        <span class="badge {{ $script->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $script->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('scripts.edit', $script) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('scripts.destroy', $script) }}"
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
                    <td colspan="8">
                        <div class="empty-state">
                            <i class="bi bi-file-text"></i>
                            <h3>No scripts yet</h3>
                            <p>Create scripts to guide agents during conversations.</p>
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
function filterByCampaign(id) {
    document.querySelectorAll('tbody tr[data-campaign]').forEach(row => {
        row.style.display = (!id || row.dataset.campaign == id) ? '' : 'none';
    });
}
</script>
@endpush

@endsection
