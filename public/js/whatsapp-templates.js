// public/js/whatsapp-templates.js

function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

window.WaTemplates = {
    openCreate() {
        openModal('modal-create');
    },
    openEdit(id, name, displayName, language, bodyPreview) {
        document.getElementById('edit-name').value         = name;
        document.getElementById('edit-display-name').value = displayName;
        document.getElementById('edit-language').value     = language;
        document.getElementById('edit-body-preview').value = bodyPreview;
        document.getElementById('edit-form').action        =
            window.WaTemplatesConfig.updateBase + '/' + id;
        openModal('modal-edit');
    },
};

document.addEventListener('DOMContentLoaded', function () {

    // Close on overlay click
    document.querySelectorAll('.modal-overlay').forEach(el => {
        el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
    });

    // Reopen create modal if there were validation errors
    if (window.WaTemplatesConfig?.hasErrors) {
        openModal('modal-create');
    }

    // ── Toggle active (badge click, identical pattern to users.js) ──
    document.querySelectorAll('.tpl-toggle').forEach(function (badge) {
        badge.addEventListener('click', function () {
            const url  = this.dataset.url;
            const self = this;
            fetch(url, {
                method:  'PATCH',
                headers: {
                    'X-CSRF-TOKEN': window.WaTemplatesConfig.csrf,
                    'Accept':       'application/json',
                },
            })
            .then(r => r.json())
            .then(res => {
                if (res.is_active) {
                    self.className = 'badge badge-success tpl-toggle';
                    self.innerHTML = '<i class="bi bi-check-circle-fill badge-icon"></i> Active';
                } else {
                    self.className = 'badge badge-danger tpl-toggle';
                    self.innerHTML = '<i class="bi bi-x-circle-fill badge-icon"></i> Inactive';
                }
                self.style.cursor = 'pointer';
                if (typeof showToast === 'function') {
                    showToast('Template status updated.', 'success');
                }
            })
            .catch(() => {
                if (typeof showToast === 'function') {
                    showToast('Error updating status.', 'danger');
                }
            });
        });
    });

});
