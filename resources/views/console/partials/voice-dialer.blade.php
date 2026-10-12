<div
    id="voiceQaDialer"
    class="modal-overlay"
    aria-hidden="true"
>
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <div class="modal-title">
                <i class="bi bi-telephone-fill" aria-hidden="true"></i>
                Make a call
            </div>
            <button
                id="voiceQaDialerClose"
                type="button"
                class="modal-close"
                aria-label="Close voice dialer"
            >&times;</button>
        </div>

        <div class="modal-body" style="display:grid;gap:12px;">
            <div>
                <label for="voiceQaCampaign" class="form-label">
                    Campaign <span style="color:#dc2626;">*</span>
                </label>
                <select id="voiceQaCampaign" class="form-control">
                    <option value="">Select campaign…</option>
                    @foreach ($voiceCampaigns ?? [] as $vc)
                        <option value="{{ $vc->id }}">{{ $vc->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="voiceQaQueue" class="form-label">
                    Queue <span style="color:#dc2626;">*</span>
                </label>
                <select id="voiceQaQueue" class="form-control">
                    <option value="">Select queue…</option>
                    @foreach ($voiceQueues ?? [] as $vq)
                        <option
                            value="{{ $vq['id'] }}"
                            data-campaign="{{ $vq['campaign_id'] }}"
                            data-default-script="{{ $vq['script_id'] }}"
                            style="display:none;"
                        >{{ $vq['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="voiceQaScript" class="form-label">
                    Script <span style="color:#dc2626;">*</span>
                </label>
                <select id="voiceQaScript" class="form-control" disabled>
                    <option value="">Select script…</option>
                    @foreach ($voiceScripts ?? [] as $vs)
                        <option
                            value="{{ $vs->id }}"
                            data-campaign="{{ $vs->campaign_id }}"
                            data-default="{{ $vs->is_default ? '1' : '0' }}"
                            style="display:none;"
                        >{{ $vs->name }}{{ $vs->is_default ? ' ★' : '' }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="voiceQaPhone" class="form-label">
                    Phone Number <span style="color:#dc2626;">*</span>
                </label>
                <div class="quick-call-phone-row">
                    <input
                        id="voiceQaPhone"
                        type="text"
                        class="form-control"
                        value=""
                        inputmode="tel"
                        autocomplete="off"
                        maxlength="24"
                        placeholder="Enter or dial a number"
                    >
                    <button
                        id="voiceQuickDialpadToggle"
                        type="button"
                        class="voice-qa-control"
                        aria-expanded="false"
                        aria-controls="voiceQuickDialpad"
                        title="Toggle dialpad"
                    >
                        <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>
                    </button>
                </div>
                <div id="voiceQaPhoneHelp" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                    Use the customer's number without the routing prefix.
                </div>
                <div id="voiceQuickDialpad" class="quick-call-dialpad" hidden>
                    @foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $digit)
                        <button type="button" class="quick-call-dialpad-key" data-digit="{{ $digit }}">{{ $digit }}</button>
                    @endforeach
                    <button type="button" class="quick-call-dialpad-clear" data-action="backspace">
                        Clear last
                    </button>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button
                id="voiceQaDialerCancel"
                type="button"
                class="btn btn-secondary"
            >Cancel</button>
            <button
                id="voiceQaDialerCall"
                type="button"
                class="btn btn-primary"
            >
                <i class="bi bi-telephone-outbound-fill" aria-hidden="true"></i>
                Call
            </button>
        </div>
    </div>
</div>

<audio id="agentVoiceRemoteAudio" autoplay></audio>
<audio
    id="agentVoiceRingbackAudio"
    src="{{ asset('audio/voice/ringtone.mp3') }}"
    preload="auto"
></audio>
<audio
    id="agentVoiceRingtoneAudio"
    src="{{ asset('audio/voice/ringtone.mp3') }}"
    preload="auto"
></audio>

@push('styles')
<style>
#voiceQaDialer .form-label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 600;
}

#voiceQaDialer .form-control {
    box-sizing: border-box;
    width: 100%;
    padding: 9px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg-input);
    color: var(--text-strong);
    font: inherit;
}

#voiceQaDialer .form-control[readonly] {
    color: var(--text-muted);
    background: var(--bg-th);
}

.quick-call-phone-row {
    display: flex;
    align-items: stretch;
    gap: 8px;
}

.quick-call-phone-row .form-control {
    flex: 1;
    min-width: 0;
}

.quick-call-dialpad {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin-top: 10px;
}

.quick-call-dialpad[hidden] {
    display: none;
}

.quick-call-dialpad-key,
.quick-call-dialpad-clear {
    min-height: 36px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg-card);
    color: var(--text-strong);
    cursor: pointer;
    font: inherit;
    font-weight: 600;
}

.quick-call-dialpad-key:hover,
.quick-call-dialpad-clear:hover {
    border-color: var(--primary);
    background: var(--primary-light);
}

.quick-call-dialpad-clear {
    grid-column: 1 / -1;
    font-size: 12px;
}

.voice-qa-control {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-height: 30px;
    padding: 5px 9px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg-card);
    color: var(--text-strong);
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
}

.voice-qa-control:hover:not(:disabled) {
    border-color: var(--primary);
    background: var(--primary-light);
}

.voice-qa-control:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.voice-qa-control:disabled {
    opacity: .42;
    cursor: not-allowed;
    box-shadow: none;
}

