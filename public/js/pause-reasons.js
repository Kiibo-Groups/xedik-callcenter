/**
 * Pause Reasons CRUD — UI logic
 * window.PauseReasonsConfig is injected by the Blade view.
 */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function updatePreview(prefix) {
    const name  = document.getElementById(prefix + 'Name')?.value || 'Preview';
    const color = document.getElementById(prefix + 'Color').value;
    const prev  = document.getElementById(prefix + 'Preview');
    prev.textContent      = name;
    prev.style.color      = color;
    prev.style.background = color + '20';
    prev.style.border     = '1px solid ' + color + '40';
    document.getElementById(prefix + 'ColorHex').value = color;
}

function syncColor(prefix) {
    const val = document.getElementById(prefix + 'ColorHex').value;
    if (/^#[0-9a-f]{6}$/i.test(val)) {
        document.getElementById(prefix + 'Color').value = val;
        updatePreview(prefix);
    }
}

// Attach live preview listeners
['createName','createColor'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function () { updatePreview('create'); });
});
['editName','editColor'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function () { updatePreview('edit'); });
});
var createHex = document.getElementById('createColorHex');
var editHex   = document.getElementById('editColorHex');
if (createHex) createHex.addEventListener('input', function () { syncColor('create'); });
if (editHex)   editHex.addEventListener('input',   function () { syncColor('edit'); });

document.querySelectorAll('.edit-reason-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const id    = this.dataset.id;
        const name  = this.dataset.name;
        const color = this.dataset.color;
        const max   = this.dataset.max;

        document.getElementById('editName').value       = name;
        document.getElementById('editColor').value      = color;
        document.getElementById('editColorHex').value   = color;
        document.getElementById('editMaxMinutes').value = max || '';
        document.getElementById('editForm').action      = '/pause-reasons/' + id;

        var prev = document.getElementById('editPreview');
        prev.textContent      = name;
        prev.style.color      = color;
        prev.style.background = color + '20';
        prev.style.border     = '1px solid ' + color + '40';

        openModal('editModal');
    });
});

document.querySelectorAll('.toggle-reason').forEach(function (badge) {
    badge.addEventListener('click', function () {
        const id = this.dataset.reasonId;
        const el = this;
        $.ajax({
            url: '/pause-reasons/' + id + '/toggle',
            method: 'PATCH',
            success: function (res) {
                el.className   = res.is_active ? 'badge badge-success toggle-reason' : 'badge badge-danger toggle-reason';
                el.textContent = res.is_active ? 'Active' : 'Inactive';
                el.dataset.reasonId = id;
                showToast('Updated.', 'success');
            }
        });
    });
});

document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = this.dataset.name;
        SwalConfirm.fire({
            title: 'Delete pause reason?',
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
        const ids = [...document.querySelectorAll('#sortableBody tr[data-id]')]
            .map(function (tr) { return tr.dataset.id; });
        $.ajax({
            url: window.PauseReasonsConfig.reorderUrl,
            method: 'POST',
            data: { order: ids },
            success: function () { showToast('Order saved.', 'success'); },
            error:   function () { showToast('Error saving order.', 'error'); },
        });
    }
});

var cfg = window.PauseReasonsConfig || {};
if (cfg.hasErrors) openModal('createModal');
