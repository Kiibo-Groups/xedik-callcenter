@php
$isEdit = isset($config);
$timezones = [
    'America/Hermosillo'  => 'Hermosillo (UTC-7)',
    'America/Mexico_City' => 'Mexico City (UTC-6)',
    'America/Monterrey'   => 'Monterrey (UTC-6)',
    'America/Tijuana'     => 'Tijuana (UTC-8)',
    'America/Chicago'     => 'Chicago (UTC-6)',
    'America/New_York'    => 'New York (UTC-5)',
    'America/Los_Angeles' => 'Los Angeles (UTC-8)',
];
$icons = [
    'bi-chat-dots-fill', 'bi-chat-fill', 'bi-headset',
    'bi-question-circle-fill', 'bi-globe', 'bi-person-lines-fill',
];
$days = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'];
$hours = [];
for ($h = 0; $h < 24; $h++) {
    $hours[] = sprintf('%02d:00', $h);
    $hours[] = sprintf('%02d:30', $h);
}
$hours[] = '23:59';
$bh           = $isEdit ? ($config->business_hours ?? []) : [];
$interestOpts = $isEdit ? ($config->interest_options ?? []) : [];
$currentIcon  = old('icon', $config->icon ?? 'bi-chat-dots-fill');
@endphp

{{-- Tabs nav --}}
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px;">
    @foreach([
        'tab-appearance' => 'Appearance',
        'tab-hours'      => 'Business Hours',
        'tab-messages'   => 'Messages',
        'tab-leadform'   => 'Lead Form',
    ] as $tabId => $tabLabel)
    <button type="button" class="wc-tab-btn {{ $loop->first ? 'active' : '' }}" data-tab="{{ $tabId }}"
            style="padding:8px 16px;background:none;border:none;border-bottom:2px solid {{ $loop->first ? 'var(--primary)' : 'transparent' }};margin-bottom:-2px;font-size:13px;font-weight:{{ $loop->first ? '600' : '400' }};color:{{ $loop->first ? 'var(--primary)' : 'var(--text-muted)' }};cursor:pointer;">
        {{ $tabLabel }}
    </button>
    @endforeach
    @if($isEdit)
    <button type="button" class="wc-tab-btn" data-tab="tab-embed"
            style="padding:8px 16px;background:none;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;font-size:13px;font-weight:400;color:var(--text-muted);cursor:pointer;">
        Embed
    </button>
    @endif
</div>

