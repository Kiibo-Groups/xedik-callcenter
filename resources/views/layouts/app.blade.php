<!DOCTYPE html>
<html lang="en" data-theme="{{ Cookie::get('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('images/xedik-logo.svg') }}" type="image/x-icon">
    <title>@yield('title', 'Dashboard') — XEDIK Contact Suite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/jodit@4.7.6/es2021/jodit.min.css">
    <script src="https://unpkg.com/jodit@4.7.6/es2021/jodit.min.js"></script>
    <script src="https://unpkg.com/@popperjs/core@2/dist/umd/popper.min.js"></script>
    <script src="https://unpkg.com/tippy.js@6/dist/tippy-bundle.umd.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/themes/translucent.css">
    <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/animations/perspective.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
 
    <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ config('app.asset_version', 1) }}">
    @stack('styles')
</head>
<body>
<script>
    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        document.documentElement.style.setProperty('--sidebar-w', '64px');
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.style.transition = 'none';
            sidebar.classList.add('collapsed');
            setTimeout(() => sidebar.style.transition = '', 50);
        });
    }
</script>
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="logo">
            <img src="{{ asset('images/xedik-logo.svg') }}" alt="XEDIK" width="36" height="36">
        </div>
        <div class="brand-text">
            <div class="brand-title">
                <span>XEDIK</span>
                <span class="brand-badge">PRO</span>
            </div>
            <small>Contact Suite</small>
        </div>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-section-label"></div>
        <nav>
            <div class="nav-item">
                <a href="{{ route('home') }}" class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}" title="Home">
                    <i class="bi bi-house-door"></i>
                    <span class="nav-link-text">Home</span>
                </a>
            </div>
        </nav>
    </div>

    @php 
        $authUser = Auth::user();
        $moduleLabels = [
            'console' => 'Agent Console',
            'leads'   => 'CRM',
            'calls'   => 'Calls',
            'admin'   => 'Admin',
            'dialer'  => 'Dialer',
            'reports' => 'Reports',
        ];
        // module=voice permissions (Voice Settings/Extensions/Gateways) are
        // intentionally excluded from this dynamic sidebar — that standalone
        // group is superseded by System → Voice (Voice Administration), which
        // already covers all of it as tabs, and every module=voice controller
        // is hard-gated to superadmin regardless of this permission catalog.
        $sidebarPerms = collect(($authUser->isSuperAdmin()
            ? \App\Models\Permission::orderBy('sort_order')->get()->groupBy('module')
            : ($authUser->role?->permissions()->orderBy('sort_order')->get()->groupBy('module') ?? collect())
        )->all())->except('voice');

        // SMS: collapse manage_sms_channels/manage_sms_templates into single "SMS" link
        $smsPermissionNames = ['manage_sms_channels', 'manage_sms_templates'];
        $sidebarPerms = $sidebarPerms->map(function ($perms, $module) use ($smsPermissionNames) {
            if ($module !== 'admin') return $perms;
            $hasSms = $perms->contains(fn($p) => in_array($p->name, $smsPermissionNames, true));
            $perms = $perms->reject(fn($p) => in_array($p->name, $smsPermissionNames, true));
            if ($hasSms) {
                $perms->push((object) [
                    'name' => 'sms_unified', 'route' => 'sms.index',
                    'icon' => 'bi-chat-dots-fill', 'label' => 'SMS', 'sort_order' => 17,
                ]);
            }
            return $perms->sortBy('sort_order')->values();
        });

        // Admin subcategories (Linear/Stripe compact hierarchy)
        $adminSubgroupsDef = [
            'team' => [
                'title' => 'Team & Access',
                'icon'  => 'bi-shield-lock-fill',
                'perms' => ['manage_users', 'manage_roles', 'manage_permissions'],
            ],
            'channels' => [
                'title' => 'Channels & Bots',
                'icon'  => 'bi-chat-square-dots-fill',
                'perms' => [
                    'manage_whatsapp_accounts', 'manage_whatsapp_templates',
                    'sms_unified',
                    'manage_email_accounts', 'manage_email_templates',
                    'manage_social_accounts',
                    'manage_webchat_configs', 'manage_conversation_automation'
                ],
            ],
            'crm' => [
                'title' => 'CRM & Catalogs',
                'icon'  => 'bi-folder2-open',
                'perms' => [
                    'manage_campaigns', 'manage_programs',
                    'manage_lead_statuses', 'manage_lead_sources',
                    'manage_tags', 'manage_dialer', 'manage_scripts'
                ],
            ],
            'telephony' => [
                'title' => 'Routing & Queues',
                'icon'  => 'bi-diagram-3-fill',
                'perms' => [
                    'manage_omni_queues', 'manage_pause_reasons', 'manage_endpoints'
                ],
            ],
        ];

        $adminPerms = $sidebarPerms->get('admin', collect());
        $adminSubgroups = [];
        $assignedPermNames = [];

        foreach ($adminSubgroupsDef as $subKey => $subMeta) {
            $matched = $adminPerms->filter(fn($p) => in_array($p->name, $subMeta['perms'], true))->values();
            if ($matched->isNotEmpty()) {
                $adminSubgroups[$subKey] = [
                    'title' => $subMeta['title'],
                    'icon'  => $subMeta['icon'],
                    'items' => $matched,
                ];
                $assignedPermNames = array_merge($assignedPermNames, $subMeta['perms']);
            }
        }

        $unassigned = $adminPerms->reject(fn($p) => in_array($p->name, $assignedPermNames, true))->values();
        if ($unassigned->isNotEmpty()) {
            $adminSubgroups['other'] = [
                'title' => 'Other Settings',
                'icon'  => 'bi-gear-fill',
                'items' => $unassigned,
            ];
        }
    @endphp

    @foreach($sidebarPerms as $module => $perms)
        <div class="sidebar-section">
            <div class="sidebar-section-label">{{ $moduleLabels[$module] ?? ucfirst($module) }}</div>
            <nav>
                @if($module === 'admin')
                    @foreach($adminSubgroups as $subKey => $subgroup)
                    @if($subgroup['items']->isNotEmpty())
                    @php
                        $subHasActive = $subgroup['items']->contains(function($p) {
                            return $p->name === 'sms_unified'
                                ? (request()->routeIs('sms-channels.*') || request()->routeIs('sms-templates.*') || request()->routeIs('sms.*'))
                                : (request()->routeIs($p->route) || request()->routeIs(str_replace('.index', '.*', $p->route)));
                        });
                    @endphp
                    <div class="sidebar-subgroup {{ $subHasActive ? 'open has-active' : '' }}" id="subgroup-{{ $subKey }}">
                        <button type="button" class="sidebar-subgroup-header" onclick="toggleSidebarSubgroup('{{ $subKey }}')">
                            <span class="header-left">
                                <i class="bi {{ $subgroup['icon'] }}"></i>
                                <span class="subgroup-title">{{ $subgroup['title'] }}</span>
                            </span>
                            <span class="header-right">
                                <span class="subgroup-count">{{ $subgroup['items']->count() }}</span>
                                <i class="bi bi-chevron-right subgroup-chevron"></i>
                            </span>
                        </button>
                        <div class="sidebar-subgroup-items">
                            @foreach($subgroup['items'] as $perm)
                            @php
                                try { 
                                    $url = route($perm->route); 
                                } catch (\Exception $e) { 
                                    $url = '#'; 
                                    \Illuminate\Support\Facades\Log::warning("Invalid route in permission: {$perm->name} -> {$perm->route}");
                                }
                                $isActive = $perm->name === 'sms_unified'
                                    ? (request()->routeIs('sms-channels.*') || request()->routeIs('sms-templates.*') || request()->routeIs('sms.*'))
                                    : (request()->routeIs($perm->route) || request()->routeIs(str_replace('.index', '.*', $perm->route)));
                            @endphp
                            @if($url !== '#')
                            <div class="nav-item">
                                <a href="{{ $url }}" class="nav-link {{ $isActive ? 'active' : '' }}" title="{{ $perm->label }}">
                                    <i class="bi {{ $perm->icon }}"></i>
                                    <span class="nav-link-text">{{ $perm->label }}</span>
                                </a>
                            </div>
                            @endif
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @endforeach
                @else
                    @foreach($perms as $perm)
                    @php
                        try { 
                            $url = route($perm->route); 
                        } catch (\Exception $e) { 
                            $url = '#'; 
                            \Illuminate\Support\Facades\Log::warning("Invalid route in permission: {$perm->name} -> {$perm->route}");
                        }
                        $isActive = request()->routeIs($perm->route) || request()->routeIs(str_replace('.index', '.*', $perm->route));
                    @endphp
                    @if($url !== '#')
                    <div class="nav-item">
                        <a href="{{ $url }}" class="nav-link {{ $isActive ? 'active' : '' }}" title="{{ $perm->label }}">
                            <i class="bi {{ $perm->icon }}"></i>
                            <span class="nav-link-text">{{ $perm->label }}</span>
                        </a>
                    </div>
                    @endif
                    @endforeach
                @endif
            </nav>
        </div>
    @endforeach

    @if(Auth::user()->isSuperAdmin())
        <div class="sidebar-section">
            <div class="sidebar-section-label">System</div>
            <nav>
                <div class="nav-item">
                    <a href="{{ route('tenants.index') }}" class="nav-link {{ request()->routeIs('tenants.*') ? 'active' : '' }}" title="Tenants">
                        <i class="bi bi-building"></i>
                        <span class="nav-link-text">Tenants</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="{{ route('admin.storage.profiles.index') }}" class="nav-link {{ request()->routeIs('admin.storage.*') ? 'active' : '' }}" title="Storage">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <span class="nav-link-text">Storage</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="{{ route('admin.voice.overview') }}" class="nav-link {{ (request()->routeIs('admin.voice.*') || request()->routeIs('admin.compliance.state-calling-rules.*')) ? 'active' : '' }}" title="Voice">
                        <i class="bi bi-telephone-fill"></i>
                        <span class="nav-link-text">Voice</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="{{ route('admin.sms.provider-accounts.index') }}" class="nav-link {{ request()->routeIs('admin.sms.*') ? 'active' : '' }}" title="SMS Gateways">
                        <i class="bi bi-hdd-network"></i>
                        <span class="nav-link-text">SMS Gateways</span>
                    </a>
                </div>
            </nav>
        </div>
    @endif

    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar" onclick="openProfileModal()" title="My Profile" style="cursor:pointer;">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
            <div class="user-info">
                <div class="name">{{ Auth::user()->name }}</div>
                <div class="role">{{ Auth::user()->role?->name }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="btn-logout" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</aside>

<!-- OVERLAY MOBILE (cierra el sidebar al tocar fuera) -->
<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;"></div>

<!-- HEADER -->
<header class="main-header">
    <button class="btn-secondary btn btn-sm" id="sidebarToggle" style="display:none">
        <i class="bi bi-list"></i>
    </button>
    <div>
        <div class="page-title">@yield('page-title', 'Dashboard')</div>
        <div class="page-subtitle">@yield('page-subtitle', '')</div>
    </div>
    <div class="header-actions">
        @yield('header-actions')
        <!-- DARK MODE TOGGLE -->
        <button class="theme-btn" id="themeBtn" title="Toggle dark/light mode">
            <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
        </button>
    </div>
</header>

<!-- CONTENT -->
<main class="main-content">
    @if(session('success'))
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i> {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i> {{ session('error') }}
        </div>
    @endif

    @yield('content')
</main>

<div id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    function showToast(message, type = 'success') {
        const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill' };
        const $t = $(`<div class="toast ${type}"><i class="bi ${icons[type] || icons.success}"></i> ${message}</div>`);
        $('#toast-container').append($t);
        setTimeout(() => $t.fadeOut(400, function(){ $(this).remove(); }), 3500);
    }

    // ── DARK MODE ──
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = document.getElementById('themeIcon');
        icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        document.getElementById('themeBtn').style.color = theme === 'dark' ? '#fbbf24' : '';
        document.cookie = 'theme=' + theme + '; path=/; max-age=' + (60*60*24*365);
        localStorage.setItem('theme', theme);
    }

    const savedTheme = localStorage.getItem('theme') || '{{ Cookie::get('theme', 'light') }}';
    applyTheme(savedTheme);

    document.getElementById('themeBtn').addEventListener('click', function() {
        const current = document.documentElement.getAttribute('data-theme');
        applyTheme(current === 'dark' ? 'light' : 'dark');
    });

    // ── SIDEBAR MOBILE ──
    function openSidebar()  { $('#sidebar').addClass('open'); $('#sidebarOverlay').fadeIn(200); }
    function closeSidebar() { $('#sidebar').removeClass('open'); $('#sidebarOverlay').fadeOut(200); }

    $('#sidebarToggle').on('click', function() {
        $('#sidebar').hasClass('open') ? closeSidebar() : openSidebar();
    });

    // Cerrar al tocar el overlay oscuro
    $('#sidebarOverlay').on('click', closeSidebar);

    // Cerrar al navegar a cualquier link
    $('#sidebar .nav-link').on('click', closeSidebar);

    $(window).on('resize', function() {
        if ($(window).width() >= 769) {
            closeSidebar();
            $('#sidebarToggle').hide();
        } else {
            $('#sidebarToggle').show();
        }
    }).trigger('resize');

        // Auto-dismiss alerts after 4 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 4000);

    // ── SIDEBAR COLLAPSIBLE ──
    const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (sidebarCollapsed) {
        document.getElementById('sidebar').classList.add('collapsed');
        document.documentElement.style.setProperty('--sidebar-w', '64px');
    }

    document.querySelector('.sidebar-brand .logo').style.cursor = 'pointer';
    document.querySelector('.sidebar-brand .logo').addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const sidebar = document.getElementById('sidebar');
        sidebar.style.overflow = 'hidden'; // ← evita el scroll flash
        sidebar.classList.toggle('collapsed');
        const isCollapsed = sidebar.classList.contains('collapsed');
        document.documentElement.style.setProperty('--sidebar-w', isCollapsed ? '64px' : '260px');
        localStorage.setItem('sidebarCollapsed', isCollapsed);
        setTimeout(() => sidebar.style.overflow = '', 300); // ← restaura después de la animación
    });
    // ── SIDEBAR SUBGROUPS ACCORDION ──
    window.toggleSidebarSubgroup = function(key) {
        const el = document.getElementById('subgroup-' + key);
        if (!el) return;
        el.classList.toggle('open');
        try {
            const isOpen = el.classList.contains('open');
            localStorage.setItem('sidebar_subgroup_' + key, isOpen ? '1' : '0');
        } catch(e) {}
    };

    // Restore saved subgroup state if group does not contain the active route
    document.querySelectorAll('.sidebar-subgroup').forEach(function(group) {
        const key = group.id.replace('subgroup-', '');
        const saved = localStorage.getItem('sidebar_subgroup_' + key);
        if (!group.classList.contains('has-active') && saved !== null) {
            if (saved === '1') {
                group.classList.add('open');
            } else if (saved === '0') {
                group.classList.remove('open');
            }
        }
    });

    // ── TIPPY TOOLTIPS ──
    function initTooltips() {
        tippy('.nav-link', {
            content(el) { return el.querySelector('.nav-link-text')?.textContent?.trim() || ''; },
            placement: 'right',
            animation: 'perspective',           
            theme: 'translucent',              
            onShow(instance) {
                // Solo mostrar cuando el sidebar está colapsado
                return document.getElementById('sidebar').classList.contains('collapsed');
            }
        });
    }

    initTooltips();

    // ── Global Modal Open / Close ──────────────────────────────────────────
    window.openModal = function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('active');
    };
    window.closeModal = function (id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('active');
    };

    // ── Modal backdrop — close only on real click, not drag release ─────────
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.modal-overlay').forEach(function (el) {
            let mdX, mdY, startedOnOverlay = false;
            el.addEventListener('mousedown', function (e) {
                startedOnOverlay = (e.target === el);
                if (startedOnOverlay) { mdX = e.clientX; mdY = e.clientY; }
            });
            el.addEventListener('mouseup', function (e) {
                if (!startedOnOverlay || e.target !== el) return;
                if (Math.abs(e.clientX - mdX) < 5 && Math.abs(e.clientY - mdY) < 5) {
                    if (typeof closeModal === 'function') closeModal(el.id);
                    else el.classList.remove('active');
                }
            });
        });
    });

    // ── SweetAlert2 global mixin & callable helper ────────────────────────────
    var _SwalMixin = Swal.mixin({
        buttonsStyling:     true,
        icon:               'warning',
        showCancelButton:   true,
        confirmButtonText:  'Yes, delete',
        cancelButtonText:   'Cancel',
        confirmButtonColor: '#ef4444',
        cancelButtonColor:  '#64748b',
        focusCancel:        true,
    });

    // Allow calling SwalConfirm(title, text, cb) as function AND SwalConfirm.fire({...}) as object
    window.SwalConfirm = function (title, text, callback) {
        return _SwalMixin.fire({
            title: title || 'Are you sure?',
            text: text || '',
        }).then(function (result) {
            if (result.isConfirmed && typeof callback === 'function') {
                callback();
            }
            return result;
        });
    };
    window.SwalConfirm.fire = function () {
        return _SwalMixin.fire.apply(_SwalMixin, arguments);
    };
