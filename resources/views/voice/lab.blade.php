@extends('layouts.app')

@section('content')
<style>
    .voice-lab-session-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    #voice-lab-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 96px;
        border: 1px solid transparent;
    }

    #voice-lab-status.bg-secondary {
        background: var(--bg-th);
        color: var(--text-mid);
        border-color: var(--border);
    }

    #voice-lab-status.bg-warning {
        background: #fef3c7;
        color: #78350f;
        border-color: #f59e0b;
    }

    #voice-lab-status.bg-success {
        background: #dcfce7;
        color: #166534;
        border-color: #22c55e;
    }

    #voice-lab-status.bg-danger {
        background: #fee2e2;
        color: #991b1b;
        border-color: #ef4444;
    }

    #voice-lab-status.text-dark {
        color: #1f2937;
    }

    [data-theme="dark"] #voice-lab-status.bg-secondary {
        background: #162035;
        color: #cbd5e1;
    }

    [data-theme="dark"] #voice-lab-status.bg-warning {
        background: #f59e0b;
        color: #111827;
    }

    [data-theme="dark"] #voice-lab-status.bg-success {
        background: #166534;
        color: #dcfce7;
    }

    [data-theme="dark"] #voice-lab-status.bg-danger {
        background: #991b1b;
        color: #fee2e2;
    }

    #voice-call,
    #voice-hangup {
        border: 1px solid transparent;
        transition:
            background-color 0.15s ease,
            border-color 0.15s ease,
            color 0.15s ease,
            box-shadow 0.15s ease,
            transform 0.15s ease;
    }

    #voice-call:disabled,
    #voice-hangup:disabled {
        opacity: 0.45;
        cursor: not-allowed;
        background: #e5e7eb;
        border-color: #d1d5db;
        color: #6b7280;
        box-shadow: none;
        transform: none;
    }

    #voice-call:disabled:hover,
    #voice-hangup:disabled:hover {
        background: #e5e7eb;
        border-color: #d1d5db;
        color: #6b7280;
        box-shadow: none;
        transform: none;
    }

    #voice-call:not(:disabled) {
        opacity: 1;
        cursor: pointer;
        background: var(--primary);
        border-color: var(--primary);
        color: #ffffff;
    }

    #voice-call:not(:disabled):hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
        transform: translateY(-1px);
    }

    #voice-hangup:not(:disabled) {
        opacity: 1;
        cursor: pointer;
        background: var(--danger);
        border-color: var(--danger);
        color: #ffffff;
    }

    #voice-hangup:not(:disabled):hover {
        background: #dc2626;
        border-color: #dc2626;
        box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25);
        transform: translateY(-1px);
    }

    [data-theme="dark"] #voice-call:disabled,
    [data-theme="dark"] #voice-hangup:disabled,
    [data-theme="dark"] #voice-call:disabled:hover,
    [data-theme="dark"] #voice-hangup:disabled:hover {
        background: #25334a;
        border-color: #334155;
        color: #94a3b8;
    }

    .voice-media-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    #voice-mute,
    #voice-hold {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid transparent;
        background: #eef2ff;
        border-color: #a5b4fc;
        color: #3730a3;
        cursor: pointer;
        transition:
            background-color 0.15s ease,
            border-color 0.15s ease,
            color 0.15s ease,
            box-shadow 0.15s ease;
    }

    #voice-mute:not(:disabled):hover,
    #voice-hold:not(:disabled):hover {
        background: #e0e7ff;
        border-color: #6366f1;
        box-shadow: 0 3px 8px rgba(79, 70, 229, 0.2);
    }

    #voice-mute:focus-visible,
    #voice-hold:focus-visible {
        outline: 3px solid rgba(79, 70, 229, 0.35);
        outline-offset: 2px;
    }

    #voice-mute.is-active {
        background: #fef3c7;
        border-color: #f59e0b;
        color: #92400e;
    }

    #voice-hold.is-active {
        background: #ede9fe;
        border-color: #8b5cf6;
        color: #5b21b6;
    }

    #voice-mute:disabled,
    #voice-hold:disabled,
    #voice-mute:disabled:hover,
    #voice-hold:disabled:hover {
        opacity: 0.45;
        cursor: not-allowed;
        background: #e5e7eb;
        border-color: #d1d5db;
        color: #6b7280;
        box-shadow: none;
    }

    [data-theme="dark"] #voice-mute:not(:disabled),
    [data-theme="dark"] #voice-hold:not(:disabled) {
        background: #1e293b;
        border-color: #6366f1;
        color: #c7d2fe;
    }

    [data-theme="dark"] #voice-mute.is-active {
        background: #78350f;
        border-color: #f59e0b;
        color: #fef3c7;
    }

    [data-theme="dark"] #voice-hold.is-active {
        background: #4c1d95;
        border-color: #8b5cf6;
        color: #ede9fe;
    }

    [data-theme="dark"] #voice-mute:disabled,
    [data-theme="dark"] #voice-hold:disabled,
    [data-theme="dark"] #voice-mute:disabled:hover,
    [data-theme="dark"] #voice-hold:disabled:hover {
        background: #25334a;
        border-color: #334155;
        color: #94a3b8;
    }

    #voice-incoming-panel {
        margin-bottom: 12px;
        padding: 10px;
        border: 1px solid #f59e0b;
        border-radius: var(--radius-sm);
        background: #fffbeb;
    }

    #voice-incoming-panel[hidden] {
        display: none;
    }

    .voice-incoming-title {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 4px;
        color: #92400e;
        font-weight: 700;
    }

    #voice-incoming-caller {
        margin-bottom: 8px;
        color: #78350f;
        font-size: 12px;
        overflow-wrap: anywhere;
    }

    .voice-incoming-actions {
        display: flex;
        gap: 8px;
    }

    #voice-answer,
    #voice-reject {
        flex: 1;
        justify-content: center;
        border: 1px solid transparent;
    }

    #voice-answer:not(:disabled) {
        cursor: pointer;
        background: var(--success);
        border-color: var(--success);
        color: #ffffff;
    }

    #voice-reject:not(:disabled) {
        cursor: pointer;
        background: var(--danger);
        border-color: var(--danger);
        color: #ffffff;
    }

    #voice-answer:disabled,
    #voice-reject:disabled {
        opacity: 0.45;
        cursor: not-allowed;
        background: #e5e7eb;
        border-color: #d1d5db;
        color: #6b7280;
        box-shadow: none;
    }

    [data-theme="dark"] #voice-incoming-panel {
        background: #2a1d06;
        border-color: #f59e0b;
    }

    [data-theme="dark"] .voice-incoming-title,
    [data-theme="dark"] #voice-incoming-caller {
        color: #fbbf24;
    }

    .voice-log-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    #voice-copy-log {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 4px 9px;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        background: var(--bg-th);
        color: var(--text-mid);
        font: inherit;
        font-size: 12px;
        line-height: 1.2;
        cursor: pointer;
        transition:
            background-color 0.15s ease,
            border-color 0.15s ease,
            color 0.15s ease;
    }

    #voice-copy-log:hover {
        background: #e5e7eb;
        border-color: #9ca3af;
        color: #111827;
    }

    #voice-copy-log:focus-visible {
        outline: 3px solid rgba(79, 70, 229, 0.35);
        outline-offset: 2px;
    }

    #voice-copy-log.is-success {
        background: #dcfce7;
        border-color: #22c55e;
        color: #166534;
    }

    #voice-copy-log.is-error {
        background: #fee2e2;
        border-color: #ef4444;
        color: #991b1b;
    }

    [data-theme="dark"] #voice-copy-log {
        background: #1e293b;
        border-color: #475569;
        color: #cbd5e1;
    }

    [data-theme="dark"] #voice-copy-log:hover {
        background: #334155;
        border-color: #64748b;
        color: #f8fafc;
    }

    [data-theme="dark"] #voice-copy-log.is-success {
        background: #14532d;
        border-color: #22c55e;
        color: #dcfce7;
    }

    [data-theme="dark"] #voice-copy-log.is-error {
        background: #7f1d1d;
        border-color: #ef4444;
        color: #fee2e2;
    }
