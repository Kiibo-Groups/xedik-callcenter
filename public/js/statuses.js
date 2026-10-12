/**
 * Lead Statuses CRUD — UI logic
 * window.StatusesConfig is injected by the Blade view.
 */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function makeSlug(name) {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function updatePreview(prefix) {
    const name  = document.getElementById(prefix + 'Name').value || 'Preview';
    const color = document.getElementById(prefix + 'Color').value;
    const bg    = document.getElementById(prefix + 'Bg').value;
    const prev  = document.getElementById(prefix + 'Preview');
    prev.textContent      = name;
    prev.style.background = bg;
    prev.style.color      = color;
    document.getElementById(prefix + 'ColorHex').value = color;
    document.getElementById(prefix + 'BgHex').value    = bg;
    if (prefix === 'create') {
        document.getElementById('slugPreview').textContent = makeSlug(name) || '—';
    }
}

function syncColor(prefix, type) {
    const hexId    = prefix + (type === 'color' ? 'ColorHex' : 'BgHex');
    const pickerId = prefix + (type === 'color' ? 'Color'    : 'Bg');
    const val = document.getElementById(hexId).value;
    if (/^#[0-9a-f]{6}$/i.test(val)) {
        document.getElementById(pickerId).value = val;
        updatePreview(prefix);
    }
}

// Attach live preview listeners
['createName','createColor','createBg'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function () { updatePreview('create'); });
});
['editName','editColor','editBg'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function () { updatePreview('edit'); });
});
[['createColorHex','create','color'],['createBgHex','create','bg'],
 ['editColorHex','edit','color'],['editBgHex','edit','bg']].forEach(function (t) {
    var el = document.getElementById(t[0]);
    if (el) el.addEventListener('input', function () { syncColor(t[1], t[2]); });
});

document.querySelectorAll('.edit-status-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const id    = this.dataset.id;
        const name  = this.dataset.name;
        const color = this.dataset.color;
        const bg    = this.dataset.bg;
        const desc  = this.dataset.description || '';
        const isDef = this.dataset.default === '1';
        const showInPipeline = this.dataset.showInPipeline === '1';
        const isWon  = this.dataset.won === '1';
        const isLost = this.dataset.lost === '1';

        document.getElementById('editName').value        = name;
        document.getElementById('editColor').value       = color;
        document.getElementById('editColorHex').value    = color;
        document.getElementById('editBg').value          = bg;
        document.getElementById('editBgHex').value       = bg;
        document.getElementById('editDescription').value = desc;
        document.getElementById('editDefault').checked   = isDef;
        document.getElementById('editShowInPipeline').checked = showInPipeline;
        document.getElementById('editIsWon').checked     = isWon;
        document.getElementById('editIsLost').checked    = isLost;
        document.getElementById('editForm').action       = '/statuses/' + id;

        var prev = document.getElementById('editPreview');
        prev.textContent      = name;
        prev.style.background = bg;
        prev.style.color      = color;

        openModal('editModal');
    });
});

document.querySelectorAll('.toggle-status').forEach(function (badge) {
    badge.addEventListener('click', function () {
        const id = this.dataset.statusId;
        const el = this;
        $.ajax({
            url: '/statuses/' + id + '/toggle-active',
            method: 'PATCH',
            success: function (res) {
                el.className   = res.is_active ? 'badge badge-success toggle-status' : 'badge badge-danger toggle-status';
                el.textContent = res.is_active ? 'Active' : 'Inactive';
                el.dataset.statusId = id;
                showToast('Status updated.', 'success');
            }
        });
    });
});

document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = this.dataset.name;
        SwalConfirm.fire({
            title: 'Delete status?',
            html:  'You are about to delete <strong>' + name + '</strong>.<br>This cannot be undone.',
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});

Sortable.create(document.getElementById('sortableBody'), {
    handle: '.bi-grip-vertical',
    animation: 150,
    onEnd: function () {
        const rows = [...document.querySelectorAll('#sortableBody tr[data-id]')];
        const ids  = rows.map(function (tr) { return tr.dataset.id; });
        $.ajax({
            url: window.StatusesConfig.reorderUrl,
            method: 'POST',
            data: { order: ids },
            success: function () {
                // El backend asigna order = index+1 según este mismo array —
                // refrescar la celda "Order" de cada fila sin esperar a F5.
                rows.forEach(function (tr, index) {
                    var cell = tr.querySelector('[data-role="order-value"]');
                    if (cell) cell.textContent = index + 1;
                });
                showToast('Order saved.', 'success');
            },
            error:   function () { showToast('Error saving order.', 'error'); },
        });
    }
});

// is_won / is_lost son mutuamente excluyentes — marcar uno desmarca el otro
[['createIsWon','createIsLost'],['createIsLost','createIsWon'],
 ['editIsWon','editIsLost'],['editIsLost','editIsWon']].forEach(function (pair) {
    var el     = document.getElementById(pair[0]);
    var other  = document.getElementById(pair[1]);
    if (el && other) {
        el.addEventListener('change', function () {
            if (this.checked) other.checked = false;
        });
    }
});

var cfg = window.StatusesConfig || {};
if (cfg.hasErrors) openModal('createModal');
