// Webchat Config Form — tabs, icon picker, business hours, interest options, live preview

const WC_SIZES = {
    small:  { btn: 44, icon: 20, panel: { w: 280, h: 400 }, label: 10, gap: 5 },
    medium: { btn: 56, icon: 26, panel: { w: 320, h: 480 }, label: 11, gap: 6 },
    large:  { btn: 68, icon: 32, panel: { w: 360, h: 540 }, label: 12, gap: 7 },
};

let _currentSize = 'medium';
let _labelHoverHandlers = null;

// ── State machine ──
function wcSetState(n) {
    [1, 2, 3].forEach(i => {
        const s = document.getElementById('wcState' + i);
        if (s) s.style.display = i === n ? 'block' : 'none';
        const b = document.getElementById('wcBtnState' + i);
        if (b) {
            b.style.background  = i === n ? '#534AB7' : '';
            b.style.color       = i === n ? 'white'   : '';
            b.style.borderColor = i === n ? '#534AB7' : '';
        }
    });
    // State 1 uses flex not block
    const s1 = document.getElementById('wcState1');
    if (n === 1 && s1) s1.style.display = 'flex';
    wcSyncSize();
}

// ── Sync preview ──
function wcSyncPreview() {
    const color       = document.getElementById('primaryColorHex')?.value    || '#534AB7';
    const windowTitle = document.getElementById('windowTitleInput')?.value   || 'Chat with us';
    const subtitle    = document.getElementById('windowSubtitleInput')?.value || 'We typically reply in 2 min';
    const buttonLabel = document.getElementById('buttonLabelInput')?.value    || 'Chat with us';
    const formSub     = document.getElementById('formSubtitleInput')?.value   || 'Before we start, tell us about you.';
    const welcome     = document.getElementById('welcomeMessage')?.value      || 'Hi! How can we help you today?';
    const icon        = document.getElementById('wcIconInput')?.value         || 'bi-chat-dots-fill';

    // Background colors
    ['wcFloatBtn', 'wcPanelHeader', 'wcChatHeader', 'wcSendBtn', 'wcStartBtn'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.background = color;
    });

    // Visitor bubble
    const vb = document.getElementById('wcVisitorBubble');
    if (vb) vb.style.background = color;

    // Welcome + agent bubbles (same color with opacity)
    const wb = document.getElementById('wcWelcomeBubble');
    if (wb) {
        wb.style.background = color + '20';
        wb.textContent = welcome;
    }
    const ab = document.getElementById('wcAgentBubble');
    if (ab) ab.style.background = color + '20';

    // Text content
    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setText('wcPreviewTitle',   buttonLabel);
    setText('wcHeaderTitle',    windowTitle);
    setText('wcHeaderSubtitle', subtitle || 'Online');
    setText('wcChatTitle',      windowTitle);
    setText('wcFormSubtitle',   formSub);

    // Icons
    ['wcFloatIcon', 'wcHeaderIcon'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.className      = 'bi ' + icon;
            el.style.color    = 'white';
            el.style.fontSize = id === 'wcFloatIcon' ? '22px' : '14px';
        }
    });

    // Position
    // Position: mover estados absolutos izquierda/derecha
    const pos = document.querySelector('[name="position"]:checked')?.value || 'br';
    ['wcState1', 'wcState2', 'wcState3'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (pos === 'bl') {
            el.style.right = 'auto';
            el.style.left  = '20px';
        } else {
            el.style.left  = 'auto';
            el.style.right = '20px';
        }
    });

    // Interest field preview
    const interestEnabled = document.getElementById('interestEnabled')?.checked;
    const interestType    = document.querySelector('input[name="interest_type"]:checked')?.value || 'dropdown';
    const interestField   = document.getElementById('wcInterestField');
    if (interestField) {
        if (!interestEnabled) {
            interestField.innerHTML = '';
        } else if (interestType === 'text') {
            interestField.innerHTML =
                '<input placeholder="Your interest / topic" style="width:100%;font-size:12px;' +
                'padding:8px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;outline:none;">';
        } else {
            const opts = Array.from(document.querySelectorAll('.interest-option-input'))
                .map(i => i.value).filter(Boolean);
            if (opts.length) {
                interestField.innerHTML = '<select style="width:100%;font-size:12px;padding:8px 10px;' +
                    'border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">' +
                    opts.map(o => `<option>${o}</option>`).join('') + '</select>';
            } else {
                interestField.innerHTML = '<select style="width:100%;font-size:12px;padding:8px 10px;' +
                    'border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">' +
                    '<option>Select an option...</option></select>';
            }
        }
    }

    // Dynamic focus ring matching primary color
    const styleId = 'wc-dynamic-focus-style';
    let styleEl = document.getElementById(styleId);
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = styleId;
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = `
        #wcState2 input:focus, #wcState2 select:focus,
        #wcState3 input:focus, #wcState3 textarea:focus {
            outline: none !important;
            border-color: ${color} !important;
            box-shadow: 0 0 0 2px ${color}30 !important;
        }
        #wcFloatBtn {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        #wcFloatBtn:hover {
            transform: scale(1.12);
            box-shadow: 0 6px 20px ${color}60 !important;
        }
    `;

    // Privacy notice in Form state preview
    const privacyEnabled = document.getElementById('privacyEnabled')?.checked;
    const privacyType    = document.querySelector('input[name="privacy_type"]:checked')?.value || 'info';
    const rawText        = document.querySelector('textarea[name="privacy_text"]')?.value
                         || 'By starting a chat you agree to our [privacy_url] and consent to be contacted.';
    const privacyUrl     = document.querySelector('input[name="privacy_url"]')?.value || '';
    const privacyField   = document.getElementById('wcPrivacyField');

    if (privacyField) {
        if (!privacyEnabled) {
            privacyField.innerHTML = '';
        } else {
            let displayText = rawText;
            if (privacyUrl) {
                displayText = displayText.replace(
                    '[privacy_url]',
                    `<a href="${privacyUrl}" target="_blank" style="color:${color};text-decoration:underline;">Privacy Policy</a>`
                );
            } else {
                displayText = displayText.replace(
                    '[privacy_url]',
                    `<span style="color:${color};text-decoration:underline;">Privacy Policy</span>`
                );
            }

            if (privacyType === 'info') {
                privacyField.innerHTML =
                    `<p style="font-size:10px;color:#888;margin:0 0 10px;line-height:1.5;text-align:center;">${displayText}</p>`;
            } else {
                privacyField.innerHTML =
                    `<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;cursor:pointer;">
                        <input type="checkbox" style="margin-top:2px;accent-color:${color};flex-shrink:0;">
                        <span style="font-size:10px;color:#555;line-height:1.5;">${displayText}</span>
                    </label>`;
            }
        }
    }

    // Label position + visibility
    const labelPos = document.querySelector('input[name="label_position"]:checked')?.value || 'top';
    const labelVis = document.querySelector('input[name="label_visibility"]:checked')?.value || 'always';
    const state1   = document.getElementById('wcState1');
    const labelEl  = document.getElementById('wcPreviewTitle');
    const btnEl    = document.getElementById('wcFloatBtn');

    if (state1 && labelEl && btnEl) {
        // Flex direction por posición
        const flexMap = { top: 'column', bottom: 'column-reverse', left: 'row', right: 'row-reverse' };
        state1.style.flexDirection = flexMap[labelPos] || 'column';
        state1.style.alignItems    = 'center';

        // Limpiar handlers de hover previos
        if (_labelHoverHandlers) {
            btnEl.removeEventListener('mouseenter', _labelHoverHandlers.enter);
            btnEl.removeEventListener('mouseleave', _labelHoverHandlers.leave);
            _labelHoverHandlers = null;
        }

        if (labelVis === 'never') {
            labelEl.style.opacity    = '0';
            labelEl.style.display    = 'none';
            labelEl.style.transition = '';
        } else if (labelVis === 'hover') {
            labelEl.style.display    = 'inline-block';
            labelEl.style.opacity    = '0';
            labelEl.style.transition = 'opacity 0.2s ease';
            _labelHoverHandlers = {
                enter: () => { labelEl.style.opacity = '1'; },
                leave: () => { labelEl.style.opacity = '0'; },
            };
            btnEl.addEventListener('mouseenter', _labelHoverHandlers.enter);
            btnEl.addEventListener('mouseleave', _labelHoverHandlers.leave);
        } else {
            labelEl.style.display    = 'inline-block';
            labelEl.style.opacity    = '1';
            labelEl.style.transition = '';
        }
    }

    wcSyncSize();
}

