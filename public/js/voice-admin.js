/* Voice Admin — shared JS for admin/voice/* pages */
(function () {
    'use strict';

    // ── Modal helpers ────────────────────────────────────────────────────────
    window.openModal = function (id) {
        document.getElementById(id)?.classList.add('active');
    };
    window.closeModal = function (id) {
        document.getElementById(id)?.classList.remove('active');
    };

    // ── Delete forms with SwalConfirm ───────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form.delete-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const name = form.dataset.name || 'this item';
                SwalConfirm.fire({
                    title: 'Delete ' + name + '?',
                    text:  'This action cannot be undone.',
                }).then(function (result) {
                    if (result.isConfirmed) form.submit();
                });
            });
        });
    });

    // ── Extension Range edit modal ───────────────────────────────────────────
    window.openEdit = function (id, start, end, label) {
        const cfg = window.VoiceAdminConfig || {};

        if (document.getElementById('edit_start')) {
            document.getElementById('edit_start').value = start;
            document.getElementById('edit_end').value   = end;
            document.getElementById('edit_label').value = label || '';
            const form = document.getElementById('editForm');
            if (form && cfg.rangeUpdateUrl) {
                form.action = cfg.rangeUpdateUrl.replace('{id}', id);
            }
            openModal('editModal');
            return;
        }

        // Gateway edit path
        if (document.getElementById('edit_name') && typeof start === 'object') {
            const data = start; // openEdit(id, dataObject) for gateways
            document.getElementById('edit_name').value        = data.name || '';
            document.getElementById('edit_endpoint_id').value = data.asterisk_endpoint_id || '';
            document.getElementById('edit_prefix').value      = data.hidden_prefix || '';
            document.getElementById('edit_country').value     = data.country_code || '';
            document.getElementById('edit_nat_len').value     = data.national_number_length || '';
            const tenantSelect = document.getElementById('edit_tenant_id');
            if (tenantSelect) tenantSelect.value = data.tenant_id != null ? String(data.tenant_id) : '';
            const gatewayIdField = document.getElementById('edit_gateway_id');
            if (gatewayIdField) gatewayIdField.value = id;
            const defCb = document.getElementById('edit_is_default');
            if (defCb) defCb.checked = !!data.is_default;
            const form = document.getElementById('editForm');
            if (form && cfg.gatewayUpdateUrl) {
                form.action = cfg.gatewayUpdateUrl.replace('{id}', id);
            }
            openModal('editModal');
        }
    };

    // ── Toggle helpers ───────────────────────────────────────────────────────
    function doToggle(url, btn, errorMsg) {
        const csrf = (window.VoiceAdminConfig || {}).csrf
            || document.querySelector('meta[name="csrf-token"]')?.content;

        fetch(url, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                btn.classList.toggle('active', !!data.is_active);
            })
            .catch(function () { alert(errorMsg || 'Toggle failed.'); });
    }

    window.toggleRange = function (id, btn) {
        const cfg = window.VoiceAdminConfig || {};
        doToggle((cfg.rangeToggleUrl || '/admin/voice/extension-ranges/{id}/toggle').replace('{id}', id), btn, 'Could not toggle range.');
    };

    window.toggleGateway = function (id, btn) {
        const cfg = window.VoiceAdminConfig || {};
        doToggle((cfg.gatewayToggleUrl || '/admin/voice/gateways/{id}/toggle').replace('{id}', id), btn, 'Could not toggle gateway.');
    };
}());
