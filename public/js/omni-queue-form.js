function toggleAgent(checkbox) {
    var agentId = checkbox.dataset.agent;
    var row     = document.getElementById('row-' + agentId);
    var enabled = checkbox.checked;
    row.style.opacity    = enabled ? '1' : '0.4';
    row.style.background = enabled ? '#f8faff' : '';
}

async function loadQueueAgents() {
    const campaignId = document.getElementById('campaignSelect')?.value;
    if (!campaignId) return;

    const resp = await fetch(`/omni-queues/agents-by-campaign/${campaignId}`);
    if (!resp.ok) return;

    const data = await resp.json();
    const sel  = document.getElementById('defaultAgentSelect');
    if (!sel) return;

    const currentVal = sel.dataset.selected || '';
    sel.innerHTML = '<option value="">Select agent...</option>';
    data.agents.forEach(a => {
        const opt = document.createElement('option');
        opt.value       = a.id;
        opt.textContent = a.name + (a.role === 'supervisor' ? ' (Supervisor)' : '');
        opt.selected    = String(a.id) === String(currentVal);
        sel.appendChild(opt);
    });
}

function syncDefaultAgentRow() {
    const mode = document.getElementById('assignmentModeSelect')?.value;
    const row  = document.getElementById('defaultAgentRow');
    if (!row) return;
    row.style.display = mode === 'direct' ? 'block' : 'none';
    if (mode === 'direct') loadQueueAgents();
}

function filterScriptsByCampaign() {
    const campaignId   = document.getElementById('campaignSelect')?.value || '';
    const scriptSelect = document.getElementById('scriptSelect');
    if (!scriptSelect) return;

    const currentVal          = scriptSelect.value;
    let   currentStillVisible = false;

    scriptSelect.querySelectorAll('option[data-campaign]').forEach(function (opt) {
        const visible = campaignId !== '' && opt.dataset.campaign === campaignId;
        opt.style.display = visible ? '' : 'none';
        if (visible && opt.value === currentVal) currentStillVisible = true;
    });

    // If the selected script no longer belongs to the chosen campaign, reset.
    if (currentVal !== '' && !currentStillVisible) {
        scriptSelect.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.agent-check').forEach(function(cb) {
        var row = document.getElementById('row-' + cb.dataset.agent);
        if (cb.checked) {
            row.style.opacity    = '1';
            row.style.background = '#f8faff';
        } else {
            row.style.opacity    = '0.4';
            row.style.background = '';
        }
    });

    document.getElementById('assignmentModeSelect')
        ?.addEventListener('change', syncDefaultAgentRow);

    document.getElementById('campaignSelect')
        ?.addEventListener('change', function() {
            // Re-filter the script dropdown on every campaign change.
            filterScriptsByCampaign();

            // Reload agents when assignment mode is direct.
            const row = document.getElementById('defaultAgentRow');
            if (!row || row.style.display === 'none') return;
            if (!this.value) {
                const sel = document.getElementById('defaultAgentSelect');
                if (sel) sel.innerHTML = '<option value="">Select agent...</option>';
                return;
            }
            loadQueueAgents();
        });

    syncDefaultAgentRow();

    // Apply script filter immediately so the dropdown matches the
    // currently-selected campaign on both create and edit.
    filterScriptsByCampaign();
});
