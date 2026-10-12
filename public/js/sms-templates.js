// public/js/sms-templates.js
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

window.SmsTemplates = {
    openCreate() {
        openModal('modal-create');
        this.updateCounter('create-body', 'create-counter');
    },
    openEdit(id, name, omniQueueId, body) {
        document.getElementById('edit-name').value  = name;
        document.getElementById('edit-queue').value = omniQueueId || '';
        document.getElementById('edit-body').value  = body;
        document.getElementById('edit-form').action = window.SmsTemplatesConfig.updateBase + '/' + id;
        this.updateCounter('edit-body', 'edit-counter');
        openModal('modal-edit');
    },
    updateCounter(textareaId, counterId) {
        var textarea = document.getElementById(textareaId);
        var counter  = document.getElementById(counterId);
        if (!textarea || !counter || typeof SmsSegmentCounter === 'undefined') return;
        var result = SmsSegmentCounter.formatLabel(textarea.value, 3);
        counter.textContent = result.label;
        counter.style.color = result.analysis.segments > 3 ? '#dc2626' : 'var(--text-muted)';
    },
};

document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.modal-overlay').forEach(el => {
        el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
    });

    if (window.SmsTemplatesConfig?.hasErrors) {
        openModal('modal-create');
    }

    document.querySelectorAll('.tpl-toggle').forEach(function (badge) {
        badge.addEventListener('click', function () {
            const url  = this.dataset.url;
            const self = this;
            fetch(url, {
                method:  'PATCH',
                headers: {
                    'X-CSRF-TOKEN': window.SmsTemplatesConfig.csrf,
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
