@extends('layouts.app')

@section('title', $lead->full_name)
@section('page-title', $lead->full_name)
@section('page-subtitle', 'Lead Detail')

@section('header-actions')
    <a href="{{ url()->previous() }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back
    </a>
@endsection

@section('content')
@php $canEdit = Auth::user()->isAdmin() || $lead->assigned_to === Auth::id(); @endphp

{{-- LEAD HEADER CARD --}}
<div class="lead-detail-header">
    <div class="lead-avatar">{{ strtoupper(substr($lead->first_name,0,1)) }}</div>
    <div style="flex:1">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div class="lead-name">{{ $lead->full_name }}</div>
            <span class="badge" style="{{ $lead->status_badge_style }}font-size:12px;padding:4px 10px;">
                {{ $lead->status_label }}
            </span>
        </div>
        <div class="lead-meta">
            @if($lead->phone)
                <div class="lead-meta-item">
                    <i class="bi bi-telephone"></i>
                    <a href="tel:{{ $lead->phone }}" style="color:inherit;text-decoration:none;">{{ $lead->phone }}</a>
                    @if($canEdit && $waAccounts->count())
                        <a href="javascript:void(0)" onclick="openLeadWaModal('{{ $lead->phone }}')" title="Send WhatsApp" style="color:#25D366;margin-left:5px;display:inline-flex;align-items:center;text-decoration:none;">
                            <i class="bi bi-whatsapp"></i>
                        </a>
                    @endif
                </div>
            @endif
            @if($lead->phone_2)
                <div class="lead-meta-item">
                    <i class="bi bi-telephone-plus"></i>
                    <a href="tel:{{ $lead->phone_2 }}" style="color:inherit;text-decoration:none;">{{ $lead->phone_2 }}</a>
                    @if($canEdit && $waAccounts->count())
                        <a href="javascript:void(0)" onclick="openLeadWaModal('{{ $lead->phone_2 }}')" title="Send WhatsApp" style="color:#25D366;margin-left:5px;display:inline-flex;align-items:center;text-decoration:none;">
                            <i class="bi bi-whatsapp"></i>
                        </a>
                    @endif
                </div>
            @endif
            @if($lead->email)
                <div class="lead-meta-item">
                    <i class="bi bi-envelope"></i> {{ $lead->email }}
                </div>
            @endif
            @if($lead->city)
                <div class="lead-meta-item">
                    <i class="bi bi-geo-alt"></i>
                    {{ $lead->city }}{{ $lead->state ? ', '.$lead->state : '' }} {{ $lead->zip }}
                </div>
            @endif
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:4px;">
        @if($canEdit)
        <a href="{{ route('leads.edit', $lead) }}"
           title="Edit lead"
           style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--bg-card);color:var(--text-muted);text-decoration:none;"
           onmouseover="this.style.background='var(--bg-th)'"
           onmouseout="this.style.background='var(--bg-card)'">
            <i class="bi bi-pencil" style="font-size:14px;"></i>
        </a>
        @endif
        @if($canEdit)
        <button onclick="confirmDelete()"
                title="Delete lead"
                style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:var(--radius-sm);border:1px solid #F09595;background:var(--bg-card);color:#A32D2D;cursor:pointer;"
                onmouseover="this.style.background='#FCEBEB'"
                onmouseout="this.style.background='var(--bg-card)'">
            <i class="bi bi-trash" style="font-size:14px;"></i>
        </button>
        <form id="deleteLeadForm" method="POST" action="{{ route('leads.destroy', $lead) }}" style="display:none">
            @csrf @method('DELETE')
        </form>
        @endif
        @if(!$canEdit)
        <span class="badge badge-secondary" style="font-size:11px;padding:6px 12px">
            <i class="bi bi-eye"></i> View Only
        </span>
        @endif
    </div>
</div>

