@extends('layouts.app')

@section('title', 'Endpoints')
@section('page-title', 'Endpoints')
@section('page-subtitle', 'External integration endpoints available for this tenant')

@push('styles')
<style>
/* ── Group headers ── */
.ep-group { margin-bottom: 16px; }
.ep-group-header {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 16px; cursor: pointer; user-select: none;
    background: var(--bg-th);
    border: 1px solid var(--border);
    border-left: 3px solid var(--primary);
    border-radius: var(--radius);
    margin-bottom: 6px;
    transition: background .12s;
}
.ep-group-header:hover { background: var(--bg-stripe); }
.ep-group-icon  { color: var(--primary); font-size: 14px; flex-shrink: 0; }
.ep-group-name  { font-weight: 700; font-size: 14px; color: var(--text-strong); flex: 1; letter-spacing: .1px; }
.ep-group-badge { font-size: 11px; background: var(--primary-light); color: var(--primary); padding: 1px 9px; border-radius: 99px; font-weight: 600; flex-shrink: 0; }
.ep-group-chevron { color: var(--text-muted); font-size: 12px; flex-shrink: 0; transition: transform .2s; }
.ep-group.closed .ep-group-chevron { transform: rotate(-90deg); }
.ep-group-body  { display: flex; flex-direction: column; gap: 6px; }
.ep-group.closed .ep-group-body { display: none; }

/* ── Accordion panels ── */
.ep-list { display: flex; flex-direction: column; gap: 6px; }