</script>

@yield('scripts')
@stack('scripts')

{{-- PROFILE MODAL --}}
<div id="profileModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100;align-items:center;justify-content:center;">
    <div style="background:var(--bg-card);border-radius:var(--radius);box-shadow:var(--shadow-lg);width:100%;max-width:560px;display:flex;flex-direction:column;max-height:92vh;">
        <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h3 style="font-size:15px;font-weight:600;color:var(--text-strong);margin:0;">My Profile</h3>
            <button onclick="closeProfileModal()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:18px;line-height:1;">&times;</button>
        </div>
        <div style="padding:20px;display:flex;flex-direction:column;gap:16px;overflow-y:auto;">
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Name</label>
                <input type="text" id="profileName" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-input, #f8fafc);color:var(--text-strong);outline:none;box-sizing:border-box;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:4px;">Email Signature</label>
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px;">Automatically added when replying and composing</div>
                <div id="profileSignatureEditor"></div>
            </div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;">
            <button type="button" onclick="closeProfileModal()" style="padding:8px 16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text-strong);font-size:13px;cursor:pointer;">Cancel</button>
            <button type="button" onclick="saveProfile()" style="padding:8px 16px;border:none;border-radius:var(--radius-sm);background:var(--primary);color:white;font-size:13px;font-weight:600;cursor:pointer;">
                <i class="bi bi-check-lg"></i> Save
            </button>
        </div>
    </div>