</style>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">Voice Lab</h3>
            <p class="text-muted mb-0">
                Laboratorio aislado para validar JsSIP y Asterisk.
            </p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header voice-lab-session-header">
                    <span>Voice Session</span>

                    <span
                        id="voice-lab-status"
                        class="badge bg-secondary"
                        role="status"
                        aria-live="polite"
                    >
                        Idle
                    </span>
                </div>

                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">
                            Browser Tab ID
                        </label>

                        <input
                            id="voice-browser-tab-id"
                            type="text"
                            class="form-control"
                            readonly
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Session UUID
                        </label>

                        <input
                            id="voice-session-uuid"
                            type="text"
                            class="form-control"
                            readonly
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Extension
                        </label>

                        <input
                            id="voice-extension"
                            type="text"
                            class="form-control"
                            readonly
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Destination
                        </label>

                        <input
                            id="voice-destination"
                            type="text"
                            class="form-control"
                            placeholder="Extension or phone number"
                            autocomplete="off"
                        >
                    </div>

                    <div
                        id="voice-incoming-panel"
                        hidden
                    >
                        <div class="voice-incoming-title">
                            <i
                                class="bi bi-telephone-inbound-fill"
                                aria-hidden="true"
                            ></i>
                            Incoming Call
                        </div>

                        <p id="voice-incoming-caller">
                            Caller: Unknown caller
                        </p>

                        <div class="voice-incoming-actions">
                            <button
                                id="voice-answer"
                                type="button"
                                class="btn btn-sm"
                                disabled
                            >
                                <i
                                    class="bi bi-telephone-fill"
                                    aria-hidden="true"
                                ></i>
                                Answer
                            </button>

                            <button
                                id="voice-reject"
                                type="button"
                                class="btn btn-sm"
                                disabled
                            >
                                <i
                                    class="bi bi-telephone-x-fill"
                                    aria-hidden="true"
                                ></i>
                                Reject
                            </button>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button
                            id="voice-start"
                            type="button"
                            class="btn btn-sm btn-primary"
                            disabled
                        >
                            <i class="bi bi-play-circle" aria-hidden="true"></i>
                            Start Voice Session
                        </button>

                        <button
                            id="voice-reconnect"
                            type="button"
                            class="btn btn-sm btn-secondary"
                            disabled
                        >
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                            Reconnect
                        </button>

                        <button
                            id="voice-check-ownership"
                            type="button"
                            class="btn btn-sm btn-outline"
                        >
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            Check Ownership
                        </button>

                        <button
                            id="voice-revoke"
                            type="button"
                            class="btn btn-sm btn-danger"
                            disabled
                        >
                            <i class="bi bi-x-circle" aria-hidden="true"></i>
                            Revoke
                        </button>

                        <button
                            id="voice-call"
                            type="button"
                            class="btn btn-sm btn-primary"
                            disabled
                        >
                            <i class="bi bi-telephone-outbound" aria-hidden="true"></i>
                            Start Outbound Call
                        </button>

                        <div class="voice-media-actions">
                            <button
                                id="voice-mute"
                                type="button"
                                class="btn btn-sm"
                                aria-pressed="false"
                                disabled
                            >
                                <i
                                    class="bi bi-mic-mute-fill"
                                    aria-hidden="true"
                                ></i>
                                <span data-voice-mute-label>Mute</span>
                            </button>

                            <button
                                id="voice-hold"
                                type="button"
                                class="btn btn-sm"
                                aria-pressed="false"
                                disabled
                            >
                                <i
                                    class="bi bi-pause-circle-fill"
                                    aria-hidden="true"
                                ></i>
                                <span data-voice-hold-label>Hold</span>
                            </button>
                        </div>

                        <button
                            id="voice-hangup"
                            type="button"
                            class="btn btn-sm btn-danger"
                            disabled
                        >
                            <i class="bi bi-telephone-x" aria-hidden="true"></i>
                            Hang Up
                        </button>
                    </div>

                    <audio
                        id="voice-remote-audio"
                        autoplay
                    ></audio>

                    <audio
                        id="voice-ringback-audio"
                        src="{{ asset('audio/voice/ringtone.mp3') }}"
                        preload="auto"
                        loop
                    ></audio>

                    <audio
                        id="voice-inbound-ringtone-audio"
                        src="{{ asset('audio/voice/ringtone.mp3') }}"
                        preload="auto"
                        loop
                    ></audio>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header voice-log-header">
                    <span>Technical Log</span>

                    <button
                        id="voice-copy-log"
                        type="button"
                        aria-label="Copy technical log"
                        title="Copy technical log"
                    >
                        <i
                            class="bi bi-clipboard"
                            aria-hidden="true"
                        ></i>
                        <span data-copy-log-label>Copy Log</span>
                    </button>
                </div>

                <div class="card-body">
                    <pre
                        id="voice-lab-log"
                        class="bg-dark text-light rounded p-3 mb-0"
                        style="height: 420px; overflow-y: auto;"
                    ></pre>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/voice/jssip.min.js') }}"></script>
<script src="{{ asset('js/voice/voice-api-client.js') }}"></script>
<script src="{{ asset('js/voice/voice-call-manager.js') }}"></script>
<script src="{{ asset('js/voice/voice-session-manager.js') }}"></script>
<script src="{{ asset('js/voice/voice-lab-adapter.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    window.voiceLabAdapter = new window.xedikVoice.VoiceLabAdapter({
        csrfToken: document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content'),
    }).init();
});
</script>

{{-- Legacy inline implementation retained temporarily as non-rendered
     reference while the shared adapter completes browser QA. --}}
