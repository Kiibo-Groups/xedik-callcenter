/**
 * Lead Sources CRUD — UI logic
 * window.SourcesConfig is injected by the Blade view.
 */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.querySelectorAll('.edit-source-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('editName').value  = this.dataset.name;
        document.getElementById('editForm').action = '/sources/' + this.dataset.id;
        openModal('editModal');
    });
});

document.querySelectorAll('.toggle-source').forEach(function (badge) {
    badge.addEventListener('click', function () {
        const id = this.dataset.sourceId;
        const el = this;
        $.ajax({
            url: '/sources/' + id + '/toggle-active',
            method: 'PATCH',
            success: function (res) {
                el.className   = res.is_active
                    ? 'badge badge-success toggle-source'
                    : 'badge badge-danger toggle-source';
                el.textContent = res.is_active ? 'Active' : 'Inactive';
                el.dataset.sourceId = id;
                showToast('Source updated.', 'success');
            }
        });
    });
});

document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = this.dataset.name;
        SwalConfirm.fire({
            title: 'Delete source?',
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
            url: window.SourcesConfig.reorderUrl,
            method: 'POST',
            data: { order: ids },
            success: function () { showToast('Order saved.', 'success'); },
        });
    }
});

var cfg = window.SourcesConfig || {};
if (cfg.hasErrors) openModal('createModal');