{{-- TAB: Appearance --}}
<div class="wc-tab-pane" id="tab-appearance" style="display:block;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

        {{-- ROW 1: Window Title + Window Subtitle --}}
        <div>
            <label class="form-label">Window Title <span style="color:#dc2626;">*</span></label>
            <input type="text" name="window_title" id="windowTitleInput" class="form-control" required maxlength="100"
                   value="{{ old('window_title', $config->window_title ?? 'Chat with us') }}"
                   placeholder="e.g. Support · Web Chat">
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Main title shown in the chat panel header</div>
            @error('window_title') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>
        <div>
            <label class="form-label">Window Subtitle</label>
            <input type="text" name="window_subtitle" id="windowSubtitleInput" class="form-control" maxlength="150"
                   value="{{ old('window_subtitle', $config->window_subtitle ?? 'We typically reply in 2 min') }}"
                   placeholder="We typically reply in 2 min">
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Tagline shown below the title in the chat panel</div>
            @error('window_subtitle') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>

        {{-- ROW 2: Button Label + Form Intro Text --}}
        <div>
            <label class="form-label">Button Label</label>
            <input type="text" name="button_label" id="buttonLabelInput" class="form-control" maxlength="100"
                   value="{{ old('button_label', $config->button_label ?? 'Chat with us') }}"
                   placeholder="Chat with us">
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Text shown next to the floating button</div>
            @error('button_label') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>
        <div>
            <label class="form-label">Form Intro Text</label>
            <input type="text" name="form_subtitle" id="formSubtitleInput" class="form-control" maxlength="200"
                   value="{{ old('form_subtitle', $config->form_subtitle ?? 'Before we start, tell us about you.') }}"
                   placeholder="Before we start, tell us about you.">
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Shown above the name/phone/email fields</div>
            @error('form_subtitle') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>

        {{-- ROW 3: Label Position + Show Label --}}
        <div>
            <label class="form-label">Label Position</label>
            <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap;">
                @foreach(['top' => 'Top', 'left' => 'Left', 'right' => 'Right', 'bottom' => 'Bottom'] as $val => $lbl)
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="label_position" value="{{ $val }}"
                           {{ old('label_position', $config->label_position ?? 'top') === $val ? 'checked' : '' }}
                           onchange="wcSyncPreview()">
                    {{ $lbl }}
                </label>
                @endforeach
            </div>
        </div>
        <div>
            <label class="form-label">Show Label</label>
            <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap;">
                @foreach(['always' => 'Always', 'hover' => 'On hover', 'never' => 'Never'] as $val => $lbl)
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="label_visibility" value="{{ $val }}"
                           {{ old('label_visibility', $config->label_visibility ?? 'always') === $val ? 'checked' : '' }}
                           onchange="wcSyncPreview()">
                    {{ $lbl }}
                </label>
                @endforeach
            </div>
        </div>

        {{-- ROW 4: Position + Omni Queue --}}
        <div>
            <label class="form-label">Position <span style="color:#dc2626;">*</span></label>
            <div style="display:flex;gap:12px;margin-top:6px;">
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="position" value="bl" {{ old('position', $config->position ?? 'br') === 'bl' ? 'checked' : '' }}>
                    Bottom Left
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="position" value="br" {{ old('position', $config->position ?? 'br') === 'br' ? 'checked' : '' }}>
                    Bottom Right
                </label>
            </div>
            @error('position') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>
        <div>
            <label class="form-label">Omni Queue <span style="color:#dc2626;">*</span></label>
            <select name="omni_queue_id" class="form-control" required>
                <option value="">Select queue...</option>
                @foreach($queues as $q)
                    <option value="{{ $q->id }}" {{ old('omni_queue_id', $config->omni_queue_id ?? '') == $q->id ? 'selected' : '' }}>
                        {{ $q->name }}
                    </option>
                @endforeach
            </select>
            @error('omni_queue_id') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>

        {{-- ROW 5: Widget Size (full width) --}}
        <div style="grid-column:1/-1;">
            <label class="form-label">Widget Size</label>
            <div style="display:flex;gap:8px;align-items:stretch;" id="wcSizeSelector">
                <label style="flex:1;cursor:pointer;" onclick="wcSelectSize('small')">
                    <input type="radio" name="size" value="small" id="size_small"
                           {{ old('size', $config->size ?? 'medium') === 'small' ? 'checked' : '' }}
                           style="display:none;">
                    <div id="size-card-small" style="border:0.5px solid var(--border);border-radius:var(--radius-sm);padding:12px;text-align:center;cursor:pointer;height:100%;box-sizing:border-box;">
                        <div style="width:36px;height:36px;border-radius:50%;background:#534AB7;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                            <i class="bi bi-chat-dots-fill" style="font-size:16px;color:white;"></i>
                        </div>
                        <p style="font-size:12px;font-weight:500;color:var(--text-strong);margin:0 0 2px;">Small</p>
                        <p style="font-size:10px;color:var(--text-muted);margin:0;">44px button</p>
                    </div>
                </label>
                <label style="flex:1;cursor:pointer;" onclick="wcSelectSize('medium')">
                    <input type="radio" name="size" value="medium" id="size_medium"
                           {{ old('size', $config->size ?? 'medium') === 'medium' ? 'checked' : '' }}
                           style="display:none;">
                    <div id="size-card-medium" style="border:0.5px solid var(--border);border-radius:var(--radius-sm);padding:12px;text-align:center;cursor:pointer;height:100%;box-sizing:border-box;">
                        <div style="width:44px;height:44px;border-radius:50%;background:#534AB7;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                            <i class="bi bi-chat-dots-fill" style="font-size:20px;color:white;"></i>
                        </div>
                        <p style="font-size:12px;font-weight:500;color:var(--text-strong);margin:0 0 2px;">Medium</p>
                        <p style="font-size:10px;color:var(--text-muted);margin:0;">56px · default</p>
                    </div>
                </label>
                <label style="flex:1;cursor:pointer;" onclick="wcSelectSize('large')">
                    <input type="radio" name="size" value="large" id="size_large"
                           {{ old('size', $config->size ?? 'medium') === 'large' ? 'checked' : '' }}
                           style="display:none;">
                    <div id="size-card-large" style="border:0.5px solid var(--border);border-radius:var(--radius-sm);padding:12px;text-align:center;cursor:pointer;height:100%;box-sizing:border-box;">
                        <div style="width:56px;height:56px;border-radius:50%;background:#534AB7;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                            <i class="bi bi-chat-dots-fill" style="font-size:26px;color:white;"></i>
                        </div>
                        <p style="font-size:12px;font-weight:500;color:var(--text-strong);margin:0 0 2px;">Large</p>
                        <p style="font-size:10px;color:var(--text-muted);margin:0;">68px button</p>
                    </div>
                </label>
            </div>
            @error('size') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>

        {{-- ROW 6: Primary Color + Icon --}}
        <div>
            <label class="form-label">Primary Color</label>
            <div style="display:flex;align-items:center;gap:0;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input);overflow:hidden;width:fit-content;">
                <input type="color" id="primaryColorPicker" name="primary_color_picker"
                       value="{{ old('primary_color', $config->primary_color ?? '#534AB7') }}"
                       style="width:40px;height:38px;border:none;border-right:1px solid var(--border);cursor:pointer;padding:4px 6px;background:var(--bg-input);">
                <input type="text" id="primaryColorHex" name="primary_color"
                       value="{{ old('primary_color', $config->primary_color ?? '#534AB7') }}"
                       maxlength="7" placeholder="#534AB7"
                       style="border:none;outline:none;font-size:13px;font-family:monospace;width:90px;padding:0 10px;height:38px;background:var(--bg-input);color:var(--text-strong);">
            </div>
            <p style="font-size:11px;color:var(--text-muted);margin-top:4px;">Click the color swatch or type a hex value</p>
            @error('primary_color') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>
        <div>
            <label class="form-label">Icon <span style="color:#dc2626;">*</span></label>
            <input type="hidden" name="icon" id="wcIconInput" value="{{ $currentIcon }}">
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                @foreach($icons as $ic)
                <div class="icon-option {{ $currentIcon === $ic ? 'selected' : '' }}"
                     data-icon="{{ $ic }}"
                     style="width:44px;height:44px;border-radius:8px;border:2px solid {{ $currentIcon === $ic ? 'var(--primary)' : 'var(--border)' }};background:{{ $currentIcon === $ic ? 'var(--primary-light)' : '' }};display:flex;align-items:center;justify-content:center;cursor:pointer;transition:.15s;">
                    <i class="bi {{ $ic }}" style="font-size:20px;"></i>
                </div>
                @endforeach
            </div>
            @error('icon') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>

        {{-- ROW 7: Allowed Origin (full width) --}}
        <div style="grid-column:1/-1;">
            <label class="form-label">Allowed Origin <span style="font-size:11px;color:var(--text-muted);">(optional)</span></label>
            <input type="text" name="allowed_origin" class="form-control" maxlength="255"
                   value="{{ old('allowed_origin', $config->allowed_origin ?? '') }}"
                   placeholder="https://yoursite.com">
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Leave empty to allow all origins</div>
            @error('allowed_origin') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>

    </div>
