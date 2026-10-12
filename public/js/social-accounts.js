/**
 * Social Network accounts — UI logic
 * window.SocialAccountsConfig is injected by the Blade view.
 */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

var cfg = window.SocialAccountsConfig || {};

document.querySelectorAll('.edit-account-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('editDisplayName').value = this.dataset.name;
        document.getElementById('editQueueId').value     = this.dataset.queue || '';
        document.getElementById('editForm').action       = cfg.baseUrl + '/' + this.dataset.id;
        openModal('editModal');
    });
});

document.querySelectorAll('.toggle-active').forEach(function (badge) {
    badge.addEventListener('click', function () {
        const accountId = this.dataset.accountId;
        const self      = this;
        $.ajax({
            url: cfg.baseUrl + '/' + accountId + '/toggle',
            method: 'PATCH',
            success: function (res) {
                if (res.is_active) {
                    self.className = 'badge badge-success toggle-active';
                    self.textContent = 'Active';
                } else {
                    self.className = 'badge badge-secondary toggle-active';
                    self.textContent = 'Inactive';
                }
                self.dataset.accountId = accountId;
                showToast('Account status updated.', 'success');
            }
        });
    });
});
