@extends('layouts.app')
@section('title', 'Webchat Configurations')
@section('page-title', 'Webchat')
@section('page-subtitle', 'Embeddable chat widgets')

@section('header-actions')
<a href="{{ route('admin.webchat-configs.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> New Webchat
</a>
@endsection

@section('content')

@if($isSuperAdmin)
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:12px 16px;">
        <form method="GET" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="font-size:13px;font-weight:500;color:var(--text-muted);white-space:nowrap;">Filter by tenant:</label>
            <select name="tenant_id" class="form-control" style="max-width:220px;" onchange="this.form.submit()">
                <option value="">All tenants</option>
                @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}" {{ request('tenant_id') == $tenant->id ? 'selected' : '' }}>
                        {{ $tenant->name }}
                    </option>
                @endforeach
            </select>
            @if(request('tenant_id'))
                <a href="{{ route('admin.webchat-configs.index') }}" class="btn btn-sm btn-secondary">
                    <i class="bi bi-x"></i> Clear
                </a>
            @endif
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-chat-dots-fill icon-primary"></i> Webchat Configurations</span>
    </div>
    <div class="table-wrapper">
        @if($configs->count())
        <table>
            <thead>
                <tr>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Title</th>
                    <th>Queue</th>
                    <th>Position</th>
                    <th>Size</th>
                    <th>Status</th>
                    <th>Token</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($configs as $config)
                <tr>
                    @if($isSuperAdmin)
                    <td>
                        <span class="badge" style="background:var(--primary-light,#e0e7ff);color:var(--primary);">
                            {{ $config->tenant?->name ?? '—' }}
                        </span>
                    </td>
                    @endif
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:30px;height:30px;border-radius:50%;background:{{ $config->primary_color }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="bi {{ $config->icon }}" style="color:#fff;font-size:13px;"></i>
                            </div>
                            <span class="cell-strong">{{ $config->window_title }}</span>
                        </div>
                    </td>
                    <td>
                        @if($config->omniQueue)
                            <span class="badge" style="background:#e0e7ff;color:#3730a3;">{{ $config->omniQueue->name }}</span>
                        @else
                            <span class="cell-muted" style="font-style:italic;">—</span>
                        @endif
                    </td>
                    <td class="cell-muted">
                        {{ $config->position === 'br' ? 'Bottom Right' : 'Bottom Left' }}
                    </td>
                    <td>
                        @php $size = $config->size ?? 'medium'; @endphp
                        <span class="badge" style="
                            background: {{ $size === 'large' ? '#fce7f3' : ($size === 'small' ? '#f0fdf4' : '#eff6ff') }};
                            color:       {{ $size === 'large' ? '#9d174d' : ($size === 'small' ? '#166534' : '#1d4ed8') }};
                        ">{{ ucfirst($size) }}</span>
                    </td>
                    <td>
                        <span class="badge {{ $config->is_active ? 'badge-success' : 'badge-danger' }} wc-status-toggle"
                              data-url="{{ route('admin.webchat-configs.toggle', $config) }}"
                              data-csrf="{{ csrf_token() }}"
                              style="cursor:pointer;">
                            <i class="bi {{ $config->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $config->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <span class="cell-code">{{ substr($config->public_token, 0, 8) }}…</span>
                        <button type="button"
                                class="wc-copy-token"
                                data-token="{{ $config->public_token }}"
                                style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:2px 4px;"
                                title="Copy full token">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </td>
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <a href="{{ url('webchat-test/' . $config->public_token) }}" target="_blank"
                               class="btn btn-outline btn-xs" title="Preview widget">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('admin.webchat-configs.edit', $config) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form action="{{ route('admin.webchat-configs.destroy', $config) }}" method="POST"
                                  class="delete-form" data-name="{{ $config->window_title }}" data-entity="Webchat config"
                                  style="display:inline;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-chat-dots-fill"></i>
            <h3>No webchat configurations yet</h3>
            <p>Add the first widget to start receiving webchat conversations.</p>
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
<script src="{{ asset('js/webchat-configs.js') }}"></script>
@endpush
@endsection
