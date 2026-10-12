document.addEventListener('DOMContentLoaded', function () {
    var config = window.ConversationAutomationConfig || {};

    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }

    window.closeModal = function (id) {
        document.getElementById(id).classList.remove('active');
    };

    document.querySelectorAll('.js-edit-automation-channel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var channel   = btn.dataset.channel;
            var enabled   = btn.dataset.enabled === '1';
            var authType  = btn.dataset.authType || 'none';
            var hasApiKey = btn.dataset.hasApiKey === '1';

            document.getElementById('automationModalChannel').textContent =
                channel.charAt(0).toUpperCase() + channel.slice(1);
            document.getElementById('automationChannelForm').action = config.updateUrlBase + '/' + channel;
            document.getElementById('automation_enabled').checked = enabled;
            document.getElementById('automation_webhook_url').value = btn.dataset.webhookUrl || '';
            document.getElementById('automation_auth_type').value = authType;
            document.getElementById('automation_timeout_ms').value = btn.dataset.timeoutMs || 5000;
            document.getElementById('automation_api_key').value = '';
            document.getElementById('automation_api_key_hint').textContent = hasApiKey ? '(set — leave blank to keep)' : '(not set)';

            toggleApiKeyField();
            openModal('automationChannelModal');
        });
    });

    function toggleApiKeyField() {
        var isApiKey = document.getElementById('automation_auth_type').value === 'api_key';
        document.getElementById('automation_api_key_group').style.display = isApiKey ? '' : 'none';
    }

    var authTypeSelect = document.getElementById('automation_auth_type');
    if (authTypeSelect) {
        authTypeSelect.addEventListener('change', toggleApiKeyField);
    }
});
