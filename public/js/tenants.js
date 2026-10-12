/**
 * Tenants CRUD — UI logic
 * window.TenantsConfig is injected by the Blade view.
 */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// Auto-generate slug from name in create form
document.getElementById('createName').addEventListener('input', function () {
    var slug = this.value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    document.getElementById('createSlug').value = slug;
});

// Edit button — populate modal
document.querySelectorAll('.edit-tenant-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id       = this.dataset.id;
        var isActive = this.dataset.isActive === '1';

        document.getElementById('editName').value     = this.dataset.name;
        document.getElementById('editSlug').value     = this.dataset.slug;
        document.getElementById('editIsActive').checked = isActive;
        document.getElementById('editForm').action    = '/tenants/' + id;

        openModal('editModal');
    });
});

// Delete confirmation
document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = this.dataset.name;
        SwalConfirm.fire({
            title: 'Delete tenant?',
            html:  'You are about to delete <strong>' + name + '</strong>.<br>This cannot be undone.',
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});

// Reopen modal on validation error
var cfg = window.TenantsConfig || {};
if (cfg.hasErrors) {
    if (cfg.isEditMethod) {
        openModal('editModal');
    } else {
        openModal('createModal');
    }
}
