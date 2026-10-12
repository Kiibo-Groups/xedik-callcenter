<div class="storage-nav">
    <a href="{{ route('admin.voice.overview') }}"
        class="storage-nav-item {{ request()->routeIs('admin.voice.overview') ? 'active' : '' }}">
        <i class="bi bi-grid-1x2-fill"></i> Overview
    </a>
    <a href="{{ route('admin.voice.tenant-settings.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.voice.tenant-settings.*') ? 'active' : '' }}">
        <i class="bi bi-gear-fill"></i> Tenant Settings
    </a>
    <a href="{{ route('admin.voice.extension-ranges.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.voice.extension-ranges.*') ? 'active' : '' }}">
        <i class="bi bi-123"></i> Extension Ranges
    </a>
    <a href="{{ route('admin.voice.gateways.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.voice.gateways.*') ? 'active' : '' }}">
        <i class="bi bi-hdd-network-fill"></i> Gateways
    </a>
    <a href="{{ route('admin.voice.extensions.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.voice.extensions.*') ? 'active' : '' }}">
        <i class="bi bi-headset"></i> Extensions
    </a>
    <a href="{{ route('admin.voice.caller-ids.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.voice.caller-ids.*') ? 'active' : '' }}">
        <i class="bi bi-telephone-outbound-fill"></i> Caller IDs
    </a>
    <a href="{{ route('admin.compliance.state-calling-rules.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.compliance.state-calling-rules.*') ? 'active' : '' }}">
        <i class="bi bi-map"></i> State Calling Rules
    </a>
</div>
