document.addEventListener('DOMContentLoaded', function () {
    var cfg             = window.TenantStorageConfig || {};
    var ownershipSelect = document.getElementById('ownershipType');

    function toggleSections() {
        var val     = ownershipSelect ? ownershipSelect.value : 'crm_managed';
        var managed = document.getElementById('managedSection');
        var owned   = document.getElementById('ownedSection');
        if (managed) managed.style.display = val === 'crm_managed' ? '' : 'none';
        if (owned)   owned.style.display   = val === 'tenant_owned' ? '' : 'none';
    }

    if (ownershipSelect) {
        ownershipSelect.addEventListener('change', toggleSections);
        toggleSections();
    }

    // Auto-dismiss success banner after 4 seconds
    var banner = document.getElementById('connStatusBanner');
    if (banner && banner.dataset.status === 'success') {
        setTimeout(function () {
            banner.style.transition = 'opacity .5s';
            banner.style.opacity    = '0';
            setTimeout(function () { banner.remove(); }, 500);
        }, 4000);
    }

    var btnTest = document.getElementById('btnTestConn');
    if (btnTest) {
        btnTest.addEventListener('click', function () {
            var tenantId = this.dataset.tenant;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;border-width:2px;"></span> Testing...';
            var self = this;

            fetch(cfg.testUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': cfg.csrf,
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ tenant_id: tenantId }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (typeof showToast === 'function') {
                        showToast(data.message, data.success ? 'success' : 'error');
                    } else {
                        alert((data.success ? '✅ ' : '❌ ') + data.message);
                    }
                    if (data.success) setTimeout(function () { location.reload(); }, 800);
                })
                .catch(function () { alert('Network error.'); })
                .finally(function () {
                    self.disabled = false;
                    self.innerHTML = '<i class="bi bi-wifi"></i> Test Connection';
                });
        });
    }

    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var name = this.dataset.name || 'this item';
            if (typeof SwalConfirm !== 'undefined') {
                SwalConfirm.fire({
                    title: 'Cancel subscription',
                    html:  'Are you sure you want to cancel <strong>' + name + '</strong>?',
                }).then(function (r) { if (r.isConfirmed) form.submit(); });
            } else if (confirm('Cancel ' + name + '?')) {
                form.submit();
            }
        });
    });
});
