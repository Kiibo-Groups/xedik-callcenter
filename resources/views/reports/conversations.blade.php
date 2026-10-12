@extends('layouts.app')

@section('title', 'Conversations Report')
@section('page-title', 'Conversations Report')
@section('page-subtitle', 'Email and chat conversation history')

@section('content')
{{-- FILTERS --}}
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.conversations') }}" id="filterForm">

            {{-- FILA 1: siempre visible --}}
            <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
                <div>
                    <label class="form-label">From</label>
                    <input type="date" name="from" class="form-control"
                           value="{{ request('from', now()->startOfDay()->format('Y-m-d')) }}"
                           style="width:130px;">
                </div>
                <div>
                    <label class="form-label">To</label>
                    <input type="date" name="to" class="form-control"
                           value="{{ request('to', now()->format('Y-m-d')) }}"
                           style="width:130px;">
                </div>
                <div style="flex:1;min-width:200px;">
                    <label class="form-label">Search</label>
                    <div style="position:relative;">
                        <i class="bi bi-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;"></i>
                        <input type="text" name="search" class="form-control"
                               style="padding-left:30px;"
                               placeholder="Contact, email, subject, lead name..."
                               value="{{ request('search') }}">
                    </div>
                </div>
                <div style="display:flex;gap:6px;align-items:flex-end;">
                    <button type="button" id="moreFiltersBtn"
                            onclick="toggleMoreFilters()"
                            style="display:inline-flex;align-items:center;gap:5px;padding:8px 12px;border-radius:var(--radius-sm);font-size:12px;font-weight:500;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--primary);white-space:nowrap;">
                        <i class="bi bi-sliders" style="font-size:12px;"></i>
                        Filters
                        @php
                            $activeFilters = collect([
                                request('agent_id'), request('disposition'),
                                request('channel'), request('direction'),
                                request('type') && request('type') !== 'detailed' ? request('type') : null
                            ])->filter()->count();
                        @endphp
                        @if($activeFilters > 0)
                            <span style="background:var(--primary);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;">{{ $activeFilters }}</span>
                        @endif
                        <i class="bi bi-chevron-down" id="moreFiltersChevron" style="font-size:11px;transition:transform .2s;"></i>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                    <a href="{{ route('reports.conversations') }}" class="btn btn-secondary">
                        <i class="bi bi-x"></i> Clear
                    </a>
                </div>
            </div>

            {{-- FILA 2: filtros adicionales colapsables --}}
            <div id="moreFilters"
                 style="display:{{ $activeFilters > 0 ? 'flex' : 'none' }};align-items:flex-end;gap:8px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">

                @if($isAdmin)
                <div style="position:relative;" id="agentPickerWrap">
                    <label class="form-label">Agent</label>
                    {{-- Hidden input que se envía con el form --}}
                    <input type="hidden" name="agent_id" id="agentIdInput" value="{{ request('agent_id') }}">
                    {{-- Input visible de búsqueda --}}
                    <div style="position:relative;">
                        <i class="bi bi-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;"></i>
                        <input type="text" id="agentSearchInput"
                               class="form-control"
                               style="padding-left:28px;width:180px;"
                               placeholder="Search agent..."
                               autocomplete="off"
                               value="{{ request('agent_id') ? $agents->firstWhere('id', request('agent_id'))?->name : '' }}"
                               oninput="filterAgentDropdown(this.value)"
                               onfocus="showAgentDropdown()"
                               onblur="setTimeout(hideAgentDropdown, 200)">
                    </div>
                    {{-- Dropdown --}}
                    <div id="agentDropdown"
                         style="display:none;position:absolute;top:calc(100% + 2px);left:0;width:220px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);z-index:999;max-height:200px;overflow-y:auto;box-shadow:var(--shadow-lg);">
                        <div id="agentDropdownList"></div>
                    </div>
                </div>
                @endif

                <div>
                    <label class="form-label">Disposition</label>
                    <select name="disposition" class="form-control" style="width:130px;">
                        <option value="">All</option>
                        @foreach($dispositions as $d)
                            <option value="{{ $d->name }}" {{ request('disposition') == $d->name ? 'selected' : '' }}>
                                {{ $d->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="form-label">Channel</label>
                    <select name="channel" class="form-control" style="width:120px;">
                        <option value="">All</option>
                        <option value="email"     {{ request('channel') === 'email'     ? 'selected' : '' }}>Email</option>
                        <option value="chat"      {{ request('channel') === 'chat'      ? 'selected' : '' }}>Webchat</option>
                        <option value="whatsapp"  {{ request('channel') === 'whatsapp'  ? 'selected' : '' }}>WhatsApp</option>
                        <option value="facebook"  {{ request('channel') === 'facebook'  ? 'selected' : '' }}>Facebook</option>
                        <option value="instagram" {{ request('channel') === 'instagram' ? 'selected' : '' }}>Instagram</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Direction</label>
                    <select name="direction" class="form-control" style="width:110px;">
                        <option value="">All</option>
                        <option value="inbound"  {{ request('direction') === 'inbound'  ? 'selected' : '' }}>Inbound</option>
                        <option value="outbound" {{ request('direction') === 'outbound' ? 'selected' : '' }}>Outbound</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Type</label>
                    <select name="type" class="form-control" style="width:140px;">
                        <option value="detailed" {{ ($type ?? 'detailed') === 'detailed' ? 'selected' : '' }}>Detailed</option>
                        <option value="grouped"  {{ ($type ?? 'detailed') === 'grouped'  ? 'selected' : '' }}>Grouped by Agent</option>
                    </select>
                </div>

            </div>

        </form>
    </div>
</div>

{{-- TABLE --}}
@if(($type ?? 'detailed') === 'detailed')
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-chat-left-text" style="color:var(--primary);margin-right:6px;"></i>
            Conversations
        </span>
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:13px;color:var(--text-muted);">
                {{ $conversations->total() }} records
            </span>
            <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"
               class="btn btn-secondary btn-sm">
                <i class="bi bi-download"></i> Export CSV
            </a>
        </div>
    </div>

    <div class="table-wrapper">
        @if($conversations->count())
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Contact</th>
                    <th>Subject / Preview</th>
                    @if($isAdmin)<th>Agent</th>@endif
                    <th>Direction</th>
                    <th>Channel</th>
                    <th>Duration</th>
                    <th>Disposition</th>
                    <th style="text-align:center;">↓In</th>
                    <th style="text-align:center;">↑Out</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($conversations as $conv)
                @php
                    $isOutbound = $conv->direction === 'outbound';
                    $dirBg      = $isOutbound ? '#FAECE7' : '#E6F1FB';
                    $dirColor   = $isOutbound ? '#712B13' : '#0C447C';
                    $dirLabel   = $isOutbound ? 'Outbound' : 'Inbound';
                @endphp
                <tr>
                    <td style="white-space:nowrap;font-size:12px;color:var(--text-muted);">
                        {{ $conv->resolved_at?->format('m/d/Y H:i:s') }}
                    </td>
                    <td>
                        <div style="font-size:13px;font-weight:500;color:var(--text-strong);">
                            {{ $conv->lead_name ?? '' }}
                            @if(!$conv->lead_name)
                                <span class="badge badge-warning" style="font-size:10px;">No Lead</span>
                            @endif
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);">
                                @if($conv->contact_email)
                                    {{ str_contains($conv->contact_email, '@')
                                        ? $conv->contact_email
                                        : \App\Helpers\PhoneHelper::formatDisplay($conv->contact_email) }}
                                @endif
                            </div>
                    </td>
                    <td style="max-width:280px;">
                        <div style="font-size:13px;font-weight:500;color:var(--text-strong);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            {{ $conv->subject }}
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            {{ $conv->first_message_preview }}
                        </div>
                    </td>
                    @if($isAdmin)
                    <td style="font-size:13px;">{{ $conv->agent_name ?? '—' }}</td>
                    @endif
                    <td>
                        <span style="font-size:11px;font-weight:500;padding:2px 8px;border-radius:20px;background:{{ $dirBg }};color:{{ $dirColor }};">
                            {{ $dirLabel }}
                        </span>
                    </td>
                    <td>
                        @php
                            $rcBg    = match($conv->channel) { 'email' => '#dbeafe', 'whatsapp' => '#dcfce7', 'voice' => '#eaf3de', 'facebook' => '#E7F0FE', 'instagram' => '#FDEAF3', default => '#dcfce7' };
                            $rcColor = match($conv->channel) { 'email' => '#1d4ed8', 'whatsapp' => '#166534', 'voice' => '#3b6d11', 'facebook' => '#1877F2', 'instagram' => '#C13584', default => '#166534' };
                            $rcIcon  = match($conv->channel) { 'email' => 'envelope', 'whatsapp' => 'whatsapp', 'voice' => 'telephone-fill', 'facebook' => 'facebook', 'instagram' => 'instagram', default => 'chat' };
                        @endphp
                        <span class="badge" style="background:{{ $rcBg }};color:{{ $rcColor }};">
                            <i class="bi bi-{{ $rcIcon }}" style="margin-right:3px;"></i>
                            {{ strtoupper($conv->channel) }}
                        </span>
                    </td>
                    <td>
                        <span style="font-size:13px;color:var(--text-strong);">{{ $conv->duration_formatted ?? '—' }}</span>
                    </td>
                    <td>
                        <span style="font-size:13px;color:var(--text-strong);">{{ $conv->disposition ?? '—' }}</span>
                    </td>
                    <td style="text-align:center;font-size:12px;color:#0C447C;font-weight:500;">
                        {{ $conv->inbound_message_count }}
                    </td>
                    <td style="text-align:center;font-size:12px;color:#712B13;font-weight:500;">
                        {{ $conv->outbound_message_count }}
                    </td>
                    <td>
                        @php
                            $resolvedByLabel = match($conv->resolved_source ?? null) {
                                'ai'    => 'Resolved by AI',
                                'human' => 'Resolved by Human',
                                'system' => 'Resolved by System',
                                default => 'Resolved',
                            };
                        @endphp
                        <span class="badge badge-success">{{ $resolvedByLabel }}</span>
                    </td>
                    <td>
                        <button class="btn btn-outline btn-xs"
                                onclick="openConvDetail({{ $conv->id }})">
                            <i class="bi bi-eye"></i> View
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($conversations->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $conversations->links('vendor.pagination.custom') }}
        </div>
        @endif
        @else
        <div class="empty-state">
            <i class="bi bi-chat-left-text"></i>
            <h3>No conversations found</h3>
            <p>Try adjusting your filters.</p>
        </div>
        @endif
    </div>
