function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

var cfg = window.StoragePlansConfig || {};

function bytesToGb(bytes) {
    return bytes > 0 ? (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB' : '';
}

var cBytes = document.getElementById('cBytes');
if (cBytes) {
    cBytes.addEventListener('input', function () {
        document.getElementById('cBytesHint').textContent = bytesToGb(parseInt(this.value) || 0);
    });
}

var ePlanBytes = document.getElementById('ePlanBytes');
if (ePlanBytes) {
    ePlanBytes.addEventListener('input', function () {
        document.getElementById('ePlanBytesHint').textContent = bytesToGb(parseInt(this.value) || 0);
    });
}

document.querySelectorAll('.edit-plan-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var d = this.dataset;
        document.getElementById('ePlanCode').value         = d.code;
        document.getElementById('ePlanName').value         = d.name;
        document.getElementById('ePlanBytes').value        = d.bytes;
        document.getElementById('ePlanPrice').value        = d.price;
        document.getElementById('ePlanCurrency').value     = d.currency;
        document.getElementById('ePlanActive').checked     = d.isActive === '1';
        document.getElementById('ePlanBytesHint').textContent = bytesToGb(parseInt(d.bytes) || 0);
        document.getElementById('editPlanForm').action = cfg.updateBase + '/' + d.id;
        openModal('editPlanModal');
    });
});
