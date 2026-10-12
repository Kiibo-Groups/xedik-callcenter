/**
 * Campaigns create/edit form
 * — Tab switching
 * — Color picker + live preview
 * — Agent dual-panel (Assigned / Available)
 *
 * window.CampaignsFormConfig is injected by the Blade template.
 */
(function () {
    'use strict';

    var cfg = window.CampaignsFormConfig || {};

    // ── Tab switching ─────────────────────────────────────────────────────────

    document.querySelectorAll('.tab-btn[data-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.dataset.tab;
            document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
            btn.classList.add('active');
            var panel = document.getElementById('tab-' + target);
            if (panel) { panel.classList.add('active'); }
        });
    });

    // ── Color picker + live preview ───────────────────────────────────────────

    function updatePreview() {
        var name  = (document.getElementById('campaignName')  || {}).value || 'Campaign';
        var color = (document.getElementById('campaignColor') || {}).value || '#4f46e5';
        var bg    = (document.getElementById('campaignBg')    || {}).value || '#e0e7ff';
        var prev  = document.getElementById('campaignPreview');
        if (!prev) { return; }
        prev.textContent      = name;
        prev.style.background = bg;
        prev.style.color      = color;
        var hexC = document.getElementById('campaignColorHex');
        var hexB = document.getElementById('campaignBgHex');
        if (hexC) { hexC.value = color; }
        if (hexB) { hexB.value = bg; }
    }

    function syncColor(type) {
        var hexId    = type === 'color' ? 'campaignColorHex' : 'campaignBgHex';
        var pickerId = type === 'color' ? 'campaignColor'    : 'campaignBg';
        var hexEl    = document.getElementById(hexId);
        if (!hexEl) { return; }
        var val = hexEl.value.trim();
        if (/^#[0-9a-f]{6}$/i.test(val)) {
            var picker = document.getElementById(pickerId);
            if (picker) { picker.value = val; }
            updatePreview();
        }
    }

    ['campaignName', 'campaignColor', 'campaignBg'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', updatePreview); }
    });

    var hexC = document.getElementById('campaignColorHex');
    var hexB = document.getElementById('campaignBgHex');
    if (hexC) { hexC.addEventListener('input', function () { syncColor('color'); }); }
    if (hexB) { hexB.addEventListener('input', function () { syncColor('bg'); }); }

    updatePreview();

    // ── Submit guard ──────────────────────────────────────────────────────────

    var submitBtn = document.getElementById('campaignSubmitBtn');
    var form      = document.getElementById('campaignForm');
    if (form && submitBtn) {
        form.addEventListener('submit', function () { submitBtn.disabled = true; });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Agent dual-panel ──────────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════

    var allAgents   = cfg.allAgents        || [];
    var assignedIds = new Set((cfg.assignedAgentIds || []).map(Number));
    var searchQuery = '';

    if (!allAgents.length) { return; }   // no agents in tenant — nothing to render

    // ── Full render ───────────────────────────────────────────────────────────
    // Called on init and after any add / remove action.
    // Rebuilds both lists from assignedIds state, then re-applies the search filter.

    function render() {
        var asgn  = allAgents.filter(function (a) { return  assignedIds.has(a.id); });
        var avail = allAgents.filter(function (a) { return !assignedIds.has(a.id); });

        buildList('assignedList',  asgn,  true);
        buildList('availableList', avail, false);

        setText('countAssigned',      asgn.length);
        setText('countAvailable',     avail.length);
        setText('countAssignedPanel', asgn.length);
        setText('countAvailablePanel',avail.length);

        syncHiddenInputs();

        if (searchQuery) { applySearchFilter(); }
    }

    function buildList(listId, agents, isAssigned) {
        var el = document.getElementById(listId);
        if (!el) { return; }
        el.innerHTML = '';

        if (!agents.length) {
            var empty = document.createElement('div');
            empty.className   = 'ca-empty';
            empty.textContent = isAssigned
                ? 'No agents assigned to this campaign.'
                : (allAgents.length ? 'All agents are already assigned.' : 'No active agents available.');
            el.appendChild(empty);
            return;
        }

        agents.forEach(function (a) {
            el.appendChild(makeRow(a, isAssigned));
        });
    }

    function makeRow(a, isAssigned) {
        var row = document.createElement('div');
        row.className    = 'ca-row';
        row.dataset.id   = a.id;
        row.dataset.name = (a.name  || '').toLowerCase();
        row.dataset.mail = (a.email || '').toLowerCase();

        var initial = (a.name || '?').charAt(0).toUpperCase();
        var btnCls  = isAssigned ? 'ca-btn ca-btn-remove' : 'ca-btn ca-btn-add';
        var btnIcon = isAssigned ? '&times;'              : '+';
        var btnTip  = isAssigned ? 'Remove'               : 'Add';

        row.innerHTML =
            '<input type="checkbox" class="ca-chk" data-id="' + a.id + '">' +
            '<span class="ca-avatar">' + esc(initial) + '</span>' +
            '<span class="ca-info">'  +
              '<span class="ca-name">'  + esc(a.name)  + '</span>' +
              '<span class="ca-email">' + esc(a.email) + '</span>' +
            '</span>' +
            '<button type="button" class="' + btnCls + '" data-id="' + a.id + '" title="' + btnTip + '">' +
              btnIcon +
            '</button>';

        return row;
    }

    // ── Search: hide/show rows without re-rendering ───────────────────────────
    // Preserves checkbox state between keystrokes.

    function applySearchFilter() {
        var q = searchQuery.toLowerCase().trim();
        ['assignedList', 'availableList'].forEach(function (listId) {
            var el = document.getElementById(listId);
            if (!el) { return; }
            el.querySelectorAll('.ca-row').forEach(function (row) {
                var matches = !q
                    || row.dataset.name.indexOf(q) >= 0
                    || row.dataset.mail.indexOf(q) >= 0;
                row.classList.toggle('ca-hidden', !matches);
            });
        });
    }

    // ── Sync hidden inputs for form submission ────────────────────────────────

    function syncHiddenInputs() {
        var c = document.getElementById('agentInputs');
        if (!c) { return; }
        c.innerHTML = '';
        assignedIds.forEach(function (id) {
            var inp  = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'agent_ids[]';
            inp.value = id;
            c.appendChild(inp);
        });
    }

    // ── Event delegation for +/× buttons and row-click checkboxes ────────────

    function handleListClick(e) {
        var addBtn = e.target.closest('.ca-btn-add');
        if (addBtn) {
            e.preventDefault();
            assignedIds.add(Number(addBtn.dataset.id));
            render();
            return;
        }
        var rmBtn = e.target.closest('.ca-btn-remove');
        if (rmBtn) {
            e.preventDefault();
            assignedIds.delete(Number(rmBtn.dataset.id));
            render();
            return;
        }
        // Click anywhere on the row (except button/checkbox itself) toggles checkbox
        var row = e.target.closest('.ca-row');
        if (row && !e.target.closest('.ca-btn') && !e.target.matches('.ca-chk')) {
            var chk = row.querySelector('.ca-chk');
            if (chk) { chk.checked = !chk.checked; }
        }
    }

    ['assignedList', 'availableList'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('click', handleListClick); }
    });

    // ── Search ────────────────────────────────────────────────────────────────

    var searchEl = document.getElementById('agentSearch');
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            searchQuery = this.value;
            applySearchFilter();
        });
    }

    // ── Available footer: Select all visible (checks visible rows in Available) ─

    var selAllBtn = document.getElementById('selectAllVisible');
    if (selAllBtn) {
        selAllBtn.addEventListener('click', function () {
            var el = document.getElementById('availableList');
            if (!el) { return; }
            el.querySelectorAll('.ca-row:not(.ca-hidden) .ca-chk').forEach(function (chk) {
                chk.checked = true;
            });
        });
    }

    // ── Assigned footer: Remove all assigned agents ───────────────────────────

    var removeAllBtn = document.getElementById('removeAll');
    if (removeAllBtn) {
        removeAllBtn.addEventListener('click', function () {
            if (!assignedIds.size) { return; }
            if (!confirm('Remove all assigned agents from this campaign?')) { return; }
            assignedIds.clear();
            render();
        });
    }

    // ── Available footer: Add selected (available → assigned) ───────────────

    var addSelBtn = document.getElementById('addSelected');
    if (addSelBtn) {
        addSelBtn.addEventListener('click', function () {
            var el = document.getElementById('availableList');
            if (!el) { return; }
            var changed = false;
            el.querySelectorAll('.ca-chk:checked').forEach(function (chk) {
                var row = chk.closest('.ca-row');
                if (row && !row.classList.contains('ca-hidden')) {
                    assignedIds.add(Number(chk.dataset.id));
                    changed = true;
                }
            });
            if (changed) { render(); }
        });
    }

    // ── Assigned footer: Remove selected (assigned → available) ─────────────

    var rmSelBtn = document.getElementById('removeSelected');
    if (rmSelBtn) {
        rmSelBtn.addEventListener('click', function () {
            var el = document.getElementById('assignedList');
            if (!el) { return; }
            var changed = false;
            el.querySelectorAll('.ca-chk:checked').forEach(function (chk) {
                var row = chk.closest('.ca-row');
                if (row && !row.classList.contains('ca-hidden')) {
                    assignedIds.delete(Number(chk.dataset.id));
                    changed = true;
                }
            });
            if (changed) { render(); }
        });
    }

    // ── Utils ─────────────────────────────────────────────────────────────────

    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) { el.textContent = val; }
    }

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    // ── Initial render ────────────────────────────────────────────────────────

    render();

}());