// ── Size selector ──
function wcSelectSize(size) {
    _currentSize = size;

    const radio = document.getElementById('size_' + size);
    if (radio) radio.checked = true;

    ['small', 'medium', 'large'].forEach(s => {
        const card = document.getElementById('size-card-' + s);
        if (!card) return;
        if (s === size) {
            card.style.boxShadow  = '0 0 0 2px #534AB7';
            card.style.border     = '0.5px solid transparent';
            card.style.background = 'rgba(83,74,183,0.05)';
            card.querySelector('p').style.color = '#534AB7';
        } else {
            card.style.boxShadow  = 'none';
            card.style.border     = '0.5px solid var(--border)';
            card.style.background = '';
            card.querySelector('p').style.color = 'var(--text-strong)';
        }
    });

    wcSyncSize();
}

function wcSyncSize() {
    const s = WC_SIZES[_currentSize] || WC_SIZES.medium;

    const btn = document.getElementById('wcFloatBtn');
    if (btn) { btn.style.width = s.btn + 'px'; btn.style.height = s.btn + 'px'; }

    const icon = document.getElementById('wcFloatIcon');
    if (icon) icon.style.fontSize = s.icon + 'px';

    const label = document.getElementById('wcPreviewTitle');
    if (label) label.style.fontSize = (s.label || 11) + 'px';

    const state1 = document.getElementById('wcState1');
    if (state1) state1.style.gap = (s.gap || 6) + 'px';

    ['wcState2', 'wcState3'].forEach(stateId => {
        const state = document.getElementById(stateId);
        if (!state) return;
        state.style.transform       = '';
        state.style.transformOrigin = '';
        state.style.marginBottom    = '';
        state.style.width           = '100%';
        state.style.position        = 'absolute';
        state.style.top             = '0';
        state.style.left            = '0';
        state.style.right           = '0';
        state.style.bottom          = '0';

        const inner = state.querySelector('.wc-preview-panel');
        if (inner) {
            inner.style.width  = '100%';
            inner.style.height = '100%';
        }
    });
}

