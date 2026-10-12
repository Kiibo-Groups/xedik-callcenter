(function () {
    'use strict';

    // Toggle active status badge via AJAX
    document.querySelectorAll('.toggle-active').forEach(function (badge) {
        badge.addEventListener('click', function () {
            var userId = badge.dataset.userId;
            $.ajax({
                url:    '/users/' + userId + '/toggle-active',
                method: 'PATCH',
                success: function (res) {
                    if (res.is_active) {
                        badge.className = 'badge badge-success toggle-active';
                        badge.innerHTML = '<i class="bi bi-check-circle-fill badge-icon"></i> Active';
                    } else {
                        badge.className = 'badge badge-danger toggle-active';
                        badge.innerHTML = '<i class="bi bi-x-circle-fill badge-icon"></i> Inactive';
                    }
                    badge.dataset.userId = userId;
                    showToast('User status updated.', 'success');
                }
            });
        });
    });

    // Delete confirmation via SwalConfirm
    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            SwalConfirm.fire({
                title: 'Delete user?',
                html:  'You are about to delete <strong>' + form.dataset.name + '</strong>.<br>This cannot be undone.',
            }).then(function (result) {
                if (result.isConfirmed) { form.submit(); }
            });
        });
    });

}());
