(function () {
    'use strict';

    let _currentConvIdForLead = null;
    let _leadSearchTimer = null;
    let _leadStatuses = [];
    let _associateInFlight = false;
    let _createLeadInFlight = false;

    // Status / Campaign searchable dropdowns
    let _statusDropdownLeadId   = null;
    let _campaignDropdownLeadId = null;
    let _campaignItems          = null; // cached, shared across opens
    let _campaignPromise        = null; // in-flight fetch coalescing

    // Tags
    let _availableTags = null; // null = not fetched yet
    let _tagDropdownLeadId = null;
    let _tagDropdownAssignedIds = new Set();
    let _tagAssignInFlight = false;
    let _panelOpts = {};
    const _dynamicLeadPayloadByConv = new Map();

    /**
     * Fetch /console/campaigns/my with cache + coalescing.
     * Multiple concurrent callers share a single in-flight request.
     * Subsequent calls after the first success resolve from _campaignItems.
     * Deduplication by ID is applied so that any accidental backend duplicates
     * never reach the select element.
     */
    function _fetchCampaigns() {
        if (_campaignItems !== null) {
            return Promise.resolve(_campaignItems);
        }
        if (_campaignPromise) {
            return _campaignPromise;
        }
        _campaignPromise = fetch('/console/campaigns/my')
            .then(r => r.json())
            .then(data => {
                const raw   = data.campaigns || [];
                // Dedup by id — protects against double backend response
                _campaignItems = [...new Map(raw.map(c => [String(c.id), c])).values()];
                return _campaignItems;
            })
            .finally(() => {
                _campaignPromise = null;
            });
        return _campaignPromise;
    }

    function _inputStyle(extra) {
        extra = extra || '';
        return `padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:12px;background:var(--bg-input);color:var(--text-strong);outline:none;width:100%;box-sizing:border-box;${extra}`;
    }

    function _esc(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function _editableField(leadId, field, label, value) {
        const display = value || '—';
        return `
        <div class="lead-field-row" style="display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid var(--border-light);">
            <span style="width:80px;font-size:11px;color:var(--text-muted);flex-shrink:0;">${label}</span>
            <div id="lf-${field}-display-${leadId}" style="flex:1;display:flex;align-items:center;gap:6px;">
                <span style="font-size:12px;color:var(--text-strong);">${display}</span>
                <button onclick="activateLeadFieldEdit(${leadId},'${field}','${(value||'').replace(/'/g,"\\'")}','${label}')"
                    style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px;opacity:0;" class="lead-edit-btn">
                    <i class="bi bi-pencil" style="font-size:11px;"></i>
                </button>
            </div>
            <div id="lf-${field}-edit-${leadId}" style="display:none;flex:1;align-items:center;gap:4px;">
                <input id="lf-${field}-input-${leadId}" value="${value||''}" style="flex:1;${_inputStyle('height:26px;font-size:12px;')}"
                    onkeydown="if(event.key==='Enter') saveLeadField(${leadId},'${field}',this.value); if(event.key==='Escape') cancelLeadField(${leadId},'${field}');">
                <button onclick="saveLeadField(${leadId},'${field}',document.getElementById('lf-${field}-input-${leadId}').value)"
                    style="background:none;border:none;cursor:pointer;color:#22c55e;font-size:16px;"><i class="bi bi-check-circle-fill"></i></button>
                <button onclick="cancelLeadField(${leadId},'${field}')"
                    style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:16px;"><i class="bi bi-x-circle-fill"></i></button>
            </div>
        </div>
    `;
    }

    function _removeNoLeadBadge(convId) {
        document.querySelector(`.conv-item[data-id="${convId}"] .no-lead-badge`)?.remove();
    }

    /**
     * After Create Lead or Associate Lead succeeds, update the Voice adapter so the
     * tray card and header reflect the new lead immediately.
     *
     * Handles only Voice (where the active interaction maps by conversationId).
     * For Email/Facebook the existing DOM-based _removeNoLeadBadge is sufficient.
     * Does NOT re-render the central panel (renderLeadPanel already did that) and
     * does NOT restart timer, modify SIP, or alter the timeline.
     */
    function _notifyVoiceLeadUpdated(convId, lead) {
        const adapter = window.agentConsoleVoiceAdapter;
        if (!adapter?.activeVoiceInteractionId || !lead) return;
        const interaction = adapter.voiceInteractions?.get(adapter.activeVoiceInteractionId);
        if (!interaction || String(interaction.conversationId) !== String(convId)) return;

        interaction.lead            = lead;
        interaction.leadId          = lead.id;
        interaction.leadLookupStatus = 'found';
        const firstName             = lead.first_name || '';
        const lastName              = lead.last_name  || '';
        interaction.displayName     = [firstName, lastName].filter(Boolean).join(' ')
            || interaction.phone;

        // Re-render only the tray card so NO LEAD disappears and name updates.
        // Central panel was already updated by renderLeadPanel().
        adapter.renderTrayInteractions?.();
    }

    function _notifyVoiceDynamicLeadRowUpdated(convId, payload) {
        const adapter = window.agentConsoleVoiceAdapter;
        if (!adapter?.voiceInteractions || !payload?.dynamic_lead_row) return;

        Array.from(adapter.voiceInteractions.values()).forEach(interaction => {
            if (String(interaction.conversationId) !== String(convId)) return;
            interaction.dynamicLeadRow = payload.dynamic_lead_row;
            interaction.leadList = payload.lead_list || interaction.leadList || null;
            interaction.dialerCampaign = payload.dialer_campaign || interaction.dialerCampaign || null;
            interaction.crmCampaign = payload.campaign || interaction.crmCampaign || null;
            if (!interaction.lead) {
                interaction.leadLookupStatus = 'dynamic_row';
            }
        });
    }

    function renderLeadPanelEmpty(conv, opts) {
        opts = opts || {};
        _currentConvIdForLead = conv.id;
        const isWaLead = _isChatLike(conv.channel);
        const contextLabel = isWaLead || conv.channel === 'voice'
            ? 'conversation'
            : 'email';
        const canPersist = opts.canPersist !== false;
        const panel = document.getElementById('leadPanel');
        panel.innerHTML = `
        <div style="padding:14px;display:flex;flex-direction:column;gap:12px;">
            ${opts.lookupFailed ? `
            <div style="background:var(--bg-th);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;font-size:12px;color:var(--text-muted);line-height:1.5;">
                Lead lookup could not be completed.
                <button type="button" onclick="window.agentConsoleVoiceAdapter?.retryVoiceLeadLookup('${opts.retryInteractionId || ''}')" style="border:0;background:none;color:var(--primary);cursor:pointer;padding:0 0 0 4px;font:inherit;">Retry</button>
            </div>` : ''}
            <div style="background:#fef9c3;border:1px solid #fde047;border-radius:var(--radius-sm);padding:10px 12px;font-size:12px;color:#854d0e;line-height:1.5;">
                <i class="bi bi-exclamation-triangle-fill" style="margin-right:6px;"></i>
                No lead associated. Fill in the details below to create one from this ${contextLabel}.
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <input id="nlFirstName" placeholder="First Name *" style="${_inputStyle()}">
                    <input id="nlLastName"  placeholder="Last Name *"  style="${_inputStyle()}">
                </div>
                <input id="nlPhone" placeholder="Phone *" value="${conv.contact_phone || ''}" style="${_inputStyle()}">
                <input id="nlEmail" placeholder="Email" value="${conv.contact_email || ''}" style="${_inputStyle()}">
                <select id="nlCampaign" style="${_inputStyle()}">
                    <option value="">Campaign *</option>
                </select>
                <div id="nlError" style="display:none;color:var(--danger);font-size:11px;"></div>
            </div>
            <button ${canPersist ? `onclick="submitCreateLead(${conv.id})"` : 'disabled title="A persisted Voice conversation is required to create and associate the Lead."'} class="btn btn-primary" style="width:100%;display:flex;align-items:center;justify-content:center;gap:6px;">
                <i class="bi bi-person-plus"></i> Create Lead from ${contextLabel === 'conversation' ? 'Conversation' : 'Email'}
            </button>
            <div style="text-align:center;${canPersist ? '' : 'display:none;'}">
                <a href="#" onclick="openSearchLeadModal(${conv.id});return false;" style="font-size:12px;color:var(--primary);">Search existing lead →</a>
            </div>
        </div>
    `;
        // Use shared cache + coalescing so concurrent calls produce one request.
        // replaceChildren() before adding ensures idempotency regardless of how
        // many times renderLeadPanelEmpty is called.
        _fetchCampaigns().then(campaigns => {
            const sel = document.getElementById('nlCampaign');
            if (!sel) return; // panel was replaced while fetch was in flight
            // Rebuild select from scratch — placeholder + deduped campaigns
            sel.replaceChildren();
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Campaign *';
            sel.appendChild(placeholder);
            campaigns.forEach(c => {
                const o = document.createElement('option');
                o.value = c.id;
                o.textContent = c.name;
                sel.appendChild(o);
            });
        }).catch(() => {/* non-critical — user can retry */});
    }

    function renderLeadPanelSuggested(conv, leads) {
        _currentConvIdForLead = conv.id;
        const panel = document.getElementById('leadPanel');
        panel.innerHTML = `
        <div style="padding:14px;display:flex;flex-direction:column;gap:8px;">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Possible matches found:</div>
            ${leads.map(l => `
                <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);">
                    <div style="width:34px;height:34px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;">
                        ${(l.first_name||'?').charAt(0).toUpperCase()}
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${l.first_name} ${l.last_name}</div>
                        <div style="font-size:11px;color:var(--text-muted);">${l.email||''} ${l.phone ? '· '+l.phone : ''}</div>
                        <div style="font-size:11px;color:var(--text-muted);">${l.campaign_name||''}</div>
                    </div>
                    <button onclick="doAssociateLead(${conv.id}, ${l.id})" class="btn btn-primary" style="font-size:11px;padding:4px 10px;">Associate</button>
                </div>
            `).join('')}
            <div style="text-align:center;margin-top:4px;">
                <a href="#" onclick="openSearchLeadModal(${conv.id});return false;" style="font-size:12px;color:var(--primary);">Search different lead →</a>
            </div>
        </div>
    `;
    }

    function renderDynamicLeadRow(conv, payload) {
        _currentConvIdForLead = conv?.id ?? null;
        payload = payload || {};
        const row = payload.dynamic_lead_row || payload.row || payload;
        const leadList = payload.lead_list || {};
        const dialerCampaign = payload.dialer_campaign || null;
        const crmCampaign = payload.campaign || null;
        const customFields = row?.custom_fields && typeof row.custom_fields === 'object'
            ? row.custom_fields
            : {};
        const panel = document.getElementById('leadPanel');
        if (!panel || !row) return;
        const convId = conv?.id ?? _currentConvIdForLead;
        if (convId) {
            _dynamicLeadPayloadByConv.set(String(convId), {
                dynamic_lead_row: row,
                lead_list: leadList,
                dialer_campaign: dialerCampaign,
                campaign: crmCampaign,
            });
        }

        const sourceName = leadList.name || leadList.table_name || 'Dynamic lead list';
        const dialerName = dialerCampaign?.name || 'Preview dialer';
        const mapping = {
            'First Name': 'first_name',
            'Last Name': 'last_name',
            'Email': 'email',
            'Phone': 'phone',
            'Street Address': 'address',
            'City': 'city',
            'State': 'state',
            'Zip': 'zip',
        };

        const fieldRow = (label, field, value, opts) => {
            opts = opts || {};
            const mappedTo = opts.mappedTo || null;
            const infoTitle = opts.infoTitle
                || (mappedTo ? `Mapped to CRM lead field: ${mappedTo}` : null);
            const displayValue = value === null || value === undefined || value === ''
                ? '—'
                : value;
            const rawValue = value === null || value === undefined ? '' : String(value);
            const fieldKey = encodeURIComponent(field);
            const rowId = `dlf-${_esc(convId)}-${fieldKey}`;
            return `
                <div id="${rowId}-row" class="dynamic-lead-field-row" style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid var(--border-light);">
                    <div style="width:96px;font-size:11px;color:var(--text-muted);flex-shrink:0;line-height:1.4;">
                        ${_esc(label)}
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div id="${rowId}-display" style="display:flex;align-items:flex-start;gap:6px;">
                            <button type="button" onclick="activateDynamicLeadFieldEdit('${_esc(convId)}','${fieldKey}')"
                                style="flex:1;text-align:left;border:0;background:none;padding:0;cursor:pointer;font-size:12px;color:var(--text-strong);word-break:break-word;">
                                <span>${_esc(displayValue)}</span>
                            </button>
                            ${infoTitle ? `<span title="${_esc(infoTitle)}" style="color:var(--text-muted);font-size:11px;line-height:1.4;padding-top:1px;cursor:help;"><i class="bi bi-info-circle"></i></span>` : ''}
                            <button type="button" onclick="activateDynamicLeadFieldEdit('${_esc(convId)}','${fieldKey}')"
                                title="Edit"
                                style="border:0;background:none;color:var(--text-muted);cursor:pointer;padding:1px 0;">
                                <i class="bi bi-pencil" style="font-size:11px;"></i>
                            </button>
                        </div>
                        <div id="${rowId}-edit" style="display:none;align-items:center;gap:4px;">
                            <input id="${rowId}-input" value="${_esc(rawValue)}" style="${_inputStyle('height:28px;font-size:12px;')}"
                                onkeydown="if(event.key==='Enter') saveDynamicLeadField('${_esc(convId)}','${fieldKey}'); if(event.key==='Escape') cancelDynamicLeadFieldEdit('${_esc(convId)}','${fieldKey}');">
                            <button id="${rowId}-save" type="button" onclick="saveDynamicLeadField('${_esc(convId)}','${fieldKey}')"
                                title="Save" style="background:none;border:none;cursor:pointer;color:#22c55e;font-size:15px;"><i class="bi bi-check-circle-fill"></i></button>
                            <button id="${rowId}-cancel" type="button" onclick="cancelDynamicLeadFieldEdit('${_esc(convId)}','${fieldKey}')"
                                title="Cancel" style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:15px;"><i class="bi bi-x-circle-fill"></i></button>
                        </div>
                    </div>
                </div>
            `;
        };

        const customRows = Object.entries(customFields).map(([key, value]) => {
            const displayValue = value === null || value === undefined || value === ''
                ? '—'
                : (typeof value === 'object' ? JSON.stringify(value) : String(value));
            const infoTitle = key === 'Phone'
                ? 'List field mapped to phone, but Main Phone has priority.'
                : null;
            return fieldRow(key, key, displayValue, { mappedTo: mapping[key] || null, infoTitle });
        }).join('');

        panel.innerHTML = `
            <div style="display:flex;flex-direction:column;height:100%;overflow:hidden;">
                <div style="flex:1;overflow-y:auto;padding:4px 14px 14px 14px;">
                    ${fieldRow('Main Phone', '__phone', row.phone, { primary: true, infoTitle: 'Used as CRM lead phone by default.' })}
                    ${customRows || '<div style="padding:12px 0;color:var(--text-muted);font-size:12px;">No custom fields.</div>'}

                    <div style="margin-top:14px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-th);padding:4px 10px;display:flex;flex-direction:column;gap:4px;">
                        <div style="font-size:11px;color:var(--text-muted);">Source: <span style="color:var(--text-strong);font-weight:600;">${_esc(sourceName)}</span></div>
                        <div style="font-size:11px;color:var(--text-muted);">Dialer: <span style="color:var(--text-strong);font-weight:600;">${_esc(dialerName)}</span></div>
                    </div>
                    <div style="margin-top:10px;">
                        <button id="dynamicCreateLeadBtn-${_esc(convId)}" type="button" onclick="createLeadFromDynamicRow('${_esc(convId)}')"
                            class="btn btn-primary"
                            style="width:100%;display:flex;align-items:center;justify-content:center;gap:6px;">
                            <i class="bi bi-person-plus"></i> Create Lead from List
                        </button>
                        <div style="text-align:center;margin-top:8px;">
                            <a href="#" onclick="openSearchLeadModal(${_esc(convId)});return false;" style="font-size:12px;color:var(--primary);">Search existing lead &rarr;</a>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function _dynamicRowId(convId, encodedField) {
        return `dlf-${_esc(convId)}-${encodedField}`;
    }

    function activateDynamicLeadFieldEdit(convId, encodedField) {
        const rowId = _dynamicRowId(convId, encodedField);
        const displayDiv = document.getElementById(`${rowId}-display`);
        const editDiv = document.getElementById(`${rowId}-edit`);
        const input = document.getElementById(`${rowId}-input`);
        if (!displayDiv || !editDiv || !input) return;

        displayDiv.style.display = 'none';
        editDiv.style.display = 'flex';
        input.focus();
        input.select();
    }

    function cancelDynamicLeadFieldEdit(convId, encodedField) {
        const rowId = _dynamicRowId(convId, encodedField);
        const displayDiv = document.getElementById(`${rowId}-display`);
        const editDiv = document.getElementById(`${rowId}-edit`);
        if (displayDiv) displayDiv.style.display = 'flex';
        if (editDiv) editDiv.style.display = 'none';
    }

    function _setDynamicLeadFieldSaving(convId, encodedField, saving) {
        const rowId = _dynamicRowId(convId, encodedField);
        ['input', 'save', 'cancel'].forEach(suffix => {
            const el = document.getElementById(`${rowId}-${suffix}`);
            if (el) el.disabled = Boolean(saving);
        });
        const row = document.getElementById(`${rowId}-row`);
        if (row) row.style.opacity = saving ? '.65' : '1';
    }

    async function saveDynamicLeadField(convId, encodedField) {
        const field = decodeURIComponent(encodedField);
        const rowId = _dynamicRowId(convId, encodedField);
        const input = document.getElementById(`${rowId}-input`);
        if (!input) return;

        const previousPayload = _dynamicLeadPayloadByConv.get(String(convId)) || null;
        _setDynamicLeadFieldSaving(convId, encodedField, true);
        try {
            const resp = await fetch(`/console/conversation/${encodeURIComponent(convId)}/dynamic-lead-row/field`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ field, value: input.value }),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok || !data.dynamic_lead_row) {
                throw new Error(data.error || 'dynamic_lead_row_update_failed');
            }

            const payload = {
                dynamic_lead_row: data.dynamic_lead_row,
                lead_list: data.lead_list || previousPayload?.lead_list || null,
                dialer_campaign: data.dialer_campaign || previousPayload?.dialer_campaign || null,
                campaign: data.campaign || previousPayload?.campaign || null,
            };
            _dynamicLeadPayloadByConv.set(String(convId), payload);
            _notifyVoiceDynamicLeadRowUpdated(convId, payload);
            renderDynamicLeadRow({ id: convId }, payload);
        } catch (_) {
            showToast('Error saving list row field', 'error');
            cancelDynamicLeadFieldEdit(convId, encodedField);
        } finally {
            _setDynamicLeadFieldSaving(convId, encodedField, false);
        }
    }

    async function createLeadFromDynamicRow(convId) {
        if (_createLeadInFlight) return;
        const btn = document.getElementById(`dynamicCreateLeadBtn-${convId}`);
        _createLeadInFlight = true;
        if (btn) {
            btn.disabled = true;
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;"></span> Creating...';
        }

        try {
            const resp = await fetch(`/console/conversation/${encodeURIComponent(convId)}/dynamic-lead-row/create-lead`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({}),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok || !data.lead) {
                throw new Error(data.error || data.message || 'dynamic_create_lead_failed');
            }

            renderLeadPanel({ id: convId }, data.lead);
            _dynamicLeadPayloadByConv.delete(String(convId));
            _removeNoLeadBadge(convId);
            _notifyVoiceLeadUpdated(convId, data.lead);
            showToast('Lead created from list row.', 'success');
        } catch (_) {
            showToast('Error creating lead from list row', 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = btn.dataset.originalHtml || '<i class="bi bi-person-plus"></i> Create Lead from List';
            }
        } finally {
            _createLeadInFlight = false;
        }
    }

    function renderLeadPanel(conv, lead, opts) {
        _panelOpts = opts || {};
        // Limpiar listeners de dropdowns del render anterior
        document.removeEventListener('keydown', _closeStatusDropdownOnEsc);
        document.removeEventListener('click',   _closeStatusDropdownOutside);
        document.removeEventListener('keydown', _closeCampaignDropdownOnEsc);
        document.removeEventListener('click',   _closeCampaignDropdownOutside);
        document.removeEventListener('keydown', _closeTagDropdownOnEsc);
        document.removeEventListener('click',   _closeTagDropdownOutside);
        _statusDropdownLeadId   = null;
        _campaignDropdownLeadId = null;
        _tagDropdownLeadId      = null;

        if (conv != null) _currentConvIdForLead = conv?.id ?? null;
        _removeNoLeadBadge(_currentConvIdForLead);
        const panel = document.getElementById(_panelOpts.containerId || 'leadPanel');
        if (!panel) return;
        const fullName = [lead.first_name, lead.last_name].filter(Boolean).join(' ') || '—';
        const initial  = (lead.first_name || lead.last_name || '?').charAt(0).toUpperCase();


        panel.innerHTML = `
        <div style="display:flex;flex-direction:column;height:100%;overflow:hidden;">
            <div style="overflow-y:auto;flex-shrink:0;padding:14px 14px 0;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                    <div id="lead-avatar-${lead.id}" style="width:42px;height:42px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;flex-shrink:0;">${initial}</div>
                    <div style="flex:1;min-width:0;">
                        <div id="lead-fullname-${lead.id}" style="font-size:14px;font-weight:700;color:var(--text-strong);">${fullName}</div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:2px;margin-bottom:10px;">
                    ${_editableField(lead.id, 'first_name',  'First Name',  lead.first_name)}
                    ${_editableField(lead.id, 'last_name',   'Last Name',   lead.last_name)}
                    ${_editableField(lead.id, 'email',       'Email',       lead.email)}
                    ${_editableField(lead.id, 'phone',       'Phone',       lead.phone)}
                    ${_editableField(lead.id, 'address',     'Address',     lead.address)}
                    ${_editableField(lead.id, 'city',        'City',        lead.city)}
                    ${_editableField(lead.id, 'state',       'State',       lead.state)}
                    ${_editableField(lead.id, 'zip',         'Zip',         lead.zip)}
                    <div class="lead-field-row" style="display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid var(--border-light);">
                        <span style="width:80px;font-size:11px;color:var(--text-muted);flex-shrink:0;">Status</span>
                        <div id="lf-status-display-${lead.id}" style="flex:1;display:flex;align-items:center;gap:6px;">
                            <span id="lf-status-badge-${lead.id}" style="display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:${lead.status_bg};color:${lead.status_color};">${lead.status_label||'—'}</span>
                            <button onclick="activateLeadStatusEdit(${lead.id},'${lead.status}')" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px;opacity:0;" class="lead-edit-btn"><i class="bi bi-pencil" style="font-size:11px;"></i></button>
                        </div>
                        <div id="lf-status-edit-${lead.id}" style="display:none;flex:1;flex-direction:column;">
                            <div style="border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);box-shadow:var(--shadow-lg);overflow:hidden;">
                                <div style="padding:4px 8px;border-bottom:1px solid var(--border);">
                                    <input type="text" id="lf-status-search-${lead.id}" placeholder="Search status…"
                                        oninput="filterStatusDropdown(${lead.id},this.value)"
                                        style="width:100%;border:none;background:transparent;outline:none;font-size:12px;color:var(--text-strong);">
                                </div>
                                <div id="lf-status-list-${lead.id}" class="scrollbar-none" style="max-height:150px;overflow-y:auto;padding:3px 0;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="lead-field-row" id="lf-campaign-row-${lead.id}" style="display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid var(--border-light);">
                        <span style="width:80px;font-size:11px;color:var(--text-muted);flex-shrink:0;">Campaign</span>
                        <div id="lf-campaign_id-display-${lead.id}" style="flex:1;display:flex;align-items:center;gap:6px;">
                            <span style="font-size:12px;color:var(--text-strong);">${lead.campaign_name||'—'}</span>
                            <button onclick="activateLeadCampaignEdit(${lead.id})" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px;opacity:0;" class="lead-edit-btn"><i class="bi bi-pencil" style="font-size:11px;"></i></button>
                        </div>
                        <div id="lf-campaign_id-edit-${lead.id}" style="display:none;flex:1;flex-direction:column;">
                            <div style="border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);box-shadow:var(--shadow-lg);overflow:hidden;">
                                <div style="padding:4px 8px;border-bottom:1px solid var(--border);">
                                    <input type="text" id="lf-campaign-search-${lead.id}" placeholder="Search campaign…"
                                        oninput="filterCampaignDropdown(${lead.id},this.value)"
                                        style="width:100%;border:none;background:transparent;outline:none;font-size:12px;color:var(--text-strong);">
                                </div>
                                <div id="lf-campaign-list-${lead.id}" class="scrollbar-none" style="max-height:150px;overflow-y:auto;padding:3px 0;"></div>
                            </div>
                        </div>
                    </div>
                    <div style="padding:6px 0 4px;">
                        <div style="font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Tags</div>
                        <div id="lead-tags-pills" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;min-height:24px;">
                            ${(lead.tags || []).map(t => `
                                <span class="lead-tag-pill" data-tag-id="${t.id}" style="background:${t.bg_color};color:${t.color};display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:500;">
                                    ${t.name}<button onclick="unassignTag(${lead.id},${t.id})" style="background:none;border:none;cursor:pointer;padding:0 0 0 2px;line-height:1;color:inherit;opacity:.65;font-size:12px;">&times;</button>
                                </span>
                            `).join('')}
                            <button onclick="openTagDropdown(${lead.id})" id="tag-add-btn" style="border:1.5px dashed var(--border);background:none;border-radius:20px;padding:1px 8px;font-size:11px;color:var(--text-muted);cursor:pointer;line-height:1.7;white-space:nowrap;">+ Add tag</button>
                        </div>
                        <div id="lead-tag-dropdown" style="display:none;margin-top:4px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:20;position:relative;">
                            <div style="padding:5px 8px;border-bottom:1px solid var(--border);">
                                <input type="text" id="tag-search-input" placeholder="Search tags…"
                                    oninput="filterTagDropdown(this.value)"
                                    style="width:100%;border:none;background:transparent;outline:none;font-size:12px;color:var(--text-strong);">
                            </div>
                            <div id="tag-dropdown-list" class="scrollbar-none" style="max-height:152px;overflow-y:auto;padding:3px 0;"></div>
                        </div>
                    </div>
                    <div style="padding:8px 12px;">
                        <button type="button" onclick="window.open('/leads/${lead.id}', '_blank')"
                           style="display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:8px 12px;background:var(--primary);color:white;border-radius:var(--radius-sm);font-size:13px;font-weight:500;cursor:pointer;box-sizing:border-box;border:none;"
                           onmouseover="this.style.background='var(--primary-dark)'"
                           onmouseout="this.style.background='var(--primary)'">
                            <i class="bi bi-person-lines-fill" style="font-size:14px;"></i>
                            View Full Lead
                        </button>
                    </div>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;flex:1;min-height:0;border-top:1px solid var(--border);">
                <div style="display:flex;border-bottom:2px solid var(--border);flex-shrink:0;padding:0 14px;">
                    <button id="tab-history-btn" onclick="switchLeadTab('history',${lead.id})"
                        style="flex:1;padding:7px;font-size:12px;font-weight:600;background:none;border:none;cursor:pointer;color:var(--primary);border-bottom:2px solid var(--primary);margin-bottom:-2px;">
                        History
                    </button>
                    <button id="tab-notes-btn" onclick="switchLeadTab('notes',${lead.id})"
                        style="flex:1;padding:7px;font-size:12px;font-weight:600;background:none;border:none;cursor:pointer;color:var(--text-muted);border-bottom:2px solid transparent;margin-bottom:-2px;">
                        Notes
                    </button>
                </div>
                <div id="tab-history-content" class="scrollbar-none" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow-y:auto;padding:8px 12px;">
                    <div style="color:var(--text-muted);font-size:12px;text-align:center;padding:12px;">Loading...</div>
                </div>
                <div id="tab-notes-content" style="display:none;flex:1;min-height:0;flex-direction:column;">
                    <div style="display:flex;flex-direction:column;flex:1;min-height:0;padding:8px 12px;">
                        <textarea id="leadNotesTA" style="flex:1;min-height:0;height:100%;width:100%;resize:none;${_inputStyle()}">${lead.notes||''}</textarea>
                        <div style="display:flex;justify-content:flex-end;padding-top:6px;flex-shrink:0;">
                            <button onclick="saveLeadNotes(${lead.id})" class="btn btn-primary" style="font-size:12px;padding:5px 12px;">Save Notes</button>
                        </div>
                    </div>
                </div>
            </div>
            ${_panelOpts.showAssociate !== false ? `
            <div style="padding:14px 12px;border-top:0.5px solid var(--border);text-align:center;font-size:11px;flex-shrink:0;">
                <a href="#" onclick="openSearchLeadModal(${_currentConvIdForLead});return false;" style="font-size:11px;color:var(--text-muted);">Associate different lead</a>
            </div>
            ` : ''}
        </div>
    `;

        panel.querySelectorAll('.lead-field-row').forEach(row => {
            row.addEventListener('mouseenter', () => row.querySelectorAll('.lead-edit-btn').forEach(b => b.style.opacity = '1'));
            row.addEventListener('mouseleave', () => row.querySelectorAll('.lead-edit-btn').forEach(b => b.style.opacity = '0'));
        });

        loadLeadHistory(lead.id);
    }

    function activateLeadFieldEdit(leadId, field, currentVal, label) {
        const displayDiv = document.getElementById(`lf-${field}-display-${leadId}`);
        displayDiv.style.display = 'none';
        const editDiv = document.getElementById(`lf-${field}-edit-${leadId}`);
        editDiv.style.display = 'flex';
        const input = document.getElementById(`lf-${field}-input-${leadId}`);
        // Read the current displayed value from the span rather than the onclick parameter.
        // The onclick attribute is set at render time and becomes stale after saveLeadField()
        // updates span.textContent without re-rendering the entire panel.
        const liveSpan = displayDiv.querySelector('span');
        const liveVal  = liveSpan && liveSpan.textContent !== '—' ? liveSpan.textContent.trim() : '';
        input.value = liveVal !== '' ? liveVal : currentVal;
        input.focus();
        input.select();
    }

    function activateLeadStatusEdit(leadId) {
        _statusDropdownLeadId = leadId;
        document.getElementById(`lf-status-display-${leadId}`).style.display = 'none';
        document.getElementById(`lf-status-edit-${leadId}`).style.display = 'flex';
        _renderStatusDropdownList(leadId, '');
        const input = document.getElementById(`lf-status-search-${leadId}`);
        if (input) { input.value = ''; input.focus(); }
        setTimeout(() => {
            document.addEventListener('click', _closeStatusDropdownOutside);
            document.addEventListener('keydown', _closeStatusDropdownOnEsc);
        }, 0);
    }

    async function activateLeadCampaignEdit(leadId) {
        _campaignDropdownLeadId = leadId;
        document.getElementById(`lf-campaign_id-display-${leadId}`).style.display = 'none';
        document.getElementById(`lf-campaign_id-edit-${leadId}`).style.display = 'flex';
        if (_campaignItems === null) {
            const list = document.getElementById(`lf-campaign-list-${leadId}`);
            if (list) list.innerHTML = '<div style="padding:8px 10px;font-size:11px;color:var(--text-muted);">Loading…</div>';
            // Use shared cache+coalescing — same request as renderLeadPanelEmpty
            await _fetchCampaigns();
        }
        _renderCampaignDropdownList(leadId, '');
        const input = document.getElementById(`lf-campaign-search-${leadId}`);
        if (input) { input.value = ''; input.focus(); }
        setTimeout(() => {
            document.addEventListener('click', _closeCampaignDropdownOutside);
            document.addEventListener('keydown', _closeCampaignDropdownOnEsc);
        }, 0);
    }

    // ── Status searchable dropdown ─────────────────────────────────────────────

    function _closeStatusDropdownOnEsc(e) {
        if (e.key !== 'Escape') return;
        _dismissStatusDropdown();
    }

    function _closeStatusDropdownOutside(e) {
        if (!_statusDropdownLeadId) return;
        const editDiv = document.getElementById(`lf-status-edit-${_statusDropdownLeadId}`);
        if (editDiv && editDiv.contains(e.target)) return;
        _dismissStatusDropdown();
    }

    function _dismissStatusDropdown() {
        document.removeEventListener('keydown', _closeStatusDropdownOnEsc);
        document.removeEventListener('click', _closeStatusDropdownOutside);
        if (_statusDropdownLeadId) cancelLeadField(_statusDropdownLeadId, 'status');
        _statusDropdownLeadId = null;
    }

    function filterStatusDropdown(leadId, q) {
        _renderStatusDropdownList(leadId, q);
    }

    function _renderStatusDropdownList(leadId, q) {
        const list = document.getElementById(`lf-status-list-${leadId}`);
        if (!list) return;
        const query    = q.trim().toLowerCase();
        const filtered = query ? _leadStatuses.filter(s => s.name.toLowerCase().includes(query)) : _leadStatuses;
        list.innerHTML = filtered.map(s =>
            `<div onclick="selectLeadStatus(${leadId},'${s.slug}','${s.name.replace(/'/g, "\\'")}','${s.bg_color}','${s.color}')"
                  style="display:flex;align-items:center;gap:8px;padding:5px 10px;cursor:pointer;"
                  onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
                <span style="display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:${s.bg_color};color:${s.color};">${s.name}</span>
             </div>`
        ).join('') || '<div style="padding:8px 10px;font-size:11px;color:var(--text-muted);">No results.</div>';
    }

    async function selectLeadStatus(leadId, slug, name, bgColor, color) {
        document.removeEventListener('keydown', _closeStatusDropdownOnEsc);
        document.removeEventListener('click', _closeStatusDropdownOutside);
        _statusDropdownLeadId = null;
        try {
            const resp = await fetch(`/console/lead/${leadId}/field`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ field: 'status', value: slug }),
            });
            const data = await resp.json();
            if (data.ok) {
                const badge = document.getElementById(`lf-status-badge-${leadId}`);
                if (badge) { badge.textContent = name; badge.style.background = bgColor; badge.style.color = color; }
                cancelLeadField(leadId, 'status');
                if (_panelOpts.onLeadUpdated) _panelOpts.onLeadUpdated(leadId, 'status', slug, {name, bg_color: bgColor, color});
            } else {
                showToast('Error saving status', 'error');
            }
        } catch (_) {
            showToast('Error saving status', 'error');
        }
    }

    // ── Campaign searchable dropdown ───────────────────────────────────────────

    function _closeCampaignDropdownOnEsc(e) {
        if (e.key !== 'Escape') return;
        _dismissCampaignDropdown();
    }

    function _closeCampaignDropdownOutside(e) {
        if (!_campaignDropdownLeadId) return;
        const editDiv = document.getElementById(`lf-campaign_id-edit-${_campaignDropdownLeadId}`);
        if (editDiv && editDiv.contains(e.target)) return;
        _dismissCampaignDropdown();
    }

    function _dismissCampaignDropdown() {
        document.removeEventListener('keydown', _closeCampaignDropdownOnEsc);
        document.removeEventListener('click', _closeCampaignDropdownOutside);
        if (_campaignDropdownLeadId) cancelLeadField(_campaignDropdownLeadId, 'campaign_id');
        _campaignDropdownLeadId = null;
    }

    function filterCampaignDropdown(leadId, q) {
        _renderCampaignDropdownList(leadId, q);
    }

    function _renderCampaignDropdownList(leadId, q) {
        const list = document.getElementById(`lf-campaign-list-${leadId}`);
        if (!list || _campaignItems === null) return;
        const query    = q.trim().toLowerCase();
        const filtered = query ? _campaignItems.filter(c => c.name.toLowerCase().includes(query)) : _campaignItems;
        let html = `<div onclick="selectLeadCampaign(${leadId},0,'')"
                         style="display:flex;align-items:center;gap:8px;padding:5px 10px;cursor:pointer;font-size:12px;color:var(--text-muted);"
                         onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
                        <i class="bi bi-x-circle"></i> None
                    </div>`;
        html += filtered.map(c =>
            `<div onclick="selectLeadCampaign(${leadId},${c.id},'${c.name.replace(/'/g, "\\'")}' )"
                  style="display:flex;align-items:center;gap:8px;padding:5px 10px;cursor:pointer;font-size:12px;"
                  onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
                ${c.name}
             </div>`
        ).join('');
        if (!filtered.length) html += '<div style="padding:8px 10px;font-size:11px;color:var(--text-muted);">No results.</div>';
        list.innerHTML = html;
    }

    async function selectLeadCampaign(leadId, campaignId, name) {
        document.removeEventListener('keydown', _closeCampaignDropdownOnEsc);
        document.removeEventListener('click', _closeCampaignDropdownOutside);
        _campaignDropdownLeadId = null;
        try {
            const resp = await fetch(`/console/lead/${leadId}/field`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ field: 'campaign_id', value: campaignId ? String(campaignId) : null }),
            });
            const data = await resp.json();
            if (data.ok) {
                const displaySpan = document.querySelector(`#lf-campaign_id-display-${leadId} span`);
                if (displaySpan) displaySpan.textContent = name || '—';
                cancelLeadField(leadId, 'campaign_id');
                if (_panelOpts.onLeadUpdated) _panelOpts.onLeadUpdated(leadId, 'campaign_id', campaignId ? String(campaignId) : null, {name});
            } else {
                showToast('Error saving campaign', 'error');
            }
        } catch (_) {
            showToast('Error saving campaign', 'error');
        }
    }

    function cancelLeadField(leadId, field) {
        document.getElementById(`lf-${field}-display-${leadId}`).style.display = 'flex';
        document.getElementById(`lf-${field}-edit-${leadId}`).style.display = 'none';
    }

    async function saveLeadField(leadId, field, value) {
        const resp = await fetch(`/console/lead/${leadId}/field`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ field, value })
        });
        const data = await resp.json();
        if (data.ok) {
            const displayDiv = document.getElementById(`lf-${field}-display-${leadId}`);
            if (field === 'status') {
                const statusObj = _leadStatuses.find(s => s.slug === value);
                const badge = document.getElementById(`lf-status-badge-${leadId}`);
                if (badge && statusObj) { badge.textContent = statusObj.name; badge.style.background = statusObj.bg_color; badge.style.color = statusObj.color; }
                cancelLeadField(leadId, field);
            } else if (field === 'first_name' || field === 'last_name') {
                displayDiv.querySelector('span').textContent = value || '—';
                cancelLeadField(leadId, field);
                const fnEl = document.getElementById(`lf-first_name-display-${leadId}`)?.querySelector('span');
                const lnEl = document.getElementById(`lf-last_name-display-${leadId}`)?.querySelector('span');
                const fn = field === 'first_name' ? (value || '') : (fnEl?.textContent !== '—' ? fnEl?.textContent || '' : '');
                const ln = field === 'last_name'  ? (value || '') : (lnEl?.textContent !== '—' ? lnEl?.textContent || '' : '');
                const newName = [fn, ln].filter(Boolean).join(' ') || '—';
                const initial = (fn || ln || '?').charAt(0).toUpperCase();
                const nameEl   = document.getElementById(`lead-fullname-${leadId}`);
                const avatarEl = document.getElementById(`lead-avatar-${leadId}`);
                if (nameEl)   nameEl.textContent   = newName;
                if (avatarEl) avatarEl.textContent  = initial;
                // Notify tray cards for all channels immediately — no poll needed
                if (typeof window.notifyLeadUpdated === 'function') {
                    window.notifyLeadUpdated(leadId, fn, ln);
                }
            } else if (field === 'campaign_id') {
                const campaignItem = (_campaignItems || []).find(c => String(c.id) === String(value));
                displayDiv.querySelector('span').textContent = campaignItem?.name || '—';
                cancelLeadField(leadId, field);
            } else {
                displayDiv.querySelector('span').textContent = value || '—';
                cancelLeadField(leadId, field);
            }
            if (_panelOpts.onLeadUpdated) _panelOpts.onLeadUpdated(leadId, field, value, null);
        } else {
            showToast('Error saving field', 'error');
        }
    }

    function switchLeadTab(tab, leadId) {
        const isHistory = tab === 'history';
        document.getElementById('tab-history-content').style.display = isHistory ? 'flex' : 'none';
        document.getElementById('tab-notes-content').style.display   = isHistory ? 'none' : 'flex';
        document.getElementById('tab-history-btn').style.color        = isHistory ? 'var(--primary)' : 'var(--text-muted)';
        document.getElementById('tab-history-btn').style.borderBottomColor = isHistory ? 'var(--primary)' : 'transparent';
        document.getElementById('tab-notes-btn').style.color          = isHistory ? 'var(--text-muted)' : 'var(--primary)';
        document.getElementById('tab-notes-btn').style.borderBottomColor   = isHistory ? 'transparent' : 'var(--primary)';
        if (isHistory) loadLeadHistory(leadId);
    }

    async function loadLeadHistory(leadId) {
        const container = document.getElementById('tab-history-content');
        if (!container) return;
        const resp = await fetch(`/console/lead/${leadId}/history`);
        const data = await resp.json();
        if (!data.history || data.history.length === 0) {
            container.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-muted);">No history yet.</div>';
            return;
        }
        const iconMap = {
            email:          '<i class="bi bi-envelope-fill" style="color:#3b82f6;"></i>',
            whatsapp:       '<i class="bi bi-whatsapp" style="color:#25d366;"></i>',
            chat:           '<i class="bi bi-chat-fill" style="color:#3C3489;"></i>',
            facebook:       '<i class="bi bi-facebook" style="color:#1877F2;"></i>',
            instagram:      '<i class="bi bi-instagram" style="color:#C13584;"></i>',
            sms:            '<i class="bi bi-chat-text" style="color:#854F0B;"></i>',
            call_answered:  '<i class="bi bi-telephone-fill" style="color:#22c55e;"></i>',
            call_missed:    '<i class="bi bi-telephone-x-fill" style="color:#ef4444;"></i>',
            // Voice conversations — same green (#3b6d11) as the Voice tray badge
            voice_inbound:  '<i class="bi bi-telephone-inbound-fill" style="color:#3b6d11;"></i>',
            voice_outbound: '<i class="bi bi-telephone-outbound-fill" style="color:#3b6d11;"></i>',
            voice:          '<i class="bi bi-telephone-fill" style="color:#3b6d11;"></i>', // fallback
        };
        const dirBadge = dir => {
            if (!dir) return '';
            const inbound = dir === 'inbound';
            return `<span style="font-size:9px;font-weight:600;padding:1px 6px;border-radius:20px;background:${inbound ? '#dbeafe' : '#dcfce7'};color:${inbound ? '#1d4ed8' : '#166534'};">${inbound ? 'Inbound' : 'Outbound'}</span>`;
        };
        container.innerHTML = data.history.map(h => `
        <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-light);">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--bg-th);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:13px;">
                ${iconMap[h.icon_type] || ''}
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${h.title}</div>
                <div style="display:flex;align-items:center;gap:5px;margin-top:2px;">
                    ${h.direction ? dirBadge(h.direction) : ''}
                    ${h.subtitle ? `<span style="font-size:11px;color:var(--text-muted);">${h.subtitle}</span>` : ''}
                </div>
            </div>
            <div style="font-size:10px;color:var(--text-muted);white-space:nowrap;">${h.date}</div>
        </div>
    `).join('');
    }

    async function saveLeadNotes(leadId) {
        const notes = document.getElementById('leadNotesTA')?.value ?? '';
        const resp = await fetch(`/console/lead/${leadId}/notes`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ notes })
        });
        const data = await resp.json();
        if (data.ok) showToast('Notes saved.', 'success');
        else showToast('Error saving notes', 'error');
    }

    // Resuelve el texto de error de una respuesta JSON del backend en el orden:
    // response.message → response.error → primer(os) mensaje(s) de response.errors
    // (validación Laravel) → fallback. Nunca inventa texto si el backend ya lo trae.
    function _resolveBackendError(data, fallback) {
        if (data && typeof data.message === 'string' && data.message.trim()) return data.message.trim();
        if (data && typeof data.error === 'string' && data.error.trim()) return data.error.trim();
        if (data && data.errors && typeof data.errors === 'object') {
            const msgs = Object.values(data.errors)
                .reduce((acc, v) => acc.concat(Array.isArray(v) ? v : [v]), [])
                .filter(m => typeof m === 'string' && m.trim());
            if (msgs.length) return msgs.join(' ');
        }
        return fallback;
    }

    async function submitCreateLead(convId) {
        if (_createLeadInFlight) return;

        const firstName  = document.getElementById('nlFirstName')?.value.trim();
        const lastName   = document.getElementById('nlLastName')?.value.trim();
        const phone      = document.getElementById('nlPhone')?.value.trim();
        const email      = document.getElementById('nlEmail')?.value.trim();
        const campaignId = document.getElementById('nlCampaign')?.value;
        const errEl      = document.getElementById('nlError');

        if (!firstName || !lastName || !phone || !campaignId) {
            errEl.textContent = 'First Name, Last Name, Phone and Campaign are required.';
            errEl.style.display = 'block';
            return;
        }
        errEl.style.display = 'none';

        _createLeadInFlight = true;
        const btn = document.querySelector('#leadPanel button.btn-primary');
        if (btn) btn.disabled = true;
        try {
            const resp = await fetch(`/console/conversation/${convId}/create-lead`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ first_name: firstName, last_name: lastName, phone, email, campaign_id: campaignId })
            });
            const data = await resp.json();
            if (data.ok) {
                renderLeadPanel({ id: convId }, data.lead);
                _removeNoLeadBadge(convId);
                _notifyVoiceLeadUpdated(convId, data.lead);
                showToast('Lead created and associated.', 'success');
            } else {
                // Pinta el mensaje REAL del backend (p.ej. "Duplicate leads already
                // exist in this campaign. A new lead was not created."). El formulario
                // se conserva lleno y la conversación no se recarga ni se asocia lead.
                errEl.textContent = _resolveBackendError(data, 'Error creating lead.');
                errEl.style.display = 'block';
            }
        } finally {
            _createLeadInFlight = false;
            if (btn) btn.disabled = false;
        }
    }

    async function doAssociateLead(convId, leadId) {
        if (_associateInFlight) return;
        _associateInFlight = true;
        try {
            const resp = await fetch(`/console/conversation/${convId}/associate-lead`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ lead_id: leadId })
            });
            const data = await resp.json();
            if (data.ok) {
                renderLeadPanel({ id: convId }, data.lead);
                _removeNoLeadBadge(convId);
                _notifyVoiceLeadUpdated(convId, data.lead);
                closeModal('searchLeadModal');
                showToast('Lead associated.', 'success');
            } else {
                showToast(data.message || 'Error associating lead.', 'error');
            }
        } finally {
            _associateInFlight = false;
        }
    }

    function openSearchLeadModal(convId) {
        _currentConvIdForLead = convId;
        document.getElementById('searchLeadInput').value = '';
        document.getElementById('searchLeadResults').innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-muted);">Type to search leads...</div>';
        document.getElementById('searchLeadModal').classList.add('active');
        setTimeout(() => document.getElementById('searchLeadInput').focus(), 100);
    }

    function debounceLeadSearch() {
        clearTimeout(_leadSearchTimer);
        _leadSearchTimer = setTimeout(runLeadSearch, 300);
    }

    async function runLeadSearch() {
        const q = document.getElementById('searchLeadInput').value.trim();
        const container = document.getElementById('searchLeadResults');
        if (q.length < 1) {
            container.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-muted);">Type to search leads...</div>';
            return;
        }
        container.innerHTML = '<div style="padding:12px;text-align:center;font-size:12px;color:var(--text-muted);">Searching...</div>';
        const resp = await fetch(`/console/leads/search?q=${encodeURIComponent(q)}`);
        const data = await resp.json();
        if (!data.leads || data.leads.length === 0) {
            container.innerHTML = '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-muted);">No leads found.</div>';
            return;
        }
        container.innerHTML = data.leads.map(l => `
        <div onclick="doAssociateLead(${_currentConvIdForLead}, ${l.id})"
             style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;margin-bottom:4px;"
             onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;">
                ${(l.first_name||'?').charAt(0).toUpperCase()}
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:600;">${l.first_name} ${l.last_name}</div>
                <div style="font-size:11px;color:var(--text-muted);">${l.email||''} ${l.phone ? '· '+l.phone : ''}</div>
                <div style="font-size:11px;color:var(--text-muted);">${l.campaign_name||''}</div>
            </div>
        </div>
    `).join('');
    }

    // ── Tags ────────────────────────────────────────────────────────────────────

    function _closeTagDropdownOutside(e) {
        const dropdown = document.getElementById('lead-tag-dropdown');
        if (!dropdown) return;
        const addBtn = document.getElementById('tag-add-btn');
        if (!dropdown.contains(e.target) && e.target !== addBtn && !addBtn?.contains(e.target)) {
            closeTagDropdown();
        }
    }

    function openTagDropdown(leadId) {
        _tagDropdownLeadId = leadId;
        const dropdown = document.getElementById('lead-tag-dropdown');
        if (!dropdown) return;
        dropdown.style.display = 'block';

        _tagDropdownAssignedIds = new Set(
            [...document.querySelectorAll('#lead-tags-pills .lead-tag-pill[data-tag-id]')]
                .map(el => parseInt(el.dataset.tagId))
        );

        if (_availableTags === null) {
            document.getElementById('tag-dropdown-list').innerHTML =
                '<div style="padding:8px 10px;font-size:11px;color:var(--text-muted);">Loading…</div>';
            fetch('/tags/available')
                .then(r => r.json())
                .then(data => {
                    _availableTags = data.tags || [];
                    _renderTagDropdownList('');
                });
        } else {
            _renderTagDropdownList(document.getElementById('tag-search-input')?.value || '');
        }

        setTimeout(() => document.getElementById('tag-search-input')?.focus(), 50);
        setTimeout(() => {
            document.addEventListener('click', _closeTagDropdownOutside);
            document.addEventListener('keydown', _closeTagDropdownOnEsc);
        }, 0);
    }

    function _closeTagDropdownOnEsc(e) {
        if (e.key === 'Escape') closeTagDropdown();
    }

    function closeTagDropdown() {
        const dropdown = document.getElementById('lead-tag-dropdown');
        if (dropdown) dropdown.style.display = 'none';
        const input = document.getElementById('tag-search-input');
        if (input) input.value = '';
        document.removeEventListener('click', _closeTagDropdownOutside);
        document.removeEventListener('keydown', _closeTagDropdownOnEsc);
        _tagDropdownLeadId = null;
    }

    function filterTagDropdown(q) {
        _renderTagDropdownList(q);
    }

    function _renderTagDropdownList(q) {
        const list = document.getElementById('tag-dropdown-list');
        if (!list || _availableTags === null) return;

        const query    = q.trim().toLowerCase();
        const filtered = query
            ? _availableTags.filter(t => t.name.toLowerCase().includes(query))
            : _availableTags;

        let html = filtered.map(t => {
            const assigned = _tagDropdownAssignedIds.has(t.id);
            return `<div onclick="${assigned ? 'unassignTag' : 'assignTag'}(${_tagDropdownLeadId},${t.id})"
                     style="display:flex;align-items:center;gap:8px;padding:5px 10px;cursor:pointer;font-size:12px;"
                     onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
                <span style="width:9px;height:9px;border-radius:50%;background:${t.bg_color};border:1px solid ${t.color};flex-shrink:0;display:inline-block;"></span>
                <span style="flex:1;">${t.name}</span>
                ${assigned ? '<i class="bi bi-check2" style="color:var(--primary);font-size:13px;"></i>' : ''}
            </div>`;
        }).join('');

        if (query && !_availableTags.some(t => t.name.toLowerCase() === query)) {
            html += `<div onclick="createAndAssignTag(${_tagDropdownLeadId},'${q.replace(/'/g, "\\'")}')"
                      style="display:flex;align-items:center;gap:6px;padding:5px 10px;cursor:pointer;font-size:12px;color:var(--primary);border-top:1px solid var(--border);"
                      onmouseover="this.style.background='var(--bg-th)'" onmouseout="this.style.background=''">
                <i class="bi bi-plus-circle"></i> Create "${q}"
            </div>`;
        }

        if (!html) {
            html = '<div style="padding:8px 10px;font-size:11px;color:var(--text-muted);">No tags found.</div>';
        }

        list.innerHTML = html;
    }

    function _updateTagPills(leadId, tags) {
        const pillsEl = document.getElementById('lead-tags-pills');
        if (!pillsEl) return;

        pillsEl.querySelectorAll('.lead-tag-pill').forEach(el => el.remove());

        const addBtn = document.getElementById('tag-add-btn');
        tags.forEach(t => {
            const pill = document.createElement('span');
            pill.className = 'lead-tag-pill';
            pill.dataset.tagId = String(t.id);
            pill.style.cssText = `background:${t.bg_color};color:${t.color};display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:500;`;
            pill.innerHTML = `${t.name}<button onclick="unassignTag(${leadId},${t.id})" style="background:none;border:none;cursor:pointer;padding:0 0 0 2px;line-height:1;color:inherit;opacity:.65;font-size:12px;">&times;</button>`;
            if (addBtn) pillsEl.insertBefore(pill, addBtn);
            else pillsEl.appendChild(pill);
        });

        _tagDropdownAssignedIds = new Set(tags.map(t => t.id));
        if (_panelOpts.onLeadUpdated) _panelOpts.onLeadUpdated(leadId, 'tags', null, {tags});
    }

    async function assignTag(leadId, tagId) {
        if (_tagAssignInFlight) return;
        _tagAssignInFlight = true;
        try {
            const resp = await fetch(`/leads/${leadId}/tags/${tagId}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            const data = await resp.json();
            if (data.ok) {
                _updateTagPills(leadId, data.tags);
                _renderTagDropdownList(document.getElementById('tag-search-input')?.value || '');
            }
        } finally {
            _tagAssignInFlight = false;
        }
    }

    async function unassignTag(leadId, tagId) {
        if (_tagAssignInFlight) return;
        _tagAssignInFlight = true;
        try {
            const resp = await fetch(`/leads/${leadId}/tags/${tagId}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            const data = await resp.json();
            if (data.ok) {
                _updateTagPills(leadId, data.tags);
                if (document.getElementById('lead-tag-dropdown')?.style.display !== 'none') {
                    _renderTagDropdownList(document.getElementById('tag-search-input')?.value || '');
                }
            }
        } finally {
            _tagAssignInFlight = false;
        }
    }

    async function createAndAssignTag(leadId, name) {
        if (_tagAssignInFlight) return;
        _tagAssignInFlight = true;
        try {
            const resp = await fetch(`/leads/${leadId}/tags`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ name }),
            });
            const data = await resp.json();
            if (data.ok) {
                _availableTags = null; // invalidate cache so new tag appears next open
                _updateTagPills(leadId, data.tags);
                closeTagDropdown();
                showToast(`Tag "${name}" created and assigned.`, 'success');
            }
        } finally {
            _tagAssignInFlight = false;
        }
    }

    // ── Public API ───────────────────────────────────────────────────────────────

    window.LeadPanel = {
        init:            function (statuses) { _leadStatuses = statuses; },
        render:          renderLeadPanel,
        renderEmpty:     renderLeadPanelEmpty,
        renderSuggested: renderLeadPanelSuggested,
        renderDynamicLeadRow,
    };

    window.activateLeadFieldEdit    = activateLeadFieldEdit;
    window.activateDynamicLeadFieldEdit = activateDynamicLeadFieldEdit;
    window.cancelDynamicLeadFieldEdit   = cancelDynamicLeadFieldEdit;
    window.saveDynamicLeadField         = saveDynamicLeadField;
    window.createLeadFromDynamicRow     = createLeadFromDynamicRow;
    window.activateLeadStatusEdit   = activateLeadStatusEdit;
    window.activateLeadCampaignEdit = activateLeadCampaignEdit;
    window.cancelLeadField          = cancelLeadField;
    window.saveLeadField            = saveLeadField;
    window.switchLeadTab            = switchLeadTab;
    window.saveLeadNotes            = saveLeadNotes;
    window.submitCreateLead         = submitCreateLead;
    window.doAssociateLead          = doAssociateLead;
    window.openSearchLeadModal      = openSearchLeadModal;
    window.debounceLeadSearch       = debounceLeadSearch;
    window.runLeadSearch            = runLeadSearch;
    window.filterStatusDropdown     = filterStatusDropdown;
    window.selectLeadStatus         = selectLeadStatus;
    window.filterCampaignDropdown   = filterCampaignDropdown;
    window.selectLeadCampaign       = selectLeadCampaign;
    window.openTagDropdown          = openTagDropdown;
    window.closeTagDropdown         = closeTagDropdown;
    window.filterTagDropdown        = filterTagDropdown;
    window.assignTag                = assignTag;
    window.unassignTag              = unassignTag;
    window.createAndAssignTag       = createAndAssignTag;

})();

