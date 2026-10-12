/* Dialer Campaigns — index con modales + play/pause/stop */
function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

document.addEventListener('DOMContentLoaded', function () {
    // Delete SwalConfirm
    document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = this.dataset.name;
            SwalConfirm.fire({
                title: 'Delete campaign?',
                html: `This will permanently delete <strong>${name}</strong>.`,
            }).then(result => { if (result.isConfirmed) this.submit(); });
        });
    });

    document.querySelectorAll('form').forEach(form => {
        if (!form.querySelector('.js-dialer-type')) return;

        form.querySelector('.js-dialer-type')?.addEventListener('change', () => syncDialerTypeFields(form));
        form.querySelector('.js-dialer-tenant')?.addEventListener('change', () => filterResourcesForTenant(form, { clearSelection: true }));
        form.querySelector('.js-dialer-campaign')?.addEventListener('change', () => filterScriptsForCampaign(form, { clearSelection: true }));
        filterResourcesForTenant(form);
        syncDialerTypeFields(form);
        filterScriptsForCampaign(form);
    });

    if (window.DialerCampaignsConfig?.hasErrors) {
        openModal('createModal');
    }

    // ── Refresh Stats (async) ─────────────────────────────────────────────────
    document.querySelectorAll('.js-refresh-stats-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var campaignId = this.dataset.campaignId;
            var btn        = this.querySelector('[data-refresh-stats-btn]');
            var savedHtml  = btn.innerHTML;
            var cfg        = window.DialerCampaignsConfig;

            btn.disabled  = true;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refreshing...';

            fetch(this.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN':     cfg.csrf,
                    'Accept':           'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled  = false;
                btn.innerHTML = savedHtml;

                if (data.ok) {
                    var c   = data.campaign;
                    var row = document.querySelector('[data-campaign-row="' + campaignId + '"]');
                    if (row) {
                        var lcEl = row.querySelector('[data-lead-count="' + campaignId + '"]');
                        if (lcEl) lcEl.textContent = c.lead_count.toLocaleString('en-US') + ' leads';

                        var stEl = row.querySelector('[data-stats-text="' + campaignId + '"]');
                        if (stEl) stEl.textContent = c.stats_text;

                        var pbEl = row.querySelector('[data-progress-bar="' + campaignId + '"]');
                        if (pbEl) pbEl.style.width = c.stats.progress + '%';
                    }
                    showToast(data.message || 'Stats refreshed.', 'success');
                } else {
                    showToast('Error: ' + (data.error || 'Unknown error.'), 'error');
                }
            })
            .catch(function () {
                btn.disabled  = false;
                btn.innerHTML = savedHtml;
                showToast('Connection error.', 'error');
            });
        });
    });
});

function campaignAction(id, action) {
    const labels = { play: 'iniciar', pause: 'pausar', stop: 'detener' };
    const confirmNeeded = action === 'stop';

    const doAction = () => {
        const cfg = window.DialerCampaignsConfig;
        fetch(cfg.routes.action + '/' + id + '/' + action, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': cfg.csrf,
                'Accept': 'application/json',
            },
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                showToast(data.message || `Campaign ${action}ed successfully.`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('Error: ' + (data.error || 'No details available.'), 'error');
            }
        })
        .catch(() => showToast('Connection error.', 'error'));
    };

    if (confirmNeeded) {
        SwalConfirm.fire({
            title: 'Stop campaign?',
            html: 'The pending queue will be cleared (undialed leads).<br>Calls already in progress will not be interrupted.',
            confirmButtonText: 'Yes, stop it',
        }).then(result => { if (result.isConfirmed) doAction(); });
    } else {
        doAction();
    }

}

function openEdit(id, tenantId, campaignId, leadListId, callerId, context, maxRetries, blockSize, dialerType, dcName, scriptId, offerTimeoutSeconds, leadOrderDirection) {
    const cfg  = window.DialerCampaignsConfig;
    const form = document.getElementById('editForm');
    form.action = cfg.routes.action + '/' + id;

    const f = attr => form.querySelector(`[name="${attr}"]`);

    // name field: usar getElementById en el modal de edición para evitar conflicto con window.name
    const nameFields = form.querySelectorAll('[name="name"]');
    if (nameFields.length > 0) nameFields[0].value = dcName || '';

    if (f('tenant_id')) f('tenant_id').value = tenantId ?? '';
    f('campaign_id').value     = campaignId;
    f('lead_list_id').value    = leadListId ?? '';
    f('caller_id').value       = callerId;
    f('context').value         = context;
    f('max_retries').value     = maxRetries;
    f('dial_block_size').value = blockSize;
    f('dialer_type').value     = dialerType || 'power';
    if (f('script_id')) f('script_id').value = scriptId ?? '';
    if (f('offer_timeout_seconds')) f('offer_timeout_seconds').value = offerTimeoutSeconds || 60;
    if (f('lead_order_direction')) f('lead_order_direction').value = leadOrderDirection || 'asc';

    filterResourcesForTenant(form);
    syncDialerTypeFields(form);
    filterScriptsForCampaign(form);

    openModal('editModal');
}

function filterResourcesForTenant(form, options = {}) {
    const tenantSelect = form.querySelector('.js-dialer-tenant');
    const tenantId = String(tenantSelect?.value || '');
    const isSuperAdminForm = Boolean(tenantSelect);
    const clearSelection = Boolean(options.clearSelection);

    ['.js-dialer-campaign', '.js-lead-list'].forEach(selector => {
        const select = form.querySelector(selector);
        if (!select) return;

        if (clearSelection) {
            select.value = '';
        }

        let visibleCount = 0;
        Array.from(select.options).forEach(option => {
            if (!option.value) return;

            const matches = !isSuperAdminForm || (Boolean(tenantId) && String(option.dataset.tenantId || '') === tenantId);
            option.hidden = !matches;
            option.disabled = !matches;
            if (matches) visibleCount += 1;
        });

        if (isSuperAdminForm) {
            select.disabled = !tenantId || visibleCount === 0;
        }

        const selected = select.selectedOptions[0];
        if (selected && selected.disabled) {
            select.value = '';
        }
    });

    filterScriptsForCampaign(form, { clearSelection });
}

