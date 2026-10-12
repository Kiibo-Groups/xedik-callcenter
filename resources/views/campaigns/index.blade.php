@extends('layouts.app')

@section('title', 'Campaigns')
@section('page-title', 'Campaigns')
@section('page-subtitle', $campaigns->count() . ' campaigns configured')

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-megaphone-fill"></i></div>
        <div><div class="stat-label">Total Campaigns</div><div class="stat-value">{{ $campaigns->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Leads</div><div class="stat-value">{{ $campaigns->sum('leads_count') }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="stat-label">Active</div><div class="stat-value">{{ $campaigns->where('is_active', true)->count() }}</div></div>
    </div>
</div>
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-megaphone icon-primary"></i> Campaigns</span>
        <a href="{{ route('campaigns.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle-fill"></i> Add Campaign
        </a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Program</th>
                    <th>Dialer Pattern</th>
                    <th>Preview</th>
                    <th>Agents</th>
                    <th>Leads</th>
                    <th>Default</th>
                    <th>Active</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($campaigns as $campaign)
                <tr>
                    <td>
                        <div class="cell-strong">{{ $campaign->name }}</div>
                        @if($campaign->description)
                            <div class="cell-muted">{{ $campaign->description }}</div>
                        @endif
                    </td>
                    <td>
                        @if($campaign->program)
                            <span class="badge badge-secondary">{{ $campaign->program->name }}</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($campaign->dialer_pattern)
                            <code class="cell-code">{{ $campaign->dialer_pattern }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge" style="background:{{ $campaign->bg_color }};color:{{ $campaign->color }};">
                            {{ $campaign->name }}
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            @forelse($campaign->users as $agent)
                                <span class="agent-avatar" title="{{ $agent->name }}">
                                    {{ strtoupper(substr($agent->name, 0, 1)) }}
                                </span>
                            @empty
                                <span class="cell-muted">No agents</span>
                            @endforelse
                            <span class="cell-muted" style="align-self:center;">
                                {{ $campaign->users_count }} agent{{ $campaign->users_count != 1 ? 's' : '' }}
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="cell-strong">{{ $campaign->leads_count }}</span>
                        <span style="font-size:11px;color:var(--text-muted);"> lead{{ $campaign->leads_count != 1 ? 's' : '' }}</span>
                    </td>
                    <td>
                        @if($campaign->is_default)
                            <span class="badge badge-primary">
                                <i class="bi bi-star-fill badge-icon"></i> Default
                            </span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $campaign->is_active ? 'badge-success' : 'badge-danger' }} toggle-campaign"
                              data-campaign-id="{{ $campaign->id }}" style="cursor:pointer;" title="Click to toggle">
                            <i class="bi {{ $campaign->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $campaign->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $campaign->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('campaigns.edit', $campaign) }}"
                               class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            @if($campaign->leads_count === 0)
                            <form method="POST" action="{{ route('campaigns.destroy', $campaign) }}"
                                  class="delete-form" data-name="{{ $campaign->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @else
                            <button class="btn btn-xs btn-disabled" title="Cannot delete — has leads">
                                <i class="bi bi-trash"></i>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $isSuperAdmin ? 10 : 9 }}">
                        <div class="empty-state">
                            <i class="bi bi-megaphone"></i>
                            <h3>No campaigns yet</h3>
                            <p>Create your first campaign to start organizing leads.</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- TIP --}}
<div class="tip-box">
    <i class="bi bi-lightbulb tip-icon"></i>
    <div>
        <strong>Tips:</strong>
        The <strong>Dialer Pattern</strong> is used to automatically assign incoming webhook leads to this campaign.
        The <strong>Default</strong> campaign receives leads that don't match any pattern.
        Agents only see leads from their assigned campaigns.
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/campaigns.js') }}"></script>
@endpush
