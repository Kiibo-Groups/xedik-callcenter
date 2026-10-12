/* Dialer Campaign edit page — tabs + field sync + caller-id card toggles */

/* ─────────────────────────────────────────────────────────────────────────
   Shared helpers (mirrors dialer-campaigns.js; keep in sync if logic changes)
   ───────────────────────────────────────────────────────────────────────── */
function filterResourcesForTenant(form, options) {
    options = options || {};
    const tenantSelect = form.querySelector('.js-dialer-tenant');
    const tenantId = String(tenantSelect ? tenantSelect.value : '');
    const isSuperAdminForm = Boolean(tenantSelect);
    const clearSelection = Boolean(options.clearSelection);

    ['.js-dialer-campaign', '.js-lead-list'].forEach(function (selector) {
        const select = form.querySelector(selector);
        if (!select) return;
        if (clearSelection) select.value = '';

        let visibleCount = 0;
        Array.from(select.options).forEach(function (option) {
            if (!option.value) return;
            const matches = !isSuperAdminForm || (Boolean(tenantId) && String(option.dataset.tenantId || '') === tenantId);
            option.hidden   = !matches;
            option.disabled = !matches;
            if (matches) visibleCount += 1;
        });

        if (isSuperAdminForm) select.disabled = !tenantId || visibleCount === 0;

        const selected = select.selectedOptions[0];
        if (selected && selected.disabled) select.value = '';
    });

    filterScriptsForCampaign(form, { clearSelection: clearSelection });
}

function syncDialerTypeFields(form) {
    const type = (form.querySelector('.js-dialer-type') || {}).value || 'power';
    const isPreview = type === 'preview';

    form.querySelectorAll('.js-power-fields').forEach(function (el) {
        el.style.display = isPreview ? 'none' : '';
        el.querySelectorAll('input,select,textarea').forEach(function (input) {
            input.disabled = isPreview;
            if (['caller_id', 'context', 'dial_block_size'].includes(input.name)) {
                input.required = !isPreview;
            }
        });
    });

    form.querySelectorAll('.js-preview-fields').forEach(function (el) {
        el.style.display = isPreview ? '' : 'none';
        el.querySelectorAll('input,select,textarea').forEach(function (input) {
            input.disabled = !isPreview;
            if (['script_id', 'offer_timeout_seconds'].includes(input.name)) {
                input.required = isPreview;
            }
        });
    });

    form.querySelectorAll('.js-preview-required').forEach(function (el) {
        el.style.display = isPreview ? '' : 'none';
    });

    const leadList = form.querySelector('[name="lead_list_id"]');
    if (leadList) leadList.required = isPreview;
}

function filterScriptsForCampaign(form, options) {
    options = options || {};
    const tenantSelect  = form.querySelector('.js-dialer-tenant');
    const tenantId      = String(tenantSelect ? tenantSelect.value : '');
    const campaignId    = String((form.querySelector('.js-dialer-campaign') || {}).value || '');
    const scriptSelect  = form.querySelector('.js-script');
    if (!scriptSelect) return;

    const placeholder   = Array.from(scriptSelect.options).find(function (o) { return o.dataset.placeholder  === 'true'; });
    const noCompatible  = Array.from(scriptSelect.options).find(function (o) { return o.dataset.noCompatible === 'true'; });
    const clearSelection = Boolean(options.clearSelection);
    let compatibleCount = 0;

    if (clearSelection) scriptSelect.value = '';

    Array.from(scriptSelect.options).forEach(function (option) {
        if (!option.value) return;
        const tenantMatches = !tenantSelect || (Boolean(tenantId) && String(option.dataset.tenantId || '') === tenantId);
        const matches = tenantMatches && Boolean(campaignId) && String(option.dataset.campaignId || '') === campaignId;
        option.hidden   = !matches;
        option.disabled = !matches;
        if (matches) compatibleCount += 1;
    });

    if (placeholder) {
        placeholder.hidden    = Boolean(campaignId);
        placeholder.disabled  = Boolean(campaignId);
        placeholder.textContent = campaignId ? '— Select Script —' : '— Select Campaign First —';
    }
    if (noCompatible) {
        noCompatible.hidden   = !campaignId || compatibleCount > 0;
        noCompatible.disabled = !campaignId || compatibleCount > 0;
    }

    scriptSelect.disabled = !campaignId || compatibleCount === 0;

    const selected = scriptSelect.selectedOptions[0];
    if (selected && selected.disabled && !selected.dataset.noCompatible) scriptSelect.value = '';
    if (campaignId && compatibleCount === 0) scriptSelect.value = noCompatible ? noCompatible.value : '';

    filterQueueForCampaign(form, { tenantSelect: tenantSelect, tenantId: tenantId, campaignId: campaignId, clearSelection: clearSelection });
}

