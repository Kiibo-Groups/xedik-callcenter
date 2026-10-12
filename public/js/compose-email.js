// ── Compose Email — shared module ─────────────────────────────────────────────
// Requiere: window.ComposeConfig { showDisposition, dispositions, signature, onSendSuccess }
// Jodit y showToast disponibles globalmente desde el layout.

var _composeLead             = null;
var _composeLeadLocked       = false;
var _composeLeadTimer        = null;
var composeAttachments       = [];
var _composeTemplates        = null; // cached template list
var _composeActiveTemplateId = null; // tracks loaded template for lead var replacement

// ── Template dropdown ─────────────────────────────────────────────────────────
window.toggleTemplateDropdown = async function () {
    const dropdown = document.getElementById('composeTemplateDropdown');
    if (!dropdown) return;

    const isOpen = dropdown.style.display === 'block';
    if (isOpen) { dropdown.style.display = 'none'; return; }

    dropdown.style.display = 'block';

    // Load once and cache
    if (!_composeTemplates) {
        try {
            const resp = await fetch('/console/email-templates');
            const data = await resp.json();
            _composeTemplates = data.templates || [];
        } catch (e) {
            _composeTemplates = [];
        }
    }

    const list = document.getElementById('composeTemplateList');
    if (!list) return;

    if (_composeTemplates.length === 0) {
        list.innerHTML = '<div style="padding:10px 14px;font-size:12px;color:var(--text-muted);">No templates available.</div>';
        return;
    }

    list.innerHTML = _composeTemplates.map(t => `
        <div onclick="applyComposeTemplate(${t.id})"
             style="padding:9px 14px;cursor:pointer;border-bottom:0.5px solid var(--border);"
             onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
            <div style="font-size:13px;font-weight:500;color:var(--text-strong);">${t.name}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px;">${t.subject}</div>
        </div>
    `).join('');
};

window.applyComposeTemplate = async function (templateId) {
    document.getElementById('composeTemplateDropdown').style.display = 'none';

    try {
        const url = _composeLead?.id
            ? `/console/email-template/${templateId}/render?lead_id=${_composeLead.id}`
            : `/console/email-template/${templateId}/render`;

        const resp = await fetch(url);
        if (!resp.ok) {
            showToast('Could not load template. Please try again.', 'error');
            return;
        }
        const data = await resp.json();
        document.getElementById('composeSubject').value = data.subject || '';
        if (window.joditCompose) {
            const sig = (window.ComposeConfig?.signature ?? window.agentSignature ?? '');
            window.joditCompose.value = (data.body || '') + (sig ? `<p><br></p><p>--</p>${sig}` : '');
        }
        _composeActiveTemplateId = templateId;
        showToast('Template applied. You can edit before sending.', 'success');
    } catch (e) {
        showToast('Error applying template.', 'error');
    }
};

// Close template dropdown when clicking outside
document.addEventListener('click', function (e) {
    if (!e.target.closest('#composeTemplateBtn') && !e.target.closest('#composeTemplateDropdown')) {
        const dd = document.getElementById('composeTemplateDropdown');
        if (dd) dd.style.display = 'none';
    }
});

// ── Disposition select change (habilita Send cuando es obligatoria) ───────────
window.onDispositionSelectChange = function () {
    const cfg     = window.ComposeConfig || {};
    if (!cfg.requireDisposition) return;
    const sel = document.getElementById('composeDispositionSelect');
    const btn = document.getElementById('composeSendBtn');
    if (!sel || !btn) return;
    btn.disabled    = !sel.value;
    btn.style.opacity = sel.value ? '1' : '0.5';
};

// ── Cerrar modal ──────────────────────────────────────────────────────────────
window.closeComposeModal = function () {
    const modal     = document.getElementById('composeModal');
    const btn       = document.getElementById('composeSendBtn');
    const cancelBtn = document.querySelector('#composeModal .btn-secondary');
    const overlay   = document.getElementById('composeSendingOverlay');

    if (modal) modal.classList.remove('active');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-send"></i> Send'; btn.style.opacity = '1'; }
    if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.style.opacity = '1'; }
    if (overlay) overlay.remove();

    if (window.joditCompose) {
        try { window.joditCompose.destruct(); } catch (e) {}
        window.joditCompose = null;
    }
    composeAttachments       = [];
    _composeTemplates        = null; // reset cache so next open re-fetches
    _composeActiveTemplateId = null;
    renderComposeAttachmentList();
};

