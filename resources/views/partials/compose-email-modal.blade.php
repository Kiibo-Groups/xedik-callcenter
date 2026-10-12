{{-- COMPOSE EMAIL MODAL — partial reutilizable --}}
{{-- Requiere: window.ComposeConfig { showDisposition, requireDisposition, dispositions, signature, onSendSuccess } --}}
{{-- Requiere: public/js/compose-email.js --}}
<div class="modal-overlay" id="composeModal">
    <div class="modal" style="max-width:700px;max-height:92vh;">
        <div class="modal-header" style="padding:14px 20px;">
            <div class="modal-title"><i class="bi bi-pencil-square" style="color:var(--primary);margin-right:6px;"></i> New Email</div>
            <button class="modal-close" onclick="closeComposeModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:16px 20px;overflow-y:auto;">

            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;">

                {{-- From --}}
                <div style="display:flex;align-items:center;gap:8px;padding:6px 0px;border-bottom:0.5px solid var(--border)">
                    <label style="font-size:12px;color:var(--text-muted);min-width:64px;flex-shrink:0">From</label>
                    <div id="composeFromWrap" style="flex:1;position:relative">
                        <span id="composeFromText" style="font-size:13px;color:var(--text-strong);cursor:default;display:block;padding:4px 0"></span>
                        <div id="composeFromDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;
                             background:#ffffff;border:1px solid #e2e8f0;border-radius:6px;z-index:9999;
                             box-shadow:0 4px 12px rgba(0,0,0,0.1)"></div>
                    </div>
                </div>

                {{-- To — botones CC/BCC flotando dentro del input --}}
                <div style="display:flex;align-items:center;">
                    <label style="min-width:64px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">To <span style="color:#dc2626;">*</span></label>
                    <div style="flex:1;position:relative;">
                        <input type="email" id="composeTo" class="form-control" placeholder="email1@domain.com, email2@domain.com" style="width:100%;padding-right:80px;">
                        <div style="position:absolute;right:6px;top:50%;transform:translateY(-50%);display:flex;gap:4px;">
                            <button type="button" onclick="toggleComposeField('cc')" id="btnCc"
                                style="font-size:10px;padding:2px 7px;border-radius:4px;border:1px solid var(--border);background:var(--bg-th);color:var(--text-muted);cursor:pointer;line-height:1.5;font-weight:500;">CC</button>
                            <button type="button" onclick="toggleComposeField('bcc')" id="btnBcc"
                                style="font-size:10px;padding:2px 7px;border-radius:4px;border:1px solid var(--border);background:var(--bg-th);color:var(--text-muted);cursor:pointer;line-height:1.5;font-weight:500;">BCC</button>
                        </div>
                    </div>
                </div>

                {{-- CC (oculto por defecto) --}}
                <div id="composeFieldCc" style="display:none;align-items:center;">
                    <label style="min-width:64px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">CC</label>
                    <input type="text" id="composeCc" class="form-control" placeholder="cc1@domain.com, cc2@domain.com" style="flex:1;">
                </div>

                {{-- BCC (oculto por defecto) --}}
                <div id="composeFieldBcc" style="display:none;align-items:center;">
                    <label style="min-width:64px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">BCC</label>
                    <input type="text" id="composeBcc" class="form-control" placeholder="bcc1@domain.com, bcc2@domain.com" style="flex:1;">
                </div>

                {{-- Subject --}}
                <div style="display:flex;align-items:center;">
                    <label style="min-width:64px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">Subject <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="composeSubject" class="form-control" placeholder="Subject..." style="flex:1;">
                </div>

                {{-- Template — campo propio con label --}}
                <div style="display:flex;align-items:center;">
                    <label style="min-width:64px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">Template</label>
                    <div style="flex:1;position:relative;" id="composeTemplateWrap">
                        <button type="button" id="composeTemplateBtn" onclick="toggleTemplateDropdown()"
                                style="width:100%;text-align:left;padding:7px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input, var(--bg-th));color:var(--text-muted);cursor:pointer;font-size:13px;display:flex;align-items:center;gap:6px;line-height:1.4;">
                            <i class="bi bi-file-earmark-text" style="font-size:13px;flex-shrink:0;"></i>
                            <span id="composeTemplateLabel" style="flex:1;">Select template</span>
                            <i class="bi bi-chevron-down" style="font-size:10px;opacity:.5;flex-shrink:0;"></i>
                        </button>
                        <div id="composeTemplateDropdown" style="display:none;position:absolute;top:calc(100% + 3px);left:0;right:0;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);z-index:9999;max-height:220px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,0.15);">
                            <div id="composeTemplateList" style="padding:4px 0;">
                                <div style="padding:10px 14px;font-size:12px;color:var(--text-muted);">Loading...</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Lead — chip bloqueado o búsqueda --}}
                <div style="display:flex;align-items:center;gap:0;" id="composeLeadRow">
                    <label style="min-width:64px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">Lead</label>
                    <div style="flex:1;position:relative;" id="composeLeadWrap">

                        {{-- Estado A: búsqueda --}}
                        <div id="composeLeadSearch">
                            <input type="text" id="composeLeadInput" class="form-control"
                                placeholder="Search lead... (optional)"
                                oninput="debounceComposeLeadSearch(this.value)"
                                autocomplete="off">
                            <div id="composeLeadDropdown" style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);z-index:9999;max-height:200px;overflow-y:auto;box-shadow:var(--shadow-lg);"></div>
                        </div>

                        {{-- Estado B: lead seleccionado — chip --}}
                        <div id="composeLeadChip" style="display:none;padding:5px 0;">
                            <div style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px 3px 8px;background:#E6F1FB;border:0.5px solid #B5D4F4;border-radius:20px;font-size:12px;color:#0C447C;font-weight:500;">
                                <div id="composeLeadChipAvatar" style="width:18px;height:18px;border-radius:50%;background:#185FA5;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">?</div>
                                <span id="composeLeadChipName">—</span>
                                <button id="composeLeadChipRemove" onclick="clearComposeLead()" style="background:none;border:none;cursor:pointer;color:#185FA5;font-size:15px;line-height:1;padding:0;margin-left:2px;opacity:0.7;">×</button>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            {{-- Hint sin lead --}}
            <div id="composeNoLeadHint" style="display:none;align-items:center;gap:6px;font-size:11px;color:#854F0B;background:#FAEEDA;border-radius:var(--radius-sm);padding:5px 10px;margin-bottom:8px;">
                <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;"></i>
                This email won't be linked to a lead. You can still send it.
            </div>

            {{-- Adjuntos preview --}}
            <div id="composeAttachmentList" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;"></div>

            {{-- Editor Jodit --}}
            <div class="form-group" style="margin-bottom:0;">
                <div id="composeBody"></div>
            </div>

        </div>

        <div class="modal-footer" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">

            {{-- Izquierda: Attach --}}
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('composeAttachmentInput').click()">
                    <i class="bi bi-paperclip"></i> Attach
                </button>
                <input type="file" id="composeAttachmentInput" multiple style="display:none;" onchange="handleComposeAttachments(this.files)">
                <span style="font-size:11px;color:var(--text-muted);" id="composeAttachCount"></span>
            </div>

            {{-- Centro: Disposition (solo visible cuando showDisposition = true) --}}
            <div id="composeDispositionFooter" style="display:none;align-items:center;gap:6px;flex:1;min-width:180px;">
                <label id="composeDispositionLabel" style="font-size:12px;color:var(--text-muted);font-weight:500;white-space:nowrap;flex-shrink:0;">Disposition</label>
                <select id="composeDispositionSelect" class="form-control" style="flex:1;font-size:13px;" onchange="onDispositionSelectChange()">
                    <option value="">Select disposition</option>
                </select>
            </div>

            {{-- Derecha: Cancel + Send --}}
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <button type="button" class="btn btn-secondary" onclick="closeComposeModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="composeSendBtn" onclick="sendCompose()">
                    <i class="bi bi-send"></i> Send
                </button>
            </div>

        </div>
    </div>
</div>
