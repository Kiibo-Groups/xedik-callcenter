<div class="storage-nav">
    <a href="{{ route('sms-channels.index') }}"
        class="storage-nav-item {{ request()->routeIs('sms-channels.*') ? 'active' : '' }}">
        <i class="bi bi-chat-dots-fill"></i> Numbers
    </a>
    <a href="{{ route('sms-templates.index') }}"
        class="storage-nav-item {{ request()->routeIs('sms-templates.*') ? 'active' : '' }}">
        <i class="bi bi-chat-square-text"></i> Templates
    </a>
</div>
