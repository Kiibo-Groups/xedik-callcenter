// ── Lead List — detail modal ───────────────────────────────────────────────────

var _leadModalId     = null;
var _leadModalCustom = null;

function openLeadModal(tr) {
    var data         = JSON.parse(tr.dataset.lead);
    _leadModalId     = data.id;
    _leadModalCustom = data.custom || {};

    var html = '';

    // ── Phone (readonly) ──────────────────────────────────────────────────────
    html += '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;margin-bottom:12px;border-bottom:1px solid var(--border);">';
    html += '<label style="min-width:110px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">Phone</label>';
    html += '<code style="font-size:13px;">' + _esc(data.phone) + '</code>';
    html += '</div>';

    // ── Custom fields (editable) ──────────────────────────────────────────────
    var keys = Object.keys(_leadModalCustom);
    if (keys.length > 0) {
        html += '<div style="display:flex;flex-direction:column;gap:8px;">';
        keys.forEach(function (key) {
            var label = key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            html += '<div style="display:flex;align-items:center;gap:8px;">';
            html += '<label style="min-width:110px;font-size:12px;color:var(--text-muted);font-weight:500;flex-shrink:0;">' + _esc(label) + '</label>';
            html += '<input type="text" class="form-control" data-field="' + _esc(key) + '" value="' + _esc(_leadModalCustom[key]) + '" style="flex:1;font-size:13px;">';
            html += '</div>';
        });
        html += '</div>';
    } else {
        html += '<div style="text-align:center;color:var(--text-muted);font-size:13px;padding:8px 0;">No custom fields</div>';
    }

    document.getElementById('leadDetailBody').innerHTML = html;
    document.getElementById('leadDetailModal').classList.add('active');
}

function closeLeadModal() {
    document.getElementById('leadDetailModal').classList.remove('active');
    _leadModalId     = null;
    _leadModalCustom = null;
}

function saveLeadChanges() {
    if (!_leadModalId) return;

    var updated = {};
    document.querySelectorAll('#leadDetailBody input[data-field]').forEach(function (input) {
        updated[input.dataset.field] = input.value.trim();
    });

    var cfg = window.LeadListConfig || {};
    var url = cfg.updateBaseUrl + _leadModalId;
    var btn = document.getElementById('leadDetailSaveBtn');

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving…';

    fetch(url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': cfg.csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ custom_fields: updated }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save"></i> Save';
        if (data.ok) {
            _updateRowPreview(_leadModalId, updated);
            closeLeadModal();
        } else {
            alert('Error saving changes.');
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save"></i> Save';
        alert('Network error. Please try again.');
    });
}

function _updateRowPreview(leadId, customFields) {
    var row = document.querySelector('tr[data-lead-id="' + leadId + '"]');
    if (!row) return;
    var preview = Object.entries(customFields).map(function (e) { return e[0] + ': ' + e[1]; }).join(' · ');
    var cell = row.querySelector('.cf-preview');
    if (cell) cell.textContent = preview || '—';
    // Update data-lead attribute so reopening shows updated values
    try {
        var leadData       = JSON.parse(row.dataset.lead);
        leadData.custom    = customFields;
        row.dataset.lead   = JSON.stringify(leadData);
    } catch (e) {}
}

function _esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.delete-lead-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var phone = form.dataset.phone || 'this lead';
            if (window.SwalConfirm && typeof window.SwalConfirm.fire === 'function') {
                window.SwalConfirm.fire({
                    title: 'Delete lead?',
                    text: 'Remove ' + phone + ' from this list?',
                    confirmButtonText: 'Yes, delete',
                }).then(function (result) {
                    if (result.isConfirmed) form.submit();
                });
            } else if (confirm('Remove ' + phone + ' from this list?')) {
                form.submit();
            }
        });
    });
});
