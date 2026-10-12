// public/js/sms-provider-accounts.js
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openEditProvider(id, data) {
    var form = document.getElementById('editProviderForm');
    form.action = window.SmsProviderAccountsConfig.routes.base + '/' + id;
    document.getElementById('editProviderTenant').value      = data.tenant_id || '';
    document.getElementById('editProviderProvider').value    = data.provider || '';
    document.getElementById('editProviderDisplayName').value = data.display_name || '';
    document.getElementById('editProviderRegion').value      = data.region || '';
    document.getElementById('editProviderOutboundUrl').value = data.outbound_url || '';
    openModal('editModal');
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-active-provider').forEach(function (badge) {
        badge.addEventListener('click', function () {
            var id  = badge.dataset.id;
            var url = window.SmsProviderAccountsConfig.routes.toggle.replace('{id}', id);
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
                badge.className = 'badge ' + (active ? 'badge-success' : 'badge-danger') + ' toggle-active-provider';
                badge.innerHTML = '<i class="bi ' + (active ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' badge-icon"></i> '
                    + (active ? 'Active' : 'Inactive');
            })
            .catch(function () { alert('Toggle failed. Please try again.'); });
        });
    });
});
