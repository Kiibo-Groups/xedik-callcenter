/* Endpoints catalog — groups + accordion + admin CRUD + token preview + search */

function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

// ── Groups ──────────────────────────────────────────────────────────────────────

function toggleGroup(slug) {
    var group = document.getElementById('epg-' + slug);
    if (group) group.classList.toggle('closed');
}

// ── Panel accordion ────────────────────────────────────────────────────────────

function togglePanel(id) {
    var panel = document.getElementById('ep-panel-' + id);
    if (!panel) return;
    panel.classList.toggle('open');
}

// ── Token preview ──────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    // Token selector
    var sel = document.getElementById('tokenSelector');
    if (sel) {
        sel.addEventListener('change', function () {
            var token = this.value;
            var note = document.getElementById('tokenNote');
            if (note) note.style.display = token ? 'inline' : 'none';
            applyToken(token);
        });
    }

    // Search
    var searchInput = document.getElementById('epSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('.ep-panel').forEach(function (panel) {
                var name = (panel.querySelector('.ep-trigger-name') || {}).textContent || '';
                var url  = (panel.querySelector('[data-template]') || {}).dataset?.template || '';
                var desc = (panel.querySelector('.ep-desc') || {}).textContent || '';
                var show = !q || name.toLowerCase().includes(q) || url.toLowerCase().includes(q) || desc.toLowerCase().includes(q);
                panel.style.display = show ? '' : 'none';
            });
            // Show/hide groups — auto-expand when searching
            document.querySelectorAll('.ep-group').forEach(function (group) {
                var anyVisible = Array.from(group.querySelectorAll('.ep-panel'))
                    .some(function (p) { return p.style.display !== 'none'; });
                group.style.display = anyVisible ? '' : 'none';
                if (q && anyVisible) group.classList.remove('closed');
            });
        });
    }

    // Delete forms
    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var name = this.dataset.name;
            SwalConfirm.fire({
                title: 'Delete endpoint?',
                html:  '<strong>' + name + '</strong> will be permanently removed from the catalog.',
            }).then(function (r) { if (r.isConfirmed) form.submit(); });
        });
    });

    // Re-open create modal on validation error
    var cfg = window.EndpointsConfig || {};
    if (cfg.hasErrors) openModal('epCreateModal');
});

function applyToken(token) {
    document.querySelectorAll('[data-template]').forEach(function (el) {
        var tpl = el.dataset.template;
        el.textContent = token ? tpl.replace(/\{token\}/g, token) : tpl;
    });
}

// ── Edit modal ─────────────────────────────────────────────────────────────────

function openEdit(id) {
    var data = (window._epData || {})[id];
    if (!data) return;

    var cfg = window.EndpointsConfig || {};

    // Update form action
    var form = document.getElementById('epEditForm');
    form.action = cfg.editRouteBase + '/' + id;

    // Populate fields
    setValue('edit_method',      data.method);
    setValue('edit_group',       data.group || '');
    setValue('edit_name',        data.name);
    setValue('edit_description', data.description || '');
    setValue('edit_url',         data.url);
    setValue('edit_auth',        data.auth_description || '');
    setValue('edit_example',     data.example || '');
    setValue('edit_response',    data.response_example || '');
    setValue('edit_sort',        data.sort_order || 0);
    document.getElementById('edit_active').checked = !!data.is_active;

    // Rebuild param rows
    var container = document.getElementById('edit_params_rows');
    container.innerHTML = '';
    (data.params || []).forEach(function (p) {
        appendParamRow('edit', p);
    });

    openModal('epEditModal');
}

function setValue(id, val) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.tagName === 'SELECT') {
        for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].value === String(val)) { el.selectedIndex = i; break; }
        }
    } else {
        el.value = val;
    }
}

// ── Param rows ─────────────────────────────────────────────────────────────────

function addParamRow(prefix, data) {
    appendParamRow(prefix, data || {});
}

function appendParamRow(prefix, data) {
    var container = document.getElementById(prefix + '_params_rows');
    if (!container) return;

    var row = document.createElement('div');
    row.className = 'param-row';
    row.innerHTML =
        '<input type="text" class="form-control param-name-f" placeholder="name" value="' + _esc(data.name || '') + '">' +
        '<select class="form-control param-in-f">' +
            ['query','body','path','header'].map(function(o) {
                return '<option value="' + o + '"' + (data.in === o ? ' selected' : '') + '>' + o + '</option>';
            }).join('') +
        '</select>' +
        '<div class="param-req-f" title="Required">' +
            '<input type="checkbox" ' + (data.required ? 'checked' : '') + '>' +
        '</div>' +
        '<input type="text" class="form-control param-desc-f" placeholder="description" value="' + _esc(data.description || '') + '">' +
        '<div class="param-del">' +
            '<button type="button" class="btn btn-danger btn-xs" onclick="this.closest(\'.param-row\').remove()">' +
                '<i class="bi bi-x"></i>' +
            '</button>' +
        '</div>';
    container.appendChild(row);
}

function serializeParams(prefix) {
    var container = document.getElementById(prefix + '_params_rows');
    var hidden    = document.getElementById(prefix + '_params_json');
    if (!container || !hidden) return;

    var params = [];
    container.querySelectorAll('.param-row').forEach(function (row) {
        var inputs   = row.querySelectorAll('input[type="text"]');
        var sel      = row.querySelector('select');
        var checkbox = row.querySelector('input[type="checkbox"]');
        var name     = inputs[0]?.value.trim();
        if (!name) return;
        params.push({
            name:        name,
            in:          sel?.value || 'query',
            required:    checkbox?.checked || false,
            description: inputs[1]?.value.trim() || '',
        });
    });

    hidden.value = JSON.stringify(params);
}

// ── Copy button ────────────────────────────────────────────────────────────────

function epCopy(btn) {
    var wrapper   = btn.closest('.ep-copyable');
    var target    = wrapper.querySelector('[data-template], pre, code');
    var text      = target ? target.textContent.trim() : '';
    var icon      = btn.querySelector('i');

    var doFeedback = function () {
        btn.classList.add('copied');
        if (icon) { icon.className = 'bi bi-clipboard-check'; }
        setTimeout(function () {
            btn.classList.remove('copied');
            if (icon) { icon.className = 'bi bi-clipboard'; }
        }, 2000);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(doFeedback).catch(function () {
            epCopyFallback(text, doFeedback);
        });
    } else {
        epCopyFallback(text, doFeedback);
    }
}

function epCopyFallback(text, callback) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); callback(); } catch (e) {}
    document.body.removeChild(ta);
}

function _esc(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
