// public/js/sms-channels-admin.js
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openEditChannel(id, data) {
    var form = document.getElementById('editChannelForm');
    form.action = window.SmsChannelsAdminConfig.routes.base + '/' + id;
    document.getElementById('editChannelTenant').value      = data.tenant_id || '';
    document.getElementById('editChannelAccount').value     = data.sms_provider_account_id || '';
    document.getElementById('editChannelDisplayName').value = data.display_name || '';
    document.getElementById('editChannelAddress').value     = data.address || '';
    document.getElementById('editChannelAddressType').value = data.address_type || 'long_code';
    document.getElementById('editChannelCountry').value     = data.country || '';
    document.getElementById('editP2p').checked               = !!data.supports_p2p;
    document.getElementById('editA2p').checked                = !!data.supports_a2p;
    openModal('editModal');
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-active-channel').forEach(function (badge) {
        badge.addEventListener('click', function () {
            var id  = badge.dataset.id;
            var url = window.SmsChannelsAdminConfig.routes.toggle.replace('{id}', id);
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
                badge.className = 'badge ' + (active ? 'badge-success' : 'badge-danger') + ' toggle-active-channel';
                badge.innerHTML = '<i class="bi ' + (active ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' badge-icon"></i> '
                    + (active ? 'Active' : 'Inactive');
            })
            .catch(function () { alert('Toggle failed. Please try again.'); });
        });
    });
});
