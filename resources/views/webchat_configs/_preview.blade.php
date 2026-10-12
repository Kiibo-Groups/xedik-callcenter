{{-- Preview sticky del widget --}}
<div style="position:sticky;top:80px;">
    <p style="font-size:11px;font-weight:500;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Live Preview</p>

    <div id="wcPreviewContainer"
         style="position:relative;background:#f0f0f5;border-radius:12px;min-height:600px;width:100%;overflow:hidden;">

        {{-- Contenido simulado de página --}}
        <div style="position:absolute;top:20px;left:20px;right:90px;">
            <div style="height:12px;background:#ddd;border-radius:4px;margin-bottom:8px;width:60%;"></div>
            <div style="height:8px;background:#e5e5e5;border-radius:4px;margin-bottom:6px;width:90%;"></div>
            <div style="height:8px;background:#e5e5e5;border-radius:4px;margin-bottom:6px;width:75%;"></div>
            <div style="height:8px;background:#e5e5e5;border-radius:4px;margin-bottom:6px;width:85%;"></div>
            <div style="height:8px;background:#e5e5e5;border-radius:4px;width:50%;"></div>
        </div>

        {{-- ESTADO 1: Botón flotante --}}
        <div id="wcState1" style="position:absolute;bottom:20px;right:20px;display:flex;flex-direction:column;align-items:center;gap:6px;">
            <div id="wcPreviewTitle" style="background:white;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:500;color:#333;box-shadow:0 2px 8px rgba(0,0,0,0.15);white-space:nowrap;display:inline-block;">
                Chat with us
            </div>
            <div id="wcFloatBtn" style="width:56px;height:56px;border-radius:50%;background:#534AB7;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.2);">
                <i id="wcFloatIcon" class="bi bi-chat-dots-fill" style="font-size:24px;color:white;"></i>
            </div>
        </div>

        {{-- ESTADO 2: Form de captura --}}
        <div id="wcState2" style="display:none;position:absolute;bottom:20px;right:12px;width:300px;">
            <div id="wcPanel" class="wc-preview-panel" style="background:white;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;display:flex;flex-direction:column;height:420px;box-shadow:0 8px 24px rgba(0,0,0,0.12);">
                {{-- Header fijo --}}
                <div id="wcPanelHeader" style="flex-shrink:0;background:#534AB7;padding:14px 16px;color:white;display:flex;align-items:center;gap:10px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;">
                        <i id="wcHeaderIcon" class="bi bi-chat-dots-fill" style="font-size:16px;color:white;"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <p id="wcHeaderTitle" style="margin:0;font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Chat with us</p>
                        <p id="wcHeaderSubtitle" style="margin:0;font-size:10px;opacity:0.85;">
                            <span style="display:inline-block;width:6px;height:6px;background:#5DCAA5;border-radius:50%;margin-right:4px;vertical-align:1px;"></span>We typically reply in 2 min
                        </p>
                    </div>
                    <button type="button" id="wcCloseBtn2" style="background:none;border:none;color:white;opacity:0.7;font-size:16px;cursor:pointer;line-height:1;padding:0;flex-shrink:0;">&#10005;</button>
                </div>
                {{-- Body scrollable --}}
                <div style="flex:1;overflow-y:auto;padding:14px 16px;">
                    <p id="wcFormSubtitle" style="font-size:12px;color:#666;margin:0 0 12px;">Before we start, tell us about you.</p>
                    <input type="text" placeholder="Name"  style="width:100%;margin-bottom:8px;font-size:12px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;outline:none;">
                    <input type="text" placeholder="Phone" style="width:100%;margin-bottom:8px;font-size:12px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;outline:none;">
                    <input type="text" placeholder="Email" style="width:100%;margin-bottom:8px;font-size:12px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;outline:none;">
                    <div id="wcInterestField"></div>
                    <div id="wcPrivacyField" style="margin-top:8px;"></div>
                </div>
                {{-- Footer fijo --}}
                <div style="flex-shrink:0;padding:12px 16px;border-top:1px solid #f0f0f0;">
                    <button type="button" id="wcStartBtn" style="width:100%;padding:10px;border:none;border-radius:8px;font-size:13px;font-weight:500;color:white;cursor:pointer;background:#534AB7;">
                        Start chat
                    </button>
                </div>
            </div>
        </div>

        {{-- ESTADO 3: Conversación --}}
        <div id="wcState3" style="display:none;position:absolute;bottom:20px;right:12px;width:300px;">
            <div class="wc-preview-panel" style="background:white;border-radius:12px;overflow:hidden;border:1px solid #e0e0e0;display:flex;flex-direction:column;height:420px;box-shadow:0 8px 24px rgba(0,0,0,0.12);">
                {{-- Header fijo --}}
                <div id="wcChatHeader" style="flex-shrink:0;background:#534AB7;padding:14px 16px;color:white;display:flex;align-items:center;gap:10px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:500;color:white;">A</div>
                    <div style="flex:1;min-width:0;">
                        <p id="wcChatTitle" style="margin:0;font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Chat with us</p>
                        <p id="wcChatSubtitle" style="margin:0;font-size:10px;opacity:0.85;">Agent connected</p>
                    </div>
                    <button type="button" id="wcCloseBtn3" style="background:none;border:none;color:white;opacity:0.7;font-size:16px;cursor:pointer;line-height:1;padding:0;flex-shrink:0;">&#10005;</button>
                </div>
                {{-- Burbujas scrollable --}}
                <div style="flex:1;overflow-y:auto;padding:12px;background:#f8f8f8;display:flex;flex-direction:column;gap:8px;">
                    <div id="wcWelcomeBubble" style="background:#534AB720;padding:8px 10px;border-radius:8px;font-size:11px;max-width:85%;line-height:1.5;color:#333;">
                        Hi! How can we help you today?
                    </div>
                    <div id="wcVisitorBubble" style="background:#534AB7;color:white;padding:8px 10px;border-radius:8px;font-size:11px;max-width:80%;align-self:flex-end;line-height:1.5;">
                        Hi, I have a question about your services
                    </div>
                    <div id="wcAgentBubble" style="background:#534AB720;padding:8px 10px;border-radius:8px;font-size:11px;max-width:80%;color:#333;line-height:1.5;">
                        Hello! I'd be happy to help. What would you like to know?
                    </div>
                </div>
                {{-- Composer fijo --}}
                <div style="flex-shrink:0;padding:8px 12px;border-top:1px solid #eee;background:white;display:flex;gap:6px;align-items:center;">
                    <input type="text" placeholder="Type a message..." style="flex:1;font-size:11px;padding:7px 10px;border:1px solid #ddd;border-radius:20px;outline:none;box-sizing:border-box;">
                    <span style="font-size:16px;opacity:0.55;line-height:1;flex-shrink:0;">😊</span>
                    <button type="button" id="wcSendBtn" style="background:#534AB7;border:none;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;">
                        <i class="bi bi-send-fill" style="font-size:12px;color:white;"></i>
                    </button>
                </div>
            </div>
        </div>

    </div>

    {{-- Controles --}}
    <div style="display:flex;justify-content:center;gap:6px;margin-top:8px;">
        <button type="button" id="wcBtnState1" onclick="wcSetState(1)" class="btn btn-sm" style="font-size:11px;padding:4px 10px;">
            <i class="bi bi-record-circle" style="font-size:9px;"></i> Button
        </button>
        <button type="button" id="wcBtnState2" onclick="wcSetState(2)" class="btn btn-sm" style="font-size:11px;padding:4px 10px;">
            <i class="bi bi-card-text"></i> Form
        </button>
        <button type="button" id="wcBtnState3" onclick="wcSetState(3)" class="btn btn-sm" style="font-size:11px;padding:4px 10px;">
            <i class="bi bi-chat-dots"></i> Chat
        </button>
    </div>
</div>