// ── Abrir modal ───────────────────────────────────────────────────────────────
window.openCompose = async function (params = {}) {
    if (typeof window.closeToolsMenu === 'function') closeToolsMenu();

    document.getElementById('composeTo').value      = params.to      || '';
    document.getElementById('composeCc').value      = '';
    document.getElementById('composeBcc').value     = '';
    document.getElementById('composeSubject').value = params.subject || '';
    document.getElementById('composeFieldCc').style.display  = 'none';
    document.getElementById('composeFieldBcc').style.display = 'none';
    ['Cc', 'Bcc'].forEach(k => {
        const b = document.getElementById('btn' + k);
        if (b) { b.style.background = 'var(--bg-th)'; b.style.color = 'var(--text-muted)'; b.style.borderColor = 'var(--border)'; }
    });

    _composeLead       = null;
    _composeLeadLocked = false;
    document.getElementById('composeLeadSearch').style.display   = 'block';
    document.getElementById('composeLeadChip').style.display     = 'none';
    document.getElementById('composeLeadInput').value            = '';
    document.getElementById('composeLeadDropdown').style.display = 'none';
    document.getElementById('composeNoLeadHint').style.display   = 'none';
    document.getElementById('composeLeadChipRemove').style.display = 'inline';

    if (params.leadId && params.leadName) {
        _composeLead       = { id: params.leadId, name: params.leadName };
        _composeLeadLocked = true;
        document.getElementById('composeLeadSearch').style.display  = 'none';
        document.getElementById('composeLeadChip').style.display    = 'block';
        document.getElementById('composeLeadChipAvatar').textContent = params.leadName.charAt(0).toUpperCase();
        document.getElementById('composeLeadChipName').textContent   = params.leadName;
        document.getElementById('composeLeadChipRemove').style.display = 'none';
    }

    const cfg        = window.ComposeConfig || {};
    const dispFooter = document.getElementById('composeDispositionFooter');
    const dispLabel  = document.getElementById('composeDispositionLabel');
    const dispSel    = document.getElementById('composeDispositionSelect');
    const sendBtn    = document.getElementById('composeSendBtn');

    if (dispFooter && dispSel) {
        if (cfg.showDisposition && cfg.dispositions && cfg.dispositions.length) {
            dispFooter.style.display = 'flex';
            const placeholder = cfg.requireDisposition ? 'Select disposition' : '— Optional —';
            dispSel.innerHTML = `<option value="">${placeholder}</option>` +
                cfg.dispositions.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
            if (dispLabel) {
                dispLabel.innerHTML = cfg.requireDisposition
                    ? 'Disposition <span style="color:#dc2626">*</span>'
                    : 'Disposition';
            }
        } else {
            dispFooter.style.display = 'none';
        }
    }

    // Bloquear Send si la disposición es obligatoria
    if (sendBtn) {
        if (cfg.requireDisposition && cfg.showDisposition) {
            sendBtn.disabled    = true;
            sendBtn.style.opacity = '0.5';
        } else {
            sendBtn.disabled    = false;
            sendBtn.style.opacity = '1';
        }
    }

    try {
        const resp = await fetch('/console/compose/accounts');
        const data = await resp.json();
        if (data.email_accounts) initComposeFrom(data.email_accounts);
    } catch (e) {}

    composeAttachments = [];
    renderComposeAttachmentList();
    document.getElementById('composeModal').classList.add('active');

    setTimeout(() => {
        if (window.joditCompose) {
            try { window.joditCompose.destruct(); } catch (e) {}
            window.joditCompose = null;
        }
        window.joditCompose = Jodit.make('#composeBody', {
            height: 220,
            toolbarButtonSize: 'small',
            language: 'en',
            toolbarAdaptive: false,
            buttons: 'bold,italic,underline,strikethrough,|,ul,ol,|,font,fontsize,brush,|,image,table,link,|,align,|,undo,redo,|,eraser',
            uploader: { insertImageAsBase64URI: true },
            placeholder: 'Write your message...',
            statusbar: false,
            showCharsCounter: false,
            showWordsCounter: false,
            showXPathInStatusbar: false,
            addNewLine: false,
            maxHeight: 350,
            allowResizeY: true,
            allowResizeX: false,
        });
        const sig = cfg.signature ?? window.agentSignature ?? '';
        window.joditCompose.value = sig ? `<p><br></p><p>--</p>${sig}` : '';
        if (!params.leadId) document.getElementById('composeTo').focus();
    }, 100);
};