</div>

<script>
let _joditProfile = null;

function openProfileModal() {
    const modal = document.getElementById('profileModal');
    modal.style.display = 'flex';

    if (!_joditProfile) {
        _joditProfile = Jodit.make('#profileSignatureEditor', {
            height: 150,
            toolbarButtonSize: 'small',
            language: 'en',
            toolbarAdaptive: false,
            buttons: 'font,fontsize,|,bold,italic,underline,|,brush,|,link,image,|,eraser',
            statusbar: false,
            showCharsCounter: false,
            showWordsCounter: false,
            showXPathInStatusbar: false,
            addNewLine: false,
            allowResizeY: false,
            allowResizeX: false,
        });
    }

    fetch('/profile')
        .then(r => r.json())
        .then(data => {
            document.getElementById('profileName').value = data.name || '';
            _joditProfile.value = data.signature || '';
        });
}

function closeProfileModal() {
    document.getElementById('profileModal').style.display = 'none';
}

async function saveProfile() {
    const name      = document.getElementById('profileName').value.trim();
    const signature = _joditProfile?.value || '';

    if (!name) { alert('Name is required.'); return; }

    const resp = await fetch('/profile', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ name, signature }),
    });

    const data = await resp.json();
    if (data.ok) {
        window.agentSignature = signature;
        closeProfileModal();
        if (typeof showToast === 'function') {
            showToast('Profile saved.', 'success');
        }
    }
}

