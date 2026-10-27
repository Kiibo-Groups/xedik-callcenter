(function (window) {
    'use strict';

    const namespace = window.xedikVoice = window.xedikVoice || {};

    const DEFAULT_RTC_CONFIG = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun2.l.google.com:19302' },
        ],
    };

    class VoiceCallManager {
        constructor(options) {
            this.emitter = namespace.createEmitter();
            this.getUa = options.getUa;
            this.getSipCredentials = options.getSipCredentials;
            this.allowInbound = options.allowInbound !== false;
            this.remoteAudio = options.remoteAudio || null;
            this.ringbackAudio = options.ringbackAudio || null;
            this.ringtoneAudio = options.ringtoneAudio || null;
            this.onCallStateChange = typeof options.onCallStateChange === 'function'
                ? options.onCallStateChange
                : null;
            this.currentSession = null;
            this.direction = null;
            this.status = null;
            this.confirmed = false;
            this.inboundPending = false;
            this.localRejectRequested = false;
            this.muted = false;
            this.onHold = false;
            this.mutePending = false;
            this.holdPending = false;
            this.boundSessions = new WeakSet();
            this.boundPeerConnections = new WeakSet();
            this.sessionCallStates = new Map();
            this.pendingOutboundAttemptId = null;
        }

        on(eventName, listener) {
            return this.emitter.on(eventName, listener);
        }

        emit(eventName, detail) {
            this.emitter.emit(eventName, detail);
        }

        log(message, data) {
            this.emit('log', { message, data: data || null });
        }

        setInboundAllowed(allowed) {
            this.allowInbound = Boolean(allowed);
        }

        snapshot() {
            return {
                session: this.currentSession,
                direction: this.direction,
                status: this.status,
                confirmed: this.confirmed,
                inboundPending: this.inboundPending,
                muted: this.muted,
                onHold: this.onHold,
                mutePending: this.mutePending,
                holdPending: this.holdPending,
            };
        }

        setStatus(status, data) {
            this.status = status;
            this.emit('status', {
                status,
                direction: this.direction,
                attempt_id: this.currentSession?._xedikOutboundAttemptId ?? null,
                sip_call_id: this.sipCallId(this.currentSession),
                ...data,
            });
            this.emit('controls', this.snapshot());
        }

        reportSessionCallState(session, state, source) {
            if (!session) return;

            if (state === 'no_call') {
                this.sessionCallStates.delete(session);
            } else {
                this.sessionCallStates.set(session, state);
            }

            const aggregate = this.aggregateCallState();
            const detail = {
                attempt_id: session?._xedikOutboundAttemptId ?? null,
                sip_call_id: this.sipCallId(session),
                state: aggregate,
                source,
                activeSessions: this.sessionCallStates.size,
            };
            this.emit('call-state', detail);
            this.onCallStateChange?.(aggregate, detail);
        }

        aggregateCallState() {
            if (this.sessionCallStates.size === 0) return 'no_call';

            const states = new Set(this.sessionCallStates.values());
            for (const state of [
                'active', 'held', 'ringing', 'connecting', 'ending',
            ]) {
                if (states.has(state)) return state;
            }

            return 'no_call';
        }

        normalizeDestination(destination) {
            const raw = String(destination || '').trim();

            if (!raw) {
                throw new Error('Destination is required.');
            }

            const normalized = raw.replace(/[\s\-()]/g, '');

            if (!/^\+?\d+$/.test(normalized)) {
                throw new Error('Destination contains unsupported characters.');
            }

            return normalized;
        }

        call(destination, options = {}) {
            const ua = this.getUa();
            const credentials = this.getSipCredentials();

            if (!ua || typeof ua.isRegistered !== 'function'
                || !ua.isRegistered()) {
                throw new Error('Voice transport is not registered.');
            }

            if (this.currentSession) {
                throw new Error('Another call is already active.');
            }

            const normalized = this.normalizeDestination(destination);
            const target = `sip:${normalized}@${credentials.realm}`;
            const attemptId = options.attemptId || null;

            this.log('Starting outbound RTCSession', {
                destination: normalized,
                direction: 'outgoing',
                attempt_id: attemptId,
            });

            this.pendingOutboundAttemptId = attemptId;
            try {
                if (this.remoteAudio) {
                    this.remoteAudio.muted = false;
                    this.remoteAudio.volume = 1.0;
                    this.remoteAudio.play().catch(() => {});
                }

                return ua.call(target, {
                    mediaConstraints: {
                        audio: true,
                        video: false,
                    },
                    pcConfig: DEFAULT_RTC_CONFIG,
                    extraHeaders: options.extraHeaders ?? [],
                });
            } catch (error) {
                this.pendingOutboundAttemptId = null;
                throw error;
            }
        }

        handleNewRtcSession(event) {
            const session = event?.session;
            const originator = event?.originator ?? null;

            if (!session) {
                this.log('JsSIP new RTCSession missing session', {
                    originator,
                });
                return;
            }

            const incoming = originator === 'remote'
                || session.direction === 'incoming';

            if (this.currentSession && this.currentSession !== session) {
                this.log(
                    incoming
                        ? 'Incoming call rejected because another RTCSession is active'
                        : 'Second RTCSession rejected',
                    {
                        originator,
                        direction: session.direction ?? null,
                    }
                );
                this.terminateBusy(session);
                this.emit('inbound-rejected', {
                    reason: 'active_session',
                });
                return;
            }

            if (incoming && !this.allowInbound) {
                this.log('Inbound RTCSession auto-rejected by outbound-only policy', {
                    originator,
                    direction: 'incoming',
                });
                this.terminateBusy(session);
                this.emit('inbound-rejected', {
                    reason: 'outbound_only',
                });
                return;
            }

            this.resetMediaState();
            this.currentSession = session;
            this.direction = incoming ? 'incoming' : 'outgoing';
            this.inboundPending = incoming;
            if (!incoming) {
                session._xedikOutboundAttemptId = this.pendingOutboundAttemptId || null;
                this.pendingOutboundAttemptId = null;
            }
            this.bindSession(session, originator);

            if (incoming) {
                this.reportSessionCallState(
                    session,
                    'ringing',
                    'newRTCSession:incoming'
                );
                const caller = this.callerDetails(session, event);
                this.log('JsSIP incoming RTCSession', {
                    originator,
                    direction: 'incoming',
                    caller_display_name: caller.displayName,
                    caller_user: caller.user,
                    queue_name: caller.acdMetadata?.queue_name || null,
                    asterisk_linkedid: caller.acdMetadata?.asterisk_linkedid || null,
                });
                this.emit('incoming', { session, caller });
                this.setStatus('Incoming Call');
                this.startTone(this.ringtoneAudio, 'Inbound ringtone');
            } else {
                this.reportSessionCallState(
                    session,
                    'connecting',
                    'newRTCSession:outgoing'
                );
                this.log('JsSIP new RTCSession', {
                    attempt_id: session._xedikOutboundAttemptId || null,
                    sip_call_id: this.sipCallId(session, event),
                    originator,
                    direction: session.direction ?? 'outgoing',
                });
                this.emit('created', {
                    session,
                    direction: 'outgoing',
                    attempt_id: session._xedikOutboundAttemptId || null,
                    sip_call_id: this.sipCallId(session, event),
                });
                this.setStatus('Calling');
            }
        }

        terminateBusy(session) {
            try {
                session.terminate({
                    status_code: 486,
                    reason_phrase: 'Busy Here',
                });
            } catch (error) {
                this.log('RTCSession busy rejection failed', {
                    message: namespace.safeError(error),
                });
            }
        }

        bindSession(session, originator) {
            if (this.boundSessions.has(session)) {
                return;
            }

            this.boundSessions.add(session);
            this.attachRemoteAudio(session);

            session.on('peerconnection', event => {
                this.bindPeerConnection(
                    event?.peerconnection || session.connection
                );
            });
            session.on('addstream', event => {
                this.attachRemoteStream(event?.stream);
            });
            session.on('connecting', event => {
                this.log('RTCSession connecting', this.eventData(
                    session,
                    event,
                    originator
                ));
                if (this.direction === 'outgoing') {
                    this.reportSessionCallState(
                        session,
                        'connecting',
                        'connecting'
                    );
                    this.setStatus('Calling');
                }
            });
            session.on('progress', event => {
                this.log('RTCSession progress', this.eventData(
                    session,
                    event,
                    event?.originator ?? originator
                ));
                if (this.direction === 'outgoing') {
                    this.reportSessionCallState(
                        session,
                        'connecting',
                        'progress'
                    );
                    const statusCode = event?.response?.status_code ?? null;
                    this.setStatus('Ringing', { progress_code: statusCode });
                    if (statusCode === 180 && !this.remoteAudioIsPlayable()) {
                        this.startTone(this.ringbackAudio, 'Outbound ringback');
                    }
                    this.attachRemoteAudio(session);
                }
            });
            session.on('accepted', event => {
                this.log('RTCSession accepted', this.eventData(
                    session,
                    event,
                    event?.originator ?? originator
                ));
                this.inboundPending = false;
                this.confirmed = true;
                this.reportSessionCallState(session, 'active', 'accepted');
                this.stopTones();
                this.attachRemoteAudio(session);
                this.setStatus('In Call');
            });
            session.on('confirmed', event => {
                this.log('RTCSession confirmed', this.eventData(
                    session,
                    event,
                    event?.originator ?? originator
                ));
                this.inboundPending = false;
                this.confirmed = true;
                this.reportSessionCallState(session, 'active', 'confirmed');
                this.stopTones();
                this.attachRemoteAudio(session);
                this.setStatus('In Call');
            });
            session.on('muted', event => {
                if (event?.audio !== false) {
                    this.muted = true;
                }
                this.mutePending = false;
                this.log('Call muted');
                this.emit('mute', this.snapshot());
                this.emit('controls', this.snapshot());
            });
            session.on('unmuted', event => {
                if (event?.audio !== false) {
                    this.muted = false;
                }
                this.mutePending = false;
                this.log('Call unmuted');
                this.emit('mute', this.snapshot());
                this.emit('controls', this.snapshot());
            });
            session.on('hold', event => {
                if (event?.originator === 'local' || !event?.originator) {
                    this.onHold = true;
                    this.holdPending = false;
                    this.log('Call held');
                    this.reportSessionCallState(session, 'held', 'hold');
                    this.setStatus('On Hold');
                    this.emit('hold', this.snapshot());
                }
            });
            session.on('unhold', event => {
                if (event?.originator === 'local' || !event?.originator) {
                    this.onHold = false;
                    this.holdPending = false;
                    this.log('Call resumed');
                    this.reportSessionCallState(session, 'active', 'unhold');
                    this.setStatus('In Call');
                    this.emit('hold', this.snapshot());
                }
            });
            session.on('ended', event => {
                this.log('RTCSession ended', this.eventData(
                    session,
                    event,
                    event?.originator ?? originator
                ));
                this.finish(session, 'Ended', event);
            });
            session.on('failed', event => {
                this.log('RTCSession failed', this.eventData(
                    session,
                    event,
                    event?.originator ?? originator
                ));
                const locallyRejected = this.localRejectRequested
                    && this.direction === 'incoming'
                    && event?.originator === 'local'
                    && event?.cause === 'Rejected';

                this.finish(
                    session,
                    locallyRejected ? 'Rejected' : 'Failed',
                    event
                );
            });
        }

        eventData(session, event, fallbackOriginator) {
            return {
                attempt_id: session?._xedikOutboundAttemptId ?? null,
                sip_call_id: this.sipCallId(session, event),
                originator: event?.originator ?? fallbackOriginator ?? null,
                cause: event?.cause ?? null,
                status_code: event?.response?.status_code ?? null,
                reason_phrase: event?.response?.reason_phrase ?? null,
                direction: session?.direction ?? this.direction,
            };
        }

        sipCallId(session, event = null) {
            return event?.request?.call_id
                ?? event?.request?.callId
                ?? event?.message?.call_id
                ?? event?.message?.callId
                ?? event?.response?.call_id
                ?? event?.response?.callId
                ?? session?.request?.call_id
                ?? session?.request?.callId
                ?? session?._request?.call_id
                ?? session?._request?.callId
                ?? session?.id
                ?? null;
        }

        answer() {
            const session = this.currentSession;

            if (!session || this.direction !== 'incoming'
                || !this.inboundPending) {
                throw new Error('There is no pending incoming call.');
            }

            this.log('Answering incoming call');
            this.stopTones();
            this.inboundPending = false;

            if (this.remoteAudio) {
                this.remoteAudio.muted = false;
                this.remoteAudio.volume = 1.0;
                this.remoteAudio.play().catch(() => {});
            }

            session.answer({
                mediaConstraints: {
                    audio: true,
                    video: false,
                },
                pcConfig: DEFAULT_RTC_CONFIG,
            });
            this.emit('controls', this.snapshot());
        }

        reject() {
            const session = this.currentSession;

            if (!session || this.direction !== 'incoming'
                || !this.inboundPending) {
                throw new Error('There is no pending incoming call.');
            }

            this.localRejectRequested = true;
            this.inboundPending = false;
            this.log('Rejecting incoming call');
            this.stopTones();
            session.terminate({
                status_code: 486,
                reason_phrase: 'Busy Here',
            });
            this.emit('controls', this.snapshot());
        }

        hangUp() {
            if (!this.currentSession) {
                return;
            }

            this.log('Terminating current RTCSession');
            this.stopTones();
            this.currentSession.terminate();
        }

        toggleMute() {
            const session = this.requireEstablishedCall();

            if (this.mutePending) {
                return;
            }

            this.mutePending = true;
            this.emit('controls', this.snapshot());

            try {
                if (this.muted) {
                    this.log('Unmuting current RTCSession');
                    session.unmute({ audio: true, video: false });
                } else {
                    this.log('Muting current RTCSession');
                    session.mute({ audio: true, video: false });
                }

                window.setTimeout(() => {
                    if (!this.mutePending) return;
                    this.muted = !this.muted;
                    this.mutePending = false;
                    this.emit('mute', this.snapshot());
                    this.emit('controls', this.snapshot());
                }, 0);
            } catch (error) {
                this.mutePending = false;
                this.emit('controls', this.snapshot());
                throw error;
            }
        }

        toggleHold() {
            const session = this.requireEstablishedCall();

            if (this.holdPending) {
                return;
            }

            this.holdPending = true;
            this.emit('controls', this.snapshot());

            try {
                this.log(
                    this.onHold
                        ? 'Resuming current RTCSession'
                        : 'Holding current RTCSession'
                );
                const accepted = this.onHold
                    ? session.unhold()
                    : session.hold();

                if (accepted === false) {
                    this.holdPending = false;
                    this.emit('controls', this.snapshot());
                    throw new Error('JsSIP rejected the hold transition.');
                }
            } catch (error) {
                this.holdPending = false;
                this.emit('controls', this.snapshot());
                throw error;
            }
        }

        requireEstablishedCall() {
            const session = this.currentSession;

            if (!session || !this.confirmed
                || (typeof session.isEnded === 'function'
                    && session.isEnded())) {
                throw new Error('There is no established call.');
            }

            return session;
        }

        finish(session, result, event) {
            this.reportSessionCallState(
                session,
                'no_call',
                result === 'Ended' ? 'ended' : 'failed'
            );

            if (session !== this.currentSession) {
                return;
            }

            this.stopTones();
            this.clearRemoteAudio();
            this.log(
                result === 'Rejected'
                    ? 'Call Rejected'
                    : `Call ${result}`
            );
            this.emit('finished', {
                result,
                attempt_id: session?._xedikOutboundAttemptId ?? null,
                sip_call_id: this.sipCallId(session, event),
                cause:         event?.cause ?? null,
                originator:    event?.originator ?? null,
                direction:     this.direction,
                // SIP response codes — safe fields only (no SDP, no headers, no auth).
                // Priority: event.message first (JsSIP incoming), then event.response.
                status_code:   event?.message?.status_code
                               ?? event?.response?.status_code
                               ?? null,
                reason_phrase: event?.message?.reason_phrase
                               ?? event?.response?.reason_phrase
                               ?? null,
            });
            this.cleanup(false);
        }

        cleanup(terminate) {
            const session = this.currentSession;

            this.stopTones();

            if (terminate && session) {
                try {
                    session.terminate();
                } catch (error) {
                    this.log('RTCSession cleanup termination failed', {
                        message: namespace.safeError(error),
                    });
                }
                this.reportSessionCallState(
                    session,
                    'no_call',
                    'cleanup'
                );
            }

            this.clearRemoteAudio();
            this.currentSession = null;
            this.direction = null;
            this.status = null;
            this.resetMediaState();
            this.emit('cleanup', this.snapshot());
            this.emit('controls', this.snapshot());
        }

        resetMediaState() {
            this.confirmed = false;
            this.inboundPending = false;
            this.localRejectRequested = false;
            this.muted = false;
            this.onHold = false;
            this.mutePending = false;
            this.holdPending = false;
        }

        attachRemoteAudio(session) {
            const pc = session?.connection;
            if (!pc) return;
            this.bindPeerConnection(pc);

            try {
                const receivers = pc.getReceivers?.() || [];
                const audioReceiver = receivers.find(r => r.track && r.track.kind === 'audio');
                if (audioReceiver && audioReceiver.track) {
                    const stream = new MediaStream([audioReceiver.track]);
                    this.attachRemoteStream(stream);
                }
            } catch (err) {
                // Ignore receiver inspection error
            }
        }

        bindPeerConnection(peerConnection) {
            if (!peerConnection) {
                return;
            }

            if (!this.boundPeerConnections.has(peerConnection)) {
                this.boundPeerConnections.add(peerConnection);
                peerConnection.addEventListener?.('track', event => {
                    const stream = event?.streams?.[0] || (event.track ? new MediaStream([event.track]) : null);
                    this.attachRemoteStream(stream);
                });
                peerConnection.addEventListener?.('addstream', event => {
                    this.attachRemoteStream(event?.stream);
                });
            }

            try {
                const receivers = peerConnection.getReceivers?.() || [];
                const audioReceiver = receivers.find(r => r.track && r.track.kind === 'audio');
                if (audioReceiver && audioReceiver.track) {
                    this.attachRemoteStream(new MediaStream([audioReceiver.track]));
                }
            } catch (err) {
                // Ignore receiver inspection error
            }
        }

        attachRemoteStream(stream) {
            if (!stream || !this.remoteAudio) {
                return;
            }

            try {
                const audioTracks = typeof stream.getAudioTracks === 'function' ? stream.getAudioTracks() : [];
                audioTracks.forEach(track => {
                    track.enabled = true;
                });
            } catch (_) {}

            this.remoteAudio.srcObject = stream;
            this.remoteAudio.muted = false;
            this.remoteAudio.volume = 1.0;
            const playPromise = this.remoteAudio.play();

            if (playPromise !== undefined) {
                playPromise.catch(error => {
                    if (error?.name !== 'AbortError') {
                        this.log('Remote audio play rejected, attaching user interaction unlocker', {
                            message: namespace.safeError(error),
                        });
                        const unlock = () => {
                            this.remoteAudio.play().catch(() => {});
                            document.removeEventListener('click', unlock);
                        };
                        document.addEventListener('click', unlock, { once: true });
                    }
                });
            }
        }

        remoteAudioIsPlayable() {
            const stream = this.remoteAudio?.srcObject;

            return Boolean(
                stream
                && typeof stream.getAudioTracks === 'function'
                && stream.getAudioTracks().some(track =>
                    track.readyState === 'live'
                )
            );
        }

        clearRemoteAudio() {
            if (!this.remoteAudio) return;
            this.remoteAudio.pause();
            this.remoteAudio.srcObject = null;
            this.remoteAudio.removeAttribute('src');
            this.remoteAudio.load();
        }

        startTone(audio, label) {
            if (!audio) return;
            audio.loop = true;
            audio.currentTime = 0;
            audio.play()?.catch(error => {
                this.log(`${label} play rejected`, {
                    message: namespace.safeError(error),
                });
            });
        }

        stopTone(audio) {
            if (!audio) return;
            audio.pause();
            try {
                audio.currentTime = 0;
            } catch (error) {
                // Media may not have loaded. Cleanup remains complete.
            }
        }

        stopTones() {
            this.stopTone(this.ringbackAudio);
            this.stopTone(this.ringtoneAudio);
        }

        callerDetails(session, event = null) {
            const identity = session?.remote_identity;
            const displayName = this.safeCallerPart(identity?.display_name);
            const user = this.safeCallerPart(identity?.uri?.user);
            const acdMetadata = this.inboundAcdMetadata(session, event, user);

            return {
                displayName,
                user,
                label: displayName || acdMetadata.ani || user || 'Unknown caller',
                acdMetadata,
            };
        }

        inboundAcdMetadata(session, event = null, remoteUser = null) {
            const request = event?.request
                || session?.request
                || session?._request
                || session?.incomingInviteRequest
                || null;

            const ani = this.safeCallerPart(
                this.sipHeader(request, 'X-xedik-ANI') || remoteUser
            );

            return {
                direction: 'inbound',
                ani,
                dnis: this.safeCallerPart(this.sipHeader(request, 'X-xedik-DNIS')),
                queue_name: this.safeQueuePart(this.sipHeader(request, 'X-xedik-QUEUE')),
                asterisk_linkedid: this.safeLinkedidPart(this.sipHeader(request, 'X-xedik-LINKEDID')),
                sip_call_id: this.safeSipCallIdPart(this.sipHeader(request, 'Call-ID') || request?.call_id),
            };
        }

        sipHeader(request, name) {
            if (!request || !name) return null;

            if (typeof request.getHeader === 'function') {
                const exact = request.getHeader(name);
                if (exact) return exact;

                const lower = request.getHeader(name.toLowerCase());
                if (lower) return lower;
            }

            const headers = request.headers || request._headers || null;
            if (headers && typeof headers === 'object') {
                const wanted = name.toLowerCase();
                for (const [key, value] of Object.entries(headers)) {
                    if (String(key).toLowerCase() !== wanted) continue;
                    if (Array.isArray(value)) {
                        const first = value[0];
                        return first?.raw || first?.value || first || null;
                    }
                    return value?.raw || value?.value || value || null;
                }
            }

            return null;
        }

        safeQueuePart(value) {
            const cleaned = this.safeCallerPart(value);
            return cleaned && /^[a-z0-9_]{1,128}$/.test(cleaned) ? cleaned : null;
        }

        safeLinkedidPart(value) {
            const cleaned = this.safeCallerPart(value);
            return cleaned && /^[0-9]+(?:\.[0-9]+)?$/.test(cleaned) ? cleaned : null;
        }

        safeSipCallIdPart(value) {
            if (typeof value !== 'string') return null;
            const cleaned = value.replace(/[\x00-\x1f\x7f<>]/g, '').trim();
            return cleaned ? cleaned.slice(0, 255) : null;
        }

        safeCallerPart(value) {
            if (typeof value !== 'string') return null;
            const cleaned = value.replace(/[\x00-\x1f\x7f<>]/g, '').trim();
            return cleaned ? cleaned.slice(0, 120) : null;
        }
    }

    namespace.VoiceCallManager = VoiceCallManager;
})(window);
