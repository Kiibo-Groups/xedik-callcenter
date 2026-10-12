(function (window) {
    'use strict';

    // Single canonical writer for the console header's #statusDot/#statusLabel/
    // #statusTimer elements. The REQUESTED status (Ready/Paused/Offline, driven by
    // resources/views/console/index.blade.php) and the EFFECTIVE Voice overlay
    // (Ringing/In Call/Wrap-Up, driven by public/js/agent-console-voice-adapter.js)
    // both call into this SAME object exclusively — there must never be two
    // independent interval writers targeting these elements again (see
    // docs/voice/VOICE_STATUS.md for the incident this file fixes).

    let generation = 0;
    let intervalId = null;
    let currentStartedAt = null;
    let currentStatus = null;
    let currentMaxSeconds = null;

    function render(seconds) {
        const timerEl = document.getElementById('statusTimer');
        if (!timerEl) return;
        const clamped = Math.max(0, seconds);
        const h = String(Math.floor(clamped / 3600)).padStart(2, '0');
        const m = String(Math.floor((clamped % 3600) / 60)).padStart(2, '0');
        const s = String(clamped % 60).padStart(2, '0');
        timerEl.textContent = h + ':' + m + ':' + s;
        timerEl.style.color = (currentMaxSeconds && clamped > currentMaxSeconds)
            ? '#ef4444'
            : 'var(--text-strong)';
    }

    // Bumps the generation (so any already-queued tick from the previous timer
    // becomes a permanent no-op the instant it runs) and clears the interval.
    // Returns the new generation so callers can capture "their" generation.
    function stop() {
        generation += 1;
        if (intervalId !== null) {
            window.clearInterval(intervalId);
            intervalId = null;
        }
        return generation;
    }

    // set(status, startedAt, { label, dotColor, maxSeconds })
    // The one entry point for both requested and effective status. Always stops
    // whatever was running first — there is only ever one interval in flight.
    function set(status, startedAt, presentation) {
        presentation = presentation || {};
        const myGeneration = stop();

        currentStatus = status;
        currentStartedAt = startedAt instanceof Date ? startedAt : new Date(startedAt);
        currentMaxSeconds = presentation.maxSeconds || null;

        const dot = document.getElementById('statusDot');
        const label = document.getElementById('statusLabel');
        if (dot && presentation.dotColor) dot.style.background = presentation.dotColor;
        if (label && presentation.label) label.textContent = presentation.label;

        if (status === 'offline') {
            render(0);
            return;
        }

        const tick = () => {
            // Stale callback from a superseded generation — permanently inert,
            // even though clearInterval() should already have prevented this.
            if (myGeneration !== generation) return;
            const seconds = Math.floor((Date.now() - currentStartedAt.getTime()) / 1000);
            render(seconds);
        };
        tick();
        intervalId = window.setInterval(tick, 1000);
    }

    function currentState() {
        return {
            status: currentStatus,
            startedAt: currentStartedAt,
            generation: generation,
        };
    }

    window.HeaderStatusTimer = { set, stop, currentState };
})(window);