</script>
<script>
(function () {
    // ── Loading bar ───────────────────────────────────────────────────────────
    var bar = document.createElement('div');
    bar.id = 'page-load-bar';
    bar.style.cssText = [
        'position:fixed', 'top:0', 'left:0', 'height:3px', 'width:0',
        'background:var(--primary,#5b4fcf)', 'z-index:99999',
        'transition:width 0.25s ease, opacity 0.3s ease',
        'pointer-events:none', 'border-radius:0 2px 2px 0',
    ].join(';');
    document.body.appendChild(bar);

    function startBar() {
        bar.style.opacity = '1';
        bar.style.width   = '70%';
    }
    function finishBar() {
        bar.style.width = '100%';
        setTimeout(function () {
            bar.style.opacity = '0';
            setTimeout(function () { bar.style.width = '0'; }, 300);
        }, 200);
    }

    var suppressNextProgress = false;

    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-no-progress="true"], .js-direct-download, a[download]');
        if (!target) return;

        suppressNextProgress = true;
        setTimeout(function () {
            suppressNextProgress = false;
        }, 2000);
    }, true);

    // Mostrar barra al salir
    window.addEventListener('beforeunload', function () {
        if (suppressNextProgress) {
            finishBar();
            return;
        }

        startBar();
    });

    // Ocultar barra al llegar
    document.addEventListener('DOMContentLoaded', finishBar);

    // Si la página ya cargó (back/forward cache)
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        finishBar();
    }
})();
</script>
</body>
</html>