</div>

{{-- TAB: Business Hours --}}
<div class="wc-tab-pane" id="tab-hours" style="display:none;">
    <div style="margin-bottom:16px;">
        <label class="form-label">Timezone</label>
        <select name="timezone" class="form-control" style="max-width:280px;">
            @foreach($timezones as $tz => $tzLabel)
                <option value="{{ $tz }}" {{ old('timezone', $config->timezone ?? 'America/Hermosillo') === $tz ? 'selected' : '' }}>
                    {{ $tzLabel }}
                </option>
            @endforeach
        </select>
        @error('timezone') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($days as $key => $dayName)
        @php
            $dayData  = $bh[$key] ?? null;
            $enabled  = is_array($dayData) && ($dayData['enabled'] ?? '1') === '1';
            $openVal  = $enabled ? ($dayData['open']  ?? '07:00') : '07:00';
            $closeVal = $enabled ? ($dayData['close'] ?? '17:00') : '17:00';
        @endphp
        <div style="display:flex;align-items:center;gap:16px;padding:10px 14px;border:1px solid var(--border);border-radius:8px;">
            <label style="display:flex;align-items:center;gap:8px;min-width:130px;cursor:pointer;font-size:13px;">
                <input type="checkbox" class="bh-day-toggle" data-day="{{ $key }}" {{ $enabled ? 'checked' : '' }}>
                {{ $dayName }}
            </label>
            <select name="business_hours[{{ $key }}][open]" class="form-control bh-time-select" data-day="{{ $key }}"
                    style="width:100px;" {{ !$enabled ? 'disabled' : '' }}>
                @foreach($hours as $h)
                    <option value="{{ $h }}" {{ $openVal === $h ? 'selected' : '' }}>{{ $h }}</option>
                @endforeach
            </select>
            <span style="font-size:12px;color:var(--text-muted);">to</span>
            <select name="business_hours[{{ $key }}][close]" class="form-control bh-time-select" data-day="{{ $key }}"
                    style="width:100px;" {{ !$enabled ? 'disabled' : '' }}>
                @foreach($hours as $h)
                    <option value="{{ $h }}" {{ $closeVal === $h ? 'selected' : '' }}>{{ $h }}</option>
                @endforeach
            </select>
            <input type="hidden" name="business_hours[{{ $key }}][enabled]" value="{{ $enabled ? '1' : '0' }}">
            @if(!$enabled)
            <span style="font-size:12px;color:var(--text-muted);font-style:italic;">Closed</span>
            @endif
        </div>
        @endforeach
    </div>
