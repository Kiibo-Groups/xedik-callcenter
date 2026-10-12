// Webchat Configs index — clipboard copy with execCommand fallback for non-HTTPS contexts

function wcCopyFallback(text, onSuccess) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); onSuccess(); } catch (e) {}
    document.body.removeChild(ta);
}

function wcCopyText(text, btn) {
    function showCheck() {
        btn.innerHTML = '<i class="bi bi-check" style="color:var(--success)"></i>';
        setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    }
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(showCheck).catch(() => wcCopyFallback(text, showCheck));
    } else {
        wcCopyFallback(text, showCheck);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.wc-copy-token').forEach(btn => {
        btn.addEventListener('click', () => wcCopyText(btn.dataset.token, btn));
    });

    document.querySelectorAll('.wc-status-toggle').forEach(function (badge) {
        badge.addEventListener('click', function () {
            const url  = this.dataset.url;
            const csrf = this.dataset.csrf;
            const self = this;
            fetch(url, {
                method:  'PATCH',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            })
            .then(r => r.json())
            .then(res => {
                if (res.is_active) {
                    self.className = 'badge badge-success wc-status-toggle';
                    self.innerHTML = '<i class="bi bi-check-circle-fill badge-icon"></i> Active';
                } else {
                    self.className = 'badge badge-danger wc-status-toggle';
                    self.innerHTML = '<i class="bi bi-x-circle-fill badge-icon"></i> Inactive';
                }
                self.style.cursor = 'pointer';
                if (typeof showToast === 'function') {
                    showToast('Webchat status updated.', 'success');
                }
            })
            .catch(() => {
                if (typeof showToast === 'function') {
                    showToast('Error updating status.', 'danger');
                }
            });
        });
    });
});