// Preview "Queue" select: same campaign/tenant filtering as scripts. When exactly one
// eligible queue exists it is auto-selected.
function filterQueueForCampaign(form, ctx) {
    const queueSelect = form.querySelector('.js-queue');
    if (!queueSelect) return;

    const tenantSelect = ctx.tenantSelect;
    const tenantId = ctx.tenantId;
    const campaignId = ctx.campaignId;
    const placeholder  = Array.from(queueSelect.options).find(function (o) { return o.dataset.placeholder  === 'true'; });
    const noCompatible = Array.from(queueSelect.options).find(function (o) { return o.dataset.noCompatible === 'true'; });

    if (ctx.clearSelection) queueSelect.value = '';

    const compatible = [];
    Array.from(queueSelect.options).forEach(function (option) {
        if (!option.value) return;
        const tenantMatches = !tenantSelect || (Boolean(tenantId) && String(option.dataset.tenantId || '') === tenantId);
        const matches = tenantMatches && Boolean(campaignId) && String(option.dataset.campaignId || '') === campaignId;
        option.hidden   = !matches;
        option.disabled = !matches;
        if (matches) compatible.push(option);
    });

    if (placeholder) {
        placeholder.hidden   = Boolean(campaignId);
        placeholder.disabled = Boolean(campaignId);
        placeholder.textContent = campaignId ? '— Select Queue —' : '— Select Campaign First —';
    }
    if (noCompatible) {
        noCompatible.hidden   = !campaignId || compatible.length > 0;
        noCompatible.disabled = !campaignId || compatible.length > 0;
    }

    queueSelect.disabled = !campaignId || compatible.length === 0;

    const selected = queueSelect.selectedOptions[0];
    if (selected && selected.disabled && !selected.dataset.noCompatible) queueSelect.value = '';

    if (campaignId && compatible.length === 0) {
        queueSelect.value = noCompatible ? noCompatible.value : '';
    } else if (campaignId && compatible.length === 1 && !queueSelect.value) {
        queueSelect.value = compatible[0].value;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
   Tab switching
   ───────────────────────────────────────────────────────────────────────── */
function activateTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.tab === tabName);
    });
    document.querySelectorAll('.tab-panel').forEach(function (panel) {
        panel.classList.toggle('active', panel.id === 'tab-' + tabName);
    });
}

/* ─────────────────────────────────────────────────────────────────────────
   Caller ID card selection
   ───────────────────────────────────────────────────────────────────────── */
function updateCidCount() {
    const count = document.querySelectorAll('#callerIdGrid input[type="checkbox"]:checked').length;
    const el = document.getElementById('cidSelectedCount');
    if (el) el.textContent = count + ' selected';
}

/* ─────────────────────────────────────────────────────────────────────────
   Init
   ───────────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    /* — Tabs — */
    const initialTab = (window.DialerCampaignEditConfig || {}).initialTab || 'general';
    activateTab(initialTab);

    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const tabName = btn.dataset.tab;
            history.replaceState(null, '', location.pathname + '?tab=' + tabName);
            activateTab(tabName);
        });
    });

    /* — General form field sync — */
    const generalForm = document.querySelector('#tab-general form');
    if (generalForm) {
        const typeSelect = generalForm.querySelector('.js-dialer-type');
        if (typeSelect) typeSelect.addEventListener('change', function () { syncDialerTypeFields(generalForm); });

        const tenantSelect = generalForm.querySelector('.js-dialer-tenant');
        if (tenantSelect) tenantSelect.addEventListener('change', function () { filterResourcesForTenant(generalForm, { clearSelection: true }); });

        const campaignSelect = generalForm.querySelector('.js-dialer-campaign');
        if (campaignSelect) campaignSelect.addEventListener('change', function () { filterScriptsForCampaign(generalForm, { clearSelection: true }); });

        filterResourcesForTenant(generalForm);
        syncDialerTypeFields(generalForm);
        filterScriptsForCampaign(generalForm);
    }

    /* — Caller ID cards — */
    document.querySelectorAll('.caller-id-card').forEach(function (card) {
        card.addEventListener('change', function () {
            const checked = card.querySelector('input[type="checkbox"]').checked;
            card.classList.toggle('selected', checked);
            updateCidCount();
        });
    });

    const clearAll = document.getElementById('cidClearAll');
    if (clearAll) {
        clearAll.addEventListener('click', function () {
            document.querySelectorAll('#callerIdGrid input[type="checkbox"]').forEach(function (cb) {
                cb.checked = false;
                cb.closest('.caller-id-card').classList.remove('selected');
            });
            updateCidCount();
        });
    }

    updateCidCount();
});
