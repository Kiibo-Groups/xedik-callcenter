@extends('layouts.app')

@section('title', 'Dialer Campaigns')
@section('page-title', 'Dialer Campaigns')
@section('page-subtitle', 'Power dialer automated calling campaigns')

@section('content')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-telephone-outbound"></i></div>
        <div><div class="stat-label">Total Campaigns</div><div class="stat-value">{{ $campaigns->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-play-fill"></i></div>
        <div><div class="stat-label">Running</div><div class="stat-value">{{ $campaigns->where('status','running')->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-check2-all"></i></div>
        <div><div class="stat-label">Completed</div><div class="stat-value">{{ $campaigns->where('status','completed')->count() }}</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-telephone-outbound icon-primary"></i> Dialer Campaigns</span>
        <button class="btn btn-primary" onclick="openModal('createModal')">
            <i class="bi bi-plus-circle-fill"></i> New Campaign
        </button>
    </div>
    <div class="table-wrapper">
        @if($campaigns->count())
        <table>
            <thead>
                <tr>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Campaign</th>
                    <th>Lead List</th>
                    <th>Type</th>
                    <th>Caller ID</th>
                    <th>Block / Retries</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($campaigns as $dc)
                @php $s = $stats[$dc->id] ?? []; @endphp
                <tr data-campaign-row="{{ $dc->id }}">
                    @if($isSuperAdmin)
                    <td class="cell-muted">{{ $dc->tenant?->name ?? '—' }}</td>
                    @endif
                    <td>
                        @if($dc->name)
                        <div class="cell-strong">{{ $dc->name }}</div>
                        <div class="cell-muted">{{ $dc->campaign?->name ?? '—' }}</div>
                        @else
                        <div class="cell-strong">{{ $dc->campaign?->name ?? '—' }}</div>
                        @endif
                    </td>
                    <td>
                        @if($dc->leadList)
                            <div>{{ $dc->leadList->name }}</div>
                            <div class="cell-muted" data-lead-count="{{ $dc->id }}">{{ number_format(($s['total'] ?? 0) > 0 ? $s['total'] : $dc->leadList->total_leads) }} leads</div>
                        @else
                            <span class="cell-muted">No list assigned</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge" style="background:#e0e7ff;color:#4338ca;">{{ strtoupper($dc->dialer_type) }}</span>
                    </td>
                    <td><code class="cell-code">{{ $dc->caller_id }}</code></td>
                    <td class="cell-muted">{{ $dc->dial_block_size }} / {{ $dc->max_retries }}</td>
                    <td>
                        @php
                            // "Finalizing" solo si hay llamadas hechas (answer/busy/etc)
                            // Y todavía quedan NEW — significa que el worker aún está guardando.
                            // Si TODO es NEW = lista nueva nunca marcada, no es finalizing.
                            $calledLeads = ($s['answer'] ?? 0) + ($s['no_answer'] ?? 0)
                                         + ($s['busy'] ?? 0) + ($s['failed'] ?? 0);
                            $isFinalizando = $dc->dialer_type === 'power'
                                && $dc->status === 'completed'
                                && $dc->leadList
                                && ($s['new'] ?? 0) > 0
                                && $calledLeads > 0;
                            $st = match(true) {
                                $isFinalizando                  => ['class'=>'badge-warning',   'icon'=>'bi-hourglass-split', 'label'=>'Finalizing...'],
                                $dc->status === 'running'       => ['class'=>'badge-success',   'icon'=>'bi-play-fill',       'label'=>'Running'],
                                $dc->status === 'paused'        => ['class'=>'badge-warning',   'icon'=>'bi-pause-fill',      'label'=>'Paused'],
                                $dc->status === 'completed'     => ['class'=>'badge-secondary', 'icon'=>'bi-check2-all',      'label'=>'Completed'],
                                default                         => ['class'=>'badge-secondary', 'icon'=>'bi-stop-fill',       'label'=>'Idle'],
                            };
                        @endphp
                        <span class="badge {{ $st['class'] }}">
                            <i class="bi {{ $st['icon'] }} badge-icon"></i> {{ $st['label'] }}
                        </span>
                        @if($isFinalizando)
                        <div style="margin-top:4px;font-size:10px;color:#b45309;">
                            Saving results to DB... {{ $s['new'] }} pending
                        </div>
                        @endif
                        @if($dc->leadList && in_array($dc->status, ['running','paused']))
                        <div style="margin-top:6px;font-size:11px;display:flex;flex-direction:column;gap:2px;">
                            @if(($s['dialing'] ?? 0) > 0)
                            <span style="color:#16a34a;font-weight:600;">
                                <i class="bi bi-telephone-fill"></i> {{ $s['dialing'] }} dialing
                            </span>
                            @endif
                            <span style="color:#64748b;">
                                <i class="bi bi-hourglass-split"></i> {{ number_format($s['pending'] ?? 0) }} in queue
                            </span>
                        </div>
                        @endif
                        @if($dc->leadList && ($s['total'] ?? 0) > 0)
                        <div style="margin-top:6px;">
                            @php
                                $total     = $s['total'];
                                $processed = $total - ($s['new'] ?? 0);
                                $pct       = $total > 0 ? round($processed / $total * 100) : 0;
                            @endphp
                            <div style="height:5px;background:var(--bg-stripe);border-radius:3px;overflow:hidden;width:120px;">
                                <div style="height:100%;background:#16a34a;width:{{ $pct }}%;transition:width .3s;" data-progress-bar="{{ $dc->id }}"></div>
                            </div>
                            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">
                                <span data-stats-text="{{ $dc->id }}">{{ number_format($s['answer'] ?? 0) }} ans · {{ number_format($s['no_answer'] ?? 0) }} n/a · {{ number_format($s['busy'] ?? 0) }} busy · {{ number_format($s['failed'] ?? 0) }} fail · {{ number_format($s['new'] ?? 0) }} new ({{ $pct }}%)</span>
                            </div>
                        </div>
                        @endif
                    </td>
                    <td>
                        <div class="table-actions">
                            @if(in_array($dc->status, ['idle','paused']))
                            <button class="btn btn-outline btn-xs" style="color:#16a34a;border-color:#16a34a;" onclick="campaignAction({{ $dc->id }},'play')" title="Start">
                                <i class="bi bi-play-fill"></i> Start
                            </button>
                            @endif
                            @if($dc->status === 'running')
                            <button class="btn btn-outline btn-xs" style="color:#b45309;border-color:#b45309;" onclick="campaignAction({{ $dc->id }},'pause')" title="Pause">
                                <i class="bi bi-pause-fill"></i> Pause
                            </button>
                            @endif
                            @if(in_array($dc->status, ['running','paused']))
                            <button class="btn btn-danger btn-xs" onclick="campaignAction({{ $dc->id }},'stop')" title="Stop and clear queue">
                                <i class="bi bi-stop-fill"></i>
                            </button>
                            @endif
                            @if($dc->status === 'completed')
                            <button class="btn btn-outline btn-xs" style="color:#0891b2;border-color:#0891b2;" onclick="campaignAction({{ $dc->id }},'reset')" title="Reset to idle">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset
                            </button>
                            @endif
                            @if($dc->leadList)
                            <form method="POST"
                                  action="{{ route('dialer-campaigns.refresh-stats', $dc) }}"
                                  class="js-refresh-stats-form"
                                  data-campaign-id="{{ $dc->id }}"
                                  style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-xs" title="Refresh stats" data-refresh-stats-btn>
                                    <i class="bi bi-arrow-clockwise"></i> Stats
                                </button>
                            </form>
                            @endif
                            @if(!$dc->isRunning())
                            <a href="{{ route('dialer-campaigns.edit', $dc) }}" class="btn btn-outline btn-xs" title="Edit">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            @endif
                            @if(!in_array($dc->status, ['running','paused']))
                            <form method="POST" action="{{ route('dialer-campaigns.destroy', $dc) }}" class="delete-form" data-name="this dialer campaign">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-telephone-outbound"></i>
            <h3>No dialer campaigns yet</h3>
            <p>Create a campaign, assign a lead list and hit Start to begin dialing.</p>
        </div>
        @endif
    </div>
</div>

{{-- Create Modal --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-telephone-outbound modal-icon"></i> New Dialer Campaign</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('dialer-campaigns.store') }}">
            @csrf
            <div class="modal-body">
                @include('dialer_campaigns._form')
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Campaign</button>
            </div>
        </form>
    </div>
</div>


@endsection

@push('scripts')
<script>
window.DialerCampaignsConfig = {
    routes: { action: '{{ url("dialer-campaigns") }}' },
    csrf: '{{ csrf_token() }}',
    hasErrors: @json($errors->any()),
};
</script>
<script src="{{ asset('js/dialer-campaigns.js') }}"></script>
@endpush