// ── Selector de cuenta From ───────────────────────────────────────────────────
function initComposeFrom(accounts) {
    const text     = document.getElementById('composeFromText');
    const dropdown = document.getElementById('composeFromDropdown');
    const wrap     = document.getElementById('composeFromWrap');

    window._composeSelectedAccount = accounts[0] || null;

    if (!accounts || accounts.length === 0) {
        text.textContent = 'No email account configured';
        return;
    }

    text.textContent = accounts[0].display;

    if (accounts.length > 1) {
        wrap.style.cssText = 'flex:1;position:relative;display:flex;align-items:center;cursor:pointer;gap:4px';
        if (!document.getElementById('composeFromChevron')) {
            const chevron = document.createElement('span');
            chevron.id = 'composeFromChevron';
            chevron.innerHTML = '▾';
            chevron.style.cssText = 'font-size:13px;color:var(--text-muted);opacity:0.7;flex-shrink:0;line-height:1';
            document.getElementById('composeFromText').parentElement.appendChild(chevron);
        }
        dropdown.innerHTML = accounts.map((a, i) => `
            <div data-id="${a.id}" data-email="${a.email}" data-name="${a.name || ''}" data-display="${a.display}"
                 onclick="selectComposeFrom(this)"
                 style="padding:10px 14px;cursor:pointer;${i === 0 ? 'color:var(--primary)' : ''}"
                 onmouseover="this.style.background='var(--bg-hover, rgba(255,255,255,0.05))'"
                 onmouseout="this.style.background='transparent'">
                <div style="font-size:13px;font-weight:500">${a.name || a.email}</div>
                <div style="font-size:11px;color:var(--text-muted)">${a.email}</div>
            </div>
        `).join('');

        wrap.onclick = (e) => {
            if (e.target.closest('#composeFromDropdown')) return;
            dropdown.style.background = 'var(--bg-th, #162035)';
            dropdown.style.border     = '1px solid var(--border, #2d3f55)';
            dropdown.style.boxShadow  = '0 4px 16px rgba(0,0,0,0.3)';
            dropdown.style.display    = dropdown.style.display === 'none' ? 'block' : 'none';
        };
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#composeFromWrap'))
                dropdown.style.display = 'none';
        });
    }
}

window.selectComposeFrom = function (el) {
    window._composeSelectedAccount = {
        id: el.dataset.id, email: el.dataset.email,
        name: el.dataset.name, display: el.dataset.display,
    };
    document.getElementById('composeFromText').textContent = el.dataset.display;
    document.getElementById('composeFromDropdown').style.display = 'none';
    document.querySelectorAll('#composeFromDropdown div[data-id]').forEach(d => d.style.color = '');
    el.style.color = 'var(--primary)';
};

function toggleComposeField(field) {
    const key       = field.charAt(0).toUpperCase() + field.slice(1);
    const el        = document.getElementById('composeField' + key);
    const btn       = document.getElementById('btn' + key);
    const isVisible = el.style.display === 'flex';
    el.style.display = isVisible ? 'none' : 'flex';
    if (btn) {
        btn.style.background  = isVisible ? 'var(--bg-th)'      : 'var(--primary)';
        btn.style.color       = isVisible ? 'var(--text-muted)' : '#fff';
        btn.style.borderColor = isVisible ? 'var(--border)'     : 'var(--primary)';
    }
    if (!isVisible) document.getElementById('compose' + key).focus();
}

// ── Búsqueda de lead ──────────────────────────────────────────────────────────
function debounceComposeLeadSearch(val) {
    clearTimeout(_composeLeadTimer);
    const dd = document.getElementById('composeLeadDropdown');
    if (val.length < 1) { dd.style.display = 'none'; return; }
    dd.style.display = 'block';
    dd.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:var(--text-muted);">Searching...</div>';
    _composeLeadTimer = setTimeout(() => runComposeLeadSearch(val), 300);
}