{{-- CONTENT GRID --}}
<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;" id="mainGrid">

    {{-- LEFT COLUMN --}}
    <div>
        {{-- LEAD INFO --}}
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <span class="card-title"><i class="bi bi-info-circle" style="color:var(--primary);margin-right:6px;"></i> Lead Information</span>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="label">First Name</div>
                        <div class="value">{{ $lead->first_name }}</div>
                    </div>
                    <div class="info-item">
                        <div class="label">Last Name</div>
                        <div class="value">{{ $lead->last_name }}</div>
                    </div>
                    <div class="info-item">
                        <div class="label">Email</div>
                        <div class="value {{ !$lead->email ? 'empty' : '' }}">
                            {{ $lead->email ?: 'Not provided' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Phone</div>
                        <div class="value {{ !$lead->phone ? 'empty' : '' }}">
                            {{ $lead->phone ?: 'Not provided' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Secondary Phone</div>
                        <div class="value {{ !$lead->phone_2 ? 'empty' : '' }}">
                            {{ $lead->phone_2 ?: 'Not provided' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Source</div>
                        <div class="value {{ !$lead->source ? 'empty' : '' }}">
                            {{ $lead->source ?: 'Unknown' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Address</div>
                        <div class="value {{ !$lead->address ? 'empty' : '' }}">
                            {{ $lead->address ?: 'Not provided' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">City / State / ZIP</div>
                        <div class="value {{ !$lead->city ? 'empty' : '' }}">
                            {{ collect([$lead->city, $lead->state, $lead->zip])->filter()->join(', ') ?: 'Not provided' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Value</div>
                        <div class="value {{ !$lead->value ? 'empty' : '' }}">
                            {{ $lead->value ? '$'.number_format($lead->value,2) : 'Not set' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Assigned Agent</div>
                        <div class="value {{ !$lead->agent ? 'empty' : '' }}">
                            {{ $lead->agent?->name ?? 'Unassigned' }}
                        </div>
                    </div>
                </div>

                @if($lead->notes)
                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                        <div class="label" style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Notes</div>
                        <p style="font-size:13px;color:#475569;line-height:1.6;white-space:pre-wrap;word-break:break-word;margin:0;">{{ $lead->notes }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- UNIFIED ACTIVITY TIMELINE --}}
        @php
        $channelConfig = [
            'email'    => ['icon' => 'bi-envelope-fill',  'border' => '#378ADD', 'iconColor' => '#185FA5'],
            'whatsapp' => ['icon' => 'bi-whatsapp',       'border' => '#1D9E75', 'iconColor' => '#0F6E56'],
            'chat'     => ['icon' => 'bi-chat-dots-fill', 'border' => '#7F77DD', 'iconColor' => '#3C3489'],
            // SMS: mismo icono que la consola (bi-chat-text) y su paleta ámbar —
            // antes no existía la clave 'sms' y caía al fallback 'phone' (icono de llamada).
            'sms'      => ['icon' => 'bi-chat-text',      'border' => '#D9A441', 'iconColor' => '#854F0B'],
            'phone'    => ['icon' => 'bi-telephone-fill', 'border' => '#BA7517', 'iconColor' => '#854F0B'],
        ];
        $grouped = $history->groupBy(fn($e) => $e->date->format('Y-m-d'));
        @endphp

        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span class="card-title">
                    <i class="bi bi-clock-history icon-primary-mr"></i>Activity
                </span>
                <span style="font-size:12px;font-weight:400;color:var(--text-muted);">
                    {{ $history->count() }} item{{ $history->count() !== 1 ? 's' : '' }}
                </span>
                <div class="history-filters" style="margin-left:auto;">
                    <button data-filter="all" class="active">All</button>
                    <button data-filter="email">Email</button>
                    <button data-filter="whatsapp">WhatsApp</button>
                    <button data-filter="chat">Webchat</button>
                    <button data-filter="phone">Calls</button>
                </div>
            </div>
            <div class="card-body" style="padding:12px 20px;">
                @forelse($grouped as $date => $entries)
                    <div class="timeline-day" data-day="{{ $date }}">
                        <p class="timeline-day-label">
                            @php $d = \Carbon\Carbon::parse($date); @endphp
                            @if($d->isToday()) Today · {{ $d->format('M j') }}
                            @elseif($d->isYesterday()) Yesterday · {{ $d->format('M j') }}
                            @else {{ $d->format('M j, Y') }}
                            @endif
                        </p>
                        <div class="timeline">
                            @foreach($entries as $entry)
                                @php $ch = $channelConfig[$entry->channel] ?? $channelConfig['phone']; @endphp

                                @if($entry->type === 'call')
                                {{-- ── Llamada ── --}}
                                <div class="timeline-item" data-channel="phone">
                                    <div class="timeline-dot" style="border-color:{{ $ch['border'] }};">
                                        <i class="bi {{ $ch['icon'] }}" style="color:{{ $ch['iconColor'] }};"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-header">
                                            <span class="timeline-title">Phone call</span>
                                            <span class="badge-direction badge-direction-{{ $entry->direction }}">
                                                <i class="bi bi-arrow-{{ $entry->direction === 'inbound' ? 'down-left' : 'up-right' }}"></i>
                                                {{ ucfirst($entry->direction) }}
                                            </span>
                                            @if($entry->disposition)
                                                <span class="badge" style="{{ $entry->disp_style }}">{{ $entry->disposition }}</span>
                                            @endif
                                            <span class="timeline-time">
                                                {{ $entry->date->format('H:i') }}
                                                @if($entry->duration_fmt)
                                                    · {{ $entry->duration_fmt }}
                                                @endif
                                            </span>
                                        </div>

                                        @if($entry->notes)
                                            <p class="timeline-call-note">"{{ $entry->notes }}"</p>
                                        @endif

                                        <div class="timeline-meta">
                                            <i class="bi bi-person"></i> {{ $entry->agent_name }}
                                            @if($entry->dialer_campaign)
                                                <span class="dot-sep">·</span>
                                                <i class="bi bi-megaphone"></i> {{ $entry->dialer_campaign }}
                                            @endif
                                            @if($entry->uniqueid)
                                                <span class="dot-sep">·</span>
                                                <i class="bi bi-hash"></i> {{ $entry->uniqueid }}
                                            @endif
                                        </div>

                                        @if($entry->recording_id)
                                        <div class="recording-player" style="margin-top:8px;">
                                            <button class="btn btn-xs btn-outline load-recording-btn"
                                                    data-call-id="{{ $entry->id }}"
                                                    style="font-size:11px;gap:4px;">
                                                <i class="bi bi-play-circle"></i> Play Recording
                                            </button>
                                            <div class="audio-container" id="audio-{{ $entry->id }}" style="display:none;margin-top:6px;">
                                                <audio controls style="width:100%;height:32px;" preload="none">
                                                    <source id="audio-src-{{ $entry->id }}" src="" type="audio/mpeg">
                                                </audio>
                                            </div>
                                        </div>
                                        @endif
                                    </div>
                                </div>

                                @else
                                {{-- ── Conversación ── --}}
                                <div class="timeline-item" data-channel="{{ $entry->channel }}">
                                    <div class="timeline-dot" style="border-color:{{ $ch['border'] }};">
                                        <i class="bi {{ $ch['icon'] }}" style="color:{{ $ch['iconColor'] }};"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-header">
                                            <span class="timeline-title">
                                                @if($entry->channel === 'email')
                                                    {{ $entry->subject ?? 'Email conversation' }}
                                                @else
                                                    {{ ucfirst($entry->channel) }} conversation
                                                @endif
                                            </span>
                                            <span class="badge-direction badge-direction-{{ $entry->direction }}">
                                                <i class="bi bi-arrow-{{ $entry->direction === 'inbound' ? 'down-left' : 'up-right' }}"></i>
                                                {{ ucfirst($entry->direction) }}
                                            </span>
                                            @if($entry->disposition)
                                                <span class="badge-disposition">{{ $entry->disposition }}</span>
                                            @endif
                                            <span class="timeline-time">
                                                {{ $entry->date->format('H:i') }}
                                                · {{ $entry->message_count }} msg
                                            </span>
                                        </div>

                                        @if($entry->first_message_preview)
                                            <p class="timeline-preview">
                                                "{{ \Illuminate\Support\Str::limit($entry->first_message_preview, 120) }}"
                                            </p>
                                        @endif

                                        @if($entry->notes)
                                            <div class="timeline-note">
                                                <i class="bi bi-sticky"></i>
                                                <strong>Note:</strong> {{ $entry->notes }}
                                            </div>
                                        @endif

                                        <div class="timeline-meta">
                                            <i class="bi bi-person"></i> {{ $entry->agent_name ?? '—' }}
                                            @if($entry->duration_seconds)
                                                <span class="dot-sep">·</span>
                                                <i class="bi bi-clock"></i>
                                                {{ gmdate($entry->duration_seconds < 3600 ? 'i\m s\s' : 'H\h i\m', $entry->duration_seconds) }}
                                            @endif
                                            <a href="#" class="view-thread-link"
                                               data-report-id="{{ $entry->id }}">View thread →</a>
                                        </div>
                                    </div>
                                </div>
                                @endif

                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="empty-state" style="padding:40px 20px;">
                        <i class="bi bi-clock-history" style="font-size:36px;"></i>
                        <h3>No activity yet</h3>
                        <p>Calls and conversations will appear here.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- RIGHT SIDEBAR --}}
    <div>
        {{-- QUICK ACTIONS --}}
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <span class="card-title">Quick Actions</span>
            </div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
                {{-- Log Call --}}
                @if($canEdit)
                    <button class="btn btn-primary" style="width:100%;justify-content:center;" onclick="openModal('dialerInfoModal')">
                        <i class="bi bi-telephone-plus"></i> Log a Call
                    </button>
                @else
                    <button class="btn btn-secondary" style="width:100%;justify-content:center;opacity:.45;cursor:not-allowed;" disabled>
                        <i class="bi bi-telephone-plus"></i> Log a Call
                    </button>
                @endif

                @if($lead->phone)
                    @if($canEdit)
                        <button class="btn btn-success" id="callNowBtn" style="width:100%;justify-content:center;"
                                onclick="dialerCall({{ $lead->id }})">
                            <i class="bi bi-telephone-fill"></i> Call Now
                        </button>
                    @else
                        <button class="btn btn-success" style="width:100%;justify-content:center;opacity:.45;cursor:not-allowed;" disabled>
                            <i class="bi bi-telephone-fill"></i> Call Now
                        </button>
                    @endif
                @endif
                @if(($lead->phone || $lead->phone_2) && $waAccounts->count())
                    @if($canEdit)
                        <button class="btn btn-outline" style="width:100%;justify-content:center;color:#128C7E;border-color:#25D366;background:rgba(37,211,102,0.06);"
                                onclick="openLeadWaModal()">
                            <i class="bi bi-whatsapp" style="color:#25D366;"></i> Send WhatsApp
                        </button>
                    @else
                        <button class="btn btn-outline" style="width:100%;justify-content:center;opacity:.5;cursor:not-allowed;" disabled>
                            <i class="bi bi-whatsapp"></i> Send WhatsApp
                        </button>
                    @endif
                @endif
                @if($lead->email)
                    @if($canEdit)
                        <button onclick="openCompose({ leadId: {{ $lead->id }}, leadName: '{{ addslashes($lead->full_name) }}', to: '{{ $lead->email }}' })"
                            class="btn btn-outline" style="width:100%;justify-content:center;">
                            <i class="bi bi-envelope"></i> Send Email
                        </button>
                    @else
                        <button class="btn btn-outline" style="width:100%;justify-content:center;opacity:.5;cursor:not-allowed;" disabled>
                            <i class="bi bi-envelope"></i> Send Email
                        </button>
                    @endif
                @endif
                @if(!$canEdit)
                    <div style="font-size:11px;color:var(--text-muted);text-align:center;padding:4px 0;">
                        <i class="bi bi-lock"></i> Assigned to {{ $lead->agent?->name ?? 'another agent' }}
                    </div>
                @endif
            </div>
        </div>

        {{-- SCHEDULED CALLS --}}
        @php $scheduledCalls = $lead->scheduledCalls; @endphp
        @if($scheduledCalls->count())
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <span class="card-title">
                    <i class="bi bi-calendar-check" style="color:var(--warning);margin-right:6px;"></i>
                    Scheduled Calls
                </span>
            </div>
            <div class="card-body" style="padding:12px;">
                @foreach($scheduledCalls->sortBy('scheduled_at') as $sc)
                    <div style="display:flex;align-items:center;gap:10px;padding:10px;background:#fffbeb;border-radius:8px;border:1px solid #fde68a;margin-bottom:8px;">
                        <i class="bi bi-clock" style="color:var(--warning);font-size:16px;"></i>
                        <div style="flex:1">
                            <div style="font-weight:600;font-size:13px;color:#92400e;">
                                {{ $sc->scheduled_at?->format('M d, Y') }}
                            </div>
                            <div style="font-size:12px;color:#b45309;">
                                {{ $sc->scheduled_at?->format('H:i') }} · {{ $sc->disposition_label }}
                            </div>
                        </div>
                       @if($canEdit)
                        <button class="btn btn-xs btn-success complete-call-btn" data-call-id="{{ $sc->id }}" title="Mark Complete">
                            <i class="bi bi-check"></i>
                        </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- CHANGE STATUS --}}
        @if($canEdit)
        <div class="card">
            <div class="card-header">
                <span class="card-title">Update Status</span>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Lead Status</label>
                    <select class="form-control" id="statusSelect">
                        @foreach($statuses as $s)
                            <option value="{{ $s->slug }}" {{ $lead->status == $s->slug ? 'selected' : '' }}>
                                {{ $s->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @if(Auth::user()->isAdmin())
                    <div class="form-group">
                        <label class="form-label">Assign To</label>
                        <select class="form-control" id="assignedToSelect">
                            <option value="">Unassigned</option>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" {{ $lead->assigned_to == $agent->id ? 'selected' : '' }}>
                                    {{ $agent->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button class="btn btn-primary btn-sm" id="updateLeadBtn" style="width:100%;justify-content:center;">
                    <i class="bi bi-save"></i> Save Changes
                </button>
            </div>
        </div>
        @endif
    </div>

</div>

{{-- Thread Modal --}}
<div id="threadModalContainer"></div>

{{-- COMPOSE MODAL --}}
@include('partials.compose-email-modal')

{{-- ── DIALER INFO MODAL ── --}}
<div class="modal-overlay" id="dialerInfoModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <div class="modal-title">
                <i class="bi bi-telephone-plus" style="color:var(--primary);margin-right:6px;"></i> Log a Call
            </div>
            <button class="modal-close" onclick="closeModal('dialerInfoModal')">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;padding:32px 24px;">
            <div style="width:64px;height:64px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <i class="bi bi-headset" style="font-size:28px;color:var(--primary);"></i>
            </div>
            <h3 style="font-size:16px;font-weight:700;color:var(--text-strong);margin-bottom:10px;">
                Calls are logged automatically
            </h3>
            <p style="font-size:13px;color:var(--text-muted);line-height:1.7;margin-bottom:20px;">
                Call records are created automatically when you disposition a call in the dialer system.
                <br><br>
                If you don't have a dialer connected, you can log calls manually instead.
            </p>
            <div style="display:flex;gap:10px;">
                <button class="btn btn-secondary" style="flex:1;justify-content:center;" onclick="closeModal('dialerInfoModal')">
                    Cancel
                </button>
                <button class="btn btn-primary" style="flex:1;justify-content:center;" onclick="closeModal('dialerInfoModal'); openLogCallModal();">
                    <i class="bi bi-pencil-square"></i> Log Manually
                </button>
            </div>
        </div>
    </div>
</div>

{{-- ── LOG CALL MODAL ── --}}
<div class="modal-overlay" id="logCallModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-telephone-plus" style="color:var(--primary);margin-right:6px;"></i> Log Call</div>
            <button class="modal-close" onclick="closeModal('logCallModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Disposition *</label>
                    <select class="form-control" id="disposition" onchange="onDispositionChange()">
                        <option value="no_answer">No Answer</option>
                        <option value="voicemail">Voicemail</option>
                        <option value="callback">Callback</option>
                        <option value="customer">Customer ✓</option>
                        <option value="busy">Busy</option>
                        <option value="wrong_number">Wrong Number</option>
                        <option value="not_interested">Not Interested</option>
                        <option value="disconnected">Disconnected</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Call Date & Time *</label>
                    <input type="datetime-local" class="form-control" id="calledAt"
                           value="{{ now()->format('Y-m-d\TH:i') }}">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Duration (seconds)</label>
                <input type="number" class="form-control" id="duration" placeholder="e.g. 120" min="0">
            </div>
            <div class="form-group" id="scheduleGroup" style="display:none;">
                <label class="form-label">
                    Schedule Callback For
                    <span style="color:var(--warning);margin-left:4px;"><i class="bi bi-calendar2"></i></span>
                </label>
                <input type="datetime-local" class="form-control" id="scheduledAt">
                <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
                    This will appear in your Scheduled Calls section.
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea class="form-control" id="callNotes" rows="3" placeholder="Call notes…"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('logCallModal')">Cancel</button>
            <button class="btn btn-primary" id="saveCallBtn">
                <i class="bi bi-save"></i> Save Call
            </button>
        </div>
    </div>
</div>

{{-- ── WHATSAPP TEMPLATE MODAL ── --}}
<div class="modal-overlay" id="leadWaModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header" style="background:#075e54;color:#fff;border-top-left-radius:var(--radius-md);border-top-right-radius:var(--radius-md);">
            <div class="modal-title" style="color:#fff;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-whatsapp" style="color:#25D366;font-size:18px;"></i> Send WhatsApp
            </div>
            <button class="modal-close" onclick="closeModal('leadWaModal')" style="color:#fff;opacity:.8;">&times;</button>
        </div>
        <div class="modal-body" style="padding:20px;">
            @if($waAccounts->isEmpty())
                <div style="text-align:center;padding:24px 12px;">
                    <i class="bi bi-exclamation-triangle" style="font-size:32px;color:var(--warning);"></i>
                    <p style="margin-top:12px;font-size:14px;color:var(--text-strong);font-weight:600;">No WhatsApp accounts connected</p>
                    <p style="font-size:12px;color:var(--text-muted);">Please configure a WhatsApp Business Cloud account in your tenant settings to start sending messages.</p>
                </div>
            @else
                {{-- Account selector --}}
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" style="font-size:12px;font-weight:600;">Sender Account</label>
                    <select class="form-control" id="leadWaAccount">
                        @foreach($waAccounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->display_name }} ({{ $acc->phone_number }})</option>
                        @endforeach
                    </select>
                </div>

                {{-- Phone Selection (if lead has secondary phone) --}}
                @if($lead->phone && $lead->phone_2)
                    <div class="form-group" style="margin-bottom:10px;">
                        <label class="form-label" style="font-size:12px;font-weight:600;">Select Number</label>
                        <div style="display:flex;gap:8px;">
                            <button type="button" class="btn btn-xs btn-outline active" id="btnWaPickPhone1"
                                    onclick="pickLeadPhone('{{ $lead->phone }}', 'btnWaPickPhone1')">
                                <i class="bi bi-telephone"></i> Primary: {{ $lead->phone }}
                            </button>
                            <button type="button" class="btn btn-xs btn-outline" id="btnWaPickPhone2"
                                    onclick="pickLeadPhone('{{ $lead->phone_2 }}', 'btnWaPickPhone2')">
                                <i class="bi bi-telephone-plus"></i> Sec: {{ $lead->phone_2 }}
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Destination phone input --}}
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" style="font-size:12px;font-weight:600;">Destination Number</label>
                    <div id="leadWaPhoneWrap" style="display:flex;border:1px solid var(--border);border-radius:6px;overflow:hidden;background:var(--bg-card);">
                        <div style="position:relative;flex-shrink:0;border-right:1px solid var(--border);">
                            <select id="leadWaCountry" style="width:95px;border:none;background:var(--bg-secondary);color:var(--text-strong);font-size:13px;padding:10px 24px 10px 8px;cursor:pointer;outline:none;appearance:none;-webkit-appearance:none;">
                                <option value="521">🇲🇽 +52</option>
                                <option value="1">🇺🇸 +1</option>
                                <option value="34">🇪🇸 +34</option>
                                <option value="57">🇨🇴 +57</option>
                            </select>
                            <i class="bi bi-chevron-down" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--text-muted);pointer-events:none;"></i>
                        </div>
                        <input id="leadWaPhone" type="tel" placeholder="10 digits..." maxlength="10"
                               style="flex:1;border:none;padding:10px 12px;font-size:13px;background:var(--bg-card);color:var(--text-strong);outline:none;" />
                    </div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">10-digit phone without country code</div>
                </div>

                {{-- Template selector --}}
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" style="font-size:12px;font-weight:600;">Approved WhatsApp Template</label>
                    <select class="form-control" id="leadWaTemplate" onchange="updateLeadWaPreview()">
                        @forelse($waTemplates as $tmpl)
                            <option value="{{ $tmpl->name }}" data-preview="{{ $tmpl->body_preview ?? $tmpl->display_name }}">{{ $tmpl->display_name }} ({{ strtoupper($tmpl->language) }})</option>
                        @empty
                            <option value="" disabled>No templates configured</option>
                        @endforelse
                    </select>
                </div>

                {{-- Template Preview --}}
                <div class="form-group" style="margin-bottom:6px;">
                    <label class="form-label" style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;">Message Preview</label>
                    <div id="leadWaPreviewBox" style="background:#eef6ee;border:1px solid #d1e7dd;border-radius:10px;padding:12px 14px;font-size:13px;color:#134e4a;line-height:1.5;white-space:pre-wrap;min-height:50px;position:relative;">
                        <span id="leadWaPreviewText">Select a template</span>
                        <div style="text-align:right;font-size:10px;color:#0f766e;margin-top:6px;">Now ✓✓</div>
                    </div>
                </div>
            @endif
        </div>
        @if($waAccounts->isNotEmpty())
            <div class="modal-footer" style="background:var(--bg-secondary);display:flex;justify-content:space-between;align-items:center;">
                <button class="btn btn-secondary" onclick="closeModal('leadWaModal')">Cancel</button>
                <button class="btn btn-success" id="leadWaSubmitBtn" onclick="submitLeadWaTemplate()" style="background:#25D366;border-color:#25D366;color:#fff;">
                    <i class="bi bi-whatsapp"></i> Send WhatsApp
                </button>
            </div>
        @endif
    </div>
</div>

@endsection

@section('scripts')
<script>
const LEAD_ID = {{ $lead->id }};
const CALLS_URL   = "{{ route('calls.store') }}";
const UPDATE_URL  = "{{ route('leads.update', $lead) }}";

// --- Modal open/close ---
function openLogCallModal() {
    $('#logCallModal').addClass('active');
}
function openModal(id)  { document.getElementById(id).classList.add('active'); }

function confirmDelete() {
    if (confirm('Delete this lead and all its call history? This cannot be undone.')) {
        document.getElementById('deleteLeadForm').submit();
    }
}
function closeModal(id) {
    if (id === 'composeModal' && typeof closeComposeModal === 'function') { closeComposeModal(); return; }
    $('#' + id).removeClass('active');
}
$(document).on('click', '.modal-overlay', function(e) {
    if ($(e.target).is('.modal-overlay') && $(this).attr('id') !== 'composeModal') {
        closeModal($(this).attr('id'));
    }
});

// --- Show scheduled_at if callback/customer ---
function onDispositionChange() {
    const d = $('#disposition').val();
    if (d === 'callback' || d === 'customer') {
        $('#scheduleGroup').slideDown(200);
    } else {
        $('#scheduleGroup').slideUp(200);
    }
}

// --- Save Call ---
$('#saveCallBtn').on('click', function() {
    const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass"></i> Saving…');
    $.ajax({
        url: CALLS_URL,
        method: 'POST',
        data: {
            lead_id:      LEAD_ID,
            disposition:  $('#disposition').val(),
            called_at:    $('#calledAt').val().replace('T', ' ') + ':00',
            duration:     $('#duration').val() || null,
            notes:        $('#callNotes').val(),
            scheduled_at: $('#scheduledAt').val() ? ($('#scheduledAt').val().replace('T',' ') + ':00') : null,
        },
        success: function(res) {
            showToast(res.message, 'success');
            closeModal('logCallModal');
            setTimeout(() => location.reload(), 1200);
        },
        error: function(xhr) {
            const msg = xhr.responseJSON?.message || 'Error saving call';
            showToast(msg, 'error');
            $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Save Call');
        }
    });
});

// --- Mark scheduled call complete ---
$(document).on('click', '.complete-call-btn', function() {
    const $btn = $(this);
    const callId = $btn.data('call-id');
    $.ajax({
        url: `/calls/${callId}/complete`,
        method: 'PATCH',
        success: function() {
            showToast('Call marked as completed.', 'success');
            $btn.closest('div[style]').fadeOut(300, function(){ $(this).remove(); });
        },
        error: function() { showToast('Error updating call.', 'error'); }
    });
});

// --- Update lead status/assign ---
$('#updateLeadBtn').on('click', function() {
    const $btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass"></i> Saving…');
    const data = { status: $('#statusSelect').val() };
    if ($('#assignedToSelect').length) {
        data.assigned_to = $('#assignedToSelect').val() || null;
    }
    $.ajax({
        url: UPDATE_URL,
        method: 'PATCH',
        data: data,
        success: function() {
            showToast('Lead updated successfully.', 'success');
            $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Save Changes');
        },
        error: function() {
            showToast('Error updating lead.', 'error');
            $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Save Changes');
        }
    });
});

// Dialer Call Now
function dialerCall(leadId) {
    const $btn = $('#callNowBtn');
    $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Calling...');

    $.ajax({
        url: `/dialer/call/${leadId}`,
        method: 'POST',
        success: function(res) {
            showToast(res.message, 'success');
            $btn.html('<i class="bi bi-telephone-fill"></i> Call Now').prop('disabled', false);
        },
        error: function(xhr) {
            const msg = xhr.responseJSON?.error ?? 'Could not initiate call.';
            showToast(msg, 'error');
            $btn.html('<i class="bi bi-telephone-fill"></i> Call Now').prop('disabled', false);
        }
    });
}

// Load recording player
$(document).on('click', '.load-recording-btn', function() {
    const callId   = $(this).data('call-id');
    const $btn     = $(this);
    const $audio   = $(`#audio-${callId}`);
    const $src     = $(`#audio-src-${callId}`);

    // If already loaded just toggle
    if ($src.attr('src')) {
        $audio.toggle();
        return;
    }

    $btn.html('<i class="bi bi-hourglass-split"></i> Loading...').prop('disabled', true);

    $.ajax({
        url: `/dialer/recording/${callId}`,
        method: 'GET',
        success: function(res) {
            if (res.url) {
                $src.attr('src', res.url);
            } else {
                // Direct audio proxied — use the endpoint as src
                $src.attr('src', `/dialer/recording/${callId}`);
            }
            $audio.show();
            $audio.find('audio')[0].load();
            $btn.html('<i class="bi bi-play-circle"></i> Play Recording').prop('disabled', false);
        },
        error: function() {
            $btn.html('<i class="bi bi-exclamation-circle"></i> Not available').prop('disabled', true);
            showToast('Recording not available.', 'error');
        }
    });
});

// --- WhatsApp Modal Logic ---
function parseLeadPhoneNumber(raw) {
    if (!raw) return { country: '521', phone: '' };
    let digits = String(raw).replace(/\D/g, '');
    if (digits.startsWith('521') && digits.length === 13) {
        return { country: '521', phone: digits.slice(3) };
    }
    if (digits.startsWith('52') && digits.length === 12) {
        return { country: '521', phone: digits.slice(2) };
    }
    if (digits.startsWith('1') && digits.length === 11) {
        return { country: '1', phone: digits.slice(1) };
    }
    if (digits.length === 10) {
        return { country: '521', phone: digits };
    }
    if (digits.length > 10) {
        return { country: '521', phone: digits.slice(-10) };
    }
    return { country: '521', phone: digits };
}

function openLeadWaModal(preferPhone = null) {
    const raw = preferPhone || @json($lead->phone) || @json($lead->phone_2) || '';
    const parsed = parseLeadPhoneNumber(raw);
    const countryEl = document.getElementById('leadWaCountry');
    const phoneEl = document.getElementById('leadWaPhone');
    if (countryEl) countryEl.value = parsed.country;
    if (phoneEl) phoneEl.value = parsed.phone;

    updateLeadWaPreview();
    openModal('leadWaModal');
}

function pickLeadPhone(phoneVal, activeBtnId) {
    const parsed = parseLeadPhoneNumber(phoneVal);
    const countryEl = document.getElementById('leadWaCountry');
    const phoneEl = document.getElementById('leadWaPhone');
    if (countryEl) countryEl.value = parsed.country;
    if (phoneEl) phoneEl.value = parsed.phone;

    $('#btnWaPickPhone1, #btnWaPickPhone2').removeClass('active');
    $('#' + activeBtnId).addClass('active');
}

function updateLeadWaPreview() {
    const select = document.getElementById('leadWaTemplate');
    const previewEl = document.getElementById('leadWaPreviewText');
    if (!select || !previewEl) return;
    const opt = select.options[select.selectedIndex];
    if (opt && opt.getAttribute('data-preview')) {
        previewEl.textContent = opt.getAttribute('data-preview');
    } else {
        previewEl.textContent = opt ? opt.text : 'No preview available.';
    }
}

async function submitLeadWaTemplate() {
    const accountEl = document.getElementById('leadWaAccount');
    const countryEl = document.getElementById('leadWaCountry');
    const phoneEl   = document.getElementById('leadWaPhone');
    const tmplEl    = document.getElementById('leadWaTemplate');
    const btn       = document.getElementById('leadWaSubmitBtn');

    if (!accountEl || !accountEl.value) {
        showToast('Please select a WhatsApp sender account.', 'error');
        return;
    }
    const phone = phoneEl ? phoneEl.value.trim() : '';
    if (!phone || phone.length !== 10) {
        showToast('Please enter a valid 10-digit phone number.', 'error');
        return;
    }
    if (!tmplEl || !tmplEl.value) {
        showToast('Please select a template to send.', 'error');
        return;
    }

    const fullPhone = countryEl.value + phone;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending...';

    try {
        const resp = await fetch('/console/whatsapp/send-template', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                account_id: parseInt(accountEl.value),
                phone: fullPhone,
                template_name: tmplEl.value,
                lead_id: LEAD_ID
            })
        });

        const data = await resp.json();
        if (resp.ok) {
            showToast('WhatsApp message sent successfully!', 'success');
            closeModal('leadWaModal');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.error || data.message || 'Error sending WhatsApp template.', 'error');
        }
    } catch (e) {
        showToast('Failed to send WhatsApp message: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-whatsapp"></i> Send WhatsApp';
    }
}

// Auto open if query string has ?open_wa=1
$(document).ready(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('open_wa') === '1') {
        openLeadWaModal();
        const url = new URL(window.location);
        url.searchParams.delete('open_wa');
        window.history.replaceState({}, document.title, url.toString());
    }
});

</script>
@endsection

@push('scripts')
<script>window.LeadShowConfig = { reportDetailUrl: '{{ rtrim(url('/leads/thread'), '/') }}/' };</script>
<script src="{{ asset('js/lead-history-filter.js') }}"></script>
<script>
window.ComposeConfig = {
    showDisposition:     true,
    requireDisposition:  true,
    dispositions:        @json($dispositions->map(fn($d) => ['id' => $d->id, 'name' => $d->name])),
    signature:           @json(auth()->user()->signature ?? ''),
    onSendSuccess: function() {
        setTimeout(() => window.location.reload(), 1200);
    },
};
</script>
<script src="{{ asset('js/compose-email.js') }}"></script>
@endpush