</div>
@endif

@if(($type ?? 'detailed') === 'grouped' && $grouped->count())
<div class="card" style="margin-top:16px;">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-bar-chart" style="color:var(--primary);margin-right:6px;"></i>
            Summary by Agent
        </span>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"
           class="btn btn-secondary btn-sm">
            <i class="bi bi-download"></i> Export CSV
        </a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Channel</th>
                    <th style="text-align:center;">Convs</th>
                    <th style="text-align:center;">Msgs Total</th>
                    <th style="text-align:center;">↓In Msgs</th>
                    <th style="text-align:center;">↑Out Msgs</th>
                    <th>Avg Duration</th>
                    <th>Total Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($grouped as $agentId => $rows)
                    @php
                        $agentName    = $rows->first()->agent_name;
                        $agentTotal   = $rows->sum('total');
                        $agentInbound = $rows->sum('total_inbound');
                        $agentOut     = $rows->sum('total_outbound');
                        $agentAvgSec  = $rows->avg('avg_duration');
                        $agentTotSec  = $rows->sum('total_duration');
                        $fmtSec = fn($s) => $s ? sprintf('%02d:%02d:%02d', floor($s/3600), floor(($s%3600)/60), $s%60) : '—';
                    @endphp
                    @foreach($rows as $row)
                    <tr>
                        @if($loop->first)
                        <td rowspan="{{ $rows->count() + 1 }}" style="vertical-align:top;padding-top:14px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:26px;height:26px;border-radius:50%;background:var(--primary);color:white;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    {{ strtoupper(substr($agentName, 0, 1)) }}
                                </div>
                                <span style="font-size:13px;font-weight:500;">{{ $agentName }}</span>
                            </div>
                        </td>
                        @endif
                        <td>
                            @php
                                $rwBg    = match($row->channel) { 'email' => '#dbeafe', 'whatsapp' => '#dcfce7', 'voice' => '#eaf3de', 'facebook' => '#E7F0FE', 'instagram' => '#FDEAF3', default => '#dcfce7' };
                                $rwColor = match($row->channel) { 'email' => '#1d4ed8', 'whatsapp' => '#166534', 'voice' => '#3b6d11', 'facebook' => '#1877F2', 'instagram' => '#C13584', default => '#166534' };
                                $rwIcon  = match($row->channel) { 'email' => 'envelope', 'whatsapp' => 'whatsapp', 'voice' => 'telephone-fill', 'facebook' => 'facebook', 'instagram' => 'instagram', default => 'chat' };
                            @endphp
                            <span class="badge" style="background:{{ $rwBg }};color:{{ $rwColor }};">
                                <i class="bi bi-{{ $rwIcon }}" style="margin-right:3px;"></i>
                                {{ strtoupper($row->channel) }}
                            </span>
                        </td>
                        <td style="text-align:center;font-weight:600;">{{ $row->total }}</td>
                        <td style="text-align:center;font-weight:500;">{{ $row->total_msgs ?? 0 }}</td>
                        <td style="text-align:center;color:#0C447C;font-weight:500;">{{ $row->total_inbound_msgs ?? 0 }}</td>
                        <td style="text-align:center;color:#712B13;font-weight:500;">{{ $row->total_outbound_msgs ?? 0 }}</td>
                        <td><span style="font-family:monospace;font-size:13px;">{{ $fmtSec((int)$row->avg_duration) }}</span></td>
                        <td><span style="font-family:monospace;font-size:13px;">{{ $fmtSec($row->total_duration) }}</span></td>
                    </tr>
                    @endforeach
                    {{-- Fila de totales del agente --}}
                    <tr style="background:var(--bg-th);">
                        <td style="font-size:11px;color:var(--text-muted);">Total {{ $agentName }}</td>
                        <td style="text-align:center;font-weight:700;color:var(--primary);">{{ $agentTotal }}</td>
                        <td style="text-align:center;font-weight:600;">{{ $rows->sum('total_msgs') }}</td>
                        <td style="text-align:center;color:#0C447C;font-weight:600;">{{ $rows->sum('total_inbound_msgs') }}</td>
                        <td style="text-align:center;color:#712B13;font-weight:600;">{{ $rows->sum('total_outbound_msgs') }}</td>
                        <td><span style="font-family:monospace;font-size:13px;">{{ $fmtSec((int)$agentAvgSec) }}</span></td>
                        <td><span style="font-family:monospace;font-size:13px;color:var(--primary);font-weight:700;">{{ $fmtSec($agentTotSec) }}</span></td>
                    </tr>
                    {{-- Separador --}}
                    <tr><td colspan="8" style="padding:3px;background:var(--bg-page);border:none;"></td></tr>
                @endforeach
                @php
                    $grandTotal   = $grouped->flatten()->sum('total');
                    $grandMsgs    = $grouped->flatten()->sum('total_msgs');
                    $grandInMsgs  = $grouped->flatten()->sum('total_inbound_msgs');
                    $grandOutMsgs = $grouped->flatten()->sum('total_outbound_msgs');
                    $grandAvgSec  = $grouped->flatten()->avg('avg_duration');
                    $grandTotSec  = $grouped->flatten()->sum('total_duration');
                    $fmtSec = fn($s) => $s ? sprintf('%02d:%02d:%02d', floor($s/3600), floor(($s%3600)/60), (int)$s%60) : '—';
                @endphp
                <tr style="background:#eef2ff;">
                    <td colspan="2" style="font-size:12px;font-weight:700;color:#4f46e5;">Grand Total</td>
                    <td style="text-align:center;font-size:14px;font-weight:700;color:#4f46e5;">{{ $grandTotal }}</td>
                    <td style="text-align:center;font-weight:700;">{{ $grandMsgs }}</td>
                    <td style="text-align:center;color:#0C447C;font-weight:700;">{{ $grandInMsgs }}</td>
                    <td style="text-align:center;color:#712B13;font-weight:700;">{{ $grandOutMsgs }}</td>
                    <td><span style="font-family:monospace;font-size:13px;font-weight:700;color:#4f46e5;">{{ $fmtSec((int)$grandAvgSec) }}</span></td>
                    <td><span style="font-family:monospace;font-size:13px;font-weight:700;color:#4f46e5;">{{ $fmtSec($grandTotSec) }}</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- MODAL DETALLE --}}
