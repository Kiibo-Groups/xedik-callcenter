// ── Email Templates — form page (create / edit) ───────────────────────────────

var _tplJodit     = null;
var _tplBodyInput = null;

// ── Insert variable at cursor ─────────────────────────────────────────────────

function insertVar(variable) {
    if (window._tplActiveField === 'jodit' && _tplJodit) {
        _tplJodit.selection.insertHTML(variable);
        _tplJodit.focus();
        return;
    }
    var input = document.getElementById('tplSubject');
    if (input) {
        var start = input.selectionStart ?? input.value.length;
        var end   = input.selectionEnd   ?? input.value.length;
        input.value = input.value.slice(0, start) + variable + input.value.slice(end);
        input.focus();
        input.selectionStart = input.selectionEnd = start + variable.length;
    }
}

function attachChipHandlers(container) {
    if (!container) return;
    container.querySelectorAll('.var-chip').forEach(function (chip) {
        chip.addEventListener('click', function () { insertVar(this.dataset.var); });
    });
}

// ── Tab switching ─────────────────────────────────────────────────────────────

function switchVarTab(tab) {
    document.querySelectorAll('.var-tab-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.tab === tab);
    });
    document.querySelectorAll('.var-pane').forEach(function (p) {
        p.style.display = 'none';
    });
    var pane = document.getElementById('varPane-' + tab);
    if (pane) pane.style.display = '';

    // Solo el tab Leads resetea explícitamente el source.
    // El tab LeadList y System no cambian el source — solo el dropdown cambia a 'leadlist'.
    if (tab === 'leads') {
        setVarSource('leads', null);
        var sel = document.getElementById('varLeadListSelect');
        if (sel) sel.value = '';
        var chips = document.getElementById('varLeadListChips');
        if (chips) chips.innerHTML = '<span style="font-size:11px;color:var(--text-muted);">Select a list above to see available variables.</span>';
    }
}

function setVarSource(source, leadlistId) {
    var srcEl = document.getElementById('tplVariableSource');
    var llEl  = document.getElementById('tplLeadlistId');
    if (srcEl) srcEl.value = source;
    if (llEl)  llEl.value  = leadlistId || '';
}

// ── Lead List fields (AJAX) ───────────────────────────────────────────────────

function loadLeadListFields(listId) {
    var chipsEl  = document.getElementById('varLeadListChips');
    var loadingEl = document.getElementById('varLeadListLoading');

    if (!listId) {
        if (chipsEl) chipsEl.innerHTML =
            '<span style="font-size:11px;color:var(--text-muted);">Select a list above to see available variables.</span>';
        setVarSource('leads', null);
        return;
    }

    setVarSource('leadlist', listId);
    if (loadingEl) loadingEl.style.display = '';

    var cfg = window.EmailTemplateFormConfig || {};
    fetch(cfg.leadlistFieldsUrl + '?lead_list_id=' + encodeURIComponent(listId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (loadingEl) loadingEl.style.display = 'none';
        renderLeadListChips(data.fixed || [], data.custom || []);
    })
    .catch(function () {
        if (loadingEl) loadingEl.style.display = 'none';
        if (chipsEl) chipsEl.innerHTML =
            '<span style="font-size:11px;color:#dc2626;">Error loading fields.</span>';
    });
}

function renderLeadListChips(fixed, custom) {
    var chipsEl = document.getElementById('varLeadListChips');
    if (!chipsEl) return;

    if (!fixed.length && !custom.length) {
        chipsEl.innerHTML = '<span style="font-size:11px;color:var(--text-muted);">No fields found in this list.</span>';
        return;
    }

    var html = '';

    if (fixed.length) {
        html += '<div style="margin-bottom:10px;">';
        html += '<div class="var-chip-group-label">Fixed fields</div>';
        html += '<div style="display:flex;flex-wrap:wrap;gap:4px;">';
        fixed.forEach(function (f) {
            var v = '{{leadlist.' + f + '}}';
            html += '<button type="button" class="btn btn-xs btn-outline var-chip" data-var="' + _escAttr(v) + '" style="font-size:11px;font-family:monospace;">' + _escHtml(v) + '</button>';
        });
        html += '</div></div>';
    }

    if (custom.length) {
        html += '<div>';
        html += '<div class="var-chip-group-label">Custom fields</div>';
        html += '<div style="display:flex;flex-wrap:wrap;gap:4px;">';
        custom.forEach(function (f) {
            var v = '{{leadlist.' + f + '}}';
            html += '<button type="button" class="btn btn-xs btn-outline var-chip" data-var="' + _escAttr(v) + '" style="font-size:11px;font-family:monospace;">' + _escHtml(v) + '</button>';
        });
        html += '</div></div>';
    }

    chipsEl.innerHTML = html;
    attachChipHandlers(chipsEl);
}

function _escAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function _escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── DOMContentLoaded ──────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {

    // ── Init Jodit sobre #tplBody ─────────────────────────────────────────────
    _tplBodyInput = document.getElementById('tplBody');
    if (_tplBodyInput && typeof Jodit !== 'undefined') {
        _tplJodit = Jodit.make('#tplBody', {
            height: 420,
            toolbarButtonSize: 'small',
            language: 'en',
            toolbarAdaptive: false,
            buttons: 'bold,italic,underline,strikethrough,|,ul,ol,|,font,fontsize,brush,|,image,table,link,|,align,|,undo,redo,|,eraser',
            uploader: { insertImageAsBase64URI: true },
            placeholder: 'Write template body here… use the variable chips above to insert placeholders.',
            statusbar: false,
            showCharsCounter: false,
            showWordsCounter: false,
            showXPathInStatusbar: false,
            addNewLine: false,
        });
        _tplJodit.events.on('focus', function () {
            window._tplActiveField = 'jodit';
        });
    }

    // ── Subject focus tracking ────────────────────────────────────────────────
    var subjectInput = document.getElementById('tplSubject');
    if (subjectInput) {
        subjectInput.addEventListener('focus', function () {
            window._tplActiveField = 'tplSubject';
        });
    }

    // ── Tab button handlers ───────────────────────────────────────────────────
    document.querySelectorAll('.var-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () { switchVarTab(this.dataset.tab); });
    });

    // ── Static chip handlers (Leads + System panes) ───────────────────────────
    attachChipHandlers(document.getElementById('varPane-leads'));
    attachChipHandlers(document.getElementById('varPane-system'));

    // ── Lead List selector ────────────────────────────────────────────────────
    var llSelect = document.getElementById('varLeadListSelect');
    if (llSelect) {
        llSelect.addEventListener('change', function () {
            loadLeadListFields(this.value);
        });
    }

    // ── Restore initial state on edit page ────────────────────────────────────
    var cfg = window.EmailTemplateFormConfig || {};
    if (cfg.initialSource === 'leadlist' && cfg.initialLlId) {
        loadLeadListFields(cfg.initialLlId);
    }
});
