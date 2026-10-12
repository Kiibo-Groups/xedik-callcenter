document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btn-temp-url').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url  = btn.dataset.url;
            var csrf = btn.dataset.csrf;
            var self = btn;
            self.disabled = true;
            self.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;border-width:2px;"></span>';

            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.url) {
                        window.open(data.url, '_blank');
                    } else {
                        alert('Could not generate temporary URL.');
                    }
                })
                .catch(function () { alert('Network error.'); })
                .finally(function () {
                    self.disabled = false;
                    self.innerHTML = '<i class="bi bi-link-45deg"></i> URL';
                });
        });
    });

    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var name = this.dataset.name || 'this file';
            if (typeof SwalConfirm !== 'undefined') {
                SwalConfirm.fire({
                    title: 'Delete file',
                    html: 'This file will be soft-deleted: <strong>' + name + '</strong>',
                }).then(function (r) { if (r.isConfirmed) form.submit(); });
            } else if (confirm('Delete ' + name + '?')) {
                form.submit();
            }
        });
    });
});