<div class="modal-overlay" id="convDetailModal">
    <div class="modal" style="max-width:720px;height:90vh;display:flex;flex-direction:column;overflow:hidden;background:var(--bg-card);color:var(--text-strong);">
        <div class="modal-header">
            <div class="modal-title">
                <i class="bi bi-chat-left-text" style="color:var(--primary);margin-right:6px;"></i>
                <span id="convDetailSubject">Conversation</span>
            </div>
            <button class="modal-close" onclick="closeConvDetail()">&times;</button>
        </div>

        <div id="convDetailMeta" style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;gap:16px;flex-wrap:wrap;background:var(--bg-th);font-size:12px;color:var(--text-muted);">
        </div>

        <div id="convDetailThread" style="background:#f8fafc;padding:12px;overflow-y:auto;flex:1;min-height:0;display:flex;flex-direction:column;gap:8px;">
            <div style="text-align:center;color:var(--text-muted);padding:20px;">Loading...</div>
        </div>

        <div class="modal-footer" style="display:flex;align-items:center;justify-content:space-between;">
            <a id="convDetailCsvLink" href="#" target="_blank"
               style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:5px;font-size:11px;font-weight:500;border:0.5px solid var(--border);background:var(--bg-card);color:var(--text-strong);text-decoration:none;visibility:hidden;">
               <i class="bi bi-download" style="font-size:11px;"></i> CSV
            </a>
            <button class="btn btn-secondary" onclick="closeConvDetail()">Close</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function reportFormatPhone(val) {
    if (!val || val.includes('@')) return val;
    const clean = val.replace(/\D/g, '');
    const prefixes = [
        {prefix: '521', digits: 13},
        {prefix: '52',  digits: 12},
        {prefix: '1',   digits: 11},
    ];
    for (const p of prefixes) {
        if (clean.startsWith(p.prefix) && clean.length === p.digits) {
            return clean.slice(p.prefix.length);
        }
    }
    return clean || val;
}

