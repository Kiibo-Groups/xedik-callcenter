<div class="modal-body">
    @if($isEdit ?? false)
    <input type="hidden" name="_gateway_id" id="edit_gateway_id" value="{{ old('_gateway_id') }}">
    @endif

    <div class="form-group">
        <label class="form-label">Scope</label>
        <select class="form-control" name="tenant_id" id="{{ ($isEdit ?? false) ? 'edit_tenant_id' : 'create_tenant_id' }}">
            <option value="">Global (all tenants)</option>
            @foreach($tenants as $t)
            <option value="{{ $t->id }}" {{ (string) old('tenant_id') === (string) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
            @endforeach
        </select>
        <div class="form-hint">
            Leave blank for a global gateway available to all tenants.
            @if($isEdit ?? false)<br>Moving a gateway to a tenant-specific scope hides it from all other tenants.@endif
        </div>
        @error('tenant_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>

    <div class="form-group">
        <label class="form-label">Name <span class="required-star">*</span></label>
        <input type="text" class="form-control" name="name" id="{{ ($isEdit ?? false) ? 'edit_name' : '' }}"
               maxlength="120" placeholder="MX Primary" value="{{ old('name') }}" required>
        @error('name')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>

    <div class="form-group">
        <label class="form-label">Asterisk Endpoint ID / Trunk Endpoint <span class="required-star">*</span></label>
        <input type="text" class="form-control" name="asterisk_endpoint_id"
               id="{{ ($isEdit ?? false) ? 'edit_endpoint_id' : '' }}"
               maxlength="80" placeholder="signalwave_mx" pattern="[a-zA-Z0-9_\-]+"
               value="{{ old('asterisk_endpoint_id') }}" required>
        <div class="form-hint">
            Must match exactly the PJSIP endpoint name in Asterisk. No spaces.<br>
            Multiple logical gateways may share the same Asterisk endpoint when they differ by country code or dial prefix.
        </div>
        @error('asterisk_endpoint_id')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
    </div>

    <div class="form-row cols-3">
        <div class="form-group">
            <label class="form-label">Hidden Prefix</label>
            <input type="text" class="form-control" name="hidden_prefix"
                   id="{{ ($isEdit ?? false) ? 'edit_prefix' : '' }}"
                   maxlength="20" placeholder="88" pattern="[0-9]*" value="{{ old('hidden_prefix') }}">
            <div class="form-hint">Digits prepended to number (never shown to agent)</div>
            @error('hidden_prefix')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label class="form-label">Country Code</label>
            <input type="text" class="form-control" name="country_code"
                   id="{{ ($isEdit ?? false) ? 'edit_country' : '' }}"
                   maxlength="5" placeholder="52" value="{{ old('country_code') }}">
            @error('country_code')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
        </div>
        <div class="form-group">
            <label class="form-label">National Digits</label>
            <input type="number" class="form-control" name="national_number_length"
                   id="{{ ($isEdit ?? false) ? 'edit_nat_len' : '' }}"
                   min="7" max="15" placeholder="10" value="{{ old('national_number_length') }}">
            @error('national_number_length')<div class="form-hint" style="color:var(--danger);">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="form-group check-row">
        <input type="checkbox" name="is_default" value="1"
               id="{{ ($isEdit ?? false) ? 'edit_is_default' : 'create_is_default' }}"
               style="width:16px;height:16px;" {{ old('is_default') ? 'checked' : '' }}>
        <label class="check-label" for="{{ ($isEdit ?? false) ? 'edit_is_default' : 'create_is_default' }}">
            Default gateway for its scope
        </label>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="closeModal('{{ ($isEdit ?? false) ? 'editModal' : 'createModal' }}')">Cancel</button>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i> {{ ($isEdit ?? false) ? 'Save Changes' : 'Add Gateway' }}
    </button>
</div>
