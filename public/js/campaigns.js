/**
 * Campaigns index — toggle-active and delete confirm.
 */
document.querySelectorAll('.toggle-campaign').forEach(function (badge) {
    badge.addEventListener('click', function () {
        const id = this.dataset.campaignId;
        const el = this;
        $.ajax({
            url: '/campaigns/' + id + '/toggle-active',
            method: 'PATCH',
            success: function (res) {
                if (res.is_active) {
                    el.className = 'badge badge-success toggle-campaign';
                    el.innerHTML = '<i class="bi bi-check-circle-fill badge-icon"></i> Active';
                } else {
                    el.className = 'badge badge-danger toggle-campaign';
                    el.innerHTML = '<i class="bi bi-x-circle-fill badge-icon"></i> Inactive';
                }
                el.dataset.campaignId = id;
                showToast('Campaign status updated.', 'success');
            }
        });
    });
});

document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = this.dataset.name;
        SwalConfirm.fire({
            title: 'Delete campaign?',
            html:  'You are about to delete <strong>' + name + '</strong>.<br>This cannot be undone.',
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});
