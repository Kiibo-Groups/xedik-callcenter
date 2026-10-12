// ── Tab switching ─────────────────────────────────────────────────────────────
var cfg = window.EmailAccountConfig || {};

function activateTab(tabId) {
    document.querySelectorAll('.ea-tab').forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    document.querySelectorAll('.ea-tab-content').forEach(function (div) {
        div.classList.toggle('active', div.id === tabId);
    });
}

document.querySelectorAll('.ea-tab').forEach(function (btn) {
    btn.addEventListener('click', function () { activateTab(this.dataset.tab); });
});

// ── Auto-activate tab with validation errors ──────────────────────────────────
var tabFields = {
    'tab-imap':     ['name', 'email', 'password', 'imap_host', 'imap_port', 'imap_security', 'omni_queue_id'],
    'tab-smtp':     ['smtp_host', 'smtp_port', 'smtp_security'],
    'tab-identity': ['from_name', 'from_email', 'reply_to'],
};

var errorFields = cfg.errorFields || [];
var defaultTab  = 'tab-imap';

if (errorFields.length > 0) {
    for (var tabId in tabFields) {
        if (tabFields[tabId].some(function (f) { return errorFields.indexOf(f) !== -1; })) {
            defaultTab = tabId;
            break;
        }
    }
}
activateTab(defaultTab);

// ── Provider chips ────────────────────────────────────────────────────────────
var providers = {
    gmail:     { host: 'smtp.gmail.com',      port: '587', security: 'TLS' },
    turbosmtp: { host: 'pro.turbo-smtp.com',  port: '465', security: 'SSL' },
    sendgrid:  { host: 'smtp.sendgrid.net',   port: '587', security: 'TLS' },
    // custom: intentionally does nothing — leaves fields untouched
};

document.querySelectorAll('.ea-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
        var provider = this.dataset.provider;

        document.querySelectorAll('.ea-chip').forEach(function (c) { c.classList.remove('active'); });
        this.classList.add('active');

        if (providers[provider]) {
            document.getElementById('smtpHost').value     = providers[provider].host;
            document.getElementById('smtpPort').value     = providers[provider].port;
            document.getElementById('smtpSecurity').value = providers[provider].security;
        } else if (provider === 'custom') {
            document.getElementById('smtpHost').value     = '';
            document.getElementById('smtpPort').value     = '';
            document.getElementById('smtpSecurity').value = 'TLS';
        }
    });
});

// Mark the active chip based on current smtp_host value on page load
// Uses partial match for Turbo SMTP to handle regional endpoints (pro.eu.turbo-smtp.com, etc.)
(function () {
    var currentHost = ((document.getElementById('smtpHost') || {}).value || '').toLowerCase();
    if (!currentHost) return;

    var matched = null;

    if (currentHost.includes('turbo-smtp.com')) {
        matched = 'turbosmtp';
    } else if (currentHost.includes('smtp.gmail.com')) {
        matched = 'gmail';
    } else if (currentHost.includes('smtp.sendgrid.net')) {
        matched = 'sendgrid';
    }

    if (matched) {
        var chip = document.querySelector('[data-provider="' + matched + '"]');
        if (chip) chip.classList.add('active');
    }
})();

// ── SPF/DKIM domain warning ───────────────────────────────────────────────────
function getDomain(email) {
    var at = email ? email.trim().lastIndexOf('@') : -1;
    return at !== -1 ? email.trim().slice(at + 1).toLowerCase() : '';
}

function updateSpfWarning() {
    var loginEmail = (document.getElementById('loginEmail') || {}).value || cfg.loginEmail || '';
    var fromEmail  = (document.getElementById('fromEmail')  || {}).value || '';
    var warning    = document.getElementById('spfWarning');
    var warningText = document.getElementById('spfWarningText');

    if (!warning) return;

    var loginDomain = getDomain(loginEmail);
    var fromDomain  = getDomain(fromEmail);

    if (fromEmail && fromDomain && loginDomain && fromDomain !== loginDomain) {
        warningText.textContent =
            '⚠️ The sender domain ' + fromDomain +
            ' must have SPF/DKIM configured for your SMTP provider, otherwise emails may land in spam.';
        warning.style.display = '';
    } else {
        warning.style.display = 'none';
    }
}

