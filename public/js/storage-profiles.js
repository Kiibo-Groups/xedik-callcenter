function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

var cfg = window.StorageProfilesConfig || {};

document.querySelectorAll('.edit-profile-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var d = this.dataset;
        document.getElementById('eCode').value         = d.code;
        document.getElementById('eName').value         = d.name;
        document.getElementById('eEnv').value          = d.environment;
        document.getElementById('eProvider').value     = d.provider;
        document.getElementById('eBucket').value       = d.bucket;
        document.getElementById('eRegion').value       = d.region  || 'auto';
        document.getElementById('eEndpoint').value     = d.endpoint  || '';
        document.getElementById('eBasePath').value     = d.basePath  || '';
        document.getElementById('eAccessKey').value    = '';
        document.getElementById('eSecretKey').value    = '';
        document.getElementById('eIsDefault').checked  = d.isDefault === '1';
        document.getElementById('eIsActive').checked   = d.isActive  === '1';
        document.getElementById('editProfileForm').action = cfg.updateBase + '/' + d.id;
        openModal('editProfileModal');
    });
});

document.querySelectorAll('.test-conn-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var url  = this.dataset.url;
        var self = this;
        self.disabled = true;
        self.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;border-width:2px;"></span> Testing...';

        fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (typeof showToast === 'function') {
                    showToast(data.message, data.success ? 'success' : 'error');
                } else {
                    alert((data.success ? '✅ ' : '❌ ') + data.message);
                }
            })
            .catch(function () { alert('Network error.'); })
            .finally(function () {
                self.disabled = false;
                self.innerHTML = '<i class="bi bi-wifi"></i> Test';
            });
    });
});
