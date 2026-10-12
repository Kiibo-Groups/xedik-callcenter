@extends('layouts.app')

@section('title', 'Voicemail')
@section('page-title', 'Voicemail')
@section('page-subtitle', 'Voicemail recordings by agent')

@section('content')
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-voicemail" style="color:var(--primary);margin-right:6px;"></i> Voicemail</div>
    </div>
    <div class="card-body">
        {{-- FILTROS --}}
        <form method="GET" action="{{ route('reports.voicemail') }}">
            <div class="filters-bar">

                {{-- Campaign + Agent (solo admins) --}}
                @if($isAdmin)
                <select name="campaign_id" id="campaign" class="form-control" style="width:220px;">
                    <option value="">All Campaigns</option>
                    @foreach($campaigns as $c)
                        <option value="{{ $c->id }}" {{ request('campaign_id') == $c->id ? 'selected' : '' }}>
                            {{ $c->name }}
                        </option>
                    @endforeach
                </select>
                <select name="agent" id="agent" class="form-control" style="width:200px;">
                    <option value="">All Agents</option>
                    @foreach($agents as $a)
                        <option value="{{ $a->phone_user_id }}"
                                {{ request('agent') === $a->phone_user_id ? 'selected' : '' }}>
                            {{ $a->name }}
                        </option>
                    @endforeach
                </select>
                @endif

                <input type="date" name="date_from" class="form-control" style="width:160px;"
                       value="{{ request('date_from') }}">
                <input type="date" name="date_to" class="form-control" style="width:160px;"
                       value="{{ request('date_to') }}">

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Search
                </button>
                <a href="{{ route('reports.voicemail') }}" class="btn btn-secondary">
                    <i class="bi bi-x"></i> Clear
                </a>
                <a href="{{ route('reports.voicemail') }}?{{ http_build_query(array_merge(request()->all(), ['export' => 'csv'])) }}"
                   class="btn btn-secondary">
                    <i class="bi bi-download"></i> Export CSV
                </a>
            </div>
        </form>

        {{-- TABLA --}}
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
                                        <audio controls style="width:200px;height:28px;"
                                               id="audio-{{ $r->recording_id }}"></audio>
                                    </div>
                                </div>
                            @else
                                <span style="color:var(--text-muted);font-size:12px;">—</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" style="text-align:center;color:var(--text-muted);padding:40px;">
                            No voicemail records found
                        </td>
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
    </div>
</div>
@endsection

@push('scripts')
<script>
window.VoicemailReportConfig = {
    agentsUrl:     '{{ route('reports.voicemail.agents') }}',
    selectedAgent: '{{ request('agent') }}',
    isAdmin:       @json($isAdmin),
};
</script>
<script src="{{ asset('js/voicemail-report.js') }}"></script>
@endpush
