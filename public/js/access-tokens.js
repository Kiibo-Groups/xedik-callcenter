/* Access Tokens — admin catalog */
function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.AccessTokensConfig || {};

    if (cfg.hasErrors) openModal('createModal');

    document.querySelectorAll('.delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = this.dataset.name;
            SwalConfirm.fire({
                title: 'Delete token?',
                html: `Token <strong>${name}</strong> will be permanently deleted.`,
            }).then(function (result) { if (result.isConfirmed) form.submit(); });
        });
    });
});

function copyToken(token) {
    navigator.clipboard.writeText(token).then(function () {
        Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'Token copied to clipboard.',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end',
        });
    }).catch(function () {
        // Fallback for environments without clipboard API
        var ta = document.createElement('textarea');
        ta.value = token;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        Swal.fire({ icon: 'success', title: 'Copied!', timer: 1500, showConfirmButton: false, toast: true, position: 'top-end' });
    });
}
