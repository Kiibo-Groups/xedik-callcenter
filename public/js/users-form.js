(function () {
    'use strict';

    const cfg = window.UsersFormConfig || {};

    // ── Tab switching ─────────────────────────────────────────────────────────

    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = btn.dataset.tab;
            document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
            btn.classList.add('active');
            const panel = document.getElementById('tab-' + target);
            if (panel) { panel.classList.add('active'); }
        });
    });

    // ── Password toggle ───────────────────────────────────────────────────────

    document.querySelectorAll('.password-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = btn.previousElementSibling;
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) { icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye'; }
        });
    });

    // ── Submit guard ──────────────────────────────────────────────────────────

    const submitBtn = document.getElementById('formSubmitBtn');
    const usersForm = document.getElementById('usersForm');
    if (usersForm && submitBtn) {
        usersForm.addEventListener('submit', function () {
            submitBtn.disabled = true;
        });
    }

    // ── Superadmin-only: dynamic role/voice/callerID filtering ────────────────

    if (!cfg.isSuperAdmin) { return; }

    const tenantSel   = document.getElementById('formTenantId');
    const roleSel     = document.getElementById('formRole');
    const voiceTabBtn = document.querySelector('.js-tab-voice');
    const callerIdSel = document.getElementById('formPersonalCallerId');
    const noMsg       = document.getElementById('noCallerIdsMsg');
    const tenantDef   = document.getElementById('formTenantDefault');

    function filterRolesByTenant(tenantId) {
        if (!roleSel) { return; }
        roleSel.querySelectorAll('option').forEach(function (opt) {
            const tid = opt.dataset.tenant;
            opt.hidden = tenantId ? (tid !== String(tenantId)) : false;
        });
        const visible = roleSel.querySelector('option:not([hidden])');
        if (visible && !roleSel.value) { roleSel.value = visible.value; }
    }

    function updateVoiceTab(tenantId) {
        if (!voiceTabBtn) { return; }
        const enabled = tenantId ? Boolean(cfg.tenantVoiceMap[tenantId]) : false;
        voiceTabBtn.style.display = enabled ? '' : 'none';
        if (!enabled) {
            // Switch away from voice tab if it was active
            const isActive = voiceTabBtn.classList.contains('active');
            if (isActive) {
                voiceTabBtn.classList.remove('active');
                document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
                const generalBtn = document.querySelector('[data-tab="general"]');
                if (generalBtn) { generalBtn.classList.add('active'); }
                const generalPanel = document.getElementById('tab-general');
                if (generalPanel) { generalPanel.classList.add('active'); }
            }
        }
    }

    function updateCallerIdDropdown(tenantId, preselectId) {
        if (!callerIdSel) { return; }
        callerIdSel.innerHTML = '<option value="">— Use tenant / campaign default —</option>';

        const cids = tenantId ? (cfg.voiceCallerIdsMap[tenantId] || []) : [];

        if (cids.length === 0) {
            if (noMsg) { noMsg.style.display = 'block'; }
        } else {
            if (noMsg) { noMsg.style.display = 'none'; }
            cids.forEach(function (c) {
                const opt = document.createElement('option');
                opt.value       = c.id;
                opt.textContent = c.label;
                if (String(c.id) === String(preselectId)) { opt.selected = true; }
                callerIdSel.appendChild(opt);
            });
        }
    }

    function updateTenantDefault(tenantId) {
        if (!tenantDef) { return; }
        const def = tenantId ? (cfg.tenantDefaultCallerIdMap[tenantId] || '—') : '—';
        tenantDef.textContent = def;
    }

    function onTenantChange(tenantId, preselectId) {
        filterRolesByTenant(tenantId);
        updateVoiceTab(tenantId);
        updateCallerIdDropdown(tenantId, preselectId);
        updateTenantDefault(tenantId);
    }

    if (tenantSel) {
        tenantSel.addEventListener('change', function () {
            onTenantChange(tenantSel.value, '');
        });

        // On page load: if a tenant is already selected (edit mode or old()), apply immediately
        if (tenantSel.value) {
            onTenantChange(tenantSel.value, cfg.initialVoiceCallerId || '');
        }
    }

}());
