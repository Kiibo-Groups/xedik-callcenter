@extends('layouts.app')

@section('title', 'CDR Report')
@section('page-title', 'CDR Report')
@section('page-subtitle', 'Call Detail Records')

@section('content')
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-telephone-fill" style="color:var(--primary);margin-right:6px;"></i> CDR Report</div>
    </div>
    <div class="card-body">
        {{-- FILTROS --}}
        <form method="GET" action="{{ route('reports.cdr') }}">
            <div class="filters-bar">
                <select name="agent" class="form-control" style="width:200px;">
                    <option value="">All Agents</option>
                    <option value="__none__" {{ request('agent') == '__none__' ? 'selected' : '' }}>None</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent }}" {{ request('agent') == $agent ? 'selected' : '' }}>{{ $agent }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" class="form-control" style="width:160px;" value="{{ request('date_from') }}">
                <input type="date" name="date_to" class="form-control" style="width:160px;" value="{{ request('date_to') }}">
                <select name="type" class="form-control" style="width:160px;">
                    <option value="detail" {{ request('type','detail') == 'detail' ? 'selected' : '' }}>Detail</option>
                    <option value="grouped" {{ request('type') == 'grouped' ? 'selected' : '' }}>Grouped by Agent</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="{{ route('reports.cdr') }}" class="btn btn-secondary"><i class="bi bi-x"></i> Clear</a>
                <a href="{{ route('reports.cdr') }}?{{ http_build_query(array_merge(request()->all(), ['export' => 'csv'])) }}" 
                class="btn btn-secondary">
                    <i class="bi bi-download"></i> Export CSV
                </a>
            </div>
        </form>

        {{-- DETALLE --}}
        @if($type === 'detail')
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Phone</th>
                        <th>DID</th>
                        <th>Campaign</th>
                        <th>Agent</th>
                        <th>Disposition</th>
                        <th>Status Call</th>
                        <th>Media Type</th>
                        <th>Duration</th>
                        <th>Recording</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $r)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($r->created_date)->format('M d, Y H:i') }}</td>
                        <td>{{ $r->phonenumber }}</td>
                        <td>{{ $r->did }}</td>
                        <td>{{ $r->campaign }}</td>
                        <td>{{ $r->agent ?: 'None' }}</td>
                        <td><span style="font-size:13px;color:var(--text-strong);">{{ $r->disposition ?? '—' }}</span></td>
                        <td><span style="font-size:13px;color:var(--text-strong);">{{ $r->disposition_code ?? '—' }}</span></td>
                        <td><span style="font-size:13px;color:var(--text-strong);">{{ $r->media_type ?? '—' }}</span></td>
                        <td>{{ $r->duration_call }}</td>
                        <td>
                           @if($r->recording_id)
                            <div>
                                <button onclick="togglePlayer('{{ $r->recording_id }}')" 
                                        style="font-size:12px;color:var(--primary);background:none;border:none;cursor:pointer;padding:0;">
                                    <i class="bi bi-play-circle" id="icon-{{ $r->recording_id }}"></i> Play
                                </button>
                                <div id="player-{{ $r->recording_id }}" style="display:none;margin-top:4px;">
                                  <audio controls style="width:200px;height:28px;" id="audio-{{ $r->recording_id }}"></audio>
                                </div>
                            </div>
                        @else
                            <span style="color:var(--text-muted);font-size:12px;">—</span>
                        @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--text-muted);padding:40px;">No records found</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($records->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $records->links('vendor.pagination.custom') }}
        </div>
        @endif

        {{-- AGRUPADO --}}
        @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Total Calls</th>
                        <th>Talk Time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $r)
                    <tr>
                       <td style="font-size:13px;color:var(--text-strong);">{{ $r->agent ?: 'None' }}</td>
                        <td>{{ number_format($r->total_calls) }}</td>
                        <td>{{ $r->talk_time }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" style="text-align:center;color:var(--text-muted);padding:40px;">No records found</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
let currentAudio = null;

function togglePlayer(uid) {
    const player = document.getElementById('player-' + uid);
    const audio = document.getElementById('audio-' + uid);
    const icon = document.getElementById('icon-' + uid);

    if (!player || !audio || !icon) return;

    // detener audio anterior
    if (currentAudio && currentAudio !== audio) {
        currentAudio.pause();

        const prevId = currentAudio.id.replace('audio-', '');
        document.getElementById('icon-' + prevId).className = 'bi bi-play-circle';
        document.getElementById('player-' + prevId).style.display = 'none';
    }

    if (player.style.display === 'none') {
        if (!audio.src) {
            audio.src = '/dialer/recording-by-uid/' + uid;
        }

        player.style.display = 'block';
        icon.className = 'bi bi-pause-circle';

        audio.play().catch(() => {});
        currentAudio = audio;

    } else {
        player.style.display = 'none';
        icon.className = 'bi bi-play-circle';
        audio.pause();
        currentAudio = null;
    }
}
</script>
@endpush