@if(false)
<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    const browserTabId =
        sessionStorage.getItem('voice_browser_tab_id')
        ?? crypto.randomUUID();

    sessionStorage.setItem(
        'voice_browser_tab_id',
        browserTabId
    );

    const state = {
        sessionUuid:
            sessionStorage.getItem('voice_session_uuid'),
        ua: null,
        heartbeatTimer: null,
        sipCredentials: null,
        backendRegistered: false,
        currentRtcSession: null,
        ownershipState: null,
        reconnectRequired: false,
        reconnectInFlight: false,
        voiceSessionStatus: 'Disconnected',
        voiceSessionStatusClass: 'bg-secondary',
        callStatus: null,
        callStatusClass: 'bg-secondary',
        incomingSession: null,
        incomingCaller: null,
        incomingPending: false,
        incomingAnswered: false,
        localRejectRequested: false,
        callConfirmed: false,
        isMuted: false,
        isOnHold: false,
        mutePending: false,
        holdPending: false,
        voiceBootMeasurement: null,
    };

    const elements = {
        status: document.getElementById('voice-lab-status'),
        browserTabId:
            document.getElementById('voice-browser-tab-id'),
        sessionUuid:
            document.getElementById('voice-session-uuid'),
        extension:
            document.getElementById('voice-extension'),
        destination:
            document.getElementById('voice-destination'),
        incomingPanel:
            document.getElementById('voice-incoming-panel'),
        incomingCaller:
            document.getElementById('voice-incoming-caller'),
        answer:
            document.getElementById('voice-answer'),
        reject:
            document.getElementById('voice-reject'),
        ownership:
            document.getElementById('voice-check-ownership'),
        start:
            document.getElementById('voice-start'),
        reconnect:
            document.getElementById('voice-reconnect'),
        revoke:
            document.getElementById('voice-revoke'),
        call:
            document.getElementById('voice-call'),
        mute:
            document.getElementById('voice-mute'),
        hold:
            document.getElementById('voice-hold'),
        hangup:
            document.getElementById('voice-hangup'),
        remoteAudio:
            document.getElementById('voice-remote-audio'),
        ringbackAudio:
            document.getElementById('voice-ringback-audio'),
        inboundRingtoneAudio:
            document.getElementById(
                'voice-inbound-ringtone-audio'
            ),
        log:
            document.getElementById('voice-lab-log'),
        copyLog:
            document.getElementById('voice-copy-log'),
    };

    const boundRtcSessions = new WeakSet();
    const boundPeerConnections = new WeakSet();
    let ringbackPlayAttempt = 0;
    let ringbackActive = false;
    let inboundRingtonePlayAttempt = 0;
    let inboundRingtoneActive = false;
    let copyLogFeedbackTimer = null;
    
    function log(message, data = null) {
        const timestamp = new Date().toISOString();

        let line = `[${timestamp}] ${message}`;

        if (data !== null) {
            line += `\n${JSON.stringify(data, null, 2)}`;
        }

        elements.log.textContent += `${line}\n\n`;
        elements.log.scrollTop = elements.log.scrollHeight;
    }

    function voiceBootDuration(measurement, from, to) {
        const startedAt = measurement.marks[from];
        const completedAt = measurement.marks[to];

        if (!Number.isFinite(startedAt)
            || !Number.isFinite(completedAt)) {
            return null;
        }

        return Math.round((completedAt - startedAt) * 100) / 100;
    }

    function failVoiceBootMeasurement(message) {
        const measurement = state.voiceBootMeasurement;

        if (!measurement) {
            return;
        }

        log('Voice startup performance failed', {
            operation: measurement.operation,
            measurement_id: measurement.measurementId,
            last_step_reached: measurement.lastStep,
            elapsed_ms: voiceBootDuration(
                measurement,
                'operation_started',
                'failure'
            ) ?? Math.round(
                (performance.now()
                    - measurement.marks.operation_started) * 100
            ) / 100,
            message: String(message ?? 'Unknown error').slice(0, 300),
        });

        state.voiceBootMeasurement = null;
    }

    function beginVoiceBootMeasurement(operation) {
        if (state.voiceBootMeasurement) {
            failVoiceBootMeasurement(
                `Measurement replaced by ${operation}.`
            );
        }

        const startedAt = performance.now();

        state.voiceBootMeasurement = {
            operation,
            measurementId: crypto.randomUUID(),
            startedAt,
            marks: {
                operation_started: startedAt,
            },
            lastStep: 'operation_started',
            backendMeasurementId: null,
            serverTiming: null,
            registeredServerTiming: null,
        };
    }

    function markVoiceBootStep(step) {
        const measurement = state.voiceBootMeasurement;

        if (!measurement || Number.isFinite(measurement.marks[step])) {
            return;
        }

        measurement.marks[step] = performance.now();
        measurement.lastStep = step;
    }

    function voiceBootRequestHeaders() {
        const measurementId =
            state.voiceBootMeasurement?.measurementId;

        return measurementId
            ? {
                'X-Voice-Measurement-ID': measurementId,
            }
            : {};
    }

    function attachVoiceBackendTiming(result) {
        const measurement = state.voiceBootMeasurement;

        if (!measurement) {
            return;
        }

        measurement.backendMeasurementId =
            result.measurementId ?? null;
        measurement.serverTiming = result.serverTiming ?? null;
    }

    function completeVoiceBootMeasurement() {
        const measurement = state.voiceBootMeasurement;

        if (!measurement) {
            return;
        }

        markVoiceBootStep('voice_ready');

        const result = {
            operation: measurement.operation,
            measurement_id:
                measurement.backendMeasurementId
                ?? measurement.measurementId,
            backend_request_ms: voiceBootDuration(
                measurement,
                'backend_request_started',
                'backend_response_received'
            ),
            jssip_start_delay_ms: voiceBootDuration(
                measurement,
                'backend_response_received',
                'jssip_start_called'
            ),
            websocket_connect_ms: voiceBootDuration(
                measurement,
                'jssip_start_called',
                'websocket_connected'
            ),
            sip_register_ms: voiceBootDuration(
                measurement,
                'websocket_connected',
                'sip_registered'
            ),
            registered_api_ms: voiceBootDuration(
                measurement,
                'registered_api_started',
                'registered_api_completed'
            ),
            post_backend_to_ready_ms: voiceBootDuration(
                measurement,
                'backend_response_received',
                'voice_ready'
            ),
            total_voice_ready_ms: voiceBootDuration(
                measurement,
                'operation_started',
                'voice_ready'
            ),
        };

        if (measurement.serverTiming) {
            result.server_timing = measurement.serverTiming;
        }

        if (measurement.registeredServerTiming) {
            result.registered_server_timing =
                measurement.registeredServerTiming;
        }

        log('Voice startup performance', result);
        state.voiceBootMeasurement = null;
    }

    function showCopyLogFeedback(
        label,
        iconClass,
        stateClass = null
    ) {
        const icon = elements.copyLog.querySelector('i');
        const labelElement = elements.copyLog.querySelector(
            '[data-copy-log-label]'
        );

        clearTimeout(copyLogFeedbackTimer);
        icon.className = `bi ${iconClass}`;
        labelElement.textContent = label;
        elements.copyLog.classList.toggle(
            'is-success',
            stateClass === 'success'
        );
        elements.copyLog.classList.toggle(
            'is-error',
            stateClass === 'error'
        );

        copyLogFeedbackTimer = setTimeout(() => {
            icon.className = 'bi bi-clipboard';
            labelElement.textContent = 'Copy Log';
            elements.copyLog.classList.remove(
                'is-success',
                'is-error'
            );
        }, 1500);
    }

    function copyTextWithFallback(text) {
        const textarea = document.createElement('textarea');

        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.setAttribute('aria-hidden', 'true');
        textarea.tabIndex = -1;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        textarea.style.pointerEvents = 'none';

        document.body.appendChild(textarea);

        try {
            textarea.focus();
            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);

            if (!document.execCommand('copy')) {
                throw new Error('Browser copy command was rejected.');
            }
        } finally {
            textarea.remove();
            elements.copyLog.focus();
        }
    }

    async function copyTechnicalLog() {
        const logText = elements.log.textContent;

        if (!logText) {
            showCopyLogFeedback(
                'Log is empty',
                'bi-clipboard-x',
                'error'
            );
            return;
        }

        try {
            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(logText);
                } catch (clipboardError) {
                    copyTextWithFallback(logText);
                }
            } else {
                copyTextWithFallback(logText);
            }

            showCopyLogFeedback(
                'Copied',
                'bi-check2',
                'success'
            );
        } catch (error) {
            log('Technical log copy failed', {
                message: error.message,
            });

            showCopyLogFeedback(
                'Copy Failed',
                'bi-x-lg',
                'error'
            );
        }
    }

    function paintStatus(label, className = 'bg-secondary') {
        elements.status.textContent = label;
        elements.status.className = `badge ${className}`;
    }

    function voiceSessionStatusIsCritical() {
        return [
            'Session Replaced',
            'Disconnected',
            'Registration Failed',
        ].includes(state.voiceSessionStatus);
    }

    function renderVoiceStatus() {
        if (voiceSessionStatusIsCritical()) {
            paintStatus(
                state.voiceSessionStatus,
                state.voiceSessionStatusClass
            );
            return;
        }

        if (state.currentRtcSession && state.callStatus) {
            paintStatus(state.callStatus, state.callStatusClass);
            return;
        }

        paintStatus(
            state.voiceSessionStatus,
            state.voiceSessionStatusClass
        );
    }

    function setStatus(label, className = 'bg-secondary') {
        state.voiceSessionStatus = label;
        state.voiceSessionStatusClass = className;
        renderVoiceStatus();
        syncCallControls();
    }

    function setCallStatus(label, className = 'bg-secondary') {
        state.callStatus = label;
        state.callStatusClass = className;
        renderVoiceStatus();
    }

    function syncFields() {
        elements.browserTabId.value = browserTabId;
        elements.sessionUuid.value = state.sessionUuid ?? '';
        elements.revoke.disabled = !state.sessionUuid;
        syncOwnershipControls();
    }

    function uaIsUsable() {
        return Boolean(
            state.ua
            && state.sipCredentials
            && typeof state.ua.isRegistered === 'function'
            && state.ua.isRegistered()
        );
    }

    function syncOwnershipControls() {
        elements.start.disabled =
            state.ownershipState !== 'none';

        elements.reconnect.disabled = !(
            state.ownershipState === 'same_tab'
            && state.reconnectRequired
            && !state.reconnectInFlight
        );
    }

    function uaCanCall() {
        return Boolean(
            state.ua
            && state.backendRegistered
            && typeof state.ua.isRegistered === 'function'
            && state.ua.isRegistered()
        );
    }

    function currentCallCanUseMediaControls() {
        const rtcSession = state.currentRtcSession;

        return Boolean(
            rtcSession
            && state.callConfirmed
            && !voiceSessionStatusIsCritical()
            && typeof rtcSession.isEstablished === 'function'
            && rtcSession.isEstablished()
            && (
                typeof rtcSession.isEnded !== 'function'
                || !rtcSession.isEnded()
            )
        );
    }

    function syncMediaControlButtons() {
        const muteIcon = elements.mute.querySelector('i');
        const muteLabel = elements.mute.querySelector(
            '[data-voice-mute-label]'
        );
        const holdIcon = elements.hold.querySelector('i');
        const holdLabel = elements.hold.querySelector(
            '[data-voice-hold-label]'
        );

        muteIcon.className = state.isMuted
            ? 'bi bi-mic-fill'
            : 'bi bi-mic-mute-fill';
        muteLabel.textContent = state.isMuted
            ? 'Unmute'
            : 'Mute';
        elements.mute.classList.toggle(
            'is-active',
            state.isMuted
        );
        elements.mute.setAttribute(
            'aria-pressed',
            String(state.isMuted)
        );

        holdIcon.className = state.isOnHold
            ? 'bi bi-play-circle-fill'
            : 'bi bi-pause-circle-fill';
        holdLabel.textContent = state.isOnHold
            ? 'Resume'
            : 'Hold';
        elements.hold.classList.toggle(
            'is-active',
            state.isOnHold
        );
        elements.hold.setAttribute(
            'aria-pressed',
            String(state.isOnHold)
        );
    }

    function resetMediaControls() {
        state.callConfirmed = false;
        state.isMuted = false;
        state.isOnHold = false;
        state.mutePending = false;
        state.holdPending = false;
    }

    function syncCallControls() {
        const hasActiveCall = Boolean(state.currentRtcSession);
        const sessionIsCritical = voiceSessionStatusIsCritical();
        const inboundPending = Boolean(
            hasActiveCall
            && state.incomingSession === state.currentRtcSession
            && state.incomingPending
        );
        const inboundAnswered = Boolean(
            hasActiveCall
            && state.incomingSession === state.currentRtcSession
            && state.incomingAnswered
        );
        const outboundCall = Boolean(
            hasActiveCall
            && state.incomingSession !== state.currentRtcSession
        );
        const canStartCall =
            !sessionIsCritical && uaCanCall() && !hasActiveCall;
        const canHangUp =
            !sessionIsCritical && (outboundCall || inboundAnswered);
        const canUseMediaControls =
            currentCallCanUseMediaControls();

        elements.call.disabled = !canStartCall;
        elements.hangup.disabled = !canHangUp;
        elements.answer.disabled =
            sessionIsCritical || !inboundPending;
        elements.reject.disabled =
            sessionIsCritical || !inboundPending;
        elements.mute.disabled =
            !canUseMediaControls || state.mutePending;
        elements.hold.disabled =
            !canUseMediaControls || state.holdPending;

        elements.incomingPanel.hidden = !inboundPending;
        elements.incomingCaller.textContent =
            `Caller: ${state.incomingCaller ?? 'Unknown caller'}`;
        syncMediaControlButtons();
    }

    function clearRemoteAudio() {
        elements.remoteAudio.pause();
        elements.remoteAudio.srcObject = null;
    }

    function sanitizeCallerPart(value) {
        if (typeof value !== 'string') {
            return null;
        }

        const sanitized = value
            .replace(/[\u0000-\u001F\u007F]/g, '')
            .trim()
            .slice(0, 120);

        return sanitized || null;
    }

    function incomingCallerDetails(rtcSession) {
        const displayName = sanitizeCallerPart(
            rtcSession?.remote_identity?.display_name
        );
        const user = sanitizeCallerPart(
            rtcSession?.remote_identity?.uri?.user
        );

        return {
            displayName,
            user,
            label: displayName ?? user ?? 'Unknown caller',
        };
    }

    function stopInboundRingtone() {
        const wasActive = inboundRingtoneActive
            || !elements.inboundRingtoneAudio.paused;

        inboundRingtonePlayAttempt += 1;
        inboundRingtoneActive = false;
        elements.inboundRingtoneAudio.pause();
        elements.inboundRingtoneAudio.currentTime = 0;

        if (wasActive) {
            log('Inbound ringtone stopped');
        }
    }

    function startInboundRingtone() {
        if (!state.incomingPending
            || state.incomingSession !== state.currentRtcSession
            || inboundRingtoneActive) {
            return;
        }

        inboundRingtoneActive = true;
        elements.inboundRingtoneAudio.loop = true;
        elements.inboundRingtoneAudio.currentTime = 0;

        const playAttempt = ++inboundRingtonePlayAttempt;

        try {
            const playPromise = elements.inboundRingtoneAudio.play();

            if (playPromise?.then) {
                playPromise
                    .then(() => {
                        if (playAttempt === inboundRingtonePlayAttempt) {
                            log('Inbound ringtone started');
                        }
                    })
                    .catch(error => {
                        if (playAttempt !== inboundRingtonePlayAttempt
                            && error.name === 'AbortError') {
                            return;
                        }

                        inboundRingtoneActive = false;

                        log('Inbound ringtone play error', {
                            name: error.name,
                            message: error.message,
                        });
                    });
            }
        } catch (error) {
            inboundRingtoneActive = false;

            log('Inbound ringtone play error', {
                name: error.name,
                message: error.message,
            });
        }
    }

    function cleanupCurrentCall(rtcSession = null) {
        if (rtcSession
            && state.currentRtcSession !== rtcSession) {
            return;
        }

        stopRingback();
        stopInboundRingtone();
        clearRemoteAudio();

        state.currentRtcSession = null;
        state.incomingSession = null;
        state.incomingCaller = null;
        state.incomingPending = false;
        state.incomingAnswered = false;
        state.localRejectRequested = false;
        state.callStatus = null;
        resetMediaControls();

        syncCallControls();
        renderVoiceStatus();
    }

    function remoteAudioIsPlayable() {
        const stream = elements.remoteAudio.srcObject;

        return Boolean(
            stream
            && typeof stream.getAudioTracks === 'function'
            && stream.getAudioTracks().some(
                track => track.readyState === 'live'
            )
            && !elements.remoteAudio.paused
            && elements.remoteAudio.readyState >= 2
        );
    }

    function stopRingback() {
        const wasActive = ringbackActive
            || !elements.ringbackAudio.paused;

        ringbackPlayAttempt += 1;
        ringbackActive = false;
        elements.ringbackAudio.pause();
        elements.ringbackAudio.currentTime = 0;

        if (wasActive) {
            log('Outbound ringback stopped');
        }
    }

    function startRingback(rtcSession, event) {
        const response = event?.response ?? event?.message ?? null;

        if (rtcSession?.direction !== 'outgoing'
            || response?.status_code !== 180
            || ringbackActive) {
            return;
        }

        if (remoteAudioIsPlayable()) {
            log('Outbound ringback skipped for remote early media');
            return;
        }

        ringbackActive = true;
        elements.ringbackAudio.loop = true;
        elements.ringbackAudio.currentTime = 0;

        const playAttempt = ++ringbackPlayAttempt;

        try {
            const playPromise = elements.ringbackAudio.play();

            if (playPromise?.then) {
                playPromise
                    .then(() => {
                        if (playAttempt === ringbackPlayAttempt) {
                            log('Outbound ringback started');
                        }
                    })
                    .catch(error => {
                        if (playAttempt !== ringbackPlayAttempt
                            && error.name === 'AbortError') {
                            return;
                        }

                        ringbackActive = false;

                        log('Outbound ringback play error', {
                            name: error.name,
                            message: error.message,
                        });
                    });
            }
        } catch (error) {
            ringbackActive = false;

            log('Outbound ringback play error', {
                name: error.name,
                message: error.message,
            });
        }
    }

    function callStatusCanChange() {
        return !voiceSessionStatusIsCritical();
    }

    async function postJson(url, payload, additionalHeaders = {}) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                ...additionalHeaders,
            },
            body: JSON.stringify(payload),
        });

        const body = await response.json();

        return {
            status: response.status,
            body,
            measurementId: response.headers.get(
                'X-Voice-Measurement-ID'
            ),
            serverTiming: response.headers.get('Server-Timing'),
        };
    }

    async function checkOwnership() {
        state.ownershipState = null;
        state.reconnectRequired = false;
        syncOwnershipControls();
        setStatus('Checking...', 'bg-warning');
        log('Checking ownership');

        try {
            const result = await postJson(
                '/voice/session/ownership',
                {
                    browser_tab_id: browserTabId,
                }
            );

            log('Ownership response', result);
            state.ownershipState = result.body.state ?? null;

            if (result.body.state === 'none') {
                state.reconnectRequired = false;
                state.sessionUuid = null;
                sessionStorage.removeItem('voice_session_uuid');
                elements.extension.value = '';
                setStatus('No Session', 'bg-secondary');
            } else if (result.body.state === 'same_tab') {
                state.sessionUuid = result.body.session?.session_uuid ?? null;

                if (state.sessionUuid) {
                    sessionStorage.setItem(
                        'voice_session_uuid',
                        state.sessionUuid
                    );
                }

                state.reconnectRequired = !uaIsUsable();

                if (state.reconnectRequired) {
                    setStatus(
                        'Reconnect Required',
                        'bg-warning text-dark'
                    );
                } else {
                    setStatus('Owned Here', 'bg-success');
                }
            } else if (result.body.state === 'other_tab') {
                state.reconnectRequired = false;
                state.sessionUuid = null;
                sessionStorage.removeItem('voice_session_uuid');

                elements.extension.value = '';

                setStatus('Owned Elsewhere', 'bg-danger');

                await showTakeoverPrompt();
            }

            syncFields();
        } catch (error) {
            state.ownershipState = null;
            state.reconnectRequired = false;
            syncOwnershipControls();
            console.error(error);
            log('Ownership error', {
                message: error.message,
            });
            setStatus('Error', 'bg-danger');
        }
    }
    async function notifyRegistered() {
        if (!state.sessionUuid) {
            throw new Error('Missing voice session UUID.');
        }

        markVoiceBootStep('registered_api_started');

        const result = await postJson(
            `/voice/session/${state.sessionUuid}/registered`,
            {
                browser_tab_id: browserTabId,
            },
            voiceBootRequestHeaders()
        );

        markVoiceBootStep('registered_api_completed');

        if (state.voiceBootMeasurement) {
            state.voiceBootMeasurement.registeredServerTiming =
                result.serverTiming ?? null;
        }

        log('Registered API response', result);

        if (!result.body.ok) {
            throw new Error(
                result.body.message
                    ?? 'Unable to mark the session as registered.'
            );
        }

        setStatus('Registered', 'bg-success');
        startHeartbeat();
    }

    async function sendHeartbeat() {
        if (!state.sessionUuid) {
            return;
        }

        const result = await postJson(
            `/voice/session/${state.sessionUuid}/heartbeat`,
            {
                browser_tab_id: browserTabId,
            }
        );

        log('Heartbeat response', result);

        if (!result.body.ok) {
            stopHeartbeat();

            if (result.body.state === 'inactive_session') {
                log('Voice session replaced', {
                    state: result.body.state,
                    message: result.body.message,
                });

                stopUa();

                state.backendRegistered = false;
                state.sipCredentials = null;
                state.sessionUuid = null;
                state.ownershipState = null;
                state.reconnectRequired = false;

                sessionStorage.removeItem(
                    'voice_session_uuid'
                );

                delete window.voiceSipCredentials;

                elements.extension.value = '';

                syncCallControls();
                if (typeof setLastHeartbeat === 'function') {
                    setLastHeartbeat();
                }

                syncFields();

                setStatus('Session Replaced', 'bg-danger');

                return;
            }

            throw new Error(
                result.body.message
                    ?? 'Voice heartbeat failed.'
            );
        }

        setStatus('Active', 'bg-success');
    }

    function startHeartbeat() {
        stopHeartbeat();

        state.heartbeatTimer = window.setInterval(() => {
            sendHeartbeat().catch(error => {
                console.error(error);

                log('Heartbeat error', {
                    message: error.message,
                });

                setStatus('Heartbeat Error', 'bg-danger');
            });
        }, 60000);

        markVoiceBootStep('heartbeat_started');

        log('Heartbeat timer started', {
            interval_ms: 60000,
        });
    }

    function stopHeartbeat() {
        if (state.heartbeatTimer !== null) {
            window.clearInterval(state.heartbeatTimer);
            state.heartbeatTimer = null;

            log('Heartbeat timer stopped');
        }
    }

    function rtcSessionLogData(
        rtcSession,
        event,
        sessionOriginator
    ) {
        const response = event?.response ?? event?.message ?? null;

        return {
            originator:
                event?.originator ?? sessionOriginator ?? null,
            cause: event?.cause ?? null,
            status_code:
                response?.status_code ?? null,
            reason_phrase:
                response?.reason_phrase ?? null,
            direction: rtcSession?.direction ?? null,
        };
    }

    function attachRemoteStream(stream) {
        if (!stream) {
            return;
        }

        if (elements.remoteAudio.srcObject) {
            return;
        }

        elements.remoteAudio.srcObject = stream;

        try {
            const playPromise = elements.remoteAudio.play();

            if (playPromise?.then) {
                playPromise
                    .then(() => {
                        if (state.currentRtcSession?.direction
                            === 'outgoing') {
                            stopRingback();
                        }
                    })
                    .catch(error => {
                        if (error.name === 'AbortError') {
                            return;
                        }

                        log('Remote audio play rejected', {
                            name: error.name,
                            message: error.message,
                        });
                    });
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                log('Remote audio play rejected', {
                    name: error.name,
                    message: error.message,
                });
            }
        }
    }

    function attachRemoteAudio(rtcSession) {
        if (!rtcSession) {
            return;
        }

        const bindPeerConnection = peerConnection => {
            if (!peerConnection
                || boundPeerConnections.has(peerConnection)) {
                return;
            }

            boundPeerConnections.add(peerConnection);

            peerConnection.addEventListener('track', event => {
                attachRemoteStream(event.streams?.[0] || (event.track ? new MediaStream([event.track]) : null));
            });

            peerConnection.addEventListener('addstream', event => {
                attachRemoteStream(event.stream ?? null);
            });
        };

        bindPeerConnection(rtcSession.connection);

        rtcSession.on('peerconnection', event => {
            bindPeerConnection(event?.peerconnection ?? null);
        });

        rtcSession.on('addstream', event => {
            attachRemoteStream(event.stream ?? null);
        });
    }

    function finishRtcSession(
        rtcSession,
        terminalStatus
    ) {
        if (state.currentRtcSession !== rtcSession) {
            return;
        }

        if (!rtcSession.voiceLabExpectedTermination) {
            log(terminalStatus);
        }

        cleanupCurrentCall(rtcSession);
    }

    function bindRtcSessionLifecycle(
        rtcSession,
        sessionOriginator
    ) {
        if (boundRtcSessions.has(rtcSession)) {
            return;
        }

        boundRtcSessions.add(rtcSession);
        attachRemoteAudio(rtcSession);

        rtcSession.on('muted', event => {
            if (state.currentRtcSession !== rtcSession) {
                return;
            }

            state.isMuted = Boolean(
                rtcSession.isMuted?.().audio
            );
            state.mutePending = false;

            if (event?.audio) {
                log('Call muted');
            }

            syncCallControls();
        });

        rtcSession.on('unmuted', event => {
            if (state.currentRtcSession !== rtcSession) {
                return;
            }

            state.isMuted = Boolean(
                rtcSession.isMuted?.().audio
            );
            state.mutePending = false;

            if (event?.audio) {
                log('Call unmuted');
            }

            syncCallControls();
        });

        rtcSession.on('hold', event => {
            log('RTCSession hold', {
                originator: event?.originator ?? null,
            });

            if (state.currentRtcSession !== rtcSession) {
                return;
            }

            state.isOnHold = Boolean(
                rtcSession.isOnHold?.().local
            );

            if (event?.originator === 'local'
                && state.isOnHold) {
                setCallStatus(
                    'On Hold',
                    'bg-warning text-dark'
                );
                log('Call held');
            }

            syncCallControls();
        });

        rtcSession.on('unhold', event => {
            log('RTCSession unhold', {
                originator: event?.originator ?? null,
            });

            if (state.currentRtcSession !== rtcSession) {
                return;
            }

            state.isOnHold = Boolean(
                rtcSession.isOnHold?.().local
            );

            if (event?.originator === 'local'
                && !state.isOnHold) {
                setCallStatus('In Call', 'bg-success');
                log('Call resumed');
            }

            syncCallControls();
        });

        rtcSession.on('connecting', event => {
            syncCallControls();

            log(
                'RTCSession connecting',
                rtcSessionLogData(
                    rtcSession,
                    event,
                    sessionOriginator
                )
            );

            if (!rtcSession.voiceLabExpectedTermination
                && callStatusCanChange()
                && rtcSession.direction === 'outgoing') {
                setCallStatus(
                    'Calling',
                    'bg-warning text-dark'
                );
            }
        });

        rtcSession.on('progress', event => {
            syncCallControls();

            log(
                'RTCSession progress',
                rtcSessionLogData(
                    rtcSession,
                    event,
                    sessionOriginator
                )
            );

            startRingback(rtcSession, event);

            const response =
                event?.response ?? event?.message ?? null;

            if (!rtcSession.voiceLabExpectedTermination
                && callStatusCanChange()
                && rtcSession.direction === 'outgoing'
                && response?.status_code === 180) {
                setCallStatus(
                    'Ringing',
                    'bg-warning text-dark'
                );
            }
        });

        rtcSession.on('accepted', event => {
            stopRingback();
            stopInboundRingtone();

            if (state.incomingSession === rtcSession) {
                state.incomingPending = false;
                state.incomingAnswered = true;
            }

            syncCallControls();

            log(
                'RTCSession accepted',
                rtcSessionLogData(
                    rtcSession,
                    event,
                    sessionOriginator
                )
            );

            if (!rtcSession.voiceLabExpectedTermination
                && callStatusCanChange()) {
                setCallStatus('In Call', 'bg-success');
            }
        });

        rtcSession.on('confirmed', event => {
            stopRingback();
            stopInboundRingtone();
            state.callConfirmed = true;

            if (state.incomingSession === rtcSession) {
                state.incomingPending = false;
                state.incomingAnswered = true;
            }

            syncCallControls();

            log(
                'RTCSession confirmed',
                rtcSessionLogData(
                    rtcSession,
                    event,
                    sessionOriginator
                )
            );

            if (!rtcSession.voiceLabExpectedTermination
                && callStatusCanChange()) {
                setCallStatus('In Call', 'bg-success');
            }
        });

        rtcSession.on('ended', event => {
            stopRingback();
            stopInboundRingtone();

            log(
                'RTCSession ended',
                rtcSessionLogData(
                    rtcSession,
                    event,
                    sessionOriginator
                )
            );

            finishRtcSession(
                rtcSession,
                'Call Ended'
            );
        });

        rtcSession.on('failed', event => {
            stopRingback();
            stopInboundRingtone();

            const wasLocallyRejected = Boolean(
                state.localRejectRequested
                && state.incomingSession === rtcSession
                && rtcSession.direction === 'incoming'
                && event?.originator === 'local'
                && event?.cause === 'Rejected'
            );

            log(
                'RTCSession failed',
                rtcSessionLogData(
                    rtcSession,
                    event,
                    sessionOriginator
                )
            );

            finishRtcSession(
                rtcSession,
                wasLocallyRejected
                    ? 'Call Rejected'
                    : 'Call Failed'
            );
        });
    }

    function handleNewRtcSession(event) {
        const rtcSession = event?.session;
        const sessionOriginator = event?.originator ?? null;

        if (!rtcSession) {
            log('JsSIP new RTCSession missing session', {
                originator: sessionOriginator,
            });
            return;
        }

        const isIncoming = sessionOriginator === 'remote'
            || rtcSession.direction === 'incoming';
        const caller = isIncoming
            ? incomingCallerDetails(rtcSession)
            : null;

        if (isIncoming) {
            log('JsSIP incoming RTCSession', {
                originator: sessionOriginator,
                direction: rtcSession.direction ?? null,
                caller_display_name: caller.displayName,
                caller_user: caller.user,
            });
        } else {
            log('JsSIP new RTCSession', {
                originator: sessionOriginator,
                direction: rtcSession.direction ?? null,
            });
        }

        if (state.currentRtcSession
            && state.currentRtcSession !== rtcSession) {
            log(
                isIncoming
                    ? 'Incoming call rejected because another RTCSession is active'
                    : 'Second RTCSession rejected',
                {
                    originator: sessionOriginator,
                    direction: rtcSession.direction ?? null,
                }
            );

            try {
                rtcSession.terminate(
                    isIncoming
                        ? {
                            status_code: 486,
                            reason_phrase: 'Busy Here',
                        }
                        : undefined
                );
            } catch (error) {
                log('Second RTCSession termination error', {
                    message: error.message,
                });
            }

            return;
        }

        state.localRejectRequested = false;
        resetMediaControls();
        state.currentRtcSession = rtcSession;

        if (isIncoming) {
            state.incomingSession = rtcSession;
            state.incomingCaller = caller.label;
            state.incomingPending = true;
            state.incomingAnswered = false;
        } else {
            state.incomingSession = null;
            state.incomingCaller = null;
            state.incomingPending = false;
            state.incomingAnswered = false;
        }

        bindRtcSessionLifecycle(
            rtcSession,
            sessionOriginator
        );

        setCallStatus(
            isIncoming ? 'Incoming Call' : 'Calling',
            'bg-warning text-dark'
        );
        syncCallControls();

        if (isIncoming) {
            startInboundRingtone();
        }
    }

    function stopUa() {
        stopHeartbeat();
        stopRingback();
        stopInboundRingtone();

        const ua = state.ua;

        if (state.currentRtcSession) {
            const rtcSession = state.currentRtcSession;

            rtcSession.voiceLabExpectedTermination = true;
            log('Terminating active RTCSession for UA shutdown');

            try {
                rtcSession.terminate();
            } catch (error) {
                log('RTCSession termination error', {
                    message: error.message,
                });
            }
        }

        cleanupCurrentCall();

        if (!ua) {
            syncCallControls();
            return;
        }

        state.ua = null;
        syncCallControls();
        ua.expectedShutdown = true;

        try {
            ua.stop();
        } catch (error) {
            console.error(error);

            log('JsSIP stop error', {
                message: error.message,
            });
        }
    }

    function startJsSip(sip) {
        if (typeof JsSIP === 'undefined') {
            throw new Error('JsSIP is not loaded.');
        }

        stopUa();
        state.sipCredentials = sip;

        const socket = new JsSIP.WebSocketInterface(
            sip.wss_server
        );

        const ua = new JsSIP.UA({
            sockets: [socket],
            uri: sip.uri,
            authorization_user: sip.authorization_user,
            password: sip.password,
            realm: sip.realm,
            register: true,
            session_timers: false,
        });

        ua.expectedShutdown = false;
        state.ua = ua;
        syncCallControls();

        ua.on('connecting', () => {
            log('JsSIP connecting');
            setStatus('Connecting', 'bg-warning');
        });

        ua.on('connected', () => {
            markVoiceBootStep('websocket_connected');
            log('JsSIP WebSocket connected');
            setStatus('Authenticating', 'bg-warning');
        });

        ua.on('registered', () => {
            markVoiceBootStep('sip_registered');
            log('JsSIP registered successfully');
            state.reconnectRequired = false;
            syncOwnershipControls();

            if (state.backendRegistered) {
                log('Backend registration already confirmed');
                setStatus('Active', 'bg-success');
                syncCallControls();
                return;
            }

            notifyRegistered()
                .then(() => {
                    state.backendRegistered = true;
                    syncCallControls();
                    completeVoiceBootMeasurement();
                })
                .catch(error => {
                    console.error(error);

                    log('Registered notification error', {
                        message: error.message,
                    });

                    failVoiceBootMeasurement(error.message);

                    syncCallControls();
                    setStatus('Backend Error', 'bg-danger');
                });
        });

        ua.on('registrationFailed', event => {
            log('JsSIP registration failed', {
                cause: event?.cause ?? null,
                status_code:
                    event?.response?.status_code ?? null,
                reason_phrase:
                    event?.response?.reason_phrase ?? null,
                expected: ua.expectedShutdown,
            });

            if (ua.expectedShutdown) {
                log(
                    'Registration failure ignored because UA shutdown was expected'
                );

                return;
            }

            if (state.ownershipState === 'same_tab') {
                state.reconnectRequired = true;
                syncOwnershipControls();
            }

            syncCallControls();
            setStatus('Registration Failed', 'bg-danger');
            failVoiceBootMeasurement(
                event?.cause ?? 'SIP registration failed.'
            );
        });

        ua.on('unregistered', () => {
            log('JsSIP unregistered', {
                expected: ua.expectedShutdown,
            });

            stopHeartbeat();
            syncCallControls();

            if (!ua.expectedShutdown) {
                if (state.ownershipState === 'same_tab') {
                    state.reconnectRequired = true;
                    syncOwnershipControls();
                }

                setStatus('Unregistered', 'bg-secondary');
                failVoiceBootMeasurement(
                    'SIP unregistered before voice ready.'
                );
            }
        });

        ua.on('disconnected', event => {
            log('JsSIP WebSocket disconnected', {
                code: event?.code ?? null,
                reason: event?.reason ?? null,
                error: event?.error ?? null,
                expected: ua.expectedShutdown,
            });

            stopHeartbeat();
            syncCallControls();

            if (!ua.expectedShutdown) {
                if (state.ownershipState === 'same_tab') {
                    state.reconnectRequired = true;
                    syncOwnershipControls();
                }

                setStatus('Disconnected', 'bg-danger');
                failVoiceBootMeasurement(
                    event?.reason ?? 'WebSocket disconnected.'
                );
            }
        });

        ua.on('newRTCSession', handleNewRtcSession);

        log('Starting JsSIP UA', {
            uri: sip.uri,
            authorization_user: sip.authorization_user,
            realm: sip.realm,
            wss_server: sip.wss_server,
        });

        markVoiceBootStep('jssip_start_called');
        ua.start();
    }

    async function reconnectVoice() {
        if (state.ownershipState !== 'same_tab'
            || !state.reconnectRequired
            || state.reconnectInFlight
            || !state.sessionUuid) {
            return;
        }

        beginVoiceBootMeasurement('reconnect');

        const requestedSessionUuid = state.sessionUuid;

        state.reconnectInFlight = true;
        syncOwnershipControls();
        setStatus('Reconnecting...', 'bg-warning');
        log('Reconnecting voice session', {
            session_uuid: requestedSessionUuid,
        });

        try {
            markVoiceBootStep('backend_request_started');

            const result = await postJson(
                '/voice/session/reconnect',
                {
                    session_uuid: requestedSessionUuid,
                    browser_tab_id: browserTabId,
                },
                voiceBootRequestHeaders()
            );

            markVoiceBootStep('backend_response_received');
            attachVoiceBackendTiming(result);

            log('Reconnect response', {
                status: result.status,
                body: {
                    ...result.body,
                    sip: result.body.sip
                        ? {
                            ...result.body.sip,
                            password: '[hidden]',
                        }
                        : undefined,
                },
            });

            if (result.status === 409) {
                failVoiceBootMeasurement(
                    result.body.message ?? 'Reconnect conflict.'
                );
                await checkOwnership();
                return;
            }

            if (!result.body.ok) {
                failVoiceBootMeasurement(
                    result.body.message ?? 'Reconnect failed.'
                );
                state.reconnectRequired = true;
                setStatus(
                    result.body.state ?? 'Reconnect Error',
                    'bg-danger'
                );
                return;
            }

            if (result.body.session?.session_uuid
                !== requestedSessionUuid) {
                throw new Error(
                    'Reconnect returned an unexpected session UUID.'
                );
            }

            state.sessionUuid = requestedSessionUuid;
            state.sipCredentials = result.body.sip;
            state.backendRegistered = false;
            state.ownershipState = 'same_tab';
            state.reconnectRequired = false;

            sessionStorage.setItem(
                'voice_session_uuid',
                state.sessionUuid
            );

            window.voiceSipCredentials = result.body.sip;

            elements.extension.value =
                result.body.extension.extension;

            syncFields();
            setStatus('SIP Starting', 'bg-warning');
            startJsSip(state.sipCredentials);
            syncCallControls();
        } catch (error) {
            console.error(error);

            state.reconnectRequired =
                state.ownershipState === 'same_tab';

            log('Reconnect error', {
                message: error.message,
            });

            failVoiceBootMeasurement(error.message);

            setStatus('Reconnect Error', 'bg-danger');
        } finally {
            state.reconnectInFlight = false;
            syncOwnershipControls();
        }
    }

    function startOutboundCall() {
        const ua = state.ua;

        if (!ua) {
            log('Outbound call not started', {
                message: 'JsSIP UA is not available.',
            });
            return;
        }

        if (typeof ua.isRegistered !== 'function'
            || !ua.isRegistered()
            || !state.backendRegistered) {
            log('Outbound call not started', {
                message: 'Voice session is not registered.',
            });
            syncCallControls();
            return;
        }

        if (state.currentRtcSession) {
            log('Outbound call not started', {
                message: 'Another RTCSession is already active.',
            });
            return;
        }

        const rawDestination = elements.destination.value.trim();
        const normalizedDestination = rawDestination.replace(
            /[\s\-()]/g,
            ''
        );

        if (!normalizedDestination) {
            log('Outbound call not started', {
                message: 'Destination is required.',
            });
            return;
        }

        if (!/^\+?\d+$/.test(normalizedDestination)) {
            log('Outbound call not started', {
                message: 'Destination contains invalid characters.',
            });
            return;
        }

        const realm = state.sipCredentials?.realm;

        if (!realm) {
            log('Outbound call not started', {
                message: 'SIP realm is not available.',
            });
            return;
        }

        const target =
            `sip:${normalizedDestination}@${realm}`;

        setCallStatus('Calling', 'bg-warning text-dark');

        log('Starting outbound call', {
            destination: normalizedDestination,
        });

        try {
            ua.call(target, {
                mediaConstraints: {
                    audio: true,
                    video: false,
                },
            });
        } catch (error) {
            console.error(error);

            log('Outbound call error', {
                message: error.message,
            });

            syncCallControls();

            if (!state.currentRtcSession
                && callStatusCanChange()) {
                log('Call Failed');
                state.callStatus = null;
                renderVoiceStatus();
            }
        }
    }

    function answerIncomingCall() {
        const rtcSession = state.currentRtcSession;

        if (!rtcSession
            || state.incomingSession !== rtcSession
            || !state.incomingPending
            || state.incomingAnswered) {
            return;
        }

        log('Answering incoming call');
        stopInboundRingtone();
        state.incomingPending = false;
        syncCallControls();

        try {
            rtcSession.answer({
                mediaConstraints: {
                    audio: true,
                    video: false,
                },
            });
        } catch (error) {
            console.error(error);

            log('Incoming call answer error', {
                message: error.message,
            });

            cleanupCurrentCall(rtcSession);
        }
    }

    function rejectIncomingCall() {
        const rtcSession = state.currentRtcSession;

        if (!rtcSession
            || state.incomingSession !== rtcSession
            || !state.incomingPending
            || state.incomingAnswered) {
            return;
        }

        log('Rejecting incoming call');
        stopInboundRingtone();
        state.incomingPending = false;
        state.localRejectRequested = true;
        syncCallControls();

        try {
            rtcSession.terminate({
                status_code: 486,
                reason_phrase: 'Busy Here',
            });
        } catch (error) {
            console.error(error);

            log('Incoming call rejection error', {
                message: error.message,
            });

            cleanupCurrentCall(rtcSession);
        }
    }

    function toggleMute() {
        const rtcSession = state.currentRtcSession;

        if (!currentCallCanUseMediaControls()
            || state.mutePending) {
            return;
        }

        const shouldMute = !state.isMuted;

        log(
            shouldMute
                ? 'Muting current RTCSession'
                : 'Unmuting current RTCSession'
        );

        state.mutePending = true;
        syncCallControls();

        try {
            if (shouldMute) {
                rtcSession.mute({
                    audio: true,
                    video: false,
                });
            } else {
                rtcSession.unmute({
                    audio: true,
                    video: false,
                });
            }

            if (state.mutePending) {
                state.isMuted = Boolean(
                    rtcSession.isMuted().audio
                );
                state.mutePending = false;

                if (state.isMuted === shouldMute) {
                    log(
                        shouldMute
                            ? 'Call muted'
                            : 'Call unmuted'
                    );
                }
            }
        } catch (error) {
            console.error(error);
            state.mutePending = false;

            log('RTCSession mute toggle error', {
                message: error.message,
            });
        }

        syncCallControls();
    }

    function toggleHold() {
        const rtcSession = state.currentRtcSession;

        if (!currentCallCanUseMediaControls()
            || state.holdPending) {
            return;
        }

        const shouldHold = !state.isOnHold;

        log(
            shouldHold
                ? 'Holding current RTCSession'
                : 'Resuming current RTCSession'
        );

        state.holdPending = true;
        syncCallControls();

        const completeTransition = () => {
            if (state.currentRtcSession !== rtcSession) {
                return;
            }

            state.holdPending = false;
            state.isOnHold = Boolean(
                rtcSession.isOnHold().local
            );
            syncCallControls();
        };

        try {
            const transitionStarted = shouldHold
                ? rtcSession.hold({}, completeTransition)
                : rtcSession.unhold({}, completeTransition);

            if (!transitionStarted) {
                state.holdPending = false;
                state.isOnHold = Boolean(
                    rtcSession.isOnHold().local
                );

                log('RTCSession hold transition was not started');
                syncCallControls();
            }
        } catch (error) {
            console.error(error);
            state.holdPending = false;

            log('RTCSession hold toggle error', {
                message: error.message,
            });

            syncCallControls();
        }
    }

    function hangUpCurrentCall() {
        const rtcSession = state.currentRtcSession;

        if (!rtcSession) {
            return;
        }

        log('Terminating current RTCSession');
        stopRingback();

        try {
            rtcSession.terminate();
        } catch (error) {
            console.error(error);

            log('RTCSession hangup error', {
                message: error.message,
            });
        }
    }

    async function showTakeoverPrompt() {
        log('Showing voice session takeover prompt');

        const result = await Swal.fire({
            icon: 'warning',
            title: 'Voice session already active',
            html: `
                <p>
                    There is already an active voice session for your
                    account in another browser tab or device.
                </p>

                <p class="mb-0">
                    Connecting here will replace the existing voice
                    session.
                </p>
            `,
            showCancelButton: true,
            confirmButtonText: 'Connect Here Instead',
            cancelButtonText: 'Keep Existing Session',
            reverseButtons: true,
            allowOutsideClick: false,
            allowEscapeKey: false,
            focusCancel: true,
        });

        if (!result.isConfirmed) {
            log('Keeping existing voice session');

            elements.start.disabled = true;

            setStatus(
                'Existing Session Kept',
                'bg-secondary'
            );

            return;
        }

        await takeOverVoice();
    }
    async function takeOverVoice() {
        state.reconnectRequired = false;
        syncOwnershipControls();
        elements.start.disabled = true;
        setStatus('Taking Over...', 'bg-warning');
        log('Starting voice session takeover');    

        try {
            const result = await postJson(
                '/voice/session/take-over',
                {
                    browser_tab_id: browserTabId,
                }
            );

            log('Takeover response', {
                status: result.status,
                body: {
                    ...result.body,
                    sip: result.body.sip
                        ? {
                            ...result.body.sip,
                            password: '[hidden]',
                        }
                        : undefined,
                },
            });

            if (!result.body.ok) {
                setStatus(
                    result.body.state ?? 'Takeover Error',
                    'bg-danger'
                );

                return;
            }

            state.sessionUuid =
            result.body.session.session_uuid;

            state.sipCredentials = result.body.sip;
            state.backendRegistered = false;
            state.ownershipState = 'same_tab';
            state.reconnectRequired = false;

            sessionStorage.setItem(
                'voice_session_uuid',
                state.sessionUuid
            );

            window.voiceSipCredentials =
                result.body.sip;

            elements.extension.value =
                result.body.extension.extension;

            elements.start.disabled = true;

            syncFields();          

            setStatus('SIP Starting', 'bg-warning');

            startJsSip(state.sipCredentials);
        } catch (error) {
            console.error(error);

            log('Takeover error', {
                message: error.message,
            });

            setStatus('Takeover Error', 'bg-danger');
        }
    }
    async function startVoice() {
        if (state.ownershipState !== 'none') {
            return;
        }

        beginVoiceBootMeasurement('start');

        elements.start.disabled = true;
        setStatus('Starting...', 'bg-warning');
        log('Starting voice session');

        try {

            const ownership = await postJson(
                '/voice/session/ownership',
                {
                    browser_tab_id: browserTabId,
                }
            );

            if (ownership.body.state === 'other_tab') {
                failVoiceBootMeasurement(
                    'Start stopped because another tab owns the session.'
                );
                state.ownershipState = 'other_tab';
                state.reconnectRequired = false;
                setStatus('Owned Elsewhere', 'bg-danger');
                await showTakeoverPrompt();
                return;
            }

            if (ownership.body.state === 'same_tab') {
                failVoiceBootMeasurement(
                    'Start stopped because this tab already owns a session.'
                );
                state.ownershipState = 'same_tab';
                state.reconnectRequired = !uaIsUsable();
                syncOwnershipControls();
                setStatus(
                    state.reconnectRequired
                        ? 'Reconnect Required'
                        : 'Already Owned Here',
                    state.reconnectRequired
                        ? 'bg-warning text-dark'
                        : 'bg-warning'
                );
                return;
            }

            markVoiceBootStep('backend_request_started');

            const result = await postJson(
                '/voice/session/start',
                {
                    browser_tab_id: browserTabId,
                },
                voiceBootRequestHeaders()
            );

            markVoiceBootStep('backend_response_received');
            attachVoiceBackendTiming(result);

            log('Start response', {
                status: result.status,
                body: {
                    ...result.body,
                    sip: result.body.sip
                        ? {
                            ...result.body.sip,
                            password: '[hidden]',
                        }
                        : undefined,
                },
            });

            if (!result.body.ok) {
                failVoiceBootMeasurement(
                    result.body.message ?? 'Start failed.'
                );
                setStatus(
                    result.body.state ?? 'Error',
                    'bg-danger'
                );
                syncOwnershipControls();
                return;
            }

            state.sessionUuid =
                result.body.session.session_uuid;

            sessionStorage.setItem(
                'voice_session_uuid',
                state.sessionUuid
            );

            window.voiceSipCredentials = result.body.sip;
            state.sipCredentials = result.body.sip; 
            state.backendRegistered = false;
            state.ownershipState = 'same_tab';
            state.reconnectRequired = false;
            elements.extension.value = result.body.extension.extension;

            elements.start.disabled = true;
            syncFields();

            setStatus('SIP Starting', 'bg-warning');
            startJsSip(state.sipCredentials);           
        } catch (error) {
            console.error(error);
            log('Start error', {
                message: error.message,
            });
            failVoiceBootMeasurement(error.message);
            setStatus('Error', 'bg-danger');
            syncOwnershipControls();
        }
    }
    async function revokeVoice() {
        if (!state.sessionUuid) {
            return;
        }
        stopUa();
        setStatus('Revoking...', 'bg-warning');
        log('Revoking voice session');
        
        try {
            const result = await postJson(
                `/voice/session/${state.sessionUuid}/revoke`,
                {
                    browser_tab_id: browserTabId,
                    reason: 'voice_lab_manual_revoke',
                }
            );

            log('Revoke response', result);

            if (result.body.ok) {
                state.sipCredentials = null;
                state.backendRegistered = false;
                state.sessionUuid = null;
                state.ownershipState = 'none';
                state.reconnectRequired = false;
                sessionStorage.removeItem('voice_session_uuid');
                delete window.voiceSipCredentials;
                elements.extension.value = '';
                syncCallControls();
                setStatus('Revoked', 'bg-secondary');
                syncFields();
                return;
            }

            setStatus(
                result.body.state ?? 'Error',
                'bg-danger'
            );
            state.reconnectRequired =
                state.ownershipState === 'same_tab';
            syncOwnershipControls();
        } catch (error) {
            console.error(error);
            log('Revoke error', {
                message: error.message,
            });
            setStatus('Error', 'bg-danger');
            state.reconnectRequired =
                state.ownershipState === 'same_tab';
            syncOwnershipControls();
        }
    }
       
    elements.ownership.addEventListener(
        'click',
        checkOwnership
    );

    elements.start.addEventListener(
        'click',
        startVoice
    );

    elements.reconnect.addEventListener(
        'click',
        reconnectVoice
    );

    elements.revoke.addEventListener(
        'click',
        revokeVoice
    );

    elements.call.addEventListener(
        'click',
        startOutboundCall
    );

    elements.answer.addEventListener(
        'click',
        answerIncomingCall
    );

    elements.reject.addEventListener(
        'click',
        rejectIncomingCall
    );

    elements.hangup.addEventListener(
        'click',
        hangUpCurrentCall
    );

    elements.mute.addEventListener(
        'click',
        toggleMute
    );

    elements.hold.addEventListener(
        'click',
        toggleHold
    );

    elements.copyLog.addEventListener(
        'click',
        copyTechnicalLog
    );

    syncFields();
    syncCallControls();
    log('Voice Lab initialized', {
        browser_tab_id: browserTabId,
    });

    checkOwnership();
});
</script>
@endif
@endpush
@endsection
