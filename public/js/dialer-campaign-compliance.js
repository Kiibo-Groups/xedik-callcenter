/* Dialer Campaign edit page — Compliance tab (Federal + State Calling Hours Guard) */

document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.DialerCampaignComplianceConfig || {};

    var enabledCb        = document.getElementById('complianceEnabled');
    var body             = document.getElementById('complianceBody');
    var policySelect     = document.getElementById('complianceUnknownPolicy');
    var policyHint       = document.getElementById('unknownPolicyHint');
    var ruleModeSelect   = document.getElementById('complianceRuleMode');
    var unknownStateGroup = document.getElementById('unknownStatePolicyGroup');

    var SKIP_HINT = 'If the area code cannot be matched to a known timezone, the lead will not be offered.';
    var ALLOW_HINT = 'If the area code cannot be matched to a known timezone, the lead may still be offered. Use only when your campaign has confirmed this is acceptable.';

    if (enabledCb && body) {
        enabledCb.addEventListener('change', function () {
            body.style.display = enabledCb.checked ? '' : 'none';
        });
    }

    if (policySelect && policyHint) {
        policySelect.addEventListener('change', function () {
            policyHint.textContent = policySelect.value === 'allow' ? ALLOW_HINT : SKIP_HINT;
        });
    }

    if (ruleModeSelect && unknownStateGroup) {
        ruleModeSelect.addEventListener('change', function () {
            unknownStateGroup.style.display = ruleModeSelect.value === 'federal_plus_state' ? '' : 'none';
        });
    }

    // ── Test box ─────────────────────────────────────────────────────────────
    var testBtn    = document.getElementById('complianceTestBtn');
    var testPhone  = document.getElementById('complianceTestPhone');
    var testResult = document.getElementById('complianceTestResult');

    if (testBtn && cfg.routes && cfg.routes.test) {
        testBtn.addEventListener('click', function () {
            var phone = testPhone ? testPhone.value.trim() : '';
            if (!phone) return;

            var unknownStateSelect = document.getElementById('complianceUnknownStatePolicy');

            var config = {
                enabled: enabledCb ? enabledCb.checked : false,
                start_time: (document.getElementById('complianceStart') || {}).value || '08:00',
                end_time: (document.getElementById('complianceEnd') || {}).value || '21:00',
                unknown_timezone_policy: policySelect ? policySelect.value : 'skip',
                rule_mode: ruleModeSelect ? ruleModeSelect.value : 'federal_baseline',
                state_strategy: 'area_code_state',
                unknown_state_policy: unknownStateSelect ? unknownStateSelect.value : 'federal_baseline',
            };

            testBtn.disabled = true;
            testBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing…';
            if (testResult) testResult.style.display = 'none';

            fetch(cfg.routes.test, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': cfg.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ phone: phone, config: config }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="bi bi-search"></i> Test';
                if (!testResult) return;

                var allowedLabel = data.allowed
                    ? '<span style="color:var(--success);font-weight:700;">Allowed</span>'
                    : '<span style="color:var(--danger);font-weight:700;">Blocked</span>';

                var rows = [
                    ['Result', allowedLabel],
                    ['Reason', data.reason || '—'],
                    ['Area code', data.area_code || '—'],
                    ['State', data.state_code || '—'],
                    ['Timezone', data.timezone || '—'],
                    ['Local time', data.local_time || '—'],
                    ['Federal window', (data.start_time || '—') + '–' + (data.end_time || '—')],
                ];

                if (data.rule_mode === 'federal_plus_state') {
                    rows.push(['State rule applied', data.state_rule_applied ? 'Yes' : 'No']);
                    rows.push(['Effective window', (data.effective_start_time || '—') + '–' + (data.effective_end_time || '—')]);
                    if (data.state_rule_source) {
                        rows.push(['State rule source', data.state_rule_source]);
                    }
                    if (data.local_day_of_week) {
                        rows.push(['Local day', data.local_day_of_week]);
                    }
                    if (data.state_rule_days && data.state_rule_days.length) {
                        rows.push(['Rule days', data.state_rule_days.join(', ')]);
                        rows.push(['Day matched', data.state_day_matched ? 'Yes' : 'No']);
                    }
                }

                testResult.innerHTML = rows.map(function (r) {
                    return '<div class="compliance-test-row"><span class="cell-muted">' + r[0] + '</span><span class="cell-strong">' + r[1] + '</span></div>';
                }).join('');
                testResult.style.display = '';
            })
            .catch(function () {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="bi bi-search"></i> Test';
                if (testResult) {
                    testResult.innerHTML = '<span style="color:var(--danger);">Connection error. Try again.</span>';
                    testResult.style.display = '';
                }
            });
        });
    }
});