var fromEmailInput = document.getElementById('fromEmail');
if (fromEmailInput) {
    fromEmailInput.addEventListener('input', function () {
        updateSpfWarning();
        updatePreview();
    });
}

// Also update warning if login email changes
var loginEmailInput = document.getElementById('loginEmail');
if (loginEmailInput) {
    loginEmailInput.addEventListener('input', updateSpfWarning);
}

updateSpfWarning(); // run on page load (edit mode may already have values)

// ── Live identity preview ─────────────────────────────────────────────────────
function updatePreview() {
    var loginEmail  = (document.getElementById('loginEmail')  || {}).value || cfg.loginEmail  || '';
    var accountName = (document.getElementById('fromName')    || {}).value || cfg.accountName || '';
    var fromName    = (document.getElementById('fromName')    || {}).value || '';
    var fromEmail   = (document.getElementById('fromEmail')   || {}).value || '';
    var replyTo     = (document.getElementById('replyTo')     || {}).value || '';

    // Mirror ConsoleController fallback logic
    var effectiveFrom  = fromEmail  || loginEmail;
    var effectiveName  = fromName   || accountName;
    var effectiveReply = replyTo    || fromEmail || loginEmail;

    var previewFrom    = document.getElementById('previewFrom');
    var previewReplyTo = document.getElementById('previewReplyTo');

    if (previewFrom) {
        previewFrom.innerHTML =
            '<strong>From:</strong> ' +
            (effectiveName ? escHtml(effectiveName) + ' ' : '') +
            '&lt;' + escHtml(effectiveFrom) + '&gt;';
    }

    if (previewReplyTo) {
        if (effectiveReply && effectiveReply.toLowerCase().trim() !== effectiveFrom.toLowerCase().trim()) {
            previewReplyTo.innerHTML = '<strong>Reply-To:</strong> ' + escHtml(effectiveReply);
            previewReplyTo.style.display = '';
        } else {
            previewReplyTo.style.display = 'none';
        }
    }
}

function escHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

['fromName', 'fromEmail', 'replyTo'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', updatePreview);
});

updatePreview(); // run on page load

// ── Test IMAP Connection ──────────────────────────────────────────────────────
var testBtn = document.getElementById('testBtn');
if (testBtn) {
    testBtn.addEventListener('click', testConnection);
}

async function testConnection() {
    var btn   = document.getElementById('testBtn');
    var icon  = document.getElementById('testIcon');
    var label = document.getElementById('testLabel');
    var res   = document.getElementById('testResult');

    btn.disabled         = true;
    icon.className       = 'bi bi-arrow-repeat';
    icon.style.display   = 'inline-block';
    icon.style.animation = 'spin .7s linear infinite';
    label.textContent    = 'Testing...';
    res.textContent      = '';

    try {
        var resp = await fetch(cfg.testUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': cfg.csrf,
            },
            body: JSON.stringify({
                imap_host:     document.querySelector('[name=imap_host]').value,
                imap_port:     document.querySelector('[name=imap_port]').value,
                imap_security: document.querySelector('[name=imap_security]').value,
                email:         document.querySelector('[name=email]').value,
                password:      document.querySelector('[name=password]').value,
            }),
        });

        var data = await resp.json();

        if (data.success) {
            icon.className   = 'bi bi-check-circle-fill';
            icon.style.color = '#16a34a';
            res.textContent  = '✓ ' + data.message;
            res.style.color  = '#16a34a';
        } else {
            icon.className   = 'bi bi-x-circle-fill';
            icon.style.color = '#dc2626';
            res.textContent  = '✗ ' + data.message;
            res.style.color  = '#dc2626';
        }
    } catch (e) {
        icon.className  = 'bi bi-wifi';
        res.textContent = '✗ Request failed.';
        res.style.color = '#dc2626';
    } finally {
        icon.style.animation = '';
        label.textContent    = 'Test IMAP Connection';
        btn.disabled         = false;
    }
}