.voice-qa-control.is-danger:not(:disabled) {
    border-color: #dc2626;
    background: #dc2626;
    color: #fff;
}

.voice-qa-control.is-answer:not(:disabled) {
    border-color: #16a34a;
    background: #16a34a;
    color: #fff;
}

.voice-qa-control.is-active:not(:disabled) {
    border-color: #d97706;
    background: #fef3c7;
    color: #92400e;
}

.voice-conversation-header {
    min-height: 38px;
    padding: 0px 10px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-card);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-shrink: 0;
}

.voice-header-identity,
.voice-header-actions,
.voice-header-meta {
    display: flex;
    align-items: center;
}

.voice-header-identity {
    gap: 9px;
    min-width: 0;
}

.voice-channel-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #eaf3de;
    color: #3b6d11;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.voice-header-copy {
    min-width: 0;
}

.voice-header-title {
    color: var(--text-strong);
    font-size: 13px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.voice-header-meta {
    gap: 7px;
    margin-top: 1px;
    color: var(--text-muted);
    font-size: 10px;
}

.voice-channel-badge {
    background: #eaf3de;
    color: #3b6d11;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    flex-shrink: 0;
}

.voice-channel-badge i {
    font-size: 9px;
}

.voice-header-actions {
    justify-content: flex-end;
    gap: 6px;
    flex-wrap: wrap;
}

.voice-call-duration {
    margin-right: 4px;
    color: var(--text-strong);
    font: 600 13px monospace;
}

.voice-conversation-content {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 20px;
    background: var(--bg-page);
    gap: 16px;
}

.voice-summary-card,
.voice-business-card,
.voice-technical-details {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-card);
    box-shadow: var(--shadow-sm);
}

.voice-summary-card {
    padding: 16px;
    display: grid;
    gap: 14px;
}

.voice-summary-main {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.voice-summary-icon {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #eaf3de;
    color: #3b6d11;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 18px;
}

.voice-summary-copy {
    min-width: 0;
}

.voice-summary-number-row {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.voice-summary-number {
    color: var(--text-strong);
    font-size: 20px;
    font-weight: 700;
    line-height: 1.15;
}

.voice-summary-subtitle {
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 600;
}

.voice-direction-badge,
.voice-status-badge {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}

.voice-direction-badge {
    padding: 4px 8px;
    background: var(--bg-th);
    color: var(--text-muted);
    border: 1px solid var(--border);
}

.voice-status-badge {
    padding: 5px 9px;
}

.voice-status-badge.is-blue {
    background: #dbeafe;
    color: #1d4ed8;
}

.voice-status-badge.is-green {
    background: #dcfce7;
    color: #15803d;
}

.voice-status-badge.is-orange {
    background: #ffedd5;
    color: #c2410c;
}

.voice-status-badge.is-gray {
    background: #f1f5f9;
    color: #475569;
}

.voice-status-badge.is-red {
    background: #fee2e2;
    color: #b91c1c;
}

.voice-summary-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.voice-conversation-grid {
    display: grid;
    grid-template-columns: minmax(260px, 0.9fr) minmax(320px, 1.1fr);
    gap: 16px;
    align-items: stretch;
    flex: 0 0 auto;
    height: clamp(240px, 30vh, 340px);
    min-height: 240px;
}

.voice-business-card {
    padding: 16px;
    height: 100%;
    min-height: 240px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.voice-business-card h3 {
    margin: 0 0 12px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
}

.voice-info-grid {
    display: grid;
    gap: 9px;
}

.voice-summary-metrics .voice-info-grid {
    gap: 8px;
}

.voice-info-item {
    display: grid;
    gap: 3px;
    min-width: 0;
}

.voice-business-card .voice-info-item {
    grid-template-columns: minmax(92px, 0.45fr) minmax(0, 1fr);
    align-items: baseline;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
}

.voice-business-card .voice-info-item:last-child {
    border-bottom: 0;
}

.voice-info-label {
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.voice-info-value {
    color: var(--text-strong);
    font-size: 13px;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.voice-timeline {
    width: 100%;
    max-width: none;
    margin: 0;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding-right: 4px;
    scrollbar-width: none;
}

.voice-timeline::-webkit-scrollbar {
    width: 0;
    height: 0;
}

.voice-timeline-event {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
}

.voice-timeline-event:first-child {
    padding-top: 0;
}

.voice-timeline-event:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.voice-timeline-marker {
    width: 10px;
    height: 10px;
    margin-top: 4px;
    border-radius: 50%;
    background: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.voice-timeline-status {
    color: var(--text-strong);
    font-size: 13px;
    font-weight: 600;
}

.voice-timeline-time {
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 11px;
}

.voice-technical-details {
    padding: 0;
}

.voice-technical-details summary {
    cursor: pointer;
    padding: 13px 16px;
    color: var(--text-strong);
    font-size: 13px;
    font-weight: 700;
}

.voice-technical-details[open] summary {
    border-bottom: 1px solid var(--border);
}

.voice-technical-grid {
    padding: 8px 16px 10px;
    gap: 8px;
}

@media (max-width: 980px) {
    .voice-conversation-grid,
    .voice-summary-metrics {
        grid-template-columns: 1fr;
    }

    .voice-conversation-grid {
        height: auto;
        min-height: 0;
    }

    .voice-business-card {
        height: auto;
    }
}

</style>
@endpush
