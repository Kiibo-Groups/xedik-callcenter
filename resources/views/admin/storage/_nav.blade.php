<div class="storage-nav">
    <a href="{{ route('admin.storage.profiles.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.storage.profiles.*') ? 'active' : '' }}">
        <i class="bi bi-cloud-arrow-up"></i> Profiles
    </a>
    <a href="{{ route('admin.storage.plans.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.storage.plans.*') ? 'active' : '' }}">
        <i class="bi bi-box-seam"></i> Plans
    </a>
    <a href="{{ route('admin.storage.providers.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.storage.providers.*') ? 'active' : '' }}">
        <i class="bi bi-grid"></i> Providers
    </a>
    <a href="{{ route('admin.storage.tenant-settings.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.storage.tenant-settings.*') ? 'active' : '' }}">
        <i class="bi bi-gear"></i> Tenant Settings
    </a>
    <a href="{{ route('admin.storage.files.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.storage.files.*') ? 'active' : '' }}">
        <i class="bi bi-files"></i> Files Browser
    </a>
</div>