.ep-panel { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
.ep-panel.inactive { opacity: .55; }

.ep-trigger {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 14px; cursor: pointer;
    background: var(--bg-card);
    user-select: none;
    transition: background .12s;
}
.ep-trigger:hover { background: var(--bg-stripe); }

.ep-method {
    font-size: 10px; font-weight: 700; padding: 2px 7px;
    border-radius: 4px; letter-spacing: .5px; flex-shrink: 0;
    min-width: 52px; text-align: center;
}
.ep-trigger-name  { font-size: 14px; font-weight: 600; color: var(--text-strong); flex: 1; }
.ep-trigger-url   { font-family: monospace; font-size: 12px; color: var(--text-muted); margin-left: auto; display: none; }
@media (min-width: 700px) { .ep-trigger-url { display: block; } }
.ep-chevron { color: var(--text-muted); font-size: 13px; flex-shrink: 0; transition: transform .2s; }
.ep-panel.open .ep-chevron { transform: rotate(180deg); }
.ep-admin-btns { display: flex; gap: 6px; margin-left: 8px; }

.ep-body { display: none; border-top: 1px solid var(--border); }
.ep-panel.open .ep-body { display: block; }

.ep-inner { padding: 20px; display: flex; flex-direction: column; gap: 18px; }

.ep-section-label {
    font-size: 10px; font-weight: 700; letter-spacing: .8px;
    text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px;
}
.ep-desc  { font-size: 13px; color: var(--text-body); line-height: 1.6; }
/* Copy wrapper */
.ep-copyable { position: relative; }
.ep-copy-btn {
    position: absolute; top: 7px; right: 8px;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
    color: #94a3b8; border-radius: 5px;
    padding: 3px 7px; font-size: 13px; line-height: 1;
    cursor: pointer; transition: background .15s, color .15s;
    display: flex; align-items: center; gap: 4px;
}
.ep-copy-btn:hover { background: rgba(255,255,255,.18); color: #e2e8f0; }
.ep-copy-btn.copied { color: #4ade80; border-color: #4ade80; }
/* Light variant for URL box */
.ep-copyable-light .ep-copy-btn {
    background: var(--bg-card); border-color: var(--border);
    color: var(--text-muted);
}
.ep-copyable-light .ep-copy-btn:hover { background: var(--bg-stripe); color: var(--text-strong); }
.ep-copyable-light .ep-copy-btn.copied { color: #16a34a; border-color: #16a34a; }

.ep-url-box {
    font-family: monospace; font-size: 13px;
    background: var(--bg-stripe); padding: 10px 42px 10px 14px;
    border-radius: var(--radius-sm); color: var(--text-strong);
    word-break: break-all;
}
.ep-auth-box {
    font-size: 12px; color: var(--text-muted);
    background: var(--bg-stripe); padding: 8px 12px;
    border-radius: var(--radius-sm); font-family: monospace;
}

/* Params table */
.params-tbl { width: 100%; border-collapse: collapse; font-size: 13px; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
.params-tbl thead tr { background: var(--bg-th); }
.params-tbl th {
    text-align: left;
    font-size: 11px; font-weight: 700;
    color: var(--text-strong);
    text-transform: uppercase; letter-spacing: .6px;
    padding: 10px 16px;
    border-bottom: 2px solid var(--border);
}
.params-tbl td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.params-tbl tbody tr:last-child td { border-bottom: none; }
.params-tbl tbody tr:hover { background: var(--bg-stripe); }
.p-name { font-family: monospace; font-size: 12px; font-weight: 700; color: var(--text-strong); }
.p-in   { font-size: 11px; background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 4px; font-family: monospace; font-weight: 600; }
.badge-req { font-size: 11px; background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-weight: 700; }
.badge-opt { font-size: 11px; background: var(--bg-stripe); color: var(--text-muted); padding: 2px 8px; border-radius: 4px; font-weight: 500; }

.ep-code {
    font-family: monospace; font-size: 12px;
    background: #0f172a; color: #e2e8f0;
    padding: 14px 16px; border-radius: var(--radius-sm);
    white-space: pre-wrap; word-break: break-all; line-height: 1.6;
}
.ep-response {
    font-family: monospace; font-size: 12px;
    background: var(--bg-stripe); color: #15803d;
    padding: 10px 14px; border-radius: var(--radius-sm);
    white-space: pre-wrap; word-break: break-all;
}

/* Token selector bar */
.token-bar {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 14px 20px;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    margin-bottom: 16px;
}
.token-bar label { font-size: 13px; font-weight: 500; white-space: nowrap; }
.token-bar select { font-size: 13px; flex: 1; min-width: 200px; max-width: 340px; }
.token-note { font-size: 11px; color: var(--text-muted); }

/* Param editor rows (modal) */
.param-row { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 8px; }
.param-row input, .param-row select { font-size: 12px; }
.param-row .param-name-f { width: 120px; flex-shrink: 0; }
.param-row .param-in-f   { width: 80px;  flex-shrink: 0; }
.param-row .param-req-f  { width: 30px;  flex-shrink: 0; text-align: center; padding-top: 8px; }
.param-row .param-desc-f { flex: 1; }
.param-row .param-del    { flex-shrink: 0; padding-top: 4px; }
</style>
@endpush

@section('content')

{{-- Header bar --}}
<div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="padding-bottom:16px;border-bottom:none;">
        <span class="card-title"><i class="bi bi-plug-fill icon-primary"></i> External Endpoints</span>
        @if($isSuperAdmin)
        <button class="btn btn-primary" onclick="openModal('epCreateModal')">
            <i class="bi bi-plus-circle-fill"></i> New Endpoint
        </button>
        @endif
    </div>

    {{-- Token selector --}}
    <div style="padding: 0 24px 20px;">
        @if($tokens->isEmpty())
        <div class="tip-box">
            <i class="bi bi-exclamation-triangle-fill tip-icon"></i>
            No active access tokens. Go to <a href="{{ route('access-tokens.index') }}">Access Tokens</a> to generate one.
        </div>
        @else
        <div class="token-bar">
            <label for="tokenSelector"><i class="bi bi-key" style="margin-right:4px;"></i> Token:</label>
            <select id="tokenSelector" class="form-control">
                <option value="">— select a token —</option>
                @foreach($tokens as $tok)
                <option value="{{ $tok->token }}">{{ $tok->label }}</option>
                @endforeach
            </select>
            <span class="token-note" id="tokenNote" style="display:none;">
                <i class="bi bi-shield-lock"></i> Private.
            </span>
            <div style="width:1px;background:var(--border);align-self:stretch;margin:0 6px;flex-shrink:0;"></div>
            <div style="flex:1;min-width:160px;max-width:260px;position:relative;">
                <i class="bi bi-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;pointer-events:none;"></i>
                <input type="text" id="epSearch" class="form-control" placeholder="Search endpoints…" style="padding-left:28px;">
            </div>
        </div>
        @endif
        {{-- Search bar when no tokens --}}
        @if($tokens->isEmpty())
        <div style="padding:0 24px 12px;">
            <div style="position:relative;max-width:280px;">
                <i class="bi bi-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;pointer-events:none;"></i>
                <input type="text" id="epSearch" class="form-control" placeholder="Search endpoints…" style="padding-left:28px;">
            </div>
        </div>
        @endif
    </div>
</div>

{{-- Grouped accordion --}}
@php
$grouped = $endpoints->groupBy(fn($ep) => ($ep->group ?: 'General'));
$sortedGroupNames = $grouped->map->min('sort_order')->sort()->keys();
@endphp

@if($endpoints->isEmpty())
<div class="empty-state" style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);">
    <i class="bi bi-plug"></i>
    <h3>No endpoints defined</h3>
    @if($isSuperAdmin)<p>Click "New Endpoint" to add one.</p>@endif
</div>
@else
<div id="epList">
@foreach($sortedGroupNames as $groupName)
@php $groupEndpoints = $grouped[$groupName]; $groupSlug = \Illuminate\Support\Str::slug($groupName); @endphp
<div class="ep-group closed" id="epg-{{ $groupSlug }}">
    <div class="ep-group-header" onclick="toggleGroup('{{ $groupSlug }}')">
        <i class="bi bi-folder2-open ep-group-icon"></i>
        <span class="ep-group-name">{{ $groupName }}</span>
        <span class="ep-group-badge">{{ $groupEndpoints->count() }}</span>
        <i class="bi bi-chevron-down ep-group-chevron"></i>
    </div>
    <div class="ep-group-body" id="epgb-{{ $groupSlug }}">
        <div class="ep-list">
        @foreach($groupEndpoints->sortBy('sort_order') as $ep)
        @php
            $params = $ep->parsed_params;
            $color  = $ep->methodColor();
            $bg     = $ep->methodBg();
        @endphp
    <div class="ep-panel {{ !$ep->is_active ? 'inactive' : '' }}" id="ep-panel-{{ $ep->id }}">

        {{-- Trigger --}}
        <div class="ep-trigger" onclick="togglePanel({{ $ep->id }})">
            <span class="ep-method" style="background:{{ $bg }};color:{{ $color }};">{{ $ep->method }}</span>
            <span class="ep-trigger-name">{{ $ep->name }}</span>
            <span class="ep-trigger-url">{{ $ep->url }}</span>{{-- path only, sin dominio --}}

            @if($isSuperAdmin)
            <div class="ep-admin-btns" onclick="event.stopPropagation()">
                <button class="btn btn-outline btn-xs" onclick="openEdit({{ $ep->id }})" title="Edit">
                    <i class="bi bi-pencil"></i>
                </button>
                <form method="POST" action="{{ route('endpoints.destroy', $ep) }}" class="delete-form" data-name="{{ $ep->name }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                </form>
            </div>
            @endif

            <i class="bi bi-chevron-down ep-chevron"></i>
        </div>

        {{-- Body --}}
        <div class="ep-body">
            <div class="ep-inner">

                {{-- Description --}}
                @if($ep->description)
                <div>
                    <div class="ep-section-label">Description</div>
                    <div class="ep-desc">{{ $ep->description }}</div>
                </div>
                @endif

                {{-- URL --}}
                @php
                    $fullUrl = str_starts_with($ep->url, 'http')
                        ? $ep->url
                        : rtrim(config('app.url'), '/') . $ep->url;
                @endphp
                <div>
                    <div class="ep-section-label">URL</div>
                    <div class="ep-copyable ep-copyable-light">
                        <div class="ep-url-box" data-template="{{ $fullUrl }}">{{ $fullUrl }}</div>
                        <button type="button" class="ep-copy-btn" onclick="epCopy(this)" title="Copy URL">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>

                {{-- Auth --}}
                @if($ep->auth_description)
                <div>
                    <div class="ep-section-label">Authentication</div>
                    <div class="ep-auth-box">{{ $ep->auth_description }}</div>
                </div>
                @endif

                {{-- Params --}}
                @if(count($params))
                <div>
                    <div class="ep-section-label">Parameters</div>
                    <table class="params-tbl">
                        <thead>
                            <tr>
                                <th>Name</th><th>Location</th><th></th><th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($params as $p)
                            <tr>
                                <td><span class="p-name">{{ $p['name'] }}</span></td>
                                <td><span class="p-in">{{ $p['in'] }}</span></td>
                                <td>
                                    @if(!empty($p['required']))
                                        <span class="badge-req">required</span>
                                    @else
                                        <span class="badge-opt">optional</span>
                                    @endif
                                </td>
                                <td style="font-size:13px;color:var(--text-body);">{{ $p['description'] ?? '' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                {{-- Example --}}
                @if($ep->example)
                @php $exampleText = str_replace('{base_url}', rtrim(config('app.url'), '/'), $ep->example); @endphp
                <div>
                    <div class="ep-section-label">Example</div>
                    <div class="ep-copyable">
                        <pre class="ep-code" data-template="{{ $exampleText }}">{{ $exampleText }}</pre>
                        <button type="button" class="ep-copy-btn" onclick="epCopy(this)" title="Copy example">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
                @endif

                {{-- Response --}}
                @if($ep->response_example)
                <div>
                    <div class="ep-section-label">Response</div>
                    <pre class="ep-response">{{ $ep->response_example }}</pre>
                </div>
                @endif

            </div>
        </div>
    </div>
        @endforeach
        </div>{{-- .ep-list --}}
    </div>{{-- .ep-group-body --}}
</div>{{-- .ep-group --}}
@endforeach
</div>{{-- #epList --}}
@endif

@if($isSuperAdmin)
@php
$epDataForJs = $endpoints->keyBy('id')->map(function ($e) {
    return [
        'id'               => $e->id,
        'method'           => $e->method,
        'group'            => $e->group,
        'name'             => $e->name,
        'description'      => $e->description,
        'url'              => $e->url,
        'auth_description' => $e->auth_description,
        'params'           => $e->parsed_params,
        'response_example' => $e->response_example,
        'example'          => $e->example,
        'sort_order'       => $e->sort_order,
        'is_active'        => $e->is_active,
    ];
});
@endphp
{{-- Hidden data for edit modals --}}
<script>
window._epData = @json($epDataForJs);
</script>

{{-- Create Modal --}}
<div class="modal-overlay" id="epCreateModal">
    <div class="modal" style="max-width:680px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle modal-icon"></i> New Endpoint</div>
            <button class="modal-close" onclick="closeModal('epCreateModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('endpoints.store') }}" id="epCreateForm">
            @csrf
            @include('endpoints._form', ['ep' => null, 'prefix' => 'create'])
        </form>
    </div>
</div>

{{-- Edit Modal --}}
<div class="modal-overlay" id="epEditModal">
    <div class="modal" style="max-width:680px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Endpoint</div>
            <button class="modal-close" onclick="closeModal('epEditModal')">&times;</button>
        </div>
        <form method="POST" action="" id="epEditForm">
            @csrf @method('PUT')
            @include('endpoints._form', ['ep' => null, 'prefix' => 'edit'])
        </form>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script src="{{ asset('js/endpoints.js') }}"></script>
<script>
window.EndpointsConfig = {
    hasErrors:   @json($errors->any()),
    editRouteBase: '{{ url("endpoints") }}',
};
</script>
@endpush
