(function (window, document) {
    'use strict';

    const namespace = window.xedikVoice = window.xedikVoice || {};

    class VoiceLabAdapter {
        constructor(options) {
            this.options = options;
            this.elements = this.findElements();
            this.voiceSessionStatus = 'Disconnected';
            this.voiceSessionClass = 'bg-secondary';
            this.callStatus = null;
            this.callStatusClass = 'bg-secondary';
            this.ownershipState = null;
            this.lastCallSnapshot = null;
            this.copyTimer = null;
            this.api = new namespace.VoiceApiClient({
                csrfToken: options.csrfToken,
            });
            this.manager = new namespace.VoiceSessionManager({
                apiClient: this.api,
                allowInbound: true,
                remoteAudio: this.elements.remoteAudio,
                ringbackAudio: this.elements.ringbackAudio,
                ringtoneAudio: this.elements.ringtoneAudio,
            });
        }

        findElements() {
            const byId = id => document.getElementById(id);
            return {
                status: byId('voice-lab-status'),
                browserTabId: byId('voice-browser-tab-id'),
                sessionUuid: byId('voice-session-uuid'),
                extension: byId('voice-extension'),
                destination: byId('voice-destination'),
                incomingPanel: byId('voice-incoming-panel'),
                incomingCaller: byId('voice-incoming-caller'),
                answer: byId('voice-answer'),
                reject: byId('voice-reject'),
                ownership: byId('voice-check-ownership'),
                start: byId('voice-start'),
                reconnect: byId('voice-reconnect'),
                revoke: byId('voice-revoke'),
                call: byId('voice-call'),
                mute: byId('voice-mute'),
                hold: byId('voice-hold'),
                hangup: byId('voice-hangup'),
                remoteAudio: byId('voice-remote-audio'),
                ringbackAudio: byId('voice-ringback-audio'),
                ringtoneAudio: byId('voice-inbound-ringtone-audio'),
                log: byId('voice-lab-log'),
                copyLog: byId('voice-copy-log'),
            };
        }

        init() {
            this.bindManager();
            this.bindDom();
            this.elements.browserTabId.value = this.api.browserTabId;
            this.syncFields();
            this.syncControls();
            this.log('Voice Lab initialized', {
                browser_tab_id: this.api.browserTabId,
            });
            this.checkOwnership();
            return this;
        }

        bindManager() {
            this.manager.on('log', entry => {
                this.log(entry.message, entry.data);
            });
            this.manager.on('credentials', detail => {
                window.voiceSipCredentials = detail.sip;
                this.elements.extension.value =
                    detail.extension?.extension || '';
                this.syncFields();
                this.syncControls();
            });
            this.manager.on('ownership', detail => {
                this.ownershipState = detail.state;
                if (detail.state === 'none') {
                    this.setSessionStatus('No Session', 'bg-secondary');
                } else if (detail.state === 'same_tab') {
                    this.setSessionStatus(
                        this.manager.isReady()
                            ? 'Active'
                            : 'Reconnect Required',
                        this.manager.isReady()
                            ? 'bg-success'
                            : 'bg-warning text-dark'
                    );
                } else if (detail.state === 'other_tab') {
                    this.setSessionStatus('Owned Elsewhere', 'bg-danger');
                }
                this.syncFields();
                this.syncControls();
            });
            this.manager.on('takeover-required', () => {
                this.showTakeoverPrompt();
            });
            this.manager.on('session-status', detail => {
                const styles = {
                    Connecting: 'bg-warning text-dark',
                    Registered: 'bg-success',
                    Active: 'bg-success',
                    'Session Replaced': 'bg-danger',
                    'Registration Failed': 'bg-danger',
                    Disconnected: 'bg-danger',
                    Revoked: 'bg-secondary',
                };
                this.setSessionStatus(
                    detail.status,
                    styles[detail.status] || 'bg-secondary'
                );
                this.syncControls();
            });
            this.manager.on('ready', () => {
                this.setSessionStatus('Registered', 'bg-success');
                this.syncControls();
            });
            this.manager.on('heartbeat', () => {
                this.setSessionStatus('Active', 'bg-success');
            });
            this.manager.on('replaced', () => {
                delete window.voiceSipCredentials;
                this.elements.extension.value = '';
                this.callStatus = null;
                this.setSessionStatus('Session Replaced', 'bg-danger');
                this.syncFields();
                this.syncControls();
            });
            this.manager.on('revoked', () => {
                delete window.voiceSipCredentials;
                this.elements.extension.value = '';
                this.ownershipState = 'none';
                this.callStatus = null;
                this.setSessionStatus('Revoked', 'bg-secondary');
                this.syncFields();
                this.syncControls();
            });
            this.manager.on('unavailable', detail => {
                this.log('Voice unavailable', {
                    message: detail.message,
                    operation: detail.operation,
                });
                this.syncControls();
            });
            this.manager.on('performance', detail => {
                this.log('Voice startup performance', detail);
            });
            this.manager.on('performance-failed', detail => {
                this.log('Voice startup performance failed', detail);
            });
            this.manager.on('call:log', entry => {
                this.log(entry.message, entry.data);
            });
            this.manager.on('call:status', detail => {
                const styles = {
                    'Incoming Call': 'bg-warning text-dark',
                    Calling: 'bg-warning text-dark',
                    Ringing: 'bg-warning text-dark',
                    'In Call': 'bg-success',
                    'On Hold': 'bg-warning text-dark',
                };
                this.callStatus = detail.status;
                this.callStatusClass = styles[detail.status]
                    || 'bg-secondary';
                this.renderStatus();
                this.syncControls();
            });
            this.manager.on('call:incoming', detail => {
                this.elements.incomingCaller.textContent =
                    `Caller: ${detail.caller.label}`;
                this.syncControls();
            });
            this.manager.on('call:controls', snapshot => {
                this.lastCallSnapshot = snapshot;
                this.syncControls();
            });
            this.manager.on('call:mute', snapshot => {
                this.lastCallSnapshot = snapshot;
                this.syncControls();
            });
            this.manager.on('call:hold', snapshot => {
                this.lastCallSnapshot = snapshot;
                this.syncControls();
            });
            this.manager.on('call:finished', detail => {
                this.log(
                    detail.result === 'Rejected'
                        ? 'Call Rejected'
                        : `Call ${detail.result}`,
                    {
                        cause: detail.cause,
                        direction: detail.direction,
                    }
                );
            });
            this.manager.on('call:cleanup', () => {
                this.callStatus = null;
                this.lastCallSnapshot = null;
                this.renderStatus();
                this.syncControls();
            });
        }

        bindDom() {
            this.elements.ownership.addEventListener(
                'click',
                () => this.checkOwnership()
            );
            this.elements.start.addEventListener(
                'click',
                () => this.start()
            );
            this.elements.reconnect.addEventListener(
                'click',
                () => this.reconnect()
            );
            this.elements.revoke.addEventListener(
                'click',
                () => this.revoke()
            );
            this.elements.call.addEventListener('click', () => {
                try {
                    this.manager.callManager.call(
                        this.elements.destination.value
                    );
                } catch (error) {
                    this.log('Outbound call error', {
                        message: namespace.safeError(error),
                    });
                    this.callStatus = null;
                    this.syncControls();
                }
            });
            this.elements.answer.addEventListener('click', () => {
                this.runCallAction('Answer error', () =>
                    this.manager.callManager.answer()
                );
            });
            this.elements.reject.addEventListener('click', () => {
                this.runCallAction('Reject error', () =>
                    this.manager.callManager.reject()
                );
            });
            this.elements.hangup.addEventListener('click', () => {
                this.runCallAction('Hangup error', () =>
                    this.manager.callManager.hangUp()
                );
            });
            this.elements.mute.addEventListener('click', () => {
                this.runCallAction('Mute error', () =>
                    this.manager.callManager.toggleMute()
                );
            });
            this.elements.hold.addEventListener('click', () => {
                this.runCallAction('Hold error', () =>
                    this.manager.callManager.toggleHold()
                );
            });
            this.elements.copyLog.addEventListener(
                'click',
                () => this.copyTechnicalLog()
            );
        }

        runCallAction(label, action) {
            try {
                action();
            } catch (error) {
                this.log(label, {
                    message: namespace.safeError(error),
                });
            }
        }

        async checkOwnership() {
            this.setSessionStatus('Checking...', 'bg-warning text-dark');
            try {
                const body = await this.manager.checkOwnership();
                if (body.state === 'other_tab') {
                    await this.showTakeoverPrompt();
                }
            } catch (error) {
                this.setSessionStatus('Error', 'bg-danger');
                this.log('Ownership error', {
                    message: namespace.safeError(error),
                });
            }
        }

        async start() {
            this.elements.start.disabled = true;
            try {
                await this.manager.start();
                await this.manager.waitUntilReady();
            } catch (error) {
                this.log('Start error', {
                    message: namespace.safeError(error),
                });
                this.setSessionStatus('Start Failed', 'bg-danger');
            }
            this.syncControls();
        }

        async reconnect() {
            this.elements.reconnect.disabled = true;
            try {
                await this.manager.reconnect();
                await this.manager.waitUntilReady();
            } catch (error) {
                this.log('Reconnect error', {
                    message: namespace.safeError(error),
                });
                if (error?.status === 409) {
                    await this.checkOwnership();
                } else {
                    this.setSessionStatus(
                        'Reconnect Required',
                        'bg-warning text-dark'
                    );
                }
            }
            this.syncControls();
        }

        async revoke() {
            try {
                await this.manager.revoke();
            } catch (error) {
                this.log('Revoke error', {
                    message: namespace.safeError(error),
                });
                this.setSessionStatus('Revoke Failed', 'bg-danger');
            }
            this.syncControls();
        }

        async showTakeoverPrompt() {
            if (!window.Swal) return;
            const result = await window.Swal.fire({
                title: 'Voice Session Already Active',
                text: 'Keep the existing session or connect in this tab instead.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Connect Here Instead',
                cancelButtonText: 'Keep Existing Session',
                reverseButtons: true,
            });

            if (!result.isConfirmed) return;

            try {
                await this.manager.takeOver(true);
                await this.manager.waitUntilReady();
            } catch (error) {
                this.log('Takeover error', {
                    message: namespace.safeError(error),
                });
                this.setSessionStatus('Takeover Failed', 'bg-danger');
            }
        }

        setSessionStatus(status, className) {
            this.voiceSessionStatus = status;
            this.voiceSessionClass = className;
            this.renderStatus();
        }

        renderStatus() {
            const critical = [
                'Session Replaced',
                'Disconnected',
                'Registration Failed',
            ].includes(this.voiceSessionStatus);
            const label = critical || !this.callStatus
                ? this.voiceSessionStatus
                : this.callStatus;
            const className = critical || !this.callStatus
                ? this.voiceSessionClass
                : this.callStatusClass;

            this.elements.status.textContent = label;
            this.elements.status.className = `badge ${className}`;
        }

        syncFields() {
            this.elements.browserTabId.value = this.api.browserTabId;
            this.elements.sessionUuid.value =
                this.manager.sessionUuid || '';
        }

        syncControls() {
            const state = this.manager.state();
            const call = this.manager.callManager.snapshot();
            const critical = [
                'Session Replaced',
                'Disconnected',
                'Registration Failed',
            ].includes(this.voiceSessionStatus);
            const hasCall = Boolean(call.session);
            const incomingPending = hasCall
                && call.direction === 'incoming'
                && call.inboundPending;
            const established = hasCall && call.confirmed && !critical;

            this.elements.start.disabled =
                this.ownershipState !== 'none';
            this.elements.reconnect.disabled = !(
                this.ownershipState === 'same_tab'
                && !state.ready
            );
            this.elements.call.disabled = !(
                state.ready && !hasCall && !critical
            );
            this.elements.answer.disabled = !incomingPending || critical;
            this.elements.reject.disabled = !incomingPending || critical;
            this.elements.hangup.disabled = !(
                hasCall && !incomingPending && !critical
            );
            this.elements.mute.disabled = !established || call.mutePending;
            this.elements.hold.disabled = !established || call.holdPending;
            this.elements.incomingPanel.hidden = !incomingPending;

            this.syncToggleButton(
                this.elements.mute,
                call.muted,
                'bi-mic-fill',
                'Unmute',
                'bi-mic-mute-fill',
                'Mute',
                '[data-voice-mute-label]'
            );
            this.syncToggleButton(
                this.elements.hold,
                call.onHold,
                'bi-play-circle-fill',
                'Resume',
                'bi-pause-circle-fill',
                'Hold',
                '[data-voice-hold-label]'
            );
            this.syncFields();
        }

        syncToggleButton(
            button,
            active,
            activeIcon,
            activeText,
            idleIcon,
            idleText,
            labelSelector
        ) {
            button.querySelector('i').className =
                `bi ${active ? activeIcon : idleIcon}`;
            button.querySelector(labelSelector).textContent =
                active ? activeText : idleText;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', String(active));
        }

        scrub(value) {
            if (Array.isArray(value)) return value.map(item => this.scrub(item));
            if (!value || typeof value !== 'object') return value;
            return Object.fromEntries(Object.entries(value).map(([key, item]) => [
                key,
                /password|secret|authorization/i.test(key)
                    ? '[hidden]'
                    : this.scrub(item),
            ]));
        }

        log(message, data) {
            const timestamp = new Date().toISOString();
            const suffix = data === null || data === undefined
                ? ''
                : ` ${JSON.stringify(this.scrub(data))}`;
            this.elements.log.textContent +=
                `[${timestamp}] ${message}${suffix}\n`;
            this.elements.log.scrollTop = this.elements.log.scrollHeight;
        }

        async copyTechnicalLog() {
            const text = this.elements.log.textContent;
            if (!text) {
                this.copyFeedback('Log is empty', false);
                return;
            }

            try {
                if (window.navigator.clipboard?.writeText) {
                    await window.navigator.clipboard.writeText(text);
                } else {
                    this.copyFallback(text);
                }
                this.copyFeedback('Copied', true);
            } catch (error) {
                try {
                    this.copyFallback(text);
                    this.copyFeedback('Copied', true);
                } catch (fallbackError) {
                    this.log('Technical log copy failed', {
                        message: namespace.safeError(fallbackError),
                    });
                    this.copyFeedback('Copy Failed', false);
                }
            }
        }

        copyFallback(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            const copied = document.execCommand('copy');
            textarea.remove();
            if (!copied) throw new Error('Copy command failed.');
        }

        copyFeedback(text, success) {
            window.clearTimeout(this.copyTimer);
            const icon = success ? 'bi-check2' : 'bi-clipboard-x';
            this.elements.copyLog.innerHTML =
                `<i class="bi ${icon}" aria-hidden="true"></i> ${text}`;
            this.elements.copyLog.classList.toggle('is-success', success);
            this.elements.copyLog.classList.toggle('is-error', !success);
            this.copyTimer = window.setTimeout(() => {
                this.elements.copyLog.innerHTML =
                    '<i class="bi bi-clipboard" aria-hidden="true"></i> Copy Log';
                this.elements.copyLog.classList.remove(
                    'is-success',
                    'is-error'
                );
            }, 1500);
        }
    }

    namespace.VoiceLabAdapter = VoiceLabAdapter;
})(window, document);
