(function () {
    'use strict';

    const SCRIPT         = document.currentScript;
    const TOKEN          = SCRIPT.dataset.config;
    const PUSHER_KEY     = SCRIPT.dataset.pusherKey;
    const PUSHER_CLUSTER = SCRIPT.dataset.pusherCluster;
    const API_BASE       = SCRIPT.src.replace(/\/webchat\/widget\.js.*$/, '');

    const WC_SIZES = {
        small:  { btn: 44, icon: 20, panelW: 280, minH: 380, maxH: 460 },
        medium: { btn: 56, icon: 26, panelW: 320, minH: 440, maxH: 520 },
        large:  { btn: 68, icon: 32, panelW: 360, minH: 500, maxH: 580 },
    };

    let config       = null;
    let sessionToken = localStorage.getItem('xedik_wc_session_' + TOKEN);
    let pusher    = null;
    let channel   = null;
    let lastMsgId = 0;
    let isOpen    = false;

    // ── CSS ──
    const CSS = `
        .xedik-wc-btn{position:fixed;z-index:999999;display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;}
        .xedik-wc-btn.br{bottom:20px;right:20px;}
        .xedik-wc-btn.bl{bottom:20px;left:20px;}
        .xedik-wc-label{background:white;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:500;color:#333;box-shadow:0 2px 8px rgba(0,0,0,.15);white-space:nowrap;font-family:system-ui,sans-serif;transition:opacity 0.15s ease;}
        .xedik-wc-circle{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.2);transition:transform .2s ease,box-shadow .2s ease;cursor:pointer;}
        .xedik-wc-circle:hover{transform:scale(1.1);}
        .xedik-wc-circle svg{width:26px;height:26px;fill:white;}
        .xedik-wc-panel{position:fixed;z-index:999998;width:320px;border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.15);display:flex;flex-direction:column;height:480px;background:white;font-family:system-ui,sans-serif;font-size:13px;}
        .xedik-wc-panel.br{bottom:90px;right:20px;}
        .xedik-wc-panel.bl{bottom:90px;left:20px;}
        .xedik-wc-header{padding:14px 16px;color:white;display:flex;align-items:center;gap:10px;flex-shrink:0;line-height:1!important;}
        .xedik-wc-avatar{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;}
        .xedik-wc-avatar svg{width:18px;height:18px;fill:white;}
        .xedik-wc-title{font-size:13px;font-weight:600;margin:0;}
        .xedik-wc-subtitle{font-size:11px;opacity:.85;margin:2px 0 0;display:flex;align-items:center;gap:4px;}
        .xedik-wc-dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;}
        .xedik-wc-close{margin-left:auto;cursor:pointer;opacity:.7;font-size:18px;line-height:1;background:none;border:none;color:white;padding:0;box-shadow:none!important;}
        .xedik-wc-body{flex:1;overflow-y:auto;padding:14px 16px;line-height:1.5!important;}
        .xedik-wc-bubbles{flex:1;overflow-y:auto;padding:12px;background:#f8f8f8;display:flex;flex-direction:column;gap:8px;}
        .xedik-wc-bubble{padding:8px 12px;border-radius:10px;font-size:13px;max-width:80%;line-height:1.5;word-break:break-word;}
        .xedik-wc-bubble.in{background:white;border:1px solid #eee;align-self:flex-start;color:#333;}
        .xedik-wc-bubble.out{color:white;align-self:flex-end;}
        .xedik-wc-footer{flex-shrink:0;padding:10px 12px;border-top:1px solid #eee;display:flex;gap:8px;align-items:center;background:white;line-height:1!important;}
        .xedik-wc-input{flex:1;border:1px solid #ddd;border-radius:20px;padding:8px 14px;font-size:13px;outline:none;font-family:system-ui,sans-serif;}
        .xedik-wc-send{width:34px;height:34px;border-radius:50%;border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;padding:0!important;box-shadow:none!important;}
        .xedik-wc-send svg{width:16px;height:16px;fill:white;}
        .xedik-wc-field{width:100%;border:1px solid #ddd;border-radius:8px;padding:9px 12px;font-size:13px;margin-bottom:8px;box-sizing:border-box;outline:none;font-family:system-ui,sans-serif;}
        .xedik-wc-select{width:100%;border:1px solid #ddd;border-radius:8px;padding:9px 12px;font-size:13px;margin-bottom:8px;box-sizing:border-box;background:white;font-family:system-ui,sans-serif;}
        .xedik-wc-start{width:100%;padding:11px;border:none;border-radius:8px;font-size:13px;font-weight:500;color:white;cursor:pointer;font-family:system-ui,sans-serif;box-shadow:none!important;}
        .xedik-wc-form-footer{flex-shrink:0;padding:12px 16px;border-top:1px solid #f0f0f0;}
        .xedik-wc-privacy-text{font-size:11px;color:#888;text-align:center;line-height:1.5;margin:0 0 10px;}
        .xedik-wc-privacy-check{display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;font-size:11px;color:#555;line-height:1.5;}
        .xedik-wc-subtitle-text{font-size:11px;opacity:.85;}
        .xedik-wc-form-subtitle{font-size:13px;color:#666;margin:0 0 12px;}
        .xedik-wc-honeypot{position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;}
        .xedik-wc-error{color:#E24B4A;font-size:11px;margin:6px 0 0;text-align:center;display:none;}
        .xedik-wc-ended{text-align:center;font-size:11px;color:#aaa;padding:8px;}
        .xedik-wc-emoji-btn{background:none;border:none;cursor:pointer;padding:0 4px;opacity:.55;line-height:1;flex-shrink:0;transition:opacity .15s;display:flex;align-items:center;box-shadow:none!important;}
        .xedik-wc-emoji-btn svg{width:18px;height:18px;fill:#555!important;}
        .xedik-wc-emoji-btn:hover{opacity:1;background:none!important;color:inherit!important;border:none!important;box-shadow:none!important;}
    `;

    // ── Íconos SVG ──
    const ICONS = {
        'bi-chat-dots-fill': '<svg viewBox="0 0 16 16"><path d="M16 8c0 3.866-3.582 7-8 7a9.06 9.06 0 0 1-2.347-.306c-.584.296-1.925.864-4.181 1.234-.2.032-.352-.176-.273-.362.354-.836.674-1.95.77-2.966C.744 11.37 0 9.76 0 8c0-3.866 3.582-7 8-7s8 3.134 8 7zM5 8a1 1 0 1 0-2 0 1 1 0 0 0 2 0zm4 0a1 1 0 1 0-2 0 1 1 0 0 0 2 0zm3 1a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/></svg>',
        'bi-chat-fill': '<svg viewBox="0 0 16 16"><path d="M8 15c4.418 0 8-3.134 8-7s-3.582-7-8-7-8 3.134-8 7c0 1.76.743 3.37 1.97 4.6-.097 1.016-.417 2.13-.771 2.966-.079.186.074.394.273.362 2.256-.37 3.597-.938 4.18-1.234A9.06 9.06 0 0 0 8 15z"/></svg>',
        'bi-headset': '<svg viewBox="0 0 16 16"><path d="M8 1a5 5 0 0 0-5 5v1h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a6 6 0 1 1 12 0v5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h1V6a5 5 0 0 0-5-5z"/></svg>',
        'bi-question-circle-fill': '<svg viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.496 6.033h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286a.237.237 0 0 0 .241.247zm2.325 6.443c.61 0 1.029-.394 1.029-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94 0 .533.425.927 1.01.927z"/></svg>',
        'bi-globe': '<svg viewBox="0 0 16 16"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855-.373.688-.648 1.593-.77 2.619l2.657-.001V1.077zM4.43 4.825l-2.33.001a6.017 6.017 0 0 0-.78 2.624h2.334c.058-.88.213-1.721.476-2.625zm-.48 3.374H1.32a6.017 6.017 0 0 0 .78 2.624l2.33.001c-.263-.904-.418-1.745-.476-2.625zm.48 3.375c.123 1.026.398 1.93.77 2.618.552 1.036 1.218 1.652 1.887 1.856V11.574l-2.657-.001zm3.07 4.474c.67-.204 1.335-.82 1.887-1.855.373-.689.648-1.593.77-2.619l-2.657.001v4.473zm2.657-5.224 2.657-.001V8.2H10.16c-.059.88-.214 1.72-.477 2.624zm.477-3.374c.263-.904.418-1.745.477-2.625h-2.657V4.825l2.18-.001zM8.5 1.077v4.474l2.657-.001c-.122-1.026-.397-1.93-.77-2.619C9.835 1.896 9.17 1.28 8.5 1.077z"/></svg>',
        'bi-person-lines-fill': '<svg viewBox="0 0 16 16"><path d="M6 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-5 6s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H1zm5-6a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm3 4h7a.5.5 0 0 0 0-1H9a.5.5 0 0 0 0 1zm0-2h7a.5.5 0 0 0 0-1H9a.5.5 0 0 0 0 1z"/></svg>',
        'send': '<svg viewBox="0 0 16 16"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855H.766l-.452.18a.5.5 0 0 0-.082.887l.41.26.001.002 4.995 3.178 3.178 4.995.002.002.26.41a.5.5 0 0 0 .886-.083l6-15Zm-1.833 1.89L6.637 10.07l-.215-.338a.5.5 0 0 0-.154-.154l-.338-.215 7.494-7.494 1.178-.471-.64 1.052Z"/></svg>',
        'emoji': '<svg viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M4.285 9.567a.5.5 0 0 1 .683.183A3.498 3.498 0 0 0 8 11.5a3.498 3.498 0 0 0 3.032-1.75.5.5 0 1 1 .866.5A4.498 4.498 0 0 1 8 12.5a4.498 4.498 0 0 1-3.898-2.25.5.5 0 0 1 .183-.683zM7 6.5C7 7.328 6.552 8 6 8s-1-.672-1-1.5S5.448 5 6 5s1 .672 1 1.5zm4 0c0 .828-.448 1.5-1 1.5s-1-.672-1-1.5S9.448 5 10 5s1 .672 1 1.5z"/></svg>',
    };

    function getIcon(name) {
        return ICONS[name] || ICONS['bi-chat-dots-fill'];
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function safeHtml(dirty, allowLinks) {
        if (!dirty) return '';
        if (typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(dirty, {
                ALLOWED_TAGS: allowLinks ? ['a', 'br', 'strong', 'em'] : ['br', 'strong', 'em'],
                ALLOWED_ATTR: allowLinks ? ['href', 'target', 'rel'] : [],
                ALLOWED_URI_REGEXP: /^(?:https?|mailto):/i,
            });
        }
        return escHtml(dirty);
    }

    function loadDeps(cb) {
        let loaded = 0;
        const total = (typeof DOMPurify === 'undefined' ? 1 : 0) +
                      (typeof Pusher === 'undefined' && PUSHER_KEY ? 1 : 0);

        if (total === 0) { cb(); return; }

        const check = () => { if (++loaded >= total) cb(); };

        if (typeof DOMPurify === 'undefined') {
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/dompurify@3.4.4/dist/purify.min.js';
            s.onload = s.onerror = check;
            document.head.appendChild(s);
        }
        if (typeof Pusher === 'undefined' && PUSHER_KEY) {
            const s = document.createElement('script');
            s.src = 'https://js.pusher.com/8.4/pusher.min.js';
            s.onload = s.onerror = check;
            document.head.appendChild(s);
        }
    }

    function injectCSS() {
        const style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function injectSizeCSS(size) {
        const s           = WC_SIZES[size] || WC_SIZES.medium;
        const bodyPadding = size === 'small' ? '10px 16px' : '14px 16px';
        const style       = document.createElement('style');
        style.textContent = [
            `.xedik-wc-circle{width:${s.btn}px;height:${s.btn}px;}`,
            `.xedik-wc-circle svg{width:${s.icon}px;height:${s.icon}px;}`,
            `.xedik-wc-panel{width:${s.panelW}px;min-height:${s.minH}px;max-height:${s.maxH}px;height:auto;}`,
            `.xedik-wc-bubbles{flex:1;overflow-y:auto;min-height:0;}`,
            `.xedik-wc-body{flex:1;overflow-y:auto;min-height:0;padding:${bodyPadding};}`,
        ].join('');
        document.head.appendChild(style);
    }

    async function fetchConfig() {
        try {
            const r = await fetch(`${API_BASE}/api/webchat/${TOKEN}/config`);
            if (!r.ok) return null;
            return await r.json();
        } catch { return null; }
    }

    function renderButton() {
        const btn = document.createElement('div');
        btn.className = `xedik-wc-btn ${config.position}`;
        btn.id = 'xedik-wc-btn';

        const labelPos = config.label_position  || 'top';
        const labelVis = config.label_visibility || 'always';

        const flexMap = { top: 'column', bottom: 'column-reverse', left: 'row', right: 'row-reverse' };
        btn.style.flexDirection = flexMap[labelPos] || 'column';

        const label = document.createElement('div');
        label.className = 'xedik-wc-label';
        label.textContent = config.button_label || 'Chat with us';

        const isMobile = 'ontouchstart' in window;
        if (labelVis === 'never' || (labelVis === 'hover' && isMobile)) {
            label.style.opacity       = '0';
            label.style.pointerEvents = 'none';
        } else if (labelVis === 'hover') {
            label.style.opacity       = '0';
            label.style.pointerEvents = 'none';
            btn.addEventListener('mouseenter', () => {
                label.style.opacity       = '1';
                label.style.pointerEvents = 'auto';
            });
            btn.addEventListener('mouseleave', () => {
                label.style.opacity       = '0';
                label.style.pointerEvents = 'none';
            });
        }

        const circle = document.createElement('div');
        circle.className = 'xedik-wc-circle';
        circle.style.background = config.primary_color;
        circle.innerHTML = getIcon(config.icon);

        btn.appendChild(label);
        btn.appendChild(circle);
        btn.addEventListener('click', openWidget);
        document.body.appendChild(btn);
    }

    function openWidget() {
        if (isOpen) return;
        isOpen = true;
        const btn = document.getElementById('xedik-wc-btn');
        if (btn) btn.style.display = 'none';

        if (sessionToken) {
            openChat(false);
        } else {
            openForm(); // Siempre mostrar form primero — online/offline se decide al submit
        }
    }

    function closeWidget() {
        isOpen = false;
        closeWcEmojiPicker();
        const panel = document.getElementById('xedik-wc-panel');
        if (panel) panel.remove();
        const btn = document.getElementById('xedik-wc-btn');
        if (btn) btn.style.display = 'flex';
        if (channel) { channel.unbind_all(); channel = null; }
        if (pusher) { pusher.disconnect(); pusher = null; }
        // sessionToken se mantiene — al reabrir retoma la conversación activa
    }

    // ── Form de captura ──
    function openForm() {
        const formSubtitle = config.is_online
            ? (config.window_subtitle || "We're online")
            : 'Outside business hours';
        const panel = createPanel(config.window_title, formSubtitle, true, !config.is_online);

        const body = panel.querySelector('.xedik-wc-body');
        const subtitle = document.createElement('p');
        subtitle.className = 'xedik-wc-form-subtitle';
        subtitle.textContent = config.form_subtitle || 'Before we start, tell us about you.';
        body.appendChild(subtitle);

        appendField(body, 'wc-name',  'text',  'Name',  100);
        appendField(body, 'wc-phone', 'tel',   'Phone', 30);
        appendField(body, 'wc-email', 'email', 'Email', 150);

        // Interest
        if (config.interest_enabled) {
            if (config.interest_type === 'dropdown' && config.interest_options?.length) {
                const sel = document.createElement('select');
                sel.className = 'xedik-wc-select';
                sel.id = 'wc-interest';
                const defOpt = document.createElement('option');
                defOpt.value = '';
                defOpt.textContent = 'Select an option...';
                sel.appendChild(defOpt);
                config.interest_options.forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = o;
                    opt.textContent = o;
                    sel.appendChild(opt);
                });
                body.appendChild(sel);
            } else {
                appendField(body, 'wc-interest', 'text', 'Your interest / topic', 100);
            }
        }

        // Privacy
        if (config.privacy_enabled && config.privacy_text) {
            let rawText = config.privacy_text;
            if (config.privacy_url && rawText.includes('[privacy_url]')) {
                rawText = rawText.replace('[privacy_url]',
                    `<a href="${escHtml(config.privacy_url)}" target="_blank" rel="noopener noreferrer">Privacy Policy</a>`);
            }
            const safeText = safeHtml(rawText, true);

            const stylePrivacyLink = (el) => {
                const a = el.querySelector('a');
                if (a) {
                    a.style.color = config.primary_color;
                    a.style.textDecoration = 'underline';
                    a.style.cursor = 'pointer';
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                }
            };

            if (config.privacy_type === 'checkbox') {
                const label = document.createElement('label');
                label.className = 'xedik-wc-privacy-check';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.id = 'wc-privacy-check';
                checkbox.style.accentColor = config.primary_color;
                const span = document.createElement('span');
                span.innerHTML = safeText;
                stylePrivacyLink(span);
                label.appendChild(checkbox);
                label.appendChild(span);
                body.appendChild(label);
            } else {
                const p = document.createElement('p');
                p.className = 'xedik-wc-privacy-text';
                p.innerHTML = safeText;
                stylePrivacyLink(p);
                body.appendChild(p);
            }
        }

        // Honeypot
        const hp = document.createElement('input');
        hp.type = 'text';
        hp.className = 'xedik-wc-honeypot';
        hp.id = 'wc-honeypot';
        hp.tabIndex = -1;
        hp.autocomplete = 'off';
        body.appendChild(hp);

        // Footer
        const footer = document.createElement('div');
        footer.className = 'xedik-wc-form-footer';
        const startBtn = document.createElement('button');
        startBtn.type = 'button';
        startBtn.className = 'xedik-wc-start';
        startBtn.style.background = config.primary_color;
        startBtn.textContent = 'Start chat';
        startBtn.id = 'wc-start-btn';
        const errorEl = document.createElement('p');
        errorEl.className = 'xedik-wc-error';
        errorEl.id = 'wc-form-error';
        footer.appendChild(startBtn);
        footer.appendChild(errorEl);
        panel.appendChild(footer);

        document.body.appendChild(panel);
        startBtn.addEventListener('click', submitForm);
    }

    function appendField(parent, id, type, placeholder, maxLen) {
        const input = document.createElement('input');
        input.type = type;
        input.className = 'xedik-wc-field';
        input.id = id;
        input.placeholder = placeholder;
        input.maxLength = maxLen;
        parent.appendChild(input);
        return input;
    }

    async function submitForm() {
        const name     = document.getElementById('wc-name')?.value.trim()    || '';
        const phone    = document.getElementById('wc-phone')?.value.trim()   || '';
        const email    = document.getElementById('wc-email')?.value.trim()   || '';
        const interest = document.getElementById('wc-interest')?.value.trim() || '';
        const honeypot = document.getElementById('wc-honeypot')?.value       || '';
        const errorEl  = document.getElementById('wc-form-error');
        const btn      = document.getElementById('wc-start-btn');

        if (!name || !phone || !email) {
            errorEl.textContent = 'Please fill in all required fields.';
            errorEl.style.display = 'block';
            return;
        }
        if (config.privacy_enabled && config.privacy_type === 'checkbox') {
            if (!document.getElementById('wc-privacy-check')?.checked) {
                errorEl.textContent = 'Please accept the privacy policy to continue.';
                errorEl.style.display = 'block';
                return;
            }
        }

        btn.disabled = true;
        btn.textContent = 'Connecting...';
        errorEl.style.display = 'none';

        try {
            const r = await fetch(`${API_BASE}/api/webchat/${TOKEN}/start`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, phone, email, interest, website: honeypot, page_url: window.location.href }),
            });
            const data = await r.json();
            sessionToken = data.session_token;
            localStorage.setItem('xedik_wc_session_' + TOKEN, sessionToken);

            if (data.mode === 'online') {
                const panel = document.getElementById('xedik-wc-panel');
                if (panel) panel.remove();
                openChat(true);
            } else {
                openOfflineInfo();
            }
        } catch {
            btn.disabled = false;
            btn.textContent = 'Start chat';
            errorEl.textContent = 'Connection error. Please try again.';
            errorEl.style.display = 'block';
        }
    }

    // ── Chat activo ──
    function openChat(newSession) {
        if (!newSession) lastMsgId = 0; // reconexión — cargar historial completo

        const panel = createPanel(config.window_title, 'Agent connected', false, false);

        const bubbles = document.createElement('div');
        bubbles.className = 'xedik-wc-bubbles';
        bubbles.id = 'wc-bubbles';
        panel.appendChild(bubbles);

        const footer = document.createElement('div');
        footer.className = 'xedik-wc-footer';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'xedik-wc-input';
        input.id = 'wc-msg-input';
        input.placeholder = 'Type a message...';
        input.maxLength = 4096;
        const sendBtn = document.createElement('button');
        sendBtn.type = 'button';
        sendBtn.className = 'xedik-wc-send';
        sendBtn.style.background = config.primary_color;
        sendBtn.id = 'wc-send-btn';
        sendBtn.innerHTML = getIcon('send');
        const emojiBtn = document.createElement('button');
        emojiBtn.type = 'button';
        emojiBtn.className = 'xedik-wc-emoji-btn';
        emojiBtn.title = 'Emoji';
        emojiBtn.innerHTML = getIcon('emoji');
        emojiBtn.addEventListener('click', e => { e.stopPropagation(); toggleWcEmojiPicker(emojiBtn); });

        footer.appendChild(input);
        footer.appendChild(emojiBtn);
        footer.appendChild(sendBtn);
        panel.appendChild(footer);
        document.body.appendChild(panel);

        setTimeout(() => input.focus(), 100);
        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });

        loadChatHistory();
        connectPusher();
    }

    async function sendMessage() {
        const input = document.getElementById('wc-msg-input');
        const text  = input?.value.trim();
        if (!text || !sessionToken) return;
        input.value = '';
        addBubble(text, 'out');
        try {
            const r = await fetch(`${API_BASE}/api/webchat/${TOKEN}/${sessionToken}/message`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ body_text: text }),
            });
            const data = await r.json();
            lastMsgId = Math.max(lastMsgId, data.id || 0);
        } catch { /* silencioso — mensaje ya mostrado */ }
    }

    function addBubble(text, direction, timestamp = null) {
        const bubbles = document.getElementById('wc-bubbles');
        if (!bubbles) return;

        const wrapper = document.createElement('div');
        wrapper.style.cssText = `display:flex;flex-direction:column;align-items:${direction === 'out' ? 'flex-end' : 'flex-start'};gap:2px;margin-bottom:2px;`;

        const div = document.createElement('div');
        div.className = `xedik-wc-bubble ${direction}`;
        if (direction === 'out') div.style.background = config.primary_color;
        div.textContent = text; // SIEMPRE textContent — nunca innerHTML para mensajes

        const time = document.createElement('span');
        const ts = timestamp ? new Date(timestamp) : new Date();
        time.textContent = ts.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        time.style.cssText = 'font-size:10px;color:#aaa;padding:0 4px;';

        wrapper.appendChild(div);
        wrapper.appendChild(time);
        bubbles.appendChild(wrapper);
        bubbles.scrollTop = bubbles.scrollHeight;
    }

    // ── Offline ──
    function openOfflineInfo() {
        const panel = document.getElementById('xedik-wc-panel');
        if (!panel) return;

        const sub = document.getElementById('wc-header-subtitle');
        if (sub) {
            sub.textContent = '';
            const dot = document.createElement('span');
            dot.className = 'xedik-wc-dot';
            dot.style.background = '#FAC775';
            const txt = document.createTextNode(" We'll be in touch soon");
            sub.appendChild(dot);
            sub.appendChild(txt);
        }

        const body = panel.querySelector('.xedik-wc-body');
        if (body) {
            body.innerHTML = `
                <div style="text-align:center;padding:20px 10px;">
                    <div style="font-size:32px;margin-bottom:12px;">✓</div>
                    <p style="font-size:14px;font-weight:500;color:#333;margin:0 0 8px;">Got it, thanks!</p>
                    <p style="font-size:12px;color:#888;line-height:1.6;margin:0;">${escHtml(config.offline_message || 'We are currently offline. One of our agents will contact you shortly.')}</p>
                </div>
            `;
        }

        const footer = panel.querySelector('.xedik-wc-form-footer');
        if (footer) footer.remove();

        sessionToken = null;
        localStorage.removeItem('xedik_wc_session_' + TOKEN);
    }

    // ── Pusher ──
    function connectPusher() {
        if (!PUSHER_KEY || typeof Pusher === 'undefined') return;
        try {
            pusher  = new Pusher(PUSHER_KEY, { cluster: PUSHER_CLUSTER });
            channel = pusher.subscribe('webchat.' + sessionToken);

            // En reconexión Pusher (no en la primera suscripción) cargar historial
            // para detectar mensajes perdidos o conversación resuelta durante el blip
            let firstSubscription = true;
            channel.bind('pusher:subscription_succeeded', () => {
                if (firstSubscription) { firstSubscription = false; return; }
                loadChatHistory();
            });

            channel.bind('agent-message', data => {
                addBubble(data.body_text, 'in', data.sent_at);
                lastMsgId = Math.max(lastMsgId, data.id || 0);
            });
            channel.bind('agent-assigned', data => {
                const sub = document.getElementById('wc-header-subtitle');
                if (sub) {
                    sub.textContent = '';
                    const dot = document.createElement('span');
                    dot.className = 'xedik-wc-dot';
                    dot.style.background = '#5DCAA5';
                    const txt = document.createTextNode(` Connected with ${data.agent_name || 'Agent'}`);
                    sub.appendChild(dot);
                    sub.appendChild(txt);
                }
            });
            channel.bind('conversation-ended', () => {
                showConversationEnded();
            });
        } catch (e) {
            console.warn('Webchat Pusher error:', e);
        }
    }

    // Carga historial y detecta si la conversación fue resuelta.
    // Se llama en apertura del chat y en reconexión de Pusher.
    async function loadChatHistory() {
        if (!sessionToken) return;
        try {
            const r = await fetch(`${API_BASE}/api/webchat/${TOKEN}/${sessionToken}/messages?after_id=${lastMsgId}`);

            if (r.status === 404) {
                sessionToken = null;
                localStorage.removeItem('xedik_wc_session_' + TOKEN);
                const panel = document.getElementById('xedik-wc-panel');
                if (panel) panel.remove();
                openForm();
                return;
            }

            const data = await r.json();

            if (data.conversation_status === 'resolved') {
                showConversationEnded();
                return;
            }

            if (data.agent_name) {
                const sub = document.getElementById('wc-header-subtitle');
                if (sub) {
                    sub.textContent = '';
                    const dot = document.createElement('span');
                    dot.className = 'xedik-wc-dot';
                    dot.style.background = '#5DCAA5';
                    sub.appendChild(dot);
                    sub.appendChild(document.createTextNode(` Connected with ${data.agent_name}`));
                }
            }

            if (data.messages?.length) {
                data.messages.forEach(m => {
                    addBubble(m.body_text, m.direction === 'outbound' ? 'in' : 'out', m.sent_at);
                    lastMsgId = Math.max(lastMsgId, m.id);
                });
            }
        } catch { /* silencioso */ }
    }

    function showConversationEnded() {
        const bubbles = document.getElementById('wc-bubbles');
        if (bubbles) {
            const div = document.createElement('div');
            div.className = 'xedik-wc-ended';
            div.textContent = 'Conversation ended. Thank you!';
            bubbles.appendChild(div);
            bubbles.scrollTop = bubbles.scrollHeight;
        }

        // Capturar token antes de limpiarlo (necesario para unsubscribe)
        const endedToken = sessionToken;
        sessionToken = null;
        localStorage.removeItem('xedik_wc_session_' + TOKEN);

        if (channel) { channel.unbind_all(); channel = null; }
        if (pusher && endedToken) { pusher.unsubscribe('webchat.' + endedToken); pusher = null; }

        const sub = document.getElementById('wc-header-subtitle');
        if (sub) {
            sub.textContent = '';
            const dot = document.createElement('span');
            dot.className = 'xedik-wc-dot';
            dot.style.background = '#ccc';
            sub.appendChild(dot);
            sub.appendChild(document.createTextNode(' Conversation ended'));
        }

        const input = document.getElementById('wc-msg-input');
        const sendBtn = document.getElementById('wc-send-btn');
        if (input) {
            input.disabled = true;
            input.placeholder = 'Conversation ended — close and reopen to start a new chat';
            input.style.background = '#f5f5f5';
            input.style.color = '#aaa';
        }
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.style.opacity = '0.4';
            sendBtn.style.cursor = 'not-allowed';
        }
        const emojiEndBtn = document.querySelector('.xedik-wc-emoji-btn');
        if (emojiEndBtn) {
            emojiEndBtn.disabled = true;
            emojiEndBtn.style.opacity = '0.25';
            emojiEndBtn.style.cursor = 'not-allowed';
        }
        closeWcEmojiPicker();
    }

    // ── Panel base ──
    function createPanel(title, subtitle, hasBody, isOffline) {
        const existing = document.getElementById('xedik-wc-panel');
        if (existing) existing.remove();

        const panel = document.createElement('div');
        panel.className = `xedik-wc-panel ${config.position}`;
        panel.id = 'xedik-wc-panel';

        const header = document.createElement('div');
        header.className = 'xedik-wc-header';
        header.style.background = config.primary_color;

        const avatar = document.createElement('div');
        avatar.className = 'xedik-wc-avatar';
        avatar.innerHTML = getIcon(config.icon);

        const titleWrap = document.createElement('div');
        titleWrap.style.flex = '1';
        const titleEl = document.createElement('p');
        titleEl.className = 'xedik-wc-title';
        titleEl.textContent = title;
        const subEl = document.createElement('p');
        subEl.className = 'xedik-wc-subtitle';
        subEl.id = 'wc-header-subtitle';
        const dot = document.createElement('span');
        dot.className = 'xedik-wc-dot';
        dot.style.background = isOffline ? '#FAC775' : '#5DCAA5';
        const subTxt = document.createTextNode(subtitle || '');
        subEl.appendChild(dot);
        subEl.appendChild(subTxt);
        titleWrap.appendChild(titleEl);
        titleWrap.appendChild(subEl);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'xedik-wc-close';
        closeBtn.textContent = '✕';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.addEventListener('click', e => { e.preventDefault(); closeWidget(); });

        header.appendChild(avatar);
        header.appendChild(titleWrap);
        header.appendChild(closeBtn);
        panel.appendChild(header);

        if (hasBody) {
            const body = document.createElement('div');
            body.className = 'xedik-wc-body';
            panel.appendChild(body);
        }

        return panel;
    }

    // ── Emoji Picker ──
    let _wcEmojiPickerEl     = null;
    let _wcEmojiMartLoaded   = false;

    function loadWcEmojiMart(cb) {
        if (_wcEmojiMartLoaded) { cb(); return; }
        const s = document.createElement('script');
        s.src = `${API_BASE}/js/vendor/emoji-mart/browser.js`;
        s.onload = () => { _wcEmojiMartLoaded = true; cb(); };
        document.head.appendChild(s);
    }

    function closeWcEmojiPicker() {
        if (_wcEmojiPickerEl) _wcEmojiPickerEl.style.display = 'none';
    }

    function toggleWcEmojiPicker(btn) {
        if (_wcEmojiPickerEl && _wcEmojiPickerEl.style.display !== 'none') {
            closeWcEmojiPicker(); return;
        }
        loadWcEmojiMart(() => {
            if (!_wcEmojiPickerEl) {
                _wcEmojiPickerEl = document.createElement('div');
                _wcEmojiPickerEl.style.cssText = 'position:fixed;z-index:9999999;display:none;';
                _wcEmojiPickerEl.addEventListener('click', e => e.stopPropagation());
                document.body.appendChild(_wcEmojiPickerEl);

                const picker = new EmojiMart.Picker({
                    data: () => fetch(`${API_BASE}/webchat/emoji-data`).then(r => r.json()),
                    onEmojiSelect: function (emoji) {
                        const input = document.getElementById('wc-msg-input');
                        if (input) { input.value += emoji.native; input.focus(); }
                        closeWcEmojiPicker();
                    },
                    theme: 'light',
                    locale: 'en',
                    set: 'native',
                    autoFocus: true,
                    navPosition: 'none',
                    previewPosition: 'none',
                    searchPosition: 'top',
                    skinTonePosition: 'none',
                    emojiVersion: '15',
                });
                _wcEmojiPickerEl.appendChild(picker);
            }

            _wcEmojiPickerEl.style.display = 'block';
            const rect    = btn.getBoundingClientRect();
            const pickerH = _wcEmojiPickerEl.offsetHeight || 380;
            const pickerW = _wcEmojiPickerEl.offsetWidth  || 352;
            let top  = rect.top - pickerH - 8;
            let left = rect.right - pickerW;
            if (top  < 8) top  = rect.bottom + 8;
            if (left < 8) left = 8;
            _wcEmojiPickerEl.style.top  = top  + 'px';
            _wcEmojiPickerEl.style.left = left + 'px';
        });
    }

    document.addEventListener('click', closeWcEmojiPicker);

    // ── Arrancar ──
    async function init() {
        injectCSS();
        config = await fetchConfig();
        if (!config) return;
        injectSizeCSS(config.size || 'medium');
        loadDeps(() => renderButton());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