// ── Icon picker ──
function wcUpdateSizeCardIcons(iconClass) {
    const sizes = { small: 16, medium: 20, large: 26 };
    ['small', 'medium', 'large'].forEach(size => {
        const card = document.getElementById('size-card-' + size);
        if (!card) return;
        const iconEl = card.querySelector('i');
        if (!iconEl) return;
        iconEl.className = 'bi ' + iconClass;
        iconEl.style.fontSize = sizes[size] + 'px';
    });
}

function wcInitIconPicker() {
    document.querySelectorAll('.icon-option').forEach(el => {
        el.addEventListener('click', () => {
            const icon = el.dataset.icon;
            document.getElementById('wcIconInput').value = icon;
            document.querySelectorAll('.icon-option').forEach(o => {
                const sel = o.dataset.icon === icon;
                o.style.borderColor = sel ? 'var(--primary)' : 'var(--border)';
                o.style.background  = sel ? 'var(--primary-light)' : '';
                o.classList.toggle('selected', sel);
            });
            wcSyncPreview();
            wcUpdateSizeCardIcons(icon);
        });
    });
}

// ── Tabs ──
function wcInitTabs() {
    document.querySelectorAll('.wc-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.wc-tab-btn').forEach(b => {
                b.style.borderBottomColor = 'transparent';
                b.style.fontWeight = '400';
                b.style.color = 'var(--text-muted)';
                b.classList.remove('active');
            });
            document.querySelectorAll('.wc-tab-pane').forEach(p => p.style.display = 'none');
            btn.style.borderBottomColor = 'var(--primary)';
            btn.style.fontWeight = '600';
            btn.style.color = 'var(--primary)';
            btn.classList.add('active');
            const target = document.getElementById(btn.dataset.tab);
            if (target) target.style.display = 'block';
        });
    });
}

// ── Business hours ──
function wcInitBusinessHours() {
    document.querySelectorAll('.bh-day-toggle').forEach(toggle => {
        toggle.addEventListener('change', function () {
            const day = this.dataset.day;
            document.querySelectorAll(`.bh-time-select[data-day="${day}"]`).forEach(s => {
                s.disabled = !this.checked;
                s.style.opacity = this.checked ? '1' : '0.4';
            });
            const hidden = document.querySelector(`input[type="hidden"][name="business_hours[${day}][enabled]"]`);
            if (hidden) hidden.value = this.checked ? '1' : '0';
        });
        // Apply initial state
        toggle.dispatchEvent(new Event('change'));
    });
}

// ── Interest options ──
function wcInitInterestOptions() {
    document.getElementById('addInterestOption')?.addEventListener('click', () => {
        const container = document.getElementById('interestOptionsContainer');
        if (!container) return;
        const idx = container.querySelectorAll('.interest-option-row').length;
        const row = document.createElement('div');
        row.className = 'interest-option-row';
        row.style.cssText = 'display:flex;gap:6px;margin-bottom:0;';
        row.innerHTML = `
            <input type="text" name="interest_options[]"
                   class="form-control interest-option-input"
                   style="flex:1;max-width:300px;" maxlength="100"
                   placeholder="Option ${idx + 1}">
            <button type="button" class="btn btn-sm btn-danger remove-interest-option">
                <i class="bi bi-trash"></i>
            </button>`;
        container.appendChild(row);
        row.querySelector('input').focus();
        row.querySelector('input').addEventListener('input', wcSyncPreview);
        wcSyncPreview();
    });

    document.addEventListener('click', e => {
        if (e.target.closest('.remove-interest-option')) {
            e.target.closest('.interest-option-row').remove();
            wcSyncPreview();
        }
    });

    // Interest section toggle
    document.getElementById('interestEnabled')?.addEventListener('change', function () {
        const section = document.getElementById('interestSection');
        if (section) section.style.display = this.checked ? '' : 'none';
        wcSyncPreview();
    });

    // Interest type toggle
    document.querySelectorAll('input[name="interest_type"]').forEach(r => {
        r.addEventListener('change', function () {
            const optsSection = document.getElementById('interestOptionsSection');
            if (optsSection) optsSection.style.display = this.value === 'dropdown' ? '' : 'none';
            wcSyncPreview();
        });
    });
}