</div>

{{-- TAB: Messages --}}
<div class="wc-tab-pane" id="tab-messages" style="display:none;">
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div>
            <label class="form-label">Welcome Message</label>
            <textarea name="welcome_message" id="welcomeMessage" class="form-control" rows="4" maxlength="500"
                      placeholder="Hi! How can we help you today?">{{ old('welcome_message', $config->welcome_message ?? '') }}</textarea>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Shown as the first message in the chat when agents are available.</div>
            @error('welcome_message') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>
        <div>
            <label class="form-label">Offline Message</label>
            <textarea name="offline_message" id="wcOfflineInput" class="form-control" rows="4" maxlength="500"
                      placeholder="We're currently offline. Leave your info and we'll get back to you.">{{ old('offline_message', $config->offline_message ?? '') }}</textarea>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Shown outside business hours.</div>
            @error('offline_message') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
        </div>
    </div>
</div>

{{-- TAB: Lead Form --}}
<div class="wc-tab-pane" id="tab-leadform" style="display:none;">
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <label class="form-label" style="margin:0;">Interest field</label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                <input type="checkbox" name="interest_enabled" id="interestEnabled" value="1"
                       {{ old('interest_enabled', $config->interest_enabled ?? true) ? 'checked' : '' }}>
                Show an interest/topic selector to visitors
            </label>
        </div>
        <div id="interestSection" style="{{ old('interest_enabled', $config->interest_enabled ?? true) ? '' : 'display:none;' }}">
            <label class="form-label">Interest Type</label>
            <div style="display:flex;gap:16px;margin-top:6px;margin-bottom:12px;">
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="interest_type" value="dropdown"
                           {{ old('interest_type', $config->interest_type ?? 'dropdown') === 'dropdown' ? 'checked' : '' }}>
                    Dropdown options
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="radio" name="interest_type" value="text"
                           {{ old('interest_type', $config->interest_type ?? 'dropdown') === 'text' ? 'checked' : '' }}>
                    Open text
                </label>
            </div>
            <div id="interestOptionsSection" style="{{ old('interest_type', $config->interest_type ?? 'dropdown') === 'dropdown' ? '' : 'display:none;' }}">
                <label class="form-label">Options</label>
                <div id="interestOptionsContainer" style="display:flex;flex-direction:column;gap:6px;margin-top:8px;margin-bottom:10px;">
                    @foreach($interestOpts as $opt)
                    <div class="interest-option-row" style="display:flex;gap:6px;margin-bottom:0;">
                        <input type="text" name="interest_options[]" value="{{ $opt }}"
                               class="form-control interest-option-input" style="flex:1;max-width:300px;" maxlength="100">
                        <button type="button" class="btn btn-sm btn-danger remove-interest-option">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    @endforeach
                </div>
                <button type="button" id="addInterestOption" class="btn btn-sm btn-secondary">
                    <i class="bi bi-plus"></i> Add option
                </button>
            </div>
        </div>

        {{-- Privacy Notice --}}
        <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                <label class="form-label" style="margin:0;">Privacy notice</label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                    <input type="hidden" name="privacy_enabled" value="0">
                    <input type="checkbox" id="privacyEnabled" name="privacy_enabled" value="1"
                           {{ old('privacy_enabled', $config->privacy_enabled ?? false) ? 'checked' : '' }}>
                    Show a privacy/consent notice to visitors
                </label>
            </div>

            <div id="privacyFields" style="{{ old('privacy_enabled', $config->privacy_enabled ?? false) ? '' : 'display:none;' }}">
                <label class="form-label">Type</label>
                <div style="display:flex;gap:16px;margin-top:6px;margin-bottom:14px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="privacy_type" value="info"
                               {{ old('privacy_type', $config->privacy_type ?? 'info') === 'info' ? 'checked' : '' }}>
                        Info text only
                        <span style="font-size:11px;color:var(--text-muted);">(recommended)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="privacy_type" value="checkbox"
                               {{ old('privacy_type', $config->privacy_type ?? 'info') === 'checkbox' ? 'checked' : '' }}>
                        Checkbox required
                        <span style="font-size:11px;color:var(--text-muted);">(TCPA safe)</span>
                    </label>
                </div>

                <label class="form-label">Notice text</label>
                <textarea name="privacy_text" rows="3" maxlength="500" class="form-control"
                          placeholder="By starting a chat you agree to our [privacy_url] and consent to be contacted."
                          style="margin-bottom:4px;">{{ old('privacy_text', $config->privacy_text ?? '') }}</textarea>
                <div style="font-size:11px;color:var(--text-muted);margin:0 0 14px;">
                    Use <code>[privacy_url]</code> as placeholder for the Privacy Policy link.
                </div>

                <label class="form-label">Privacy Policy URL <span style="font-size:11px;font-weight:400;color:var(--text-muted);">(optional)</span></label>
                <input type="url" name="privacy_url" class="form-control"
                       placeholder="https://yoursite.com/privacy"
                       value="{{ old('privacy_url', $config->privacy_url ?? '') }}">
                @error('privacy_url') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>
