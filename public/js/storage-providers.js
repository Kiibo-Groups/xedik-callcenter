document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-provider').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url  = btn.dataset.url;
            var csrf = window.StorageProvidersConfig.csrf;

            fetch(url, {
                method: 'PATCH',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.is_active) {
                        btn.className = 'btn btn-sm btn-success toggle-provider';
                        btn.textContent = 'Active';
                    } else {
                        btn.className = 'btn btn-sm btn-outline-secondary toggle-provider';
                        btn.textContent = 'Inactive';
                    }
                })
                .catch(function () { alert('Network error.'); });
        });
    });
});
