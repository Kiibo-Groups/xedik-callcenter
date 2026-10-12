<div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">

    <div style="display:grid;grid-template-columns:120px 1fr 1fr;gap:12px;">
        <div class="form-group" style="margin:0;">
            <label class="form-label">Method <span class="required-star">*</span></label>
            <select name="method" id="{{ $prefix }}_method" class="form-control" required>
                @foreach(['GET','POST','PUT','PATCH','DELETE'] as $m)
                <option value="{{ $m }}" {{ old('method', $ep?->method) === $m ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Group</label>
            <input type="text" name="group" id="{{ $prefix }}_group" class="form-control"
                   value="{{ old('group', $ep?->group) }}"
                   placeholder="e.g. Lead Lists">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Name <span class="required-star">*</span></label>
            <input type="text" name="name" id="{{ $prefix }}_name" class="form-control"
                   value="{{ old('name', $ep?->name) }}" required placeholder="e.g. Add Lead to List">
        </div>
    </div>

    <div class="form-group" style="margin:0;">
        <label class="form-label">Description</label>
        <textarea name="description" id="{{ $prefix }}_description" class="form-control" rows="2"
                  placeholder="What does this endpoint do?">{{ old('description', $ep?->description) }}</textarea>
    </div>

    <div class="form-group" style="margin:0;">
        <label class="form-label">URL <span class="required-star">*</span></label>
        <input type="text" name="url" id="{{ $prefix }}_url" class="form-control"
               value="{{ old('url', $ep?->url) }}" required
               placeholder="https://your-domain.com/api/external/...">
        <div class="form-hint">Use {curly_braces} for path parameters.</div>
    </div>

    <div class="form-group" style="margin:0;">
        <label class="form-label">Authentication</label>
        <input type="text" name="auth_description" id="{{ $prefix }}_auth" class="form-control"
               value="{{ old('auth_description', $ep?->auth_description) }}"
               placeholder="e.g. Query param: token={access_token}">
    </div>

    {{-- Params builder --}}
    <div class="form-group" style="margin:0;">
        <label class="form-label">Parameters</label>
        <div id="{{ $prefix }}_params_rows" style="display:flex;flex-direction:column;gap:6px;"></div>
        <button type="button" class="btn btn-outline btn-xs" style="margin-top:6px;"
                onclick="addParamRow('{{ $prefix }}')">
            <i class="bi bi-plus"></i> Add parameter
        </button>
        <input type="hidden" name="params" id="{{ $prefix }}_params_json">
        <div class="form-hint" style="margin-top:4px;">Name · Location · Required · Description</div>
    </div>

    <div class="form-group" style="margin:0;">
        <label class="form-label">Example</label>
        <textarea name="example" id="{{ $prefix }}_example" class="form-control" rows="4"
                  style="font-family:monospace;font-size:12px;"
                  placeholder="GET https://... or cURL / JSON body">{{ old('example', $ep?->example) }}</textarea>
    </div>

    <div class="form-group" style="margin:0;">
        <label class="form-label">Response Example</label>
        <textarea name="response_example" id="{{ $prefix }}_response" class="form-control" rows="3"
                  style="font-family:monospace;font-size:12px;"
                  placeholder='{"ok":true,...}'>{{ old('response_example', $ep?->response_example) }}</textarea>
    </div>

    <div style="display:grid;grid-template-columns:100px 1fr;gap:12px;align-items:center;">
        <div class="form-group" style="margin:0;">
            <label class="form-label">Sort order</label>
            <input type="number" name="sort_order" id="{{ $prefix }}_sort" class="form-control"
                   value="{{ old('sort_order', $ep?->sort_order ?? 0) }}" min="0">
        </div>
        <div class="form-group" style="margin:0;padding-top:20px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="is_active" id="{{ $prefix }}_active" value="1"
                       {{ old('is_active', $ep?->is_active ?? true) ? 'checked' : '' }}>
                <span class="form-label" style="margin:0;">Active (visible in catalog)</span>
            </label>
        </div>
    </div>

</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" onclick="closeModal('{{ $prefix === 'create' ? 'epCreateModal' : 'epEditModal' }}')">Cancel</button>
    <button type="submit" class="btn btn-primary" onclick="serializeParams('{{ $prefix }}')">
        <i class="bi bi-save"></i> Save
    </button>
</div>
