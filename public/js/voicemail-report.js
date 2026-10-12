var cfg = window.VoicemailReportConfig || {};

// ── Recording player ──────────────────────────────────────────────────────────
var currentAudio = null;

function togglePlayer(uid) {
    var player = document.getElementById('player-' + uid);
    var audio  = document.getElementById('audio-' + uid);
    var icon   = document.getElementById('icon-' + uid);

    if (!player || !audio || !icon) return;

    if (currentAudio && currentAudio !== audio) {
        currentAudio.pause();
        var prevId     = currentAudio.id.replace('audio-', '');
        var prevIcon   = document.getElementById('icon-' + prevId);
        var prevPlayer = document.getElementById('player-' + prevId);
        if (prevIcon)   prevIcon.className      = 'bi bi-play-circle';
        if (prevPlayer) prevPlayer.style.display = 'none';
    }

    if (player.style.display === 'none') {
        if (!audio.src) {
            audio.src = '/dialer/recording-by-uid/' + uid;
        }
        player.style.display = 'block';
        icon.className = 'bi bi-pause-circle';
        audio.play().catch(function () {});
        currentAudio = audio;
    } else {
        player.style.display = 'none';
        icon.className = 'bi bi-play-circle';
        audio.pause();
        currentAudio = null;
    }
}

// ── Agent dropdown (admin only) carga por campaña ────────────────────────────
if (cfg.isAdmin) {
    var campaignSel = document.getElementById('campaign');
    var agentSel    = document.getElementById('agent');

    if (campaignSel && agentSel) {
        campaignSel.addEventListener('change', function () {
            var campaignId = this.value;
            var url        = cfg.agentsUrl + (campaignId ? '?campaign_id=' + encodeURIComponent(campaignId) : '');

            agentSel.innerHTML = '<option value="">All Agents</option>';

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (agents) {
                    agents.forEach(function (a) {
                        var opt         = document.createElement('option');
                        opt.value       = a.phone_user_id;
                        opt.textContent = a.name;
                        if (a.phone_user_id === cfg.selectedAgent) {
                            opt.selected = true;
                        }
                        agentSel.appendChild(opt);
                    });
                })
                .catch(function () {});
        });
    }
}
