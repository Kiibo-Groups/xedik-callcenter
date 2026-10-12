/**
 * Voice Caller IDs admin UI
 * window.VoiceCallerIdsConfig is injected by the Blade view.
 */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openEdit(id, data) {
    var form = document.getElementById('editForm');
    form.action = window.VoiceCallerIdsConfig.routes.base + '/' + id;
    document.getElementById('editNumber').value    = data.number  || '';
    document.getElementById('editName').value      = data.name    || '';
    document.getElementById('editGateway').value   = data.voice_gateway_id || '';
    document.getElementById('editIsActive').checked   = !!data.is_active;
    document.getElementById('editIsVerified').checked = !!data.is_verified;
    openModal('editModal');
}

// Toggle active via AJAX
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-active-cid').forEach(function (badge) {
        badge.addEventListener('click', function () {
            var id  = badge.dataset.id;
            var url = window.VoiceCallerIdsConfig.routes.toggle.replace('{id}', id);
            fetch(url, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var active = !!json.is_active;
                badge.className = 'badge ' + (active ? 'badge-success' : 'badge-danger') + ' toggle-active-cid';
                badge.dataset.active = active ? '1' : '0';
                badge.innerHTML = '<i class="bi ' + (active ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' badge-icon"></i> '
                    + (active ? 'Active' : 'Inactive');
            })
            .catch(function () { alert('Toggle failed. Please try again.'); });
        });
    });

    // SweetAlert delete confirmation
    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var name = form.dataset.name || 'this item';
            if (typeof SwalConfirm === 'function') {
                SwalConfirm('Delete Caller ID', 'Delete "' + name + '"?', function () { form.submit(); });
            } else if (confirm('Delete "' + name + '"?')) {
                form.submit();
            }
        });
    });
});