</div>

@if($isEdit)
{{-- TAB: Embed --}}
<div class="wc-tab-pane" id="tab-embed" style="display:none;">
    <div style="display:flex;flex-direction:column;gap:16px;">
        <div>
            <label class="form-label">Embed Script</label>
            <div style="position:relative;">
                <textarea id="embedCode" readonly rows="4"
                          style="width:100%;font-size:12px;font-family:monospace;padding:10px 80px 10px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg-th);resize:none;color:var(--text-strong);box-sizing:border-box;line-height:1.6;"
><script src="{{ url('/webchat/widget.js') }}"
  data-config="{{ $config->public_token }}"
  data-pusher-key="{{ config('broadcasting.connections.pusher.key') }}"
  data-pusher-cluster="{{ config('broadcasting.connections.pusher.options.cluster') }}"></script></textarea>
                <button type="button" id="copyEmbedBtn"
                        class="btn btn-sm btn-secondary"
                        style="position:absolute;top:8px;right:8px;">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                Paste before the &lt;/body&gt; tag on your website.
            </div>
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
            <button type="button" class="btn btn-secondary btn-sm" id="regenerateTokenBtn">
                <i class="bi bi-arrow-clockwise"></i> Regenerate Token
            </button>
            <span style="font-size:12px;color:var(--danger);">
                <i class="bi bi-exclamation-triangle"></i> This will break existing embed scripts
            </span>
        </div>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
            <a href="{{ url('webchat-test/' . $config->public_token) }}" target="_blank" class="btn btn-secondary btn-sm">
                <i class="bi bi-eye"></i> Open Preview Page
            </a>
            <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">
                Share this URL with your team to preview the widget.
            </p>
        </div>
    </div>
</div>
@endif
