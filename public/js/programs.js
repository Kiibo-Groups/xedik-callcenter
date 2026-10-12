/**
 * Programs CRUD — UI logic
 * window.ProgramsConfig is injected by the Blade view.
 */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    if (id === 'editModal')   resetEditBtn();
    if (id === 'createModal') resetCreateBtn();
}

function resetEditBtn() {
    const btn = document.getElementById('editSubmitBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save"></i> Save Changes'; }
}
function resetCreateBtn() {
    const btn = document.getElementById('createSubmitBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save"></i> Create Program'; }
}

function previewSlug(value, targetId) {
    document.getElementById(targetId).value = value.toLowerCase().trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');
}


document.getElementById('createName').addEventListener('input', function () {
    previewSlug(this.value, 'createSlugPreview');
});
document.getElementById('editName').addEventListener('input', function () {
    previewSlug(this.value, 'editSlugPreview');
});

document.getElementById('createForm').addEventListener('submit', function () {
    const btn = document.getElementById('createSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creating...';
});
document.getElementById('editForm').addEventListener('submit', function () {
    const btn = document.getElementById('editSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
});

document.querySelectorAll('.edit-program-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('editName').value        = this.dataset.name;
        document.getElementById('editSlugPreview').value = this.dataset.slug;
        document.getElementById('editIsActive').checked  = this.dataset.isActive === '1';
        document.getElementById('editForm').action       = '/programs/' + this.dataset.id;
        resetEditBtn();
        openModal('editModal');
    });
});

// Delete confirmation via SweetAlert2
document.querySelectorAll('.btn-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const name = this.dataset.name;
        const form = this.closest('.delete-form');
        SwalConfirm.fire({
            title: 'Delete program?',
            html:  'You are about to delete <strong>' + name + '</strong>.<br>This cannot be undone.',
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});
var cfg = window.ProgramsConfig || {};
if (cfg.hasErrors) {
    if (cfg.oldMethod === 'PUT') {
        openModal('editModal');
        resetEditBtn();
        document.getElementById('editName').value = cfg.oldName || '';
        previewSlug(cfg.oldName || '', 'editSlugPreview');
    } else {
        openModal('createModal');
        document.getElementById('createName').value = cfg.oldName || '';
        previewSlug(cfg.oldName || '', 'createSlugPreview');
    }
}
