{{--
  Shared create/edit form for Users.

  Required variables (passed from create.blade.php / edit.blade.php):
    $action  (string)       — form POST URL
    $user    (User|null)    — null for create, User model for edit

  Variables injected by UserController::formData():
    $roles, $tenants, $isSuperAdmin,
    $tenantVoiceEnabled, $tenantVoiceMap,
    $voiceCallerIds, $voiceCallerIdsMap,
    $tenantDefaultCallerId, $tenantDefaultCallerIdMap
--}}

@push('styles')
<style>
.form-page-card    { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); }
.tab-nav           { display:flex; border-bottom:2px solid var(--border); gap:0; padding:0 24px; border-radius:var(--radius) var(--radius) 0 0; }
.tab-btn           { background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px;
                     padding:12px 18px; font-size:14px; font-weight:500; color:var(--text-muted); cursor:pointer;
                     display:flex; align-items:center; gap:6px; transition:color .15s, border-color .15s; white-space:nowrap; }
.tab-btn:hover     { color:var(--text-strong); }
.tab-btn.active    { color:var(--primary); border-bottom-color:var(--primary); }
.tab-err-dot       { width:7px; height:7px; border-radius:50%; background:#ef4444; display:inline-block; flex-shrink:0; }
.tab-panels        { padding:24px 24px 0; }
.tab-panel         { display:none; }
.tab-panel.active  { display:block; }
.form-actions      { display:flex; justify-content:flex-end; gap:8px; padding:20px 24px; border-top:1px solid var(--border); margin-top:8px; }
.voice-status-card { padding:12px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:var(--radius-sm);
                     margin-bottom:16px; display:flex; align-items:center; flex-wrap:wrap; gap:10px; font-size:13px; }
.voice-no-cids     { padding:10px 14px; background:var(--bg-th); border:1px dashed var(--border); border-radius:var(--radius-sm);
                     font-size:13px; color:var(--text-muted); margin-bottom:6px; }
</style>
@endpush

@php
    $isEdit           = isset($user) && $user !== null;
    $voiceExt         = $isEdit ? ($user->voiceExtension ?? null) : null;
    $hasVoice         = $voiceExt?->is_active ?? false;
    $selectedCallerId = old('personal_caller_id_id', $voiceExt?->personal_caller_id_id ?? '');

    // Which tab opens first — errors take priority; General wins over Voice if both have errors
    $initialTab = 'general';
    if ($errors->hasAny(['password']))                            $initialTab = 'security';
    if ($errors->hasAny(['enable_voice','personal_caller_id_id'])) $initialTab = 'voice';
    if ($errors->hasAny(['name','email','role','phone_user_id','tenant_id'])) $initialTab = 'general';

    // Voice tab visible server-side for non-superadmin
    $voiceTabVisible = !$isSuperAdmin && $tenantVoiceEnabled;
@endphp

<div class="form-page-card">

    {{-- Tab Navigation --}}
    <div class="tab-nav" role="tablist">
        <button type="button" class="tab-btn {{ $initialTab === 'general' ? 'active' : '' }}" data-tab="general">
            <i class="bi bi-person"></i> General
            @if($errors->hasAny(['name','email','role','phone_user_id','tenant_id']))
                <span class="tab-err-dot" title="This tab has errors"></span>
            @endif
        </button>
        <button type="button" class="tab-btn {{ $initialTab === 'security' ? 'active' : '' }}" data-tab="security">
            <i class="bi bi-shield-lock"></i> Security
            @if($errors->has('password'))
                <span class="tab-err-dot" title="This tab has errors"></span>
            @endif
        </button>
        <button type="button" class="tab-btn {{ $initialTab === 'voice' ? 'active' : '' }} js-tab-voice" data-tab="voice"
                @if(!$voiceTabVisible && !$isSuperAdmin) style="display:none" @endif>
            <i class="bi bi-telephone-fill"></i> Voice
            @if($errors->hasAny(['enable_voice','personal_caller_id_id']))
                <span class="tab-err-dot" title="This tab has errors"></span>
            @endif
        </button>
    </div>

    <form method="POST" action="{{ $action }}" id="usersForm">
        @csrf
        @if($isEdit) @method('PUT') @endif

        <div class="tab-panels">

            {{-- ── TAB: GENERAL ─────────────────────────────────────────────── --}}
            <div class="tab-panel {{ $initialTab === 'general' ? 'active' : '' }}" id="tab-general">

                @if($isSuperAdmin)
                <div class="form-group">
                    <label class="form-label">Tenant <span class="required-star">*</span></label>
                    <select name="tenant_id" id="formTenantId" class="form-control {{ $errors->has('tenant_id') ? 'is-invalid' : '' }}" required>
                        <option value="">— Select tenant —</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}"
                                {{ old('tenant_id', $isEdit ? $user->tenant_id : '') == $tenant->id ? 'selected' : '' }}>
                                {{ $tenant->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('tenant_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endif

                <div class="form-group">
                    <label class="form-label">Full Name <span class="required-star">*</span></label>
                    <input type="text" name="name" required
                           class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}"
                           placeholder="John Smith"
                           value="{{ old('name', $isEdit ? $user->name : '') }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-group">
                    <label class="form-label">Email <span class="required-star">*</span></label>
                    <input type="email" name="email" required
                           class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}"
                           placeholder="john@company.com"
                           value="{{ old('email', $isEdit ? $user->email : '') }}">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="form-row cols-2">
                    <div class="form-group mb-0">
                        <label class="form-label">Role <span class="required-star">*</span></label>
                        <select name="role" id="formRole"
                                class="form-control {{ $errors->has('role') ? 'is-invalid' : '' }}">
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}"
                                    data-tenant="{{ $role->tenant_id ?? '' }}"
                                    {{ old('role', $isEdit ? $user->role_id : '') == $role->id ? 'selected' : '' }}>
                                    {{ $role->label }}
                                </option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Phone User ID</label>
                        <input type="text" name="phone_user_id"
                               class="form-control {{ $errors->has('phone_user_id') ? 'is-invalid' : '' }}"
                               placeholder="e.g. 3131"
                               value="{{ old('phone_user_id', $isEdit ? $user->phone_user_id : '') }}">
                        <div class="form-hint">Dialer agent ID. Leave blank if not applicable.</div>
                        @error('phone_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

            </div>{{-- /tab-general --}}

            {{-- ── TAB: SECURITY ────────────────────────────────────────────── --}}
            <div class="tab-panel {{ $initialTab === 'security' ? 'active' : '' }}" id="tab-security">

                @if($isEdit)
                <p class="form-hint" style="margin-bottom:16px;">Leave both fields blank to keep the current password.</p>
                @endif

                <div class="form-row cols-2">
                    <div class="form-group mb-0">
                        <label class="form-label">
                            {{ $isEdit ? 'New Password' : 'Password' }}
                            @if(!$isEdit)<span class="required-star">*</span>@endif
                        </label>
                        <div class="password-input-wrapper">
                            <input type="password" name="password"
                                   class="form-control toggle-password-input {{ $errors->has('password') ? 'is-invalid' : '' }}"
                                   placeholder="Password"
                                   {!! !$isEdit ? 'required' : '' !!}>
                            <button type="button" class="password-toggle" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-hint">Min 8 chars, upper/lowercase, number &amp; symbol</div>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">
                            Confirm Password
                            @if(!$isEdit)<span class="required-star">*</span>@endif
                        </label>
                        <div class="password-input-wrapper">
                            <input type="password" name="password_confirmation"
                                   class="form-control toggle-password-input"
                                   placeholder="Repeat password"
                                   {!! !$isEdit ? 'required' : '' !!}>
                            <button type="button" class="password-toggle" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </div>{{-- /tab-security --}}

            {{-- ── TAB: VOICE ────────────────────────────────────────────────── --}}
            <div class="tab-panel {{ $initialTab === 'voice' ? 'active' : '' }}" id="tab-voice">

                <input type="hidden" name="voice_assignment" value="automatic">

                {{-- Extension status badge (edit + has extension) --}}
                @if($isEdit && $voiceExt)
                <div class="voice-status-card">
                    <span>
                        <i class="bi bi-telephone-fill" style="color:var(--primary)"></i>
                        Extension <strong>{{ $voiceExt->extension }}</strong>
                    </span>
                    <span class="badge {{ $voiceExt->sip_provisioning_status === 'provisioned' ? 'badge-success' : 'badge-secondary' }}">
                        <i class="bi {{ $voiceExt->sip_provisioning_status === 'provisioned' ? 'bi-check-circle-fill' : 'bi-hourglass-split' }} badge-icon"></i>
                        {{ $voiceExt->sip_provisioning_status }}
                    </span>
                    <span class="cell-muted" style="font-size:12px;">
                        Caller ID source:
                        <em>{{ $voiceExt->caller_id_source === 'user' ? 'personal' : 'tenant default' }}</em>
                    </span>
                </div>
                @endif

                {{-- Enable Voice checkbox --}}
                <div class="check-row form-group">
                    <input type="checkbox" name="enable_voice" id="formEnableVoice" value="1"
                           style="width:16px;height:16px;"
                           {{ (old('enable_voice') === '1' || ($isEdit && $hasVoice)) ? 'checked' : '' }}>
                    <label class="check-label" for="formEnableVoice">Enable voice for this user</label>
                </div>
                @error('enable_voice')
                    <div class="invalid-feedback" style="display:block;margin-bottom:8px;">{{ $message }}</div>
                @enderror

                {{-- Personal Caller ID --}}
                <div class="form-group mb-0">
                    <label class="form-label">Personal Caller ID</label>

                    @if($isSuperAdmin)
                        {{-- Superadmin: dropdown populated by JS when tenant is selected --}}
                        <select name="personal_caller_id_id" id="formPersonalCallerId"
                                class="form-control {{ $errors->has('personal_caller_id_id') ? 'is-invalid' : '' }}">
                            <option value="">— Use tenant / campaign default —</option>
                        </select>
                        <div class="voice-no-cids" id="noCallerIdsMsg" style="display:none;margin-top:6px;">
                            <i class="bi bi-info-circle" style="margin-right:6px;"></i>
                            No authorized Caller IDs available for the selected tenant.
                        </div>
                    @elseif($voiceCallerIds->isNotEmpty())
                        <select name="personal_caller_id_id" id="formPersonalCallerId"
                                class="form-control {{ $errors->has('personal_caller_id_id') ? 'is-invalid' : '' }}">
                            <option value="">— Use tenant / campaign default —</option>
                            @foreach($voiceCallerIds as $cid)
                                <option value="{{ $cid->id }}"
                                    {{ $selectedCallerId == $cid->id ? 'selected' : '' }}>
                                    {{ $cid->display_label }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        <div class="voice-no-cids">
                            <i class="bi bi-info-circle" style="margin-right:6px;"></i>
                            No authorized Caller IDs available for this tenant.
                            Create one in <a href="{{ route('admin.voice.caller-ids.index') }}">Voice → Caller IDs</a>.
                        </div>
                        <input type="hidden" name="personal_caller_id_id" value="">
                    @endif

                    <div class="form-hint" style="margin-top:4px;">
                        Tenant default: <span id="formTenantDefault">{{ $tenantDefaultCallerId ?? '—' }}</span>
                    </div>
                    @error('personal_caller_id_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

            </div>{{-- /tab-voice --}}

        </div>{{-- .tab-panels --}}

        {{-- Form Actions --}}
        <div class="form-actions">
            <a href="{{ route('users.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary" id="formSubmitBtn">
                <i class="bi bi-save"></i> {{ $isEdit ? 'Save Changes' : 'Create User' }}
            </button>
        </div>

    </form>
</div>

@push('scripts')
<script>
window.UsersFormConfig = {
    isSuperAdmin:             @json($isSuperAdmin),
    isEdit:                   @json($isEdit),
    tenantVoiceEnabled:       @json($tenantVoiceEnabled),
    tenantVoiceMap:           @json($tenantVoiceMap),
    voiceCallerIdsMap:        @json($voiceCallerIdsMap),
    tenantDefaultCallerIdMap: @json($tenantDefaultCallerIdMap),
    tenantDefaultCallerId:    @json($tenantDefaultCallerId),
    initialTab:               @json($initialTab),
    initialVoiceCallerId:     @json((string) ($selectedCallerId ?? '')),
};
</script>
<script src="{{ asset('js/users-form.js') }}"></script>
@endpush
