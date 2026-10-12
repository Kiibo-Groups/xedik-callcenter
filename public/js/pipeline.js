/**
 * Pipeline Kanban — drag & drop entre columnas de status, panel lateral de detalle.
 * window.PipelineConfig es inyectado por la vista pipeline/index.blade.php.
 */
(function () {
    var cfg = window.PipelineConfig || {};

    // ── Shared helper — construye URL preservando scope + campaign + tags ──
    function buildPipelineUrl(overrides) {
        var params = new URLSearchParams();
        var scope      = overrides.scope      !== undefined ? overrides.scope      : cfg.scope;
        var campaignId = overrides.campaignId !== undefined ? overrides.campaignId : cfg.campaignId;
        var tagIds     = overrides.tagIds     !== undefined ? overrides.tagIds     : (cfg.tagIds || []);

        if (scope === 'mine') params.set('scope', 'mine');
        if (campaignId) params.set('campaign_id', campaignId);
        tagIds.forEach(function (id) { params.append('tag_ids[]', id); });

        var qs = params.toString();
        return cfg.indexUrl + (qs ? '?' + qs : '');
    }

    // ── Campaign filter — recarga con scope + campaign_id (preserva tags) ──
    var campaignFilter = document.getElementById('pipelineCampaignFilter');
    if (campaignFilter) {
        campaignFilter.addEventListener('change', function () {
            window.location.href = buildPipelineUrl({ campaignId: this.value || null });
        });
    }

    // ── Tag filter dropdown ──
    var tagFilterBtn    = document.getElementById('pipelineTagFilterBtn');
    var tagFilterDD     = document.getElementById('pipelineTagFilterDropdown');
    var tagFilterApply  = document.getElementById('pipelineTagFilterApply');
    var tagFilterClear  = document.getElementById('pipelineTagFilterClear');

    if (tagFilterBtn && tagFilterDD) {
        tagFilterBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = tagFilterDD.style.display !== 'none';
            tagFilterDD.style.display = open ? 'none' : 'block';
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('#pipelineTagFilterWrap')) {
                tagFilterDD.style.display = 'none';
            }
        });
    }

    if (tagFilterApply) {
        tagFilterApply.addEventListener('click', function () {
            var checked = Array.from(document.querySelectorAll('.pipeline-tag-checkbox:checked'))
                .map(function (cb) { return parseInt(cb.value, 10); });
            window.location.href = buildPipelineUrl({ tagIds: checked });
        });
    }

    if (tagFilterClear) {
        tagFilterClear.addEventListener('click', function () {
            document.querySelectorAll('.pipeline-tag-checkbox').forEach(function (cb) {
                cb.checked = false;
            });
            window.location.href = buildPipelineUrl({ tagIds: [] });
        });
    }

    // ── Helpers ──
    function formatMoney(value) {
        return '$' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function recalcColumn(columnBody) {
        var column = columnBody.closest('.pipeline-column');
        var cards  = columnBody.querySelectorAll('.pipeline-card');
        var total  = 0;
        cards.forEach(function (card) {
            total += parseFloat(card.dataset.value || '0');
        });
        column.querySelector('[data-role="count"]').textContent = cards.length;
        column.querySelector('[data-role="value"]').textContent = formatMoney(total);
    }

    // ── "Delete Status" — habilitar/deshabilitar en vivo según leads_count ──
    // El conteo inicial viene del servidor (data-leads-count), pero el drag&drop
    // de leads entre columnas lo deja obsoleto sin un reload. Cada movimiento
    // exitoso ajusta el contador de origen/destino y refresca el botón.
    function updateDeleteStatusButton(column) {
        var header = column.querySelector('.pipeline-column-header');
        var btn    = column.querySelector('.pipeline-column-menu-item-danger');
        if (!header || !btn) return;

        var count = parseInt(header.dataset.leadsCount || '0', 10);
        if (count > 0) {
            btn.disabled = true;
            btn.title = 'Cannot delete — has leads';
            btn.removeAttribute('data-action');
        } else {
            btn.disabled = false;
            btn.title = '';
            btn.setAttribute('data-action', 'delete-status');
        }
    }

    function adjustLeadsCount(column, delta) {
        var header = column && column.querySelector('.pipeline-column-header');
        if (!header) return;

        var count = parseInt(header.dataset.leadsCount || '0', 10) + delta;
        header.dataset.leadsCount = Math.max(count, 0);
        updateDeleteStatusButton(column);
    }

    function recalcSummary() {
        var allCards   = document.querySelectorAll('.pipeline-card');
        var totalLeads = allCards.length;
        var totalValue = 0;
        var openValue  = 0;
        var wonValue   = 0;
        var wonCount    = 0;
        var countsBySlug = {};

        allCards.forEach(function (card) {
            var value  = parseFloat(card.dataset.value || '0');
            var status = card.dataset.statusSlug;
            totalValue += value;
            countsBySlug[status] = (countsBySlug[status] || 0) + 1;

            if (cfg.wonSlugs.indexOf(status) !== -1) {
                wonValue += value;
                wonCount += 1;
            } else if (cfg.lostSlugs.indexOf(status) === -1) {
                openValue += value;
            }
        });

        var conversion = totalLeads > 0 ? Math.round((wonCount / totalLeads) * 100) : 0;
        var avgTicket  = totalLeads > 0 ? totalValue / totalLeads : 0;

        var summary = document.getElementById('pipelineSummary');
        if (!summary) return;
        summary.querySelector('[data-role="summary-total-leads"]').textContent = totalLeads;
        summary.querySelector('[data-role="summary-open-value"]').textContent = formatMoney(openValue);
        summary.querySelector('[data-role="summary-won"]').textContent = wonCount + ' · ' + formatMoney(wonValue);
        summary.querySelector('[data-role="summary-conversion"]').textContent = conversion + '%';
        summary.querySelector('[data-role="summary-avg-ticket"]').textContent = formatMoney(avgTicket);

        updateDonut(countsBySlug, totalLeads);
    }

    // ── Donut chart — distribution by stage ──
    // Tooltip externo (div HTML, position:fixed, appended a <body>) — evita
    // que el tooltip se encime con el número central Y que se corte contra
    // el overflow:hidden de la card del donut o de la leyenda.
    function donutTooltipHandler(context) {
        var chart = context.chart;
        var tooltip = context.tooltip;
        var tooltipEl = document.body.querySelector('.pipeline-donut-tooltip');

        if (!tooltipEl) {
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'pipeline-donut-tooltip';
            document.body.appendChild(tooltipEl);
        }

        if (tooltip.opacity === 0) {
            tooltipEl.style.opacity = 0;
            return;
        }

        if (tooltip.dataPoints && tooltip.dataPoints.length) {
            var point = tooltip.dataPoints[0];
            var value = point.parsed;
            var total = point.dataset.data.reduce(function (a, b) { return a + b; }, 0);
            var pct = total > 0 ? Math.round((value / total) * 100) : 0;
            tooltipEl.textContent = point.label + ': ' + value + ' (' + pct + '%)';
        }

        var canvasRect = chart.canvas.getBoundingClientRect();
        var anchorX = canvasRect.left + tooltip.caretX;
        var anchorY = canvasRect.top + tooltip.caretY;

        tooltipEl.style.opacity = 1;

        var margin = 8;
        var width  = tooltipEl.offsetWidth;
        var height = tooltipEl.offsetHeight;

        var left = anchorX - width / 2;
        if (left < margin) left = margin;
        if (left + width > window.innerWidth - margin) left = window.innerWidth - margin - width;

        var top = anchorY - height - 10;
        if (top < margin) top = anchorY + 10;

        tooltipEl.style.left = left + 'px';
        tooltipEl.style.top  = top + 'px';
    }

    var donutChart = null;
    var donutCanvas = document.getElementById('pipelineDonutChart');
    if (donutCanvas && window.Chart && cfg.stageDistribution) {
        donutChart = new Chart(donutCanvas, {
            type: 'doughnut',
            data: {
                labels: cfg.stageDistribution.map(function (s) { return s.label; }),
                datasets: [{
                    data: cfg.stageDistribution.map(function (s) { return s.count; }),
                    backgroundColor: cfg.stageDistribution.map(function (s) { return s.color; }),
                    borderWidth: 0,
                }],
            },
            options: {
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false, external: donutTooltipHandler },
                },
                animation: { duration: 300 },
            },
        });
    }

    function updateDonut(countsBySlug, totalLeads) {
        if (!donutChart) return;

        cfg.stageDistribution.forEach(function (s, i) {
            donutChart.data.datasets[0].data[i] = countsBySlug[s.slug] || 0;
        });
        donutChart.update();

        var totalEl = document.querySelector('[data-role="donut-total"]');
        if (totalEl) totalEl.textContent = totalLeads;

        document.querySelectorAll('.pipeline-donut-legend-item').forEach(function (item) {
            var slug = item.dataset.status;
            var countEl = item.querySelector('[data-role="donut-count"]');
            if (countEl) countEl.textContent = countsBySlug[slug] || 0;
        });
    }

    // ── Donut — agregar/quitar segmento (New Status / Delete Status, sin reload) ──
    // `index` posiciona el nuevo segmento en el mismo lugar donde quedó la
    // columna en el board (dataset/leyenda), en vez de agregarlo al final.
    function addDonutSegment(slug, label, count, color, index) {
        if (typeof index !== 'number' || index < 0 || index > cfg.stageDistribution.length) {
            index = cfg.stageDistribution.length;
        }

        cfg.stageDistribution.splice(index, 0, { slug: slug, label: label, count: count, color: color });

        if (donutChart) {
            donutChart.data.labels.splice(index, 0, label);
            donutChart.data.datasets[0].data.splice(index, 0, count);
            donutChart.data.datasets[0].backgroundColor.splice(index, 0, color);
            donutChart.update();
        }

        var legend = document.querySelector('.pipeline-donut-legend');
        if (legend) {
            var item = document.createElement('div');
            item.className = 'pipeline-donut-legend-item';
            item.setAttribute('data-status', slug);
            item.innerHTML =
                '<span class="pipeline-donut-legend-dot" style="background:' + color + '"></span>' +
                '<span class="pipeline-donut-legend-label"></span>' +
                '<span class="pipeline-donut-legend-count" data-role="donut-count">' + count + '</span>';
            item.querySelector('.pipeline-donut-legend-label').textContent = label;

            var legendItems = legend.querySelectorAll('.pipeline-donut-legend-item');
            if (index < legendItems.length) {
                legend.insertBefore(item, legendItems[index]);
            } else {
                legend.appendChild(item);
            }
        }
    }

    function removeDonutSegment(slug) {
        var idx = cfg.stageDistribution.findIndex(function (s) { return s.slug === slug; });
        if (idx === -1) return;
        cfg.stageDistribution.splice(idx, 1);

        if (donutChart) {
            donutChart.data.labels.splice(idx, 1);
            donutChart.data.datasets[0].data.splice(idx, 1);
            donutChart.data.datasets[0].backgroundColor.splice(idx, 1);
            donutChart.update();
        }

        var item = document.querySelector('.pipeline-donut-legend-item[data-status="' + slug + '"]');
        if (item) item.remove();
    }

    // ── Donut — reordenar dataset/leyenda tras un reorder de columnas ──
    // Mapea por slug (no por índice viejo) para no desincronizar conteos.
    function reorderDonutSegments(slugOrder) {
        if (!donutChart || !cfg.stageDistribution) return;

        var oldSlugOrder = cfg.stageDistribution.map(function (s) { return s.slug; });
        if (slugOrder.length !== oldSlugOrder.length) return;

        var newStageDistribution = [];
        var newLabels = [];
        var newData = [];
        var newBg = [];

        slugOrder.forEach(function (slug) {
            var oldIndex = oldSlugOrder.indexOf(slug);
            if (oldIndex === -1) return;

            var entry = cfg.stageDistribution[oldIndex];
            var count = donutChart.data.datasets[0].data[oldIndex];

            newStageDistribution.push({ slug: entry.slug, label: entry.label, count: count, color: entry.color });
            newLabels.push(donutChart.data.labels[oldIndex]);
            newData.push(count);
            newBg.push(donutChart.data.datasets[0].backgroundColor[oldIndex]);
        });

        if (newStageDistribution.length !== oldSlugOrder.length) return;

        cfg.stageDistribution = newStageDistribution;
        donutChart.data.labels = newLabels;
        donutChart.data.datasets[0].data = newData;
        donutChart.data.datasets[0].backgroundColor = newBg;
        donutChart.update();

        var legend = document.querySelector('.pipeline-donut-legend');
        if (legend) {
            slugOrder.forEach(function (slug) {
                var item = legend.querySelector('.pipeline-donut-legend-item[data-status="' + slug + '"]');
                if (item) legend.appendChild(item);
            });
        }
    }

    // ── Confeti al ganar un lead ──
    var lastConfettiAt = 0;
    function fireWonConfetti() {
        var now = Date.now();
        if (now - lastConfettiAt < 1500) return;
        lastConfettiAt = now;

        var chaChing = new Audio('/sounds/cha-ching.mp3');
        chaChing.volume = 0.5;
        chaChing.play().catch(function () {});

        if (!window.confetti) return;

        var duration = 2500;
        var end = Date.now() + duration;

        // Pop inicial fuerte en el centro
        window.confetti({
            particleCount: 150,
            spread: 100,
            startVelocity: 45,
            origin: { y: 0.6 },
        });

        // Los TRES frentes disparan sostenido durante toda la animación
        (function frame() {
            // cañón izquierdo
            window.confetti({
                particleCount: 6,
                angle: 60,
                spread: 65,
                startVelocity: 55,
                origin: { x: 0, y: 0.7 },
            });
            // cañón derecho
            window.confetti({
                particleCount: 6,
                angle: 120,
                spread: 65,
                startVelocity: 55,
                origin: { x: 1, y: 0.7 },
            });
            // centro sostenido
            window.confetti({
                particleCount: 8,
                spread: 90,
                startVelocity: 40,
                origin: { x: 0.5, y: 0.6 },
            });
            if (Date.now() < end) requestAnimationFrame(frame);
        })();
    }

    // ── SortableJS — drag & drop entre columnas ──
    var board = document.getElementById('pipelineBoard');

    function initLeadColumnSortable(columnBody) {
        new Sortable(columnBody, {
            group: 'pipeline',
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            onStart: function () {
                if (board) board.classList.add('no-snap');
            },
            onEnd: function (evt) {
                if (board) board.classList.remove('no-snap');

                var card = evt.item;
                var fromColumn = evt.from;
                var toColumn   = evt.to;

                if (fromColumn === toColumn && evt.oldIndex === evt.newIndex) {
                    return;
                }

                var leadId    = card.dataset.leadId;
                var newStatus = toColumn.dataset.status;
                var oldStatus = card.dataset.statusSlug;

                if (newStatus === oldStatus) {
                    recalcColumn(fromColumn);
                    recalcColumn(toColumn);
                    recalcSummary();
                    return;
                }

                var url = cfg.updateUrlTemplate.replace('LEAD_ID', leadId);

                $.ajax({
                    url: url,
                    method: 'PATCH',
                    data: { status: newStatus },
                    success: function () {
                        card.dataset.statusSlug = newStatus;
                        var stageInfo = (cfg.stages || {})[newStatus];
                        if (stageInfo) {
                            card.dataset.statusLabel = stageInfo.name;
                            card.dataset.statusStyle = stageInfo.badge_style;
                            card.style.borderLeftColor = stageInfo.bg_color;
                        }
                        recalcColumn(fromColumn);
                        recalcColumn(toColumn);
                        recalcSummary();

                        adjustLeadsCount(fromColumn.closest('.pipeline-column'), -1);
                        adjustLeadsCount(toColumn.closest('.pipeline-column'), 1);

                        if (cfg.wonSlugs.indexOf(newStatus) !== -1) {
                            fireWonConfetti();
                        }
                    },
                    error: function () {
                        // Revertir: regresar la card a su columna/posición original
                        var reference = fromColumn.children[evt.oldIndex] || null;
                        if (reference) {
                            fromColumn.insertBefore(card, reference);
                        } else {
                            fromColumn.appendChild(card);
                        }
                        recalcColumn(fromColumn);
                        recalcColumn(toColumn);
                        showToast('Could not update lead status. Please try again.', 'error');
                    },
                });
            },
        });
    }

    document.querySelectorAll('.pipeline-column-body').forEach(initLeadColumnSortable);

    // ── SortableJS — reordenar COLUMNAS (solo manage_lead_statuses) ──
    // Instancia INDEPENDIENTE de la de leads (group distinto, handle propio en
    // el header) para que ambos drags no se mezclen. Cambia el 'order' de los
    // LeadStatus visibles en Pipeline para TODOS los usuarios del tenant.
    if (cfg.canManageStatuses && board) {
        new Sortable(board, {
            handle: '.pipeline-column-drag-handle',
            group: 'pipeline-columns',
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            onStart: function () {
                board.classList.add('no-snap');
            },
            onEnd: function (evt) {
                board.classList.remove('no-snap');

                if (evt.oldIndex === evt.newIndex) return;

                var order = Array.from(board.querySelectorAll('.pipeline-column-header'))
                    .map(function (header) { return header.dataset.statusId; });

                var slugOrder = Array.from(board.querySelectorAll('.pipeline-column-body'))
                    .map(function (body) { return body.dataset.status; });

                $.ajax({
                    url: cfg.reorderStatusUrl,
                    method: 'PATCH',
                    data: { order: order },
                    success: function () {
                        reorderDonutSegments(slugOrder);
                    },
                    error: function () {
                        // Revertir: regresar la columna a su posición original
                        var item = evt.item;
                        board.removeChild(item);
                        var reference = board.children[evt.oldIndex] || null;
                        if (reference) {
                            board.insertBefore(item, reference);
                        } else {
                            board.appendChild(item);
                        }
                        showToast('Could not reorder columns. Please try again.', 'error');
                    },
                });
            },
        });
    }

    // ── Board navigation — flechas laterales + scroll-snap ──
    var boardWrap  = document.getElementById('pipelineBoardWrap');
    var arrowLeft  = document.getElementById('pipelineArrowLeft');
    var arrowRight = document.getElementById('pipelineArrowRight');

    if (board && boardWrap && arrowLeft && arrowRight) {
        function scrollToPosition(left) {
            var maxScroll = board.scrollWidth - board.clientWidth;
            var target = Math.max(0, Math.min(left, maxScroll));

            // Deshabilitar snap DURANTE la animación: con scroll-snap mandatory el
            // navegador puede corregir el punto final y quedar 1-3 px fuera del
            // offsetLeft real de la columna. Al desactivarlo, el smooth scroll aterra
            // exactamente en `target` (= offsetLeft de la columna destino).
            // Cuando la animación termina (`scrollend`) se reactiva sin corrección
            // porque ya estamos en un snap-point real.
            board.classList.add('no-snap');
            board.scrollTo({ left: target, behavior: 'smooth' });
            board.addEventListener('scrollend', function handler() {
                board.removeEventListener('scrollend', handler);
                board.classList.remove('no-snap');
            }, { once: true });
        }

        function updateArrows() {
            var atStart = board.scrollLeft <= 0;
            var atEnd = board.scrollLeft + board.clientWidth >= board.scrollWidth - 5;
            arrowLeft.classList.toggle('is-edge', atStart);
            arrowRight.classList.toggle('is-edge', atEnd);
        }

        // Usa offsetLeft real de cada columna como target de scroll — evita
        // acumular error de redondeo sub-pixel que ocurre con stride hardcodeado
        // (289+14=303 CSS px puede diferir del stride real en displays con DPI
        // fraccionario, produciendo 1-3 px de desfase tras uno o varios clics).
        function getColumns() {
            return Array.from(board.querySelectorAll('.pipeline-column'));
        }

        // Índice de la columna más cercana al borde izquierdo visible del board.
        // Usa getBoundingClientRect para posiciones sub-pixel reales.
        function currentColIndex(cols) {
            var boardLeft = board.getBoundingClientRect().left;
            var best = 0;
            var bestDist = Infinity;
            cols.forEach(function (col, i) {
                var dist = Math.abs(col.getBoundingClientRect().left - boardLeft);
                if (dist < bestDist) { bestDist = dist; best = i; }
            });
            return best;
        }

        // Cuántas columnas caben completas en el viewport (≥1 siempre).
        function visibleColCount(cols) {
            if (!cols.length) return 1;
            var colW = cols[0].getBoundingClientRect().width;
            return Math.max(1, Math.floor(board.clientWidth / colW) - 1);
        }

        // Posición de scroll exacta para dejar `col` en el borde izquierdo del board.
        // getBoundingClientRect devuelve floats reales — sin redondeo de offsetLeft.
        function colScrollTarget(col) {
            var colLeft   = col.getBoundingClientRect().left;
            var boardLeft = board.getBoundingClientRect().left;
            return board.scrollLeft + (colLeft - boardLeft);
        }

        arrowLeft.addEventListener('click', function () {
            var cols   = getColumns();
            var target = Math.max(0, currentColIndex(cols) - visibleColCount(cols));
            scrollToPosition(cols[target] ? colScrollTarget(cols[target]) : 0);
        });
        arrowRight.addEventListener('click', function () {
            var cols        = getColumns();
            var targetIdx   = Math.min(cols.length - 1, currentColIndex(cols) + visibleColCount(cols));
            var targetCol   = cols[targetIdx];
            scrollToPosition(targetCol ? colScrollTarget(targetCol) : board.scrollWidth);
        });

        // Con scroll-snap-align:start el maxScroll natural puede no coincidir con
        // ningún offsetLeft de columna (diferencia de 1-N px), dejando la primera
        // columna visible unos píxeles a la derecha del borde izquierdo del board
        // en la última "página". Este padding-right cierra ese gap exacto para que
        // el primer column de la última página quede flush al borde.
        function fixBoardScrollRange() {
            board.style.paddingRight = '';          // resetear antes de medir
            var cols = getColumns();
            if (cols.length < 2) return;
            var maxScroll = board.scrollWidth - board.clientWidth;
            if (maxScroll <= 0) return;             // sin scroll → no hace falta
            var colW         = cols[0].getBoundingClientRect().width;
            var visCount     = Math.max(1, Math.floor(board.clientWidth / colW));
            var lastStartCol = cols[Math.max(0, cols.length - visCount)];
            if (!lastStartCol) return;
            var needed = lastStartCol.offsetLeft - maxScroll;
            if (needed > 0) board.style.paddingRight = needed + 'px';
        }

        // Throttle a 1 frame — evita recalcular en cada pixel de scroll/resize.
        var arrowsRaf = null;
        function scheduleUpdateArrows() {
            if (arrowsRaf) return;
            arrowsRaf = requestAnimationFrame(function () {
                arrowsRaf = null;
                updateArrows();
            });
        }

        board.addEventListener('scroll', scheduleUpdateArrows);

        if (window.ResizeObserver) {
            new ResizeObserver(function () {
                fixBoardScrollRange();
                scheduleUpdateArrows();
            }).observe(board);
        } else {
            window.addEventListener('resize', function () {
                fixBoardScrollRange();
                scheduleUpdateArrows();
            });
        }

        fixBoardScrollRange();
        updateArrows();
    }

    // ── Side panel ──
    var panel        = document.getElementById('pipelinePanel');
    var panelOverlay = document.getElementById('pipelinePanelOverlay');
    var activeCard   = null;

    // Callback del LeadPanel: actualiza la card y el board cuando cambia un campo
    function onPanelLeadUpdated(leadId, field, value, data) {
        var card = document.querySelector('.pipeline-card[data-lead-id="' + leadId + '"]');
        if (!card) return;

        if (field === 'status') {
            var newStatus = value;
            var oldStatus = card.dataset.statusSlug;
            if (newStatus === oldStatus) return;

            card.dataset.statusSlug = newStatus;
            var stageInfo = (cfg.stages || {})[newStatus];
            if (stageInfo) {
                card.dataset.statusLabel = stageInfo.name;
                card.dataset.statusStyle = stageInfo.badge_style;
                card.style.borderLeftColor = stageInfo.bg_color;
            }

            var fromColumn = card.closest('.pipeline-column-body');
            var toColumn   = document.querySelector('.pipeline-column-body[data-status="' + newStatus + '"]');

            if (toColumn && fromColumn !== toColumn) {
                toColumn.prepend(card);
                recalcColumn(fromColumn);
                recalcColumn(toColumn);
                adjustLeadsCount(fromColumn.closest('.pipeline-column'), -1);
                adjustLeadsCount(toColumn.closest('.pipeline-column'), 1);
            } else if (!toColumn && fromColumn) {
                // Status no visible en Pipeline — sacar la card y cerrar panel
                fromColumn.removeChild(card);
                recalcColumn(fromColumn);
                adjustLeadsCount(fromColumn.closest('.pipeline-column'), -1);
                closePanel();
            }
            recalcSummary();

            if (cfg.wonSlugs.indexOf(newStatus) !== -1) fireWonConfetti();

        } else if (field === 'first_name' || field === 'last_name') {
            var nameEl   = card.querySelector('.pipeline-card-name');
            var avatarEl = card.querySelector('.pipeline-card-avatar');
            var panelName   = document.getElementById('lead-fullname-' + leadId);
            var panelAvatar = document.getElementById('lead-avatar-' + leadId);
            if (nameEl   && panelName)   nameEl.textContent   = panelName.textContent;
            if (avatarEl && panelAvatar) avatarEl.textContent = panelAvatar.textContent;

        } else if (field === 'campaign_id') {
            card.dataset.campaign = (data && data.name) ? data.name : '';

        } else if (field === 'tags') {
            var tags = (data && data.tags) ? data.tags : [];
            var tagsContainer = card.querySelector('.pipeline-card-tags');
            if (tags.length === 0) {
                if (tagsContainer) tagsContainer.remove();
                return;
            }
            if (!tagsContainer) {
                tagsContainer = document.createElement('div');
                tagsContainer.className = 'pipeline-card-tags';
                card.appendChild(tagsContainer);
            }
            var visible  = tags.slice(0, 3);
            var overflow = tags.length - 3;
            tagsContainer.innerHTML = visible.map(function (t) {
                return '<span class="pipeline-tag-pill" style="background:' + t.bg_color + ';color:' + t.color + '">' + escapeHtml(t.name) + '</span>';
            }).join('') + (overflow > 0 ? '<span class="pipeline-tag-more">+' + overflow + '</span>' : '');
        }
    }

    function openPanel(card) {
        if (activeCard) activeCard.classList.remove('pipeline-card-active');
        activeCard = card;
        activeCard.classList.add('pipeline-card-active');

        panel.classList.add('active');
        panelOverlay.classList.add('active');

        var leadId = card.dataset.leadId;
        var body   = document.getElementById('pipelinePanelBody');
        if (body) {
            body.innerHTML = '<div style="padding:32px 20px;text-align:center;color:var(--text-muted);font-size:13px;"><i class="bi bi-hourglass-split" style="font-size:28px;display:block;margin-bottom:10px;opacity:.5;"></i>Loading…</div>';
        }

        var url = cfg.leadDetailUrl
            ? cfg.leadDetailUrl.replace('LEAD_ID', leadId)
            : '/console/lead/' + leadId;

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '',
            },
        })
        .then(function (r) { return r.json(); })
        .then(function (lead) {
            if (!window.LeadPanel) return;
            window.LeadPanel.render(null, lead, {
                containerId:      'pipelinePanelBody',
                showAssociate:    false,
                showQuickActions: true,
                onLeadUpdated:    onPanelLeadUpdated,
            });
        })
        .catch(function () {
            if (body) body.innerHTML = '<div style="padding:20px;text-align:center;color:var(--danger);font-size:13px;">Error loading lead details.</div>';
        });
    }

    function closePanel() {
        if (activeCard) activeCard.classList.remove('pipeline-card-active');
        activeCard = null;

        panel.classList.remove('active');
        panelOverlay.classList.remove('active');
    }

    document.querySelectorAll('.pipeline-card').forEach(function (card) {
        card.addEventListener('click', function () {
            openPanel(card);
        });
    });

    var closeBtn = document.getElementById('pipelinePanelClose');
    if (closeBtn) closeBtn.addEventListener('click', closePanel);
    if (panelOverlay) panelOverlay.addEventListener('click', closePanel);

    // ── Column ⋮ menu — New Status (columna inline) / Delete Status ──
    // Solo para usuarios con manage_lead_statuses (cfg.canManageStatuses).
    // TODO: extraer la lógica de preview/sync de color a un archivo compartido
    // (p.ej. public/js/lead-status-form.js) cuando se haga el refactor del
    // panel — hoy está duplicada de public/js/statuses.js.
    if (cfg.canManageStatuses && board) {
        var newStatusTemplate = document.getElementById('pipelineNewStatusTemplate');

        function closeColumnMenus() {
            document.querySelectorAll('.pipeline-column-menu-dropdown.active').forEach(function (dd) {
                dd.classList.remove('active');
            });
        }

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.pipeline-column-menu')) closeColumnMenus();
        });

        board.addEventListener('click', function (e) {
            var menuBtn = e.target.closest('[data-role="column-menu-btn"]');
            if (menuBtn) {
                var dropdown = menuBtn.nextElementSibling;
                var wasActive = dropdown.classList.contains('active');
                closeColumnMenus();
                if (!wasActive) dropdown.classList.add('active');
                return;
            }

            var actionBtn = e.target.closest('[data-action]');
            if (!actionBtn) return;

            closeColumnMenus();

            var column = actionBtn.closest('.pipeline-column');
            var header = column.querySelector('.pipeline-column-header');

            if (actionBtn.dataset.action === 'new-status') {
                openInlineNewStatus(column);
            } else if (actionBtn.dataset.action === 'delete-status') {
                var statusId   = header.dataset.statusId;
                var statusName = header.querySelector('.pipeline-column-title').textContent.trim();
                var slug       = column.querySelector('.pipeline-column-body').dataset.status;
                deleteStatus(statusId, statusName, slug, column);
            }
        });

        function buildColumnNode(status) {
            var wrap = document.createElement('div');
            wrap.innerHTML =
                '<div class="pipeline-column">' +
                    '<div class="pipeline-column-header" data-status-id="' + status.id + '" data-leads-count="0">' +
                        '<i class="bi bi-grip-vertical pipeline-column-drag-handle" title="Drag to reorder"></i>' +
                        '<span class="pipeline-dot" style="background:' + status.bgColor + '"></span>' +
                        '<span class="pipeline-column-title">' + escapeHtml(status.name) + '</span>' +
                        '<span class="pipeline-column-count" data-role="count">0</span>' +
                        '<div class="pipeline-column-menu">' +
                            '<button type="button" class="pipeline-column-menu-btn" data-role="column-menu-btn" title="Column options"><i class="bi bi-three-dots-vertical"></i></button>' +
                            '<div class="pipeline-column-menu-dropdown" data-role="column-menu-dropdown">' +
                                '<button type="button" class="pipeline-column-menu-item" data-action="new-status"><i class="bi bi-plus-circle"></i> New Status</button>' +
                                '<button type="button" class="pipeline-column-menu-item pipeline-column-menu-item-danger" data-action="delete-status"><i class="bi bi-trash"></i> Delete Status</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="pipeline-column-value" data-role="value">$0.00</div>' +
                    '<div class="pipeline-column-body" data-status="' + status.slug + '"></div>' +
                '</div>';
            return wrap.firstElementChild;
        }

        function openInlineNewStatus(afterColumn) {
            // Solo una columna inline a la vez
            var existing = board.querySelector('.pipeline-column-new');
            if (existing) existing.remove();

            var node = newStatusTemplate.content.firstElementChild.cloneNode(true);
            afterColumn.parentNode.insertBefore(node, afterColumn.nextSibling);

            var nameInput  = node.querySelector('[data-role="ns-name"]');
            var colorInput = node.querySelector('[data-role="ns-color"]');
            var colorHex   = node.querySelector('[data-role="ns-color-hex"]');
            var bgInput    = node.querySelector('[data-role="ns-bg"]');
            var bgHex      = node.querySelector('[data-role="ns-bg-hex"]');
            var preview    = node.querySelector('[data-role="ns-preview"]');
            var countsAs   = node.querySelector('[data-role="ns-counts-as"]');
            var errorEl    = node.querySelector('[data-role="ns-error"]');
            var cancelBtn  = node.querySelector('[data-role="ns-cancel"]');
            var createBtn  = node.querySelector('[data-role="ns-create"]');

            function updatePreview() {
                preview.textContent      = nameInput.value || 'New Status';
                preview.style.background = bgInput.value;
                preview.style.color      = colorInput.value;
                colorHex.value = colorInput.value;
                bgHex.value    = bgInput.value;
            }

            function syncHex(hexInput, pickerInput) {
                var val = hexInput.value;
                if (/^#[0-9a-f]{6}$/i.test(val)) {
                    pickerInput.value = val;
                    updatePreview();
                }
            }

            nameInput.addEventListener('input', updatePreview);
            colorInput.addEventListener('input', updatePreview);
            bgInput.addEventListener('input', updatePreview);
            colorHex.addEventListener('input', function () { syncHex(colorHex, colorInput); });
            bgHex.addEventListener('input', function () { syncHex(bgHex, bgInput); });

            cancelBtn.addEventListener('click', function () {
                node.remove();
            });

            createBtn.addEventListener('click', function () {
                var name = nameInput.value.trim();
                if (!name) {
                    errorEl.textContent = 'Status name is required.';
                    return;
                }
                errorEl.textContent = '';
                createBtn.disabled = true;

                var color  = colorInput.value;
                var bg     = bgInput.value;
                var isWon  = countsAs.value === 'won'  ? 1 : 0;
                var isLost = countsAs.value === 'lost' ? 1 : 0;

                $.ajax({
                    url: cfg.storeStatusUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        name: name,
                        color: color,
                        bg_color: bg,
                        show_in_pipeline: 1,
                        is_won: isWon,
                        is_lost: isLost,
                    },
                    success: function (res) {
                        // Reposicionar: el nuevo status entra justo después de
                        // la columna donde se abrió el menú — reutiliza el mismo
                        // endpoint de "renumerar lista completa" del reorder de columnas.
                        var ids = Array.from(board.querySelectorAll('.pipeline-column-header'))
                            .filter(function (h) { return h.dataset.statusId; })
                            .map(function (h) { return parseInt(h.dataset.statusId, 10); });
                        var sourceId = parseInt(afterColumn.querySelector('.pipeline-column-header').dataset.statusId, 10);
                        var pos = ids.indexOf(sourceId);
                        ids.splice(pos + 1, 0, res.id);

                        $.ajax({
                            url: cfg.reorderStatusUrl,
                            method: 'PATCH',
                            data: { order: ids },
                            complete: function () {
                                // Reemplazar la columna inline (dashed) por la columna real
                                var newColumn = buildColumnNode({
                                    id: res.id,
                                    slug: res.slug,
                                    name: name,
                                    color: color,
                                    bgColor: bg,
                                });
                                node.parentNode.replaceChild(newColumn, node);
                                initLeadColumnSortable(newColumn.querySelector('.pipeline-column-body'));

                                // Registrar el nuevo stage para drag&drop / side panel
                                cfg.stages[res.slug] = {
                                    name: name,
                                    badge_style: 'background:' + bg + ';color:' + color + ';',
                                    bg_color: bg,
                                };
                                if (isWon)  cfg.wonSlugs.push(res.slug);
                                if (isLost) cfg.lostSlugs.push(res.slug);

                                addDonutSegment(res.slug, name, 0, bg, pos + 1);

                                showToast('Status created.', 'success');
                            },
                        });
                    },
                    error: function (xhr) {
                        createBtn.disabled = false;
                        var msg = 'Could not create the status. Please check the form.';
                        if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                            msg = Object.values(xhr.responseJSON.errors).flat().join(' ');
                        }
                        errorEl.textContent = msg;
                    },
                });
            });

            nameInput.focus();
        }

        function deleteStatus(statusId, statusName, slug, column) {
            SwalConfirm.fire({
                title: 'Delete status?',
                html:  'You are about to delete <strong>' + escapeHtml(statusName) + '</strong>.<br>This cannot be undone.',
            }).then(function (result) {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: cfg.destroyStatusUrlTemplate.replace('STATUS_ID', statusId),
                    method: 'DELETE',
                    dataType: 'json',
                    success: function () {
                        // Fade-out + colapso horizontal, luego remover del DOM
                        column.style.transition = 'opacity .2s ease';
                        column.style.opacity = '0';
                        setTimeout(function () {
                            column.style.transition = 'flex-basis .2s ease, width .2s ease, min-width .2s ease, margin .2s ease, padding .2s ease, border-width .2s ease';
                            column.style.flexBasis = '0';
                            column.style.width = '0';
                            column.style.minWidth = '0';
                            column.style.padding = '0';
                            column.style.margin = '0';
                            column.style.borderWidth = '0';
                            column.style.overflow = 'hidden';
                            setTimeout(function () { column.remove(); }, 220);
                        }, 200);

                        removeDonutSegment(slug);
                        delete cfg.stages[slug];
                        cfg.wonSlugs  = cfg.wonSlugs.filter(function (s) { return s !== slug; });
                        cfg.lostSlugs = cfg.lostSlugs.filter(function (s) { return s !== slug; });

                        showToast('Status deleted.', 'success');
                    },
                    error: function (xhr) {
                        var msg = 'Could not delete the status.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                        showToast(msg, 'error');
                    },
                });
            });
        }
    }
})();
