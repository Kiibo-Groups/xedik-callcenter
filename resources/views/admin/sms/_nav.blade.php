<div class="storage-nav">
    <a href="{{ route('admin.sms.provider-accounts.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.sms.provider-accounts.*') ? 'active' : '' }}">
        <i class="bi bi-hdd-network-fill"></i> Providers
    </a>
    <a href="{{ route('admin.sms.channels.index') }}"
        class="storage-nav-item {{ request()->routeIs('admin.sms.channels.*') ? 'active' : '' }}">
        <i class="bi bi-chat-dots-fill"></i> Channels
    </a>
    <a href="{{ route('sms-templates.index') }}"
        class="storage-nav-item {{ request()->routeIs('sms-templates.*') ? 'active' : '' }}">
        <i class="bi bi-chat-square-text"></i> Templates
    </a>
</div>