async function runComposeLeadSearch(q) {
    const dd   = document.getElementById('composeLeadDropdown');
    const resp = await fetch(`/console/leads/search?q=${encodeURIComponent(q)}`);
    const data = await resp.json();
    if (!data.leads || data.leads.length === 0) {
        dd.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:var(--text-muted);">No leads found.</div>';
        return;
    }
    dd.innerHTML = data.leads.map(l => `
        <div onclick="selectComposeLead(${l.id}, '${(l.first_name + ' ' + l.last_name).trim().replace(/'/g, "\\'")}', '${(l.email || '').replace(/'/g, "\\'")}') "
             style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:0.5px solid var(--border);"
             onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--primary);color:white;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                ${(l.first_name || '?').charAt(0).toUpperCase()}
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:500;color:var(--text-strong);">${l.first_name} ${l.last_name}</div>
                <div style="font-size:11px;color:var(--text-muted);">${l.email || ''} ${l.phone ? '· ' + l.phone : ''} ${l.campaign_name ? '· ' + l.campaign_name : ''}</div>
            </div>
        </div>
    `).join('');
}

function selectComposeLead(id, name, email) {
    _composeLead = { id, name };
    document.getElementById('composeLeadSearch').style.display  = 'none';
    document.getElementById('composeLeadChip').style.display    = 'block';
    document.getElementById('composeLeadChipAvatar').textContent = name.charAt(0).toUpperCase();
    document.getElementById('composeLeadChipName').textContent   = name;
    document.getElementById('composeLeadDropdown').style.display = 'none';
    document.getElementById('composeNoLeadHint').style.display   = 'none';
    const toInput = document.getElementById('composeTo');
    if (toInput && !toInput.value.trim() && email) toInput.value = email;

    if (_composeActiveTemplateId) {
        applyLeadVarsToCompose(id);
    }
}

async function applyLeadVarsToCompose(leadId) {
    try {
        const resp = await fetch(`/console/lead/${leadId}`);
        if (!resp.ok) return;
        const lead = await resp.json();

        const val = (v) => (v !== null && v !== undefined && v !== '') ? String(v) : '—';

        const map = {
            '{{lead.first_name}}': val(lead.first_name),
            '{{lead.last_name}}':  val(lead.last_name),
            '{{lead.email}}':      val(lead.email),
            '{{lead.phone}}':      val(lead.phone),
            '{{lead.phone_2}}':    val(lead.phone_2),
            '{{lead.address}}':    val(lead.address),
            '{{lead.city}}':       val(lead.city),
            '{{lead.state}}':      val(lead.state),
            '{{lead.zip}}':        val(lead.zip),
            '{{lead.country}}':    val(lead.country),
            '{{lead.status}}':     val(lead.status),
            '{{lead.source}}':     val(lead.source),
            '{{lead.value}}':      val(lead.value),
            '{{lead.notes}}':      val(lead.notes),
        };

        if (window.joditCompose) {
            let html = window.joditCompose.value;
            for (const [placeholder, value] of Object.entries(map)) {
                html = html.split(placeholder).join(value);
            }
            window.joditCompose.value = html;
        }

        const subjectEl = document.getElementById('composeSubject');
        if (subjectEl) {
            let subject = subjectEl.value;
            for (const [placeholder, value] of Object.entries(map)) {
                subject = subject.split(placeholder).join(value);
            }
            subjectEl.value = subject;
        }
    } catch (e) {}
}

function clearComposeLead() {
    if (_composeLeadLocked) return;
    _composeLead = null;
    document.getElementById('composeLeadChip').style.display   = 'none';
    document.getElementById('composeLeadSearch').style.display = 'block';
    document.getElementById('composeLeadInput').value = '';
    document.getElementById('composeLeadInput').focus();
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('#composeLeadWrap')) {
        const dd = document.getElementById('composeLeadDropdown');
        if (dd) dd.style.display = 'none';
    }
});

// ── Adjuntos ──────────────────────────────────────────────────────────────────
function handleComposeAttachments(files) {
    composeAttachments = [...composeAttachments, ...Array.from(files)];
    renderComposeAttachmentList();
    document.getElementById('composeAttachmentInput').value = '';
}

function removeComposeAttachment(index) {
    composeAttachments.splice(index, 1);
    renderComposeAttachmentList();
}