// ── Clipboard fallback for non-HTTPS contexts ──
function wcCopyTextEmbed(text, btn, origHtml) {
    function showCheck() {
        btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
        setTimeout(() => { btn.innerHTML = origHtml; }, 2000);
    }
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(showCheck).catch(() => wcExecCopy(text, showCheck));
    } else {
        wcExecCopy(text, showCheck);
    }
}
function wcExecCopy(text, onSuccess) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); onSuccess(); } catch (e) {}
    document.body.removeChild(ta);
}

// ── Embed ──
function wcInitEmbed() {
    document.getElementById('copyEmbedBtn')?.addEventListener('click', () => {
        const btn  = document.getElementById('copyEmbedBtn');
        const code = document.getElementById('embedCode')?.value || '';
        wcCopyTextEmbed(code.trim(), btn, btn.innerHTML);
    });

    document.getElementById('regenerateTokenBtn')?.addEventListener('click', () => {
        const url  = window.WebchatFormConfig?.regenerateTokenUrl;
        const csrf = window.WebchatFormConfig?.csrfToken;
        if (!url) return;

        SwalConfirm.fire({
            title:             'Regenerate Token?',
            text:              'This will break existing embed scripts. You will need to update your website.',
            confirmButtonText: 'Yes, regenerate',
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch(url, {
                method:  'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            }).then(r => {
                if (r.ok || r.redirected) window.location.reload();
            });
        });
    });
}

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
    wcInitTabs();
    wcInitIconPicker();
    wcInitBusinessHours();
    wcInitInterestOptions();
    wcInitEmbed();

    wcSetState(1);
    wcSyncPreview();
    wcUpdateSizeCardIcons(document.getElementById('wcIconInput')?.value || 'bi-chat-dots-fill');

    const savedSize = document.querySelector('input[name="size"]:checked')?.value || 'medium';
    wcSelectSize(savedSize);
    document.querySelectorAll('input[name="size"]').forEach(r => {
        r.addEventListener('change', () => wcSelectSize(r.value));
    });

    // Preview navigation
    document.getElementById('wcFloatBtn')?.addEventListener('click',   e => { e.preventDefault(); wcSetState(2); });
    document.getElementById('wcStartBtn')?.addEventListener('click',   e => { e.preventDefault(); wcSetState(3); });
    document.getElementById('wcCloseBtn2')?.addEventListener('click',  e => { e.preventDefault(); wcSetState(1); });
    document.getElementById('wcCloseBtn3')?.addEventListener('click',  e => { e.preventDefault(); wcSetState(1); });

    // Real-time sync
    ['windowTitleInput', 'windowSubtitleInput', 'buttonLabelInput', 'formSubtitleInput', 'welcomeMessage']
        .forEach(id => document.getElementById(id)?.addEventListener('input', wcSyncPreview));

    document.getElementById('primaryColorPicker')?.addEventListener('input', function () {
        const hex = document.getElementById('primaryColorHex');
        if (hex) hex.value = this.value;
        wcSyncPreview();
    });
    document.getElementById('primaryColorHex')?.addEventListener('input', function () {
        const picker = document.getElementById('primaryColorPicker');
        if (picker) picker.value = this.value;
        wcSyncPreview();
    });

    document.querySelectorAll('[name="position"]').forEach(r => r.addEventListener('change', wcSyncPreview));
    document.querySelectorAll('[name="label_position"]').forEach(r => r.addEventListener('change', wcSyncPreview));
    document.querySelectorAll('[name="label_visibility"]').forEach(r => r.addEventListener('change', wcSyncPreview));

    document.querySelectorAll('.interest-option-input').forEach(inp => {
        inp.addEventListener('input', wcSyncPreview);
    });

    // Privacy toggle + fields
    document.getElementById('privacyEnabled')?.addEventListener('change', function () {
        const fields = document.getElementById('privacyFields');
        if (fields) fields.style.display = this.checked ? '' : 'none';
        wcSyncPreview();
    });
    document.querySelectorAll('input[name="privacy_type"]').forEach(r => r.addEventListener('change', wcSyncPreview));
    document.querySelector('textarea[name="privacy_text"]')?.addEventListener('input', wcSyncPreview);
    document.querySelector('input[name="privacy_url"]')?.addEventListener('input', wcSyncPreview);
});