function _escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function openConvDetail(id) {
    document.getElementById('convDetailModal').classList.add('active');
    document.getElementById('convDetailThread').innerHTML =
        '<div style="text-align:center;color:var(--text-muted);padding:30px;"><i class="bi bi-hourglass-split" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading...</div>';
    document.getElementById('convDetailMeta').innerHTML = '';
    document.getElementById('convDetailSubject').textContent = 'Conversation';
    const csvLink = document.getElementById('convDetailCsvLink');
    if (csvLink) csvLink.style.visibility = 'hidden';

    fetch(`/reports/conversation-report/${id}`)
        .then(r => r.json())
        .then(data => {
            const conv = data.conversation;
            const msgs = data.messages;
            const lead = data.lead;
            // whatsapp/chat/facebook/instagram comparten render de burbujas
            const isWa = ['whatsapp', 'chat', 'facebook', 'instagram'].includes(conv.channel);

            // CSV link
            if (csvLink) {
                csvLink.href = `/reports/conversations/${id}/export-csv`;
                csvLink.style.visibility = 'visible';
            }

            const displaySubject = isWa
                ? reportFormatPhone(conv.subject || conv.from_email)
                : (conv.subject || '(no subject)');
            document.getElementById('convDetailSubject').textContent = displaySubject;

            const leadName = lead
                ? (lead.first_name + ' ' + lead.last_name).trim()
                : (conv.from_name || reportFormatPhone(conv.from_email));

            const firstMsg = msgs[msgs.length - 1] ?? null;
            const contactRaw = (firstMsg && firstMsg.direction === 'outbound')
                ? firstMsg.to_email : conv.from_email;
            const contactDisplay = reportFormatPhone(contactRaw);

            const leadDisplay = lead
                ? `<span><i class="bi bi-person" style="margin-right:4px;"></i>${(lead.first_name + ' ' + lead.last_name).trim()}</span>`
                : `<span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:#fef9c3;color:#854d0e;"><i class="bi bi-exclamation-triangle-fill" style="margin-right:4px;"></i>No Lead</span>`;

            const chanIcon = conv.channel === 'email'     ? 'bi-envelope'
                           : conv.channel === 'whatsapp'  ? 'bi-whatsapp'
                           : conv.channel === 'facebook'  ? 'bi-facebook'
                           : conv.channel === 'instagram' ? 'bi-instagram'
                           : 'bi-chat-dots';
            document.getElementById('convDetailMeta').innerHTML = `
                ${leadDisplay}
                <span><i class="bi ${chanIcon}" style="margin-right:4px;"></i>${contactDisplay}</span>
                <span><i class="bi bi-tag" style="margin-right:4px;"></i>${conv.status}</span>
                ${conv.notes ? `<span style="display:block;width:100%;margin-top:6px;color:var(--text-muted);"><i class="bi bi-sticky" style="margin-right:4px;"></i>${_escHtml(conv.notes)}</span>` : ''}
            `;

            if (msgs.length === 0) {
                document.getElementById('convDetailThread').innerHTML =
                    '<div style="text-align:center;color:var(--text-muted);padding:20px;">No messages.</div>';
                return;
            }

            if (isWa) {
                // Burbujas estilo chat para WhatsApp
                let lastDate = '';
                let html = '<div style="display:flex;flex-direction:column;gap:8px;padding:4px 0;">';
                msgs.slice().reverse().forEach(function(m) {
                    const sentParts = m.sent_at ? m.sent_at.split(' · ') : ['', ''];
                    const dateStr   = sentParts[0] || '';
                    const timeStr   = sentParts[1] || '';
                    if (dateStr && dateStr !== lastDate) {
                        html += `<div style="text-align:center;font-size:11px;color:var(--text-muted);margin:8px 0;display:flex;align-items:center;gap:8px;"><div style="flex:1;height:0.5px;background:var(--border);"></div>${dateStr}<div style="flex:1;height:0.5px;background:var(--border);"></div></div>`;
                        lastDate = dateStr;
                    }
                    const isOut  = m.direction === 'outbound';
                    const name   = m.from_name || reportFormatPhone(m.from_email) || '?';
                    const initRaw = name.replace(/^\+?\d/, '') ? name.trim()[0] : '#';
                    const inicial = initRaw.toUpperCase();
                    const body    = (m.body_text || '').replace(/\n/g, '<br>');
                    if (isOut) {
                        html += `<div style="display:flex;gap:8px;align-items:flex-end;max-width:75%;align-self:flex-end;flex-direction:row-reverse;"><div style="width:26px;height:26px;border-radius:50%;background:#E6F1FB;color:#185FA5;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">${inicial}</div><div><div style="padding:8px 12px;border-radius:16px;border-bottom-right-radius:3px;background:#E1F5EE;color:#085041;font-size:13px;line-height:1.5;">${body}</div><div style="font-size:10px;color:var(--text-muted);margin-top:3px;text-align:right;">${timeStr}</div></div></div>`;
                    } else {
                        html += `<div style="display:flex;gap:8px;align-items:flex-end;max-width:75%;align-self:flex-start;"><div style="width:26px;height:26px;border-radius:50%;background:#E1F5EE;color:#085041;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">${inicial}</div><div><div style="padding:8px 12px;border-radius:16px;border-bottom-left-radius:3px;background:var(--bg-card);color:var(--text-strong);font-size:13px;line-height:1.5;">${body}</div><div style="font-size:10px;color:var(--text-muted);margin-top:3px;">${timeStr}</div></div></div>`;
                    }
                });
                html += '</div>';
                document.getElementById('convDetailThread').innerHTML = html;
            } else {
                // Paneles para email
                document.getElementById('convDetailThread').innerHTML = msgs.map(m => {
                    const isOut = m.direction === 'outbound';
                    return `
                        <div style="background:#ffffff;border-radius:8px;border:1px solid #e2e8f0;">
                            <div style="background:#f8fafc;padding:10px 14px;border-bottom:1px solid #e2e8f0;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <div style="width:28px;height:28px;border-radius:50%;background:${isOut ? 'var(--primary)' : 'var(--bg-stripe)'};color:${isOut ? 'white' : 'var(--text-strong)'};font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    ${(m.from_email||'?').charAt(0).toUpperCase()}
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <span style="font-size:12px;font-weight:600;color:var(--text-strong);">${m.from_email}</span>
                                    <span style="font-size:11px;color:var(--text-muted);margin-left:6px;">→ ${m.to_email}</span>
                                    <span style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:99px;margin-left:6px;background:${isOut ? 'var(--bg-stripe)' : '#dcfce7'};color:${isOut ? 'var(--primary)' : '#16a34a'};">
                                        ${isOut ? '↑ OUT' : '↓ IN'}
                                    </span>
                                </div>
                                <span style="font-size:11px;color:var(--text-muted);flex-shrink:0;">${m.sent_at}</span>
                            </div>
                            <div style="padding:12px 14px;background:#ffffff;color:#1e293b;font-size:13px;line-height:1.6;">
                                ${m.body_html || m.body_text || ''}
                            </div>
                        </div>
                    `;
                }).join('');
            }
        })
        .catch(() => {
            document.getElementById('convDetailThread').innerHTML =
                '<div style="text-align:center;color:var(--danger);padding:20px;">Error loading conversation.</div>';
        });
}

