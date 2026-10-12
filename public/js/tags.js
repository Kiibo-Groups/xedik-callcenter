/**
 * Tags CRUD — UI logic
 * window.TagsConfig is injected by the Blade view.
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

document.querySelectorAll('.edit-tag-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const id    = this.dataset.id;
        const name  = this.dataset.name;
        const color = this.dataset.color;
        const bg    = this.dataset.bg;

        document.getElementById('editName').value     = name;
        document.getElementById('editColor').value    = color;
        document.getElementById('editColorHex').value = color;
        document.getElementById('editBg').value       = bg;
        document.getElementById('editBgHex').value    = bg;
        document.getElementById('editForm').action    = '/tags/' + id;

        var prev = document.getElementById('editPreview');
        prev.textContent      = name;
        prev.style.background = bg;
        prev.style.color      = color;

        openModal('editModal');
    });
});

document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = this.dataset.name;
        SwalConfirm.fire({
            title: 'Delete tag?',
            html:  'You are about to delete <strong>' + name + '</strong>.<br>This cannot be undone.',
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});

var cfg = window.TagsConfig || {};
if (cfg.hasErrors) openModal('createModal');
