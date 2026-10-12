/* Campaign Dialer — Preview Filters tab */

(function () {
    'use strict';

    var cfg = window.CampaignFiltersConfig || {};

    var fields     = cfg.fields     || [];
    var isRunning  = Boolean(cfg.isRunning);

    var OPERATOR_LABELS = {
        equals:       'equals',
        not_equals:   'does not equal',
        contains:     'contains',
        not_contains: 'does not contain',
        starts_with:  'starts with',
        ends_with:    'ends with',
        in:           'is one of',
        empty:        'is empty',
        not_empty:    'is not empty',
        greater_than: 'greater than',
        less_than:    'less than',
    };

    var NO_VALUE_OPERATORS = ['empty', 'not_empty'];

    // ── Init ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        var enabledCb = document.getElementById('filtersEnabled');
        if (!enabledCb) return;  // not on filters tab page

        // Restore saved conditions
        var saved = cfg.currentConditions || [];
        saved.forEach(function (c) { addCondition(c.field, c.operator, c.value); });
        updatePreview();

        // Enable/disable body
        enabledCb.addEventListener('change', function () {
            var body = document.getElementById('filtersBody');
            if (body) body.style.display = this.checked ? '' : 'none';
            updateSummary();
        });

        // Match segmented control buttons
        document.querySelectorAll('#matchControl .filters-match-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#matchControl .filters-match-btn').forEach(function (b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');
                var matchEl = document.getElementById('filtersMatch');
                if (matchEl) matchEl.value = btn.dataset.match;
                updatePreview();
            });
        });

        // Status pills — update summary on change
        document.querySelectorAll('#statusChecks input[type="checkbox"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                updateSummary();
                updatePreview();
            });
        });

        // Default statuses button
        var defaultBtn = document.getElementById('defaultStatusesBtn');
        if (defaultBtn) {
            defaultBtn.addEventListener('click', function () {
                var defaults = cfg.defaultStatuses || ['NEW', 'NO_ANSWER', 'BUSY', 'FAILED', 'CONGESTION', 'REJECTED'];
                document.querySelectorAll('#statusChecks input[type="checkbox"]').forEach(function (cb) {
                    cb.checked = defaults.includes(cb.value);
                });
                updateSummary();
                updatePreview();
            });
        }

        // Add condition button
        var addBtn = document.getElementById('addConditionBtn');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                addCondition('', 'equals', '');
                updatePreview();
            });
        }

        // Serialize conditions before form submit
        var form = document.getElementById('filtersForm');
        if (form) {
            form.addEventListener('submit', function () {
                serializeConditionsToHidden();
            });
        }

        // Test Filters — bind all buttons with the class (sidebar + footer)
        document.querySelectorAll('.js-test-filters-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { runTestFilters(); });
        });

        updatePreview();
        updateSummary();
    });

    // ── Condition management ──────────────────────────────────────────────────

    function addCondition(field, operator, value) {
        var template = document.getElementById('conditionRowTemplate');
        if (!template) return;

        var clone = template.content.cloneNode(true);
        var row   = clone.firstElementChild;

        // Populate field select
        var fieldSel = row.querySelector('.cond-field');
        if (fieldSel) {
            populateFieldOptions(fieldSel, field || '');
            fieldSel.disabled = isRunning;
            fieldSel.addEventListener('change', updatePreview);
        }

        // Populate operator select
        var opSel = row.querySelector('.cond-operator');
        if (opSel) {
            if (operator) opSel.value = operator;
            opSel.disabled = isRunning;
            opSel.addEventListener('change', function () {
                toggleValueInput(row, opSel.value);
                updatePreview();
            });
            toggleValueInput(row, opSel.value);
        }

        // Value input
        var valInput = row.querySelector('.cond-value');
        if (valInput) {
            if (value !== null && value !== undefined) {
                valInput.value = Array.isArray(value) ? value.join(', ') : String(value);
            }
            valInput.disabled = isRunning;
            valInput.addEventListener('input', updatePreview);
        }

        // Remove button
        var removeBtn = row.querySelector('.remove-condition-btn');
        if (removeBtn) {
            if (isRunning) {
                removeBtn.style.display = 'none';
            } else {
                removeBtn.addEventListener('click', function () {
                    row.remove();
                    updatePreview();
                });
            }
        }

        var list = document.getElementById('conditionsList');
        if (list) list.appendChild(clone);

        // Show/hide empty hint
        refreshEmptyHint();
    }

    function populateFieldOptions(select, selectedField) {
        while (select.options.length > 1) select.remove(1);

        fields.forEach(function (f) {
            var opt = document.createElement('option');
            opt.value = f;
            opt.textContent = f;
            if (f === selectedField) opt.selected = true;
            select.appendChild(opt);
        });
    }

    function toggleValueInput(row, operator) {
        var wrap = row.querySelector('.cond-value-wrap');
        if (!wrap) return;
        var noVal = NO_VALUE_OPERATORS.includes(operator);
        wrap.style.display = noVal ? 'none' : '';
        var input = wrap.querySelector('.cond-value');
        if (input) {
            input.disabled = noVal || isRunning;
            input.placeholder = operator === 'in' ? 'AZ, NY, CA' : 'Value';
        }
    }

    function refreshEmptyHint() {
        var hint  = document.getElementById('conditionsEmptyHint');
        var list  = document.getElementById('conditionsList');
        if (!hint || !list) return;
        var count = list.querySelectorAll('.condition-row').length;
        hint.style.display = count === 0 ? '' : 'none';
    }

    // ── Serialization ─────────────────────────────────────────────────────────

    function collectConditions() {
        var result = [];
        document.querySelectorAll('#conditionsList .condition-row').forEach(function (row) {
            var field    = (row.querySelector('.cond-field')    || {}).value || '';
            var operator = (row.querySelector('.cond-operator') || {}).value || '';
            var rawVal   = (row.querySelector('.cond-value')    || {}).value || '';

            if (!field || !operator) return;

            var value;
            if (NO_VALUE_OPERATORS.includes(operator)) {
                value = null;
            } else if (operator === 'in') {
                value = rawVal.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            } else {
                value = rawVal;
            }

            result.push({ field: field, operator: operator, value: value });
        });
        return result;
    }

    function serializeConditionsToHidden() {
        var hidden = document.getElementById('conditionsJson');
        if (hidden) hidden.value = JSON.stringify(collectConditions());
    }

    // ── Rule prefix (AND / OR between rows) ───────────────────────────────────

    function updateRulePrefixes() {
        var matchEl = document.getElementById('filtersMatch');
        var joiner  = (matchEl && matchEl.value === 'any') ? 'OR' : 'AND';

        document.querySelectorAll('#conditionsList .condition-row').forEach(function (row, idx) {
            var prefix = row.querySelector('.filters-rule-prefix');
            if (!prefix) return;
            if (idx === 0) {
                prefix.style.display = 'none';
            } else {
                prefix.textContent    = joiner;
                prefix.style.display  = 'block';
            }
        });
    }

    // ── Preview text ──────────────────────────────────────────────────────────

    function updatePreview() {
        refreshEmptyHint();
        updateRulePrefixes();

        var preview = document.getElementById('filterPreview');
        var text    = document.getElementById('filterPreviewText');
        if (!preview || !text) {
            updateSummary();
            return;
        }

        var conds = collectConditions();

        if (conds.length === 0) {
            preview.style.display = 'none';
            updateSummary();
            return;
        }

        var matchEl = document.getElementById('filtersMatch');
        var match   = matchEl ? matchEl.value : 'all';
        var joiner  = match === 'any' ? ' OR ' : ' AND ';

        // Build human-readable lines
        var lines = [];
        var statusChecks = document.querySelectorAll('#statusChecks input[type="checkbox"]:checked');
        var statuses = [];
        statusChecks.forEach(function (cb) { statuses.push(cb.value); });

        if (statuses.length > 0) {
            lines.push('Status in ' + statuses.join(', '));
        }

        conds.forEach(function (c, idx) {
            var opLabel = OPERATOR_LABELS[c.operator] || c.operator;
            var prefix  = '';
            if (statuses.length > 0 || idx > 0) {
                prefix = (idx === 0 ? 'WHERE ' : joiner.trim() + ' ');
            }
            if (NO_VALUE_OPERATORS.includes(c.operator)) {
                lines.push(prefix + c.field + ' ' + opLabel);
            } else {
                var val = Array.isArray(c.value) ? c.value.join(', ') : (c.value || '');
                if (c.operator === 'in') {
                    lines.push(prefix + c.field + ' ' + opLabel + ' ' + val);
                } else {
                    lines.push(prefix + c.field + ' ' + opLabel + ' "' + val + '"');
                }
            }
        });

        text.textContent = lines.join('\n');
        preview.style.display = '';

        updateSummary();
    }

    // ── Summary sidebar ───────────────────────────────────────────────────────

    function updateSummary() {
        var enabledCb = document.getElementById('filtersEnabled');
        var matchEl   = document.getElementById('filtersMatch');

        var summEnabled  = document.getElementById('summaryEnabled');
        var summStatuses = document.getElementById('summaryStatuses');
        var summRules    = document.getElementById('summaryRules');
        var summMatch    = document.getElementById('summaryMatch');

        if (summEnabled) {
            summEnabled.textContent = (enabledCb && enabledCb.checked) ? 'Enabled' : 'Disabled';
        }

        if (summStatuses) {
            var count = document.querySelectorAll('#statusChecks input[type="checkbox"]:checked').length;
            summStatuses.textContent = count + ' selected';
        }

        if (summRules) {
            var ruleCount = document.querySelectorAll('#conditionsList .condition-row').length;
            summRules.textContent = ruleCount + ' rule' + (ruleCount !== 1 ? 's' : '');
        }

        if (summMatch && matchEl) {
            summMatch.textContent = matchEl.value.toUpperCase();
        }
    }

    // ── Test Filters AJAX ─────────────────────────────────────────────────────

    function runTestFilters() {
        var resultEl = document.getElementById('testFiltersResult');
        if (!document.querySelectorAll('.js-test-filters-btn').length) return;

        var enabledCb = document.getElementById('filtersEnabled');
        var matchEl   = document.getElementById('filtersMatch');

        var statusChecks = document.querySelectorAll('#statusChecks input[type="checkbox"]');
        var statuses = [];
        statusChecks.forEach(function (cb) { if (cb.checked) statuses.push(cb.value); });

        var config = {
            enabled:    enabledCb ? enabledCb.checked : false,
            statuses:   statuses,
            match:      matchEl ? matchEl.value : 'all',
            conditions: collectConditions(),
        };

        // Disable all test buttons
        document.querySelectorAll('.js-test-filters-btn').forEach(function (b) {
            b.disabled = true;
            b.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing…';
        });

        if (resultEl) resultEl.style.display = 'none';

        // Reset metrics to loading state
        setMetric('metricTotal',    '…');
        setMetric('metricMatching', '…');
        setMetric('metricCalled',   '…');
        setMetric('metricEligible', '…');

        fetch(cfg.routes.test, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'Accept':           'application/json',
                'X-CSRF-TOKEN':     cfg.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ config: config }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.querySelectorAll('.js-test-filters-btn').forEach(function (b) {
                b.disabled = false;
                b.innerHTML = '<i class="bi bi-search"></i> Test Filters';
            });

            if (data.ok) {
                // total_leads          = all rows in the lead list
                // eligible_now         = rows passing Preview core eligibility before filters
                // status_field_matches = rows matching selected statuses + field conditions (raw table)
                // matching_filters     = "will dial" = eligible_now intersected with full filter config
                var total       = data.total_leads          || 0;
                var eligible    = data.eligible_now          || 0;
                var filterMatch = data.status_field_matches  || 0;
                var willDial    = data.matching_filters       || 0;
                setMetric('metricTotal',    total);
                setMetric('metricCalled',   eligible);
                setMetric('metricMatching', filterMatch);
                setMetric('metricEligible', willDial);

            } else {
                setMetric('metricTotal',    '—');
                setMetric('metricMatching', '—');
                setMetric('metricCalled',   '—');
                setMetric('metricEligible', '—');

                if (resultEl) {
                    resultEl.innerHTML =
                        '<div class="tip-box" style="margin:0;border-color:var(--danger);">' +
                        '<i class="bi bi-exclamation-triangle tip-icon" style="color:var(--danger);"></i>' +
                        (data.error || 'Unknown error.') + '</div>';
                    resultEl.style.display = '';
                }
            }
        })
        .catch(function () {
            document.querySelectorAll('.js-test-filters-btn').forEach(function (b) {
                b.disabled = false;
                b.innerHTML = '<i class="bi bi-search"></i> Test Filters';
            });

            setMetric('metricTotal',    '—');
            setMetric('metricMatching', '—');
            setMetric('metricCalled',   '—');
            setMetric('metricEligible', '—');

            if (resultEl) {
                resultEl.innerHTML =
                    '<div class="tip-box" style="margin:0;border-color:var(--danger);">' +
                    '<i class="bi bi-exclamation-triangle tip-icon" style="color:var(--danger);"></i>' +
                    'Connection error. Try again.</div>';
                resultEl.style.display = '';
            }
        });
    }

    function setMetric(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

}());
