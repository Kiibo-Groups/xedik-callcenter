@extends('layouts.app')

@section('title', $script->exists ? 'Edit Script' : 'New Script')
@section('page-title', $script->exists ? 'Edit Script' : 'New Script')
@section('page-subtitle', 'Configure script content shown to agents during conversations')

@section('content')

@if($errors->any())
<div class="alert alert-danger" style="margin-bottom:16px;">
    <ul style="margin:0;padding-left:16px;">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

<form method="POST"
      action="{{ $script->exists ? route('scripts.update', $script) : route('scripts.store') }}"
      id="scriptForm"
      style="max-width:860px;">
    @csrf
    @if($script->exists) @method('PUT') @endif

    <div style="background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.25);border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
        <span style="font-size:20px;">🤖</span>
        <div style="font-size:13px;color:var(--text-primary);line-height:1.4;">
            <strong>Sincronización con Voicebot IA:</strong> Al guardar cambios en este guion, el contenido y la frase indicada en <code>SALUDO INICIAL: "..."</code> se sincronizan automáticamente en tiempo real con el Asesor de Voz en ElevenLabs.
        </div>
    </div>

    <div class="card" style="padding:24px;margin-bottom:16px;">
        <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px;">
            Script Settings
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            {{-- Campaign --}}
            <div>
                <label class="form-label">Campaign <span class="required-star">*</span></label>
                <select name="campaign_id" id="scriptCampaign" class="form-control" required>
                    <option value="">— Select campaign —</option>
                    @foreach($campaigns as $c)
                        <option value="{{ $c->id }}"
                            {{ old('campaign_id', $script->campaign_id) == $c->id ? 'selected' : '' }}>
                            {{ $c->name }}
                        </option>
                    @endforeach
                </select>
                @error('campaign_id')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            {{-- Name --}}
            <div>
                <label class="form-label">Script Name <span class="required-star">*</span></label>
                <input type="text" name="name" class="form-control"
                       value="{{ old('name', $script->name) }}"
                       placeholder="e.g. Opening – Inbound Sales" required maxlength="150">
                @error('name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
            {{-- Type --}}
            <div>
                <label class="form-label">Type <span class="required-star">*</span></label>
                <select name="type" id="scriptType" class="form-control" onchange="toggleScriptType(this.value)">
                    <option value="builder" {{ old('type', $script->type ?? 'builder') === 'builder' ? 'selected' : '' }}>Builder (rich text)</option>
                    <option value="url"     {{ old('type', $script->type) === 'url' ? 'selected' : '' }}>URL</option>
                </select>
                @error('type')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            {{-- Direction --}}
            <div>
                <label class="form-label">Direction <span class="required-star">*</span></label>
                <select name="direction" class="form-control">
                    <option value="both"     {{ old('direction', $script->direction ?? 'both') === 'both'     ? 'selected' : '' }}>Both</option>
                    <option value="inbound"  {{ old('direction', $script->direction) === 'inbound'  ? 'selected' : '' }}>Inbound</option>
                    <option value="outbound" {{ old('direction', $script->direction) === 'outbound' ? 'selected' : '' }}>Outbound</option>
                </select>
                <div class="form-hint">Used to match scripts to the conversation flow.</div>
                @error('direction')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            {{-- Sort order --}}
            <div>
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" class="form-control"
                       value="{{ old('sort_order', $script->sort_order ?? 0) }}"
                       min="0" max="9999">
                <div class="form-hint">Lower numbers appear first.</div>
                @error('sort_order')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>
        </div>

        <div style="display:flex;gap:24px;margin-bottom:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="is_active" value="1"
                    {{ old('is_active', $script->exists ? $script->is_active : true) ? 'checked' : '' }}>
                Active
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="is_default" value="1"
                    {{ old('is_default', $script->is_default ?? false) ? 'checked' : '' }}>
                Default script for this campaign / direction
            </label>
        </div>
        <div class="form-hint" style="margin-bottom:0;">
            At most one default per campaign + direction combination. Setting this will unmark the previous default.
        </div>
    </div>

    {{-- Builder body --}}
    <div class="card" style="padding:24px;margin-bottom:16px;" id="builderSection">
        <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">
            Script Content
        </div>
        <div class="form-hint" style="margin-bottom:12px;">
            Use the editor below to write the agent script. This content will be shown read-only to agents in Agent Console.
        </div>
        {{-- Hidden textarea synced with Jodit before submit --}}
        <textarea name="body" id="scriptBody" style="display:none;">{{ old('body', $script->body) }}</textarea>
        <div id="scriptBodyEditor"></div>
        @error('body')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
    </div>

    {{-- URL section --}}
    <div class="card" style="padding:24px;margin-bottom:16px;display:none;" id="urlSection">
        <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">
            URL Settings
        </div>
        <div class="form-group">
            <label class="form-label">URL <span class="required-star">*</span></label>
            <input type="text" name="url" class="form-control"
                   value="{{ old('url', $script->url) }}"
                   placeholder="https://crm.example.com/scripts/page1">
            <div class="form-hint">Must start with https:// or http://.</div>
            @error('url')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <label class="form-label">Open In</label>
                <select name="url_target" class="form-control">
                    <option value="tab"   {{ old('url_target', $script->url_target ?? 'tab')   === 'tab'   ? 'selected' : '' }}>New tab</option>
                    <option value="panel" {{ old('url_target', $script->url_target) === 'panel' ? 'selected' : '' }}>Inline panel (iframe)</option>
                    <option value="modal" {{ old('url_target', $script->url_target) === 'modal' ? 'selected' : '' }}>Modal (degrades to tab)</option>
                </select>
            </div>
            <div>
                <label class="form-label">Inject Parameters</label>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                    @foreach(['conversation_id','lead_id','campaign_id','queue_id','channel','direction'] as $param)
                    <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" name="url_params[{{ $param }}]" value="1"
                            {{ !empty(old('url_params.'.$param, ($script->url_params[$param] ?? null))) ? 'checked' : '' }}>
                        {{ $param }}
                    </label>
                    @endforeach
                </div>
                <div class="form-hint">Append these as query parameters to the URL.</div>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary" id="submitBtn">
            <i class="bi bi-save"></i> {{ $script->exists ? 'Save Changes' : 'Create Script' }}
        </button>
        <a href="{{ route('scripts.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
(function () {
    'use strict';

    // ── Jodit initialization ──────────────────────────────────────────────────
    let _joditScript = null;

    function initJodit() {
        if (_joditScript) {
            _joditScript.destruct();
            _joditScript = null;
        }
        if (!document.getElementById('scriptBodyEditor')) return;

        _joditScript = Jodit.make('#scriptBodyEditor', {
            height: 380,
            toolbarButtonSize: 'middle',
            language: 'en',
            toolbarAdaptive: false,
            buttons: 'bold,italic,underline,strikethrough,|,ul,ol,|,h2,h3,|,font,fontsize,brush,|,table,|,align,|,undo,redo,|,eraser,fullsize',
            placeholder: 'Write the agent script here…',
            statusbar: false,
            showCharsCounter: false,
            showWordsCounter: false,
            showXPathInStatusbar: false,
            addNewLine: false,
        });

        // Load existing content from hidden textarea
        const existing = document.getElementById('scriptBody').value;
        if (existing) {
            _joditScript.value = existing;
        }
    }

    // ── Type toggle ───────────────────────────────────────────────────────────
    window.toggleScriptType = function (type) {
        const builderSection = document.getElementById('builderSection');
        const urlSection     = document.getElementById('urlSection');
        if (type === 'builder') {
            builderSection.style.display = '';
            urlSection.style.display     = 'none';
            initJodit();
        } else {
            builderSection.style.display = 'none';
            urlSection.style.display     = '';
            if (_joditScript) {
                _joditScript.destruct();
                _joditScript = null;
            }
        }
    };

    // ── Sync Jodit before submit ──────────────────────────────────────────────
    document.getElementById('scriptForm').addEventListener('submit', function () {
        if (_joditScript) {
            document.getElementById('scriptBody').value = _joditScript.value;
        }
    });

    // ── Initialize on page load ───────────────────────────────────────────────
    const initialType = document.getElementById('scriptType').value;
    toggleScriptType(initialType);
}());
</script>
@endpush

@endsection
