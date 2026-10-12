@extends('layouts.app')

@section('title', $account->exists ? 'Edit Email Account' : 'New Email Account')
@section('page-title', $account->exists ? 'Edit Email Account' : 'New Email Account')
@section('page-subtitle', 'Configure IMAP incoming, SMTP outgoing and sender identity')

@section('content')

@if($errors->any())
    <div class="alert alert-danger" style="margin-bottom:16px;">
        <ul style="margin:0;padding-left:16px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST"
      action="{{ $account->exists ? route('email-accounts.update', $account) : route('email-accounts.store') }}">
    @csrf
    @if($account->exists) @method('PUT') @endif

    {{-- ── Tab navigation ── --}}
    <div class="ea-tabs">
        <button type="button" class="ea-tab" data-tab="tab-imap">
            <i class="bi bi-envelope-arrow-down"></i> Account &amp; Incoming
        </button>
        <button type="button" class="ea-tab" data-tab="tab-smtp">
            <i class="bi bi-send"></i> Outgoing (SMTP)
        </button>
        <button type="button" class="ea-tab" data-tab="tab-identity">
            <i class="bi bi-person-badge"></i> Identity &amp; Reply-To
        </button>
    </div>

    {{-- ── TAB 1: Account & Incoming ── --}}
    <div id="tab-imap" class="ea-tab-content card" style="padding:24px;margin-bottom:0;border-top:none;border-radius:0 0 var(--radius) var(--radius);">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label">Account Name <span style="color:#dc2626;">*</span></label>
                <input type="text" name="name" class="form-control"
                       value="{{ old('name', $account->name) }}"
                       placeholder="e.g. Sales Account, Support Account">
                @error('name') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
            <div></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
            <div>
                <label class="form-label">Login Email (IMAP auth) <span style="color:#dc2626;">*</span></label>
                <input type="email" name="email" id="loginEmail" class="form-control"
                       value="{{ old('email', $account->email) }}"
                       placeholder="account@gmail.com">
                <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Used to authenticate with the IMAP server to receive emails. Also used as SMTP fallback if no SMTP credentials are set in Tab 2.</div>
                @error('email') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="form-label">
                    Password <span style="color:#dc2626;">*</span>
                    @if($account->exists)
                        <span style="color:var(--text-muted);font-size:11px;">(leave blank to keep current)</span>
                    @endif
                </label>
                <input type="password" name="password" class="form-control"
                       placeholder="{{ $account->exists ? '••••••••••••••••' : 'App password or SMTP password' }}"
                       autocomplete="new-password">
                @error('password') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
        </div>

        <div style="border-top:1px solid var(--border);margin-bottom:20px;padding-top:20px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px;">
                Incoming Mail (IMAP)
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label class="form-label">Incoming URL <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="imap_host" class="form-control"
                           value="{{ old('imap_host', $account->imap_host) }}"
                           placeholder="imap.gmail.com">
                    @error('imap_host') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">Incoming Port <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="imap_port" class="form-control"
                           value="{{ old('imap_port', $account->imap_port ?? 993) }}">
                    @error('imap_port') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Incoming Security <span style="color:#dc2626;">*</span></label>
                    <select name="imap_security" class="form-control">
                        @foreach(['SSL','TLS','NONE'] as $opt)
                            <option value="{{ $opt }}" {{ old('imap_security', $account->imap_security ?? 'SSL') === $opt ? 'selected' : '' }}>
                                {{ $opt }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Queue</label>
                    <select name="omni_queue_id" class="form-control">
                        <option value="">— No queue —</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->id }}"
                                {{ old('omni_queue_id', $account->omni_queue_id) == $queue->id ? 'selected' : '' }}>
                                {{ $queue->name }} ({{ ucfirst($queue->type) }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div>
            <button type="button" id="testBtn" class="btn btn-secondary btn-sm">
                <i class="bi bi-wifi" id="testIcon"></i>
                <span id="testLabel">Test IMAP Connection</span>
            </button>
            <div id="testResult" style="margin-top:6px;font-size:13px;"></div>
        </div>
    </div>

    {{-- ── TAB 2: Outgoing (SMTP) ── --}}
    <div id="tab-smtp" class="ea-tab-content card" style="padding:24px;margin-bottom:0;border-top:none;border-radius:0 0 var(--radius) var(--radius);">

        {{-- Provider chips --}}
        <div style="margin-bottom:20px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;">
                Quick Setup
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="ea-chip" data-provider="gmail">
                    <i class="bi bi-google"></i> Gmail
                </button>
                <button type="button" class="ea-chip" data-provider="turbosmtp">
                    <i class="bi bi-lightning-charge-fill"></i> Turbo SMTP
                </button>
                <button type="button" class="ea-chip" data-provider="sendgrid">
                    <i class="bi bi-grid-3x3-gap-fill"></i> SendGrid
                </button>
                <button type="button" class="ea-chip" data-provider="custom">
                    <i class="bi bi-tools"></i> Custom
                </button>
            </div>
        </div>

        <div style="border-top:1px solid var(--border);margin-bottom:20px;padding-top:20px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px;">
                Outgoing Mail (SMTP)
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label class="form-label">Outgoing URL <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="smtp_host" id="smtpHost" class="form-control"
                           value="{{ old('smtp_host', $account->smtp_host) }}"
                           placeholder="smtp.gmail.com">
                    @error('smtp_host') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">Outgoing Port <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="smtp_port" id="smtpPort" class="form-control"
                           value="{{ old('smtp_port', $account->smtp_port ?? 587) }}">
                    @error('smtp_port') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Outgoing Security <span style="color:#dc2626;">*</span></label>
                    <select name="smtp_security" id="smtpSecurity" class="form-control">
                        @foreach(['TLS','SSL','NONE'] as $opt)
                            <option value="{{ $opt }}" {{ old('smtp_security', $account->smtp_security ?? 'TLS') === $opt ? 'selected' : '' }}>
                                {{ $opt }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div></div>
            </div>
        </div>

        {{-- SMTP Credentials (optional, for providers like Turbo SMTP with separate auth) --}}
        <div style="border-top:1px solid var(--border);margin:20px 0;padding-top:20px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">
                SMTP Authentication
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
                Leave blank to use the Login Email and Password from Tab 1.
                Fill these if your SMTP provider (e.g. Turbo SMTP) uses different credentials.
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">SMTP Username</label>
                    <input type="text" name="smtp_username" class="form-control" autocomplete="off"
                           value="{{ old('smtp_username', $account->smtp_username) }}"
                           placeholder="your-account@turbo-smtp.com">
                    @error('smtp_username') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">
                        SMTP Password
                        @if($account->exists && $account->smtp_password)
                            <span style="color:var(--text-muted);font-size:11px;">(leave blank to keep current)</span>
                        @endif
                    </label>
                    <input type="password" name="smtp_password" class="form-control" autocomplete="new-password"
                           placeholder="{{ ($account->exists && $account->smtp_password) ? '••••••••••••••••' : 'API key or SMTP password' }}">
                    @error('smtp_password') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div style="display:flex;gap:24px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="require_auth" value="1"
                       {{ old('require_auth', $account->require_auth ?? true) ? 'checked' : '' }}>
                Require Authentication to Send Emails
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="active" value="1"
                       {{ old('active', $account->active ?? true) ? 'checked' : '' }}>
                Active
            </label>
        </div>
    </div>

    {{-- ── TAB 3: Identity & Reply-To ── --}}
    <div id="tab-identity" class="ea-tab-content card" style="padding:24px;margin-bottom:0;border-top:none;border-radius:0 0 var(--radius) var(--radius);">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label">From Name</label>
                <input type="text" name="from_name" id="fromName" class="form-control"
                       value="{{ old('from_name', $account->from_name) }}"
                       placeholder="{{ $account->name ?: 'Display name shown to recipients' }}">
                <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Display name shown to recipients. Leave blank to use Account Name.</div>
                @error('from_name') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="form-label">From Email</label>
                <input type="email" name="from_email" id="fromEmail" class="form-control"
                       value="{{ old('from_email', $account->from_email) }}"
                       placeholder="{{ $account->email ?: 'sender@yourdomain.com' }}">
                <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Sender address (must be verified in your SMTP provider). Leave blank to use Login Email.</div>
                @error('from_email') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
            <div>
                <label class="form-label">Reply-To Email</label>
                <input type="email" name="reply_to" id="replyTo" class="form-control"
                       value="{{ old('reply_to', $account->reply_to) }}"
                       placeholder="replies@yourdomain.com">
                <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Where replies are delivered. Leave blank to use From Email.</div>
                @error('reply_to') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
            <div></div>
        </div>

        {{-- SPF/DKIM Warning --}}
        <div id="spfWarning" style="display:none;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;
            background:#fffbeb;border:1px solid #fcd34d;color:#92400e;">
            <i class="bi bi-exclamation-triangle-fill" style="margin-right:6px;"></i>
            <span id="spfWarningText"></span>
        </div>

        {{-- Live Preview --}}
        <div style="border-top:1px solid var(--border);padding-top:20px;">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;">
                Preview
            </div>
            <div class="preview-box" id="identityPreview">
                <div id="previewFrom" style="margin-bottom:4px;"></div>
                <div id="previewReplyTo" style="color:var(--text-muted);font-size:12px;"></div>
            </div>
        </div>
    </div>

    {{-- ── Actions ── --}}
    <div style="display:flex;align-items:center;gap:12px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save
        </button>
        <a href="{{ route('email-accounts.index') }}" class="btn btn-secondary">Back</a>
    </div>

</form>

@endsection

@push('scripts')
<script>
window.EmailAccountConfig = {
    testUrl:      '{{ route("email-accounts.test-connection") }}',
    csrf:         '{{ csrf_token() }}',
    errorFields:  @json(array_keys($errors->messages())),
    loginEmail:   '{{ old('email', $account->email) }}',
    accountName:  '{{ old('name', $account->name) }}',
};
</script>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
.ea-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border);
    margin-bottom: 0;
    background: var(--bg-card);
    border-radius: var(--radius) var(--radius) 0 0;
    border: 1px solid var(--border);
    border-bottom: none;
    overflow: hidden;
}
.ea-tab {
    padding: 12px 20px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    transition: color .15s, border-color .15s, background .15s;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.ea-tab:hover { color: var(--text-strong); background: var(--bg-hover, #f5f5f5); }
.ea-tab.active { color: #5b4fcf; border-bottom-color: #5b4fcf; background: var(--bg-card); }
.ea-tab-content { display: none; }
.ea-tab-content.active { display: block; }
.ea-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border: 1.5px solid var(--border);
    border-radius: 99px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-mid);
    background: var(--bg-card);
    cursor: pointer;
    transition: border-color .15s, color .15s, background .15s;
}
.ea-chip:hover { border-color: #5b4fcf; color: #5b4fcf; }
.ea-chip.active { border-color: #5b4fcf; background: #ede9fe; color: #5b4fcf; }
</style>
<script src="{{ asset('js/email-account-form.js') }}"></script>
@endpush
