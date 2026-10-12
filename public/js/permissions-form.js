var icons = [
    'bi-house','bi-people','bi-person','bi-person-fill','bi-person-check',
    'bi-people-fill','bi-shield','bi-shield-fill','bi-key','bi-key-fill',
    'bi-lock','bi-unlock','bi-gear','bi-gear-fill','bi-sliders',
    'bi-headset','bi-telephone','bi-telephone-fill','bi-envelope',
    'bi-envelope-fill','bi-chat','bi-chat-fill','bi-bell','bi-bell-fill',
    'bi-bar-chart','bi-bar-chart-fill','bi-graph-up','bi-pie-chart',
    'bi-calendar','bi-calendar-fill','bi-calendar-check','bi-calendar-check-fill',
    'bi-clock','bi-clock-fill','bi-alarm','bi-stopwatch',
    'bi-tag','bi-tag-fill','bi-tags','bi-tags-fill',
    'bi-bookmark','bi-bookmark-fill','bi-star','bi-star-fill',
    'bi-heart','bi-heart-fill','bi-check-circle','bi-check-circle-fill',
    'bi-x-circle','bi-x-circle-fill','bi-exclamation-circle','bi-info-circle',
    'bi-plus-circle','bi-dash-circle','bi-question-circle',
    'bi-search','bi-filter','bi-funnel','bi-funnel-fill',
    'bi-grid','bi-grid-fill','bi-list','bi-list-ul',
    'bi-table','bi-layout-sidebar','bi-layout-text-window',
    'bi-file','bi-file-fill','bi-file-text','bi-file-text-fill',
    'bi-folder','bi-folder-fill','bi-archive','bi-archive-fill',
    'bi-download','bi-upload','bi-cloud','bi-cloud-fill',
    'bi-link','bi-link-45deg','bi-box-arrow-right','bi-box-arrow-in-right',
    'bi-pencil','bi-pencil-fill','bi-trash','bi-trash-fill',
    'bi-plus','bi-dash','bi-x','bi-check',
    'bi-eye','bi-eye-fill','bi-eye-slash','bi-toggle-on','bi-toggle-off',
    'bi-megaphone','bi-megaphone-fill','bi-broadcast','bi-wifi',
    'bi-diagram-3','bi-diagram-3-fill','bi-diagram-2','bi-node-plus',
    'bi-cpu','bi-database','bi-server','bi-hdd',
    'bi-credit-card','bi-cash','bi-wallet','bi-currency-dollar',
    'bi-map','bi-geo-alt','bi-geo-alt-fill','bi-pin-map',
    'bi-trophy','bi-trophy-fill','bi-award','bi-award-fill',
    'bi-lightbulb','bi-lightbulb-fill','bi-lightning','bi-lightning-fill',
    'bi-pause-circle','bi-pause-circle-fill','bi-play-circle','bi-stop-circle',
    'bi-person-lines-fill','bi-person-badge','bi-person-badge-fill',
    'bi-person-vcard','bi-person-vcard-fill',
    'bi-person-check-fill','bi-person-dash','bi-person-dash-fill',
    'bi-person-plus','bi-person-plus-fill',
    'bi-building','bi-building-fill','bi-buildings','bi-buildings-fill',
];

function renderIcons(filtered) {
    var grid = document.getElementById('iconGrid');
    grid.innerHTML = filtered.map(function(icon) {
        return '<div onclick="selectIcon(\'' + icon + '\')" title="' + icon + '"'
            + ' style="display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:10px;color:var(--text-muted);"'
            + ' onmouseover="this.style.background=\'var(--bg-th)\';this.style.color=\'var(--primary)\'"'
            + ' onmouseout="this.style.background=\'\';this.style.color=\'var(--text-muted)\'">'
            + '<i class="bi ' + icon + '" style="font-size:20px;"></i>'
            + '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;text-align:center;">'
            + icon.replace('bi-', '') + '</span>'
            + '</div>';
    }).join('');
}

function openIconPicker() {
    renderIcons(icons);
    document.getElementById('iconPickerModal').classList.add('active');
    document.getElementById('iconSearch').value = '';
    document.getElementById('iconSearch').focus();
}

function closeIconPicker() {
    document.getElementById('iconPickerModal').classList.remove('active');
}

function filterIcons() {
    var search = document.getElementById('iconSearch').value.toLowerCase();
    renderIcons(icons.filter(function(i) { return i.includes(search); }));
}

function selectIcon(icon) {
    document.getElementById('iconInput').value = icon;
    document.getElementById('iconPreview').innerHTML = '<i class="bi ' + icon + '" style="font-size:18px;color:var(--primary);"></i>';
    closeIconPicker();
}
