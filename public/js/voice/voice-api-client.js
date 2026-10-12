(function (window) {
    'use strict';

    const namespace = window.xedikVoice = window.xedikVoice || {};

    function uuid() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(
            /[xy]/g,
            character => {
                const random = Math.random() * 16 | 0;
                const value = character === 'x'
                    ? random
                    : (random & 0x3 | 0x8);

                return value.toString(16);
            }
        );
    }

    function createEmitter() {
        const listeners = new Map();

        return {
            on(eventName, listener) {
                if (!listeners.has(eventName)) {
                    listeners.set(eventName, new Set());
                }

                listeners.get(eventName).add(listener);

                return () => listeners.get(eventName)?.delete(listener);
            },

            emit(eventName, detail) {
                listeners.get(eventName)?.forEach(listener => {
                    try {
                        listener(detail);
                    } catch (error) {
                        window.console.error(error);
                    }
                });
            },
        };
    }

    function safeError(error) {
        return error instanceof Error
            ? error.message
            : String(error || 'Unknown error');
    }

    class VoiceApiClient {
        constructor(options) {
            this.csrfToken = options.csrfToken;
            this.baseUrl = options.baseUrl || '/voice/session';
            this.browserTabStorageKey = options.browserTabStorageKey
                || 'voice_browser_tab_id';
            this.sessionStorageKey = options.sessionStorageKey
                || 'voice_session_uuid';
            this.browserTabId = window.sessionStorage.getItem(
                this.browserTabStorageKey
            ) || uuid();

            window.sessionStorage.setItem(
                this.browserTabStorageKey,
                this.browserTabId
            );
        }

        sessionUuid() {
            return window.sessionStorage.getItem(this.sessionStorageKey);
        }

        storeSessionUuid(sessionUuid) {
            if (sessionUuid) {
                window.sessionStorage.setItem(
                    this.sessionStorageKey,
                    sessionUuid
                );
                return;
            }

            window.sessionStorage.removeItem(this.sessionStorageKey);
        }

        async post(path, payload, options) {
            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            };

            if (options?.measurementId) {
                headers['X-Voice-Measurement-ID'] =
                    options.measurementId;
            }

            const response = await window.fetch(
                `${this.baseUrl}${path}`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers,
                    body: JSON.stringify(payload),
                }
            );
            let body;

            try {
                body = await response.json();
            } catch (error) {
                throw new Error(
                    `Voice endpoint returned invalid JSON (${response.status}).`
                );
            }

            return {
                ok: response.ok,
                status: response.status,
                body,
                measurementId: response.headers.get(
                    'X-Voice-Measurement-ID'
                ),
                serverTiming: response.headers.get('Server-Timing'),
            };
        }

        ownership() {
            return this.post('/ownership', {
                browser_tab_id: this.browserTabId,
            });
        }

        start(measurementId) {
            return this.post('/start', {
                browser_tab_id: this.browserTabId,
            }, { measurementId });
        }

        reconnect(sessionUuid, measurementId) {
            return this.post('/reconnect', {
                session_uuid: sessionUuid,
                browser_tab_id: this.browserTabId,
            }, { measurementId });
        }

        takeOver(measurementId, confirmed) {
            const payload = {
                browser_tab_id: this.browserTabId,
            };
            if (typeof confirmed === 'boolean') {
                payload.confirmed = confirmed;
            }
            return this.post('/take-over', payload, { measurementId });
        }

        callState(sessionUuid, browserTabId, callState) {
            return this.post('/call-state', {
                session_uuid: sessionUuid,
                browser_tab_id: browserTabId,
                call_state: callState,
            });
        }

        registered(sessionUuid, measurementId) {
            return this.post(`/${sessionUuid}/registered`, {
                browser_tab_id: this.browserTabId,
            }, { measurementId });
        }

        heartbeat(sessionUuid) {
            return this.post(`/${sessionUuid}/heartbeat`, {
                browser_tab_id: this.browserTabId,
            });
        }

        revoke(sessionUuid) {
            return this.post(`/${sessionUuid}/revoke`, {
                browser_tab_id: this.browserTabId,
            });
        }

        // Real-time outbound correlation (Phase 3). Posts to the dedicated backend
        // endpoint — note this is NOT under `${baseUrl}` (/voice/session), so it uses
        // an absolute path. Only linkedid + conversation_id travel; tenant/user/pbx/
        // direction are resolved server-side from the authenticated session.
        async correlateLinkedid(callUuid, linkedid, conversationId) {
            const url = `/console/voice/calls/${encodeURIComponent(callUuid)}/linkedid`;
            const response = await window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    linkedid,
                    conversation_id: conversationId,
                }),
            });

            let body = null;
            try {
                body = await response.json();
            } catch (error) {
                body = null;
            }

            return {
                ok: response.ok,
                status: response.status,
                body,
            };
        }

        pagehide(sessionUuid) {
            if (!sessionUuid) return false;

            const url = `${this.baseUrl}/${encodeURIComponent(sessionUuid)}/pagehide`;
            const payload = new FormData();
            payload.append('_token', this.csrfToken);
            payload.append('browser_tab_id', this.browserTabId);

            if (window.navigator?.sendBeacon) {
                return window.navigator.sendBeacon(url, payload);
            }

            window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload,
                keepalive: true,
                headers: {
                    Accept: 'application/json',
                },
            }).catch(() => {});

            return true;
        }
    }

    namespace.uuid = uuid;
    namespace.createEmitter = createEmitter;
    namespace.safeError = safeError;
    namespace.VoiceApiClient = VoiceApiClient;
})(window);
