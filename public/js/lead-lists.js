/* Lead Lists — modals + CSV import */
function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }

document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.LeadListsConfig || {};

    if (cfg.hasErrors) openModal('createModal');

    // Delete
    document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = this.dataset.name;
            SwalConfirm.fire({
                title: 'Delete lead list?',
                html:  `<strong>${name}</strong> and its leads and log tables will be permanently deleted.`,
            }).then(result => { if (result.isConfirmed) this.submit(); });
        });
    });
});

// ── Import ────────────────────────────────────────────────────────────────────

let _importListId = null;

function openImport(id, name) {
    _importListId = id;
    document.getElementById('importListName').textContent = name;
    document.getElementById('csvFile').value             = '';
    document.getElementById('csvFileName').textContent   = 'No file selected';
    document.getElementById('importProgress').style.display = 'none';
    document.getElementById('importBar').style.width        = '0%';
    document.getElementById('importStatus').textContent     = '';
    document.getElementById('importBtn').disabled           = false;
    document.getElementById('importBtn').innerHTML          = '<i class="bi bi-upload"></i> Import';
    openModal('importModal');
}

document.addEventListener('DOMContentLoaded', function () {
    const fileInput = document.getElementById('csvFile');
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            const label = document.getElementById('csvFileName');
            if (label) label.textContent = this.files[0]?.name || 'No file selected';
        });
    }
});

function submitImport() {
    const file = document.getElementById('csvFile').files[0];
    if (!file) {
        Swal.fire({ icon: 'warning', title: 'No file selected', text: 'Please select a CSV file first.', confirmButtonColor: '#4f46e5' });
        return;
    }

    const cfg = window.LeadListsConfig;
    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Importing...';

    document.getElementById('importProgress').style.display = 'block';
    document.getElementById('importBar').style.width = '20%';
    document.getElementById('importStatus').textContent = 'Uploading...';

    const formData = new FormData();
    formData.append('_token', cfg.csrf);
    formData.append('csv', file);

    fetch(cfg.routes.import + '/' + _importListId + '/import', {
        method: 'POST',
        body: formData,
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('importBar').style.width = '100%';
        if (data.ok) {
            var skipParts = [];
            if (data.skip_detail) {
                if (data.skip_detail.duplicates) skipParts.push(data.skip_detail.duplicates + ' duplicates');
                if (data.skip_detail.dnc)        skipParts.push(data.skip_detail.dnc + ' DNC');
                if (data.skip_detail.invalid)    skipParts.push(data.skip_detail.invalid + ' invalid');
            } else if (data.skipped) {
                skipParts.push(data.skipped + ' skipped');
            }

            var html = `<span style="color:#16a34a;">✓ ${data.imported.toLocaleString()} leads imported</span>`;
            if (skipParts.length) {
                html += ` <span style="color:var(--text-muted);">· ${skipParts.join(', ')} skipped</span>`;
            }

            // Mostrar detalle de inválidos si los hay
            if (data.invalid_samples && data.invalid_samples.length > 0) {
                html += '<div style="margin-top:8px;padding:8px 10px;background:var(--bg-stripe);border-radius:4px;border-left:3px solid var(--warning, #f59e0b);">';
                html += '<div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Rejected numbers:</div>';
                data.invalid_samples.forEach(function(s) {
                    var reason = s.reason === 'empty_phone' ? 'empty'
                               : s.reason === 'too_short'  ? 'too short after cleaning → "' + (s.cleaned || '') + '"'
                               : s.reason;
                    html += `<div style="font-size:11px;font-family:monospace;color:var(--text-strong);">• <strong>${s.raw || '(empty)'}</strong> — ${reason}</div>`;
                });
                if (data.skip_detail && data.skip_detail.invalid > data.invalid_samples.length) {
                    html += `<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">… and ${data.skip_detail.invalid - data.invalid_samples.length} more (see laravel.log)</div>`;
                }
                html += '</div>';
            }

            document.getElementById('importStatus').innerHTML = html;
            var delay = (data.invalid_samples && data.invalid_samples.length > 0) ? 8000 : 3500;
            setTimeout(() => { closeModal('importModal'); location.reload(); }, delay);
        } else {
            document.getElementById('importBar').style.width = '0%';
            document.getElementById('importStatus').innerHTML =
                `<span style="color:var(--danger);">✗ ${data.error || 'Import failed.'}</span>`;
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-upload"></i> Import';
        }
    })
    .catch(() => {
        document.getElementById('importBar').style.width = '0%';
        document.getElementById('importStatus').innerHTML = '<span style="color:var(--danger);">✗ Connection error.</span>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-upload"></i> Import';
    });
}

// ── Archive ───────────────────────────────────────────────────────────────────

function archiveList(id, name) {
    SwalConfirm.fire({
        title: 'Archive lead list?',
        html:  `<strong>${name}</strong> will be archived and cannot be used in new campaigns.`,
        confirmButtonText: 'Yes, archive',
    }).then(result => {
        if (!result.isConfirmed) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.LeadListsConfig.routes.archive + '/' + id + '/archive';
        form.innerHTML = `<input type="hidden" name="_token" value="${window.LeadListsConfig.csrf}">
                          <input type="hidden" name="_method" value="PATCH">`;
        document.body.appendChild(form);
        form.submit();
    });
}
