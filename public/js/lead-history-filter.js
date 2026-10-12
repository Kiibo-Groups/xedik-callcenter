// Lead History — filtros de canal + thread modal viewer

document.querySelectorAll('.history-filters button').forEach(btn => {
    btn.addEventListener('click', () => {
        const filter = btn.dataset.filter;
        document.querySelectorAll('.history-filters button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        document.querySelectorAll('.timeline-item').forEach(item => {
            item.style.display = (filter === 'all' || item.dataset.channel === filter) ? '' : 'none';
        });

        document.querySelectorAll('.timeline-day').forEach(day => {
            const visible = [...day.querySelectorAll('.timeline-item')].filter(i => i.style.display !== 'none');
            day.style.display = visible.length > 0 ? '' : 'none';
        });
    });
});

// ── Thread modal ──
const threadContainer = document.getElementById('threadModalContainer');

function renderThreadMessages(msgs, channel) {
    if (!msgs || msgs.length === 0) {
        return '<div style="text-align:center;color:var(--text-muted);padding:20px;">Messages not archived.</div>';
    }

    const isWa = channel === 'whatsapp' || channel === 'chat';

    if (isWa) {
        let lastDate = '';
        let html = '<div style="display:flex;flex-direction:column;gap:8px;padding:4px 0;">';
        msgs.slice().reverse().forEach(m => {
            const sentParts = m.sent_at ? m.sent_at.split(' · ') : ['', ''];
            const dateStr   = sentParts[0] || '';
            const timeStr   = sentParts[1] || '';
            if (dateStr && dateStr !== lastDate) {
                html += `<div style="text-align:center;font-size:11px;color:var(--text-muted);margin:8px 0;display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:0.5px;background:var(--border);"></div>
                            ${dateStr}
                            <div style="flex:1;height:0.5px;background:var(--border);"></div>
                         </div>`;
                lastDate = dateStr;
            }
            const isOut  = m.direction === 'outbound';
            const name   = m.from_name || m.from_email || '?';
            const inicial = name.trim()[0]?.toUpperCase() || '?';
            const body    = (m.body_text || '').replace(/\n/g, '<br>');

            if (isOut) {
                html += `<div style="display:flex;gap:8px;align-items:flex-end;max-width:75%;align-self:flex-end;flex-direction:row-reverse;">
                            <div style="width:26px;height:26px;border-radius:50%;background:#E6F1FB;color:#185FA5;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">${inicial}</div>
                            <div>
                                <div style="padding:8px 12px;border-radius:16px;border-bottom-right-radius:3px;background:#E1F5EE;color:#085041;font-size:13px;line-height:1.5;">${body}</div>
                                <div style="font-size:10px;color:var(--text-muted);margin-top:3px;text-align:right;">${timeStr}</div>
                            </div>
                         </div>`;
            } else {
                html += `<div style="display:flex;gap:8px;align-items:flex-end;max-width:75%;align-self:flex-start;">
                            <div style="width:26px;height:26px;border-radius:50%;background:#E1F5EE;color:#085041;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">${inicial}</div>
                            <div>
                                <div style="padding:8px 12px;border-radius:16px;border-bottom-left-radius:3px;background:var(--bg-card);color:var(--text-strong);font-size:13px;line-height:1.5;">${body}</div>
                                <div style="font-size:10px;color:var(--text-muted);margin-top:3px;">${timeStr}</div>
                            </div>
                         </div>`;
            }
        });
        html += '</div>';
        return html;
    } else {
        // Email — paneles
        return msgs.map(m => {
            const isOut = m.direction === 'outbound';
            return `<div style="background:var(--bg-card);color:var(--text-strong);border-radius:8px;border:1px solid var(--border);overflow:hidden;display:flex;flex-direction:column;flex:1;">
                        <div style="background:var(--bg-th);padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;flex-shrink:0;">
                            <div style="width:28px;height:28px;border-radius:50%;background:${isOut ? 'var(--primary)' : 'var(--bg-stripe)'};color:${isOut ? 'white' : 'var(--text-strong)'};font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                ${(m.from_email || '?').charAt(0).toUpperCase()}
                            </div>
                            <div style="flex:1;min-width:0;">
                                <span style="font-size:12px;font-weight:600;color:var(--text-strong);">${m.from_email || ''}</span>
                                <span style="font-size:11px;color:var(--text-muted);margin-left:6px;">→ ${m.to_email || ''}</span>
                                <span style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:99px;margin-left:6px;background:${isOut ? 'var(--bg-stripe)' : '#dcfce7'};color:${isOut ? 'var(--primary)' : '#16a34a'};">
                                    ${isOut ? '↑ OUT' : '↓ IN'}
                                </span>
                            </div>
                            <span style="font-size:11px;color:var(--text-muted);flex-shrink:0;">${m.sent_at || ''}</span>
                        </div>
                        <div style="padding:12px 14px;background:#ffffff;color:#1e293b;font-size:13px;line-height:1.6;flex:1;overflow-y:auto;min-height:0;">
                            ${m.body_html || m.body_text || ''}
                        </div>
                    </div>`;
        }).join('');
    }
}

function buildThreadModal(data) {
    const conv    = data.conversation;
    const msgs    = data.messages || [];
    const isWa    = conv.channel === 'whatsapp' || conv.channel === 'chat';
    const chanIcon = isWa ? 'bi-whatsapp' : 'bi-envelope-fill';
    const subject  = isWa
        ? (conv.from_name || conv.from_email || conv.subject || '')
        : (conv.subject || '(no subject)');

    const threadHtml = renderThreadMessages(msgs, conv.channel);

    return `
        <div class="thread-modal-overlay active" id="threadModal">
            <div class="thread-modal">
                <div class="thread-modal-header">
                    <i class="bi ${chanIcon}" style="font-size:18px;color:var(--primary);"></i>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            ${subject}
                        </div>
                        <div style="font-size:12px;color:var(--text-muted);">
                            ${conv.agent_name || ''} · ${conv.disposition || ''} · ${conv.resolved_at || ''}
                        </div>
                    </div>
                    <button onclick="closeThreadModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">&times;</button>
                </div>
                <div class="thread-modal-body">${threadHtml}</div>
            </div>
        </div>`;
}

function closeThreadModal() {
    document.getElementById('threadModal')?.remove();
}

document.addEventListener('click', async e => {
    const link = e.target.closest('.view-thread-link');
    if (!link) return;
    e.preventDefault();

    const reportId = link.dataset.reportId;
    if (!reportId) return;

    const baseUrl = window.LeadShowConfig?.reportDetailUrl || '/leads/thread/';
    const url = baseUrl + reportId;

    const originalText = link.textContent;
    link.textContent = 'Loading…';

    try {
        const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!resp.ok) throw new Error(resp.status);
        const data = await resp.json();
        threadContainer.innerHTML = buildThreadModal(data);
        const overlay = document.getElementById('threadModal');
        if (overlay) {
            let mdX, mdY, startedOnOverlay = false;
            overlay.addEventListener('mousedown', e => {
                startedOnOverlay = (e.target === overlay);
                if (startedOnOverlay) { mdX = e.clientX; mdY = e.clientY; }
            });
            overlay.addEventListener('mouseup', e => {
                if (!startedOnOverlay || e.target !== overlay) return;
                if (Math.abs(e.clientX - mdX) < 5 && Math.abs(e.clientY - mdY) < 5) {
                    closeThreadModal();
                }
            });
        }
    } catch {
        // no-op
    } finally {
        link.textContent = originalText;
    }
});