function syncDialerTypeFields(form) {
    const type = form.querySelector('.js-dialer-type')?.value || 'power';
    const isPreview = type === 'preview';

    form.querySelectorAll('.js-power-fields').forEach(el => {
        el.style.display = isPreview ? 'none' : '';
        el.querySelectorAll('input,select,textarea').forEach(input => {
            input.disabled = isPreview;
            if (input.name === 'caller_id' || input.name === 'context' || input.name === 'dial_block_size') {
                input.required = !isPreview;
            }
        });
    });

    form.querySelectorAll('.js-preview-fields').forEach(el => {
        el.style.display = isPreview ? '' : 'none';
        el.querySelectorAll('input,select,textarea').forEach(input => {
            input.disabled = !isPreview;
            if (input.name === 'script_id' || input.name === 'offer_timeout_seconds') {
                input.required = isPreview;
            }
        });
    });

    form.querySelectorAll('.js-preview-required').forEach(el => {
        el.style.display = isPreview ? '' : 'none';
    });

    const leadList = form.querySelector('[name="lead_list_id"]');
    if (leadList) leadList.required = isPreview;
}

function filterScriptsForCampaign(form, options = {}) {
    const tenantSelect = form.querySelector('.js-dialer-tenant');
    const tenantId = String(tenantSelect?.value || '');
    const campaignId = String(form.querySelector('.js-dialer-campaign')?.value || '');
    const scriptSelect = form.querySelector('.js-script');
    if (!scriptSelect) return;

    const placeholder = Array.from(scriptSelect.options).find(option => option.dataset.placeholder === 'true');
    const noCompatible = Array.from(scriptSelect.options).find(option => option.dataset.noCompatible === 'true');
    const clearSelection = Boolean(options.clearSelection);
    let compatibleCount = 0;

    if (clearSelection) {
        scriptSelect.value = '';
    }

    Array.from(scriptSelect.options).forEach(option => {
        if (!option.value) {
            return;
        }

        const tenantMatches = !tenantSelect || (Boolean(tenantId) && String(option.dataset.tenantId || '') === tenantId);
        const matches = tenantMatches && Boolean(campaignId) && String(option.dataset.campaignId || '') === campaignId;
        option.hidden = !matches;
        option.disabled = !matches;

        if (matches) {
            compatibleCount += 1;
        }
    });

    if (placeholder) {
        placeholder.hidden = Boolean(campaignId);
        placeholder.disabled = Boolean(campaignId);
        placeholder.textContent = campaignId ? '— Select Script —' : '— Select Campaign First —';
    }

    if (noCompatible) {
        noCompatible.hidden = !campaignId || compatibleCount > 0;
        noCompatible.disabled = !campaignId || compatibleCount > 0;
    }

    scriptSelect.disabled = !campaignId || compatibleCount === 0;

    const selected = scriptSelect.selectedOptions[0];
    if (selected && selected.disabled && !selected.dataset.noCompatible) {
        scriptSelect.value = '';
    }

    if (campaignId && compatibleCount === 0) {
        scriptSelect.value = noCompatible ? noCompatible.value : '';
    }

    filterQueueForCampaign(form, { tenantSelect, tenantId, campaignId, clearSelection });
}

// Preview "Queue" select: same campaign/tenant filtering as scripts. When exactly one
// eligible queue exists it is auto-selected (no point forcing a choice with one option).
function filterQueueForCampaign(form, ctx) {
    const queueSelect = form.querySelector('.js-queue');
    if (!queueSelect) return;

    const { tenantSelect, tenantId, campaignId, clearSelection } = ctx;
    const placeholder = Array.from(queueSelect.options).find(o => o.dataset.placeholder === 'true');
    const noCompatible = Array.from(queueSelect.options).find(o => o.dataset.noCompatible === 'true');

    if (clearSelection) queueSelect.value = '';

    let compatible = [];
    Array.from(queueSelect.options).forEach(option => {
        if (!option.value) return;
        const tenantMatches = !tenantSelect || (Boolean(tenantId) && String(option.dataset.tenantId || '') === tenantId);
        const matches = tenantMatches && Boolean(campaignId) && String(option.dataset.campaignId || '') === campaignId;
        option.hidden = !matches;
        option.disabled = !matches;
        if (matches) compatible.push(option);
    });

    if (placeholder) {
        placeholder.hidden = Boolean(campaignId);
        placeholder.disabled = Boolean(campaignId);
        placeholder.textContent = campaignId ? '— Select Queue —' : '— Select Campaign First —';
    }
    if (noCompatible) {
        noCompatible.hidden = !campaignId || compatible.length > 0;
        noCompatible.disabled = !campaignId || compatible.length > 0;
    }

    queueSelect.disabled = !campaignId || compatible.length === 0;

    const selected = queueSelect.selectedOptions[0];
    if (selected && selected.disabled && !selected.dataset.noCompatible) {
        queueSelect.value = '';
    }

    if (campaignId && compatible.length === 0) {
        queueSelect.value = noCompatible ? noCompatible.value : '';
    } else if (campaignId && compatible.length === 1 && !queueSelect.value) {
        // Auto-select the single eligible queue.
        queueSelect.value = compatible[0].value;
    }
}
