// public/js/dispositions.js

function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.addEventListener('DOMContentLoaded', function () {

    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(el => {
        el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
    });

    // ── Live preview ──
    function updatePreview() {
        const color   = document.getElementById('colorHex').value;
        const bgColor = document.getElementById('bgColorHex').value;
        const preview = document.getElementById('badgePreview');
        if (preview) {
            preview.style.color      = color;
            preview.style.background = bgColor;
        }
    }

    // Color picker ↔ hex input sync
    document.getElementById('colorPicker').addEventListener('input', function () {
        document.getElementById('colorHex').value = this.value;
        updatePreview();
    });
    document.getElementById('colorHex').addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
            document.getElementById('colorPicker').value = this.value;
            updatePreview();
        }
    });
    document.getElementById('bgColorPicker').addEventListener('input', function () {
        document.getElementById('bgColorHex').value = this.value;
        updatePreview();
    });
    document.getElementById('bgColorHex').addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
            document.getElementById('bgColorPicker').value = this.value;
            updatePreview();
        }
    });

    // ── Presets ──
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const color = this.dataset.color;
            const bg    = this.dataset.bg;
            document.getElementById('colorHex').value      = color;
            document.getElementById('bgColorHex').value    = bg;
            document.getElementById('colorPicker').value   = color;
            document.getElementById('bgColorPicker').value = bg;
            updatePreview();
        });
    });

    // ── Edit modal ──
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const name    = this.dataset.name;
            const desc    = this.dataset.description;
            const requiresCallbackAt = this.dataset.requiresCallbackAt === '1';
            const color   = this.dataset.color;
            const bgColor = this.dataset.bgColor;

            document.getElementById('editName').value                 = name;
            document.getElementById('editDescription').value          = desc;
            document.getElementById('editRequiresCallbackAt').checked = requiresCallbackAt;
            document.getElementById('colorHex').value                 = color;
            document.getElementById('bgColorHex').value               = bgColor;
            document.getElementById('colorPicker').value              = color;
            document.getElementById('bgColorPicker').value            = bgColor;
            document.getElementById('badgePreview').textContent       = name;
            document.getElementById('badgePreview').style.color       = color;
            document.getElementById('badgePreview').style.background  = bgColor;
            document.getElementById('editForm').action                = window.DispositionsConfig.updateBase + '/' + this.dataset.id;
            openModal('editModal');
        });
    });

    // ── Toggle creates lead ──
    document.querySelectorAll('.toggle-disp').forEach(input => {
        input.addEventListener('change', function () {
            const id    = this.dataset.id;
            const track = document.getElementById('track-' + id);
            const thumb = document.getElementById('thumb-' + id);
            $.ajax({
                url:    window.DispositionsConfig.updateBase + '/' + id + '/toggle',
                method: 'PATCH',
                success: res => {
                    if (res.is_active) {
                        track.style.background = '#22c55e';
                        thumb.style.left = '23px';
                        showToast('Activated — will create leads.', 'success');
                    } else {
                        track.style.background = '#cbd5e1';
                        thumb.style.left = '3px';
                        showToast('Deactivated.', 'success');
                    }
                }
            });
        });
    });

    // ── Delete ──
    document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = this.dataset.name;
            SwalConfirm.fire({
                title: 'Delete disposition?',
                html:  'You are about to delete <strong>' + name + '</strong>.<br>This cannot be undone.',
            }).then(result => {
                if (result.isConfirmed) this.submit();
            });
        });
    });

});