function closeConvDetail() {
    document.getElementById('convDetailModal').classList.remove('active');
}

function toggleMoreFilters() {
    const panel   = document.getElementById('moreFilters');
    const chevron = document.getElementById('moreFiltersChevron');
    const visible = panel.style.display !== 'none';
    panel.style.display     = visible ? 'none' : 'flex';
    chevron.style.transform = visible ? 'rotate(0deg)' : 'rotate(180deg)';
}

// ── Agent search picker ──
const _allAgents = @json($agents->map(fn($a) => ['id' => $a->id, 'name' => $a->name]));

function showAgentDropdown() {
    filterAgentDropdown(document.getElementById('agentSearchInput').value);
    document.getElementById('agentDropdown').style.display = 'block';
}

function hideAgentDropdown() {
    document.getElementById('agentDropdown').style.display = 'none';
}

function filterAgentDropdown(q) {
    const list     = document.getElementById('agentDropdownList');
    const filtered = _allAgents.filter(a => a.name.toLowerCase().includes(q.toLowerCase()));

    let html = '<div onclick="selectAgent(\'\', \'\')" style="padding:8px 12px;cursor:pointer;font-size:12px;color:var(--text-muted);border-bottom:1px solid var(--border);" onmouseover="this.style.background=\'var(--bg-th)\'" onmouseout="this.style.background=\'\'">All Agents</div>';

    if (filtered.length === 0) {
        html += '<div style="padding:10px 12px;font-size:12px;color:var(--text-muted);">No agents found</div>';
    } else {
        html += filtered.map(a => `
            <div onclick="selectAgent(${a.id}, '${a.name.replace(/'/g, "\\'")}')"
                 style="padding:8px 12px;cursor:pointer;font-size:13px;color:var(--text-strong);border-bottom:0.5px solid var(--border);"
                 onmouseover="this.style.background='var(--bg-th)'"
                 onmouseout="this.style.background=''">
                <div style="font-weight:500;">${a.name}</div>
            </div>
        `).join('');
    }

    list.innerHTML = html;
    document.getElementById('agentDropdown').style.display = 'block';
}

function selectAgent(id, name) {
    document.getElementById('agentIdInput').value     = id;
    document.getElementById('agentSearchInput').value = name;
    document.getElementById('agentDropdown').style.display = 'none';
}

document.addEventListener('click', function(e) {
    if (e.target === document.getElementById('convDetailModal')) closeConvDetail();
    if (!e.target.closest('#agentPickerWrap')) hideAgentDropdown();
});
</script>
@endsection