function renderComposeAttachmentList() {
    const list  = document.getElementById('composeAttachmentList');
    const count = document.getElementById('composeAttachCount');
    if (!list) return;
    if (composeAttachments.length === 0) {
        list.innerHTML = '';
        if (count) count.textContent = '';
        return;
    }
    if (count) count.textContent = composeAttachments.length + ' file' + (composeAttachments.length > 1 ? 's' : '') + ' attached';
    list.innerHTML = composeAttachments.map((f, i) => `
        <div style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:11px;color:var(--text-mid);background:var(--bg-th);">
            <i class="bi bi-paperclip" style="font-size:11px;"></i>
            <span>${f.name}</span>
            <span onclick="removeComposeAttachment(${i})" style="cursor:pointer;color:var(--danger);font-weight:700;margin-left:2px;font-size:13px;">×</span>
        </div>
    `).join('');
}

// ── Enviar ────────────────────────────────────────────────────────────────────
window.sendCompose = async function () {
    const to      = document.getElementById('composeTo').value.trim();
    const cc      = document.getElementById('composeCc')?.value.trim()  || '';
    const bcc     = document.getElementById('composeBcc')?.value.trim() || '';
    const subject = document.getElementById('composeSubject').value.trim();
    const body    = window.joditCompose?.value?.trim() || '';

    if (!to || !subject || !body) { showToast('Please fill all required fields.', 'error'); return; }

    const cfg2    = window.ComposeConfig || {};
    const dispSel = document.getElementById('composeDispositionSelect');
    if (cfg2.requireDisposition && cfg2.showDisposition && (!dispSel || !dispSel.value)) {
        showToast('Please select a disposition before sending.', 'error');
        return;
    }

    const hint = document.getElementById('composeNoLeadHint');
    if (!_composeLead) {
        if (hint) hint.style.display = 'flex';
    } else {
        if (hint) hint.style.display = 'none';
    }

    const btn       = document.getElementById('composeSendBtn');
    const cancelBtn = document.querySelector('#composeModal .btn-secondary');
    btn.disabled    = true;
    btn.innerHTML   = '<i class="bi bi-hourglass-split"></i> Sending...';
    btn.style.opacity = '0.7';
    if (cancelBtn) { cancelBtn.disabled = true; cancelBtn.style.opacity = '0.4'; }

    const modalBody = document.querySelector('#composeModal .modal-body');
    const overlay   = document.createElement('div');
    overlay.id = 'composeSendingOverlay';
    overlay.style.cssText = 'position:absolute;inset:0;background:rgba(255,255,255,.75);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;z-index:10;border-radius:var(--radius);';
    overlay.innerHTML = `
        <div style="width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;"></div>
        <div style="font-size:13px;font-weight:500;color:var(--text-strong);">Sending email...</div>
    `;
    modalBody.style.position = 'relative';
    modalBody.appendChild(overlay);

    const resetBtn = () => {
        overlay.remove();
        btn.disabled    = false;
        btn.innerHTML   = '<i class="bi bi-send"></i> Send';
        btn.style.opacity = '1';
        if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.style.opacity = '1'; }
    };

    try {
        const formData = new FormData();
        formData.append('to', to);
        formData.append('subject', subject);
        formData.append('body', body);
        if (cc)  formData.append('cc', cc);
        if (bcc) formData.append('bcc', bcc);
        if (_composeLead?.id) formData.append('lead_id', _composeLead.id);
        if (window._composeSelectedAccount) {
            formData.append('email_account_id', window._composeSelectedAccount.id);
        }
        if (dispSel && dispSel.value) formData.append('disposition_id', dispSel.value);

        composeAttachments.forEach((file, i) => formData.append(`attachments[${i}]`, file));

        const resp = await fetch('/console/compose', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: formData,
        });

        const data = await resp.json();
        if (data.ok) {
            closeComposeModal();
            showToast('Email sent successfully.', 'success');
            if (typeof window.ComposeConfig?.onSendSuccess === 'function') {
                await window.ComposeConfig.onSendSuccess(data);
            }
        } else {
            resetBtn();
            showToast(data.error || 'Error sending email.', 'error');
        }
    } catch (e) {
        resetBtn();
        showToast('Connection error. Please try again.', 'error');
    }
};
