@extends('layouts.app')

@section('title', 'CRM Files Browser')
@section('page-title', 'CRM Files Browser')
@section('page-subtitle', 'All files stored across channels and tenants')

@section('content')
@include('admin.storage._nav')

<div class="card" style="margin-bottom:16px;">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-funnel icon-primary"></i> Filters</span>
        <a href="{{ route('admin.storage.files.index') }}" class="btn btn-outline btn-xs">
            <i class="bi bi-x-lg"></i> Clear
        </a>
    </div>
    <div style="padding:14px 16px;">
        <form method="GET">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;align-items:flex-end;">
                <div class="form-group mb-0">
                    <label class="form-label">Tenant</label>
                    <select name="tenant_id" class="form-control">
                        <option value="">All</option>
                        @foreach($tenants as $t)
                            <option value="{{ $t->id }}" {{ request('tenant_id') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Channel</label>
                    <select name="channel" class="form-control">
                        <option value="">All</option>
                        @foreach(['whatsapp','email','webchat','manual','system'] as $ch)
                            <option value="{{ $ch }}" {{ request('channel') === $ch ? 'selected' : '' }}>{{ $ch }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <option value="">All</option>
                        @foreach(['images','audio','video','documents','attachments','inline_images','raw','stickers','other'] as $cat)
                            <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="">All</option>
                        @foreach(['active','deleted','archived','failed_migration'] as $st)
                            <option value="{{ $st }}" {{ request('status') === $st ? 'selected' : '' }}>{{ $st }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Conv. ID</label>
                    <input type="number" name="conversation_id" class="form-control" value="{{ request('conversation_id') }}" placeholder="ID">
                </div>
                <div class="form-group mb-0" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-files icon-primary"></i> Files
            <span class="cell-muted" style="font-size:13px;font-weight:400;">({{ $files->total() }} results)</span>
        </span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Filename</th>
                    <th>Channel / Cat.</th>
                    <th>Direction</th>
                    <th style="text-align:right">Size</th>
                    <th>MIME Type</th>
                    <th>Tenant</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($files as $file)
                <tr>
                    <td><code class="cell-code">{{ $file->id }}</code></td>
                    <td>
                        <div class="cell-strong" title="{{ $file->object_key }}"
                            style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            {{ $file->original_filename }}
                        </div>
                        <div class="cell-muted" style="font-size:11px;">msg {{ $file->message_id ?? '—' }}</div>
                    </td>
                    <td>
                        @if($file->channel)
                            <span class="badge badge-secondary">{{ $file->channel }}</span>
                        @endif
                        <span class="badge" style="background:var(--border-color);color:var(--text-muted);font-size:11px;">{{ $file->category }}</span>
                    </td>
                    <td>
                        @if($file->direction === 'inbound')
                            <span class="badge badge-secondary"><i class="bi bi-arrow-down badge-icon"></i> in</span>
                        @elseif($file->direction === 'outbound')
                            <span class="badge badge-primary"><i class="bi bi-arrow-up badge-icon"></i> out</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td style="text-align:right">
                        <span class="cell-strong">{{ $file->size_human }}</span>
                    </td>
                    <td><span class="cell-muted" style="font-size:11px;">{{ $file->mime_type }}</span></td>
                    <td><span class="cell-muted">{{ $file->tenant_id }}</span></td>
                    <td><span class="cell-muted">{{ $file->created_at->format('m/d/Y') }}</span></td>
                    <td>
                        <span class="badge {{ match($file->status) {
                            'active'           => 'badge-success',
                            'deleted'          => 'badge-danger',
                            'archived'         => 'badge-secondary',
                            'failed_migration' => 'badge-warning',
                            default            => 'badge-secondary'
                        } }}">{{ $file->status }}</span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button type="button" class="btn btn-outline btn-xs btn-temp-url"
                                data-url="{{ route('admin.storage.files.temporary-url', $file) }}"
                                data-csrf="{{ csrf_token() }}"
                                title="Open temporary URL (10 min)">
                                <i class="bi bi-link-45deg"></i> URL
                            </button>
                            @if($file->status === 'active')
                            <form method="POST" action="{{ route('admin.storage.files.destroy', $file) }}"
                                class="delete-form" data-name="{{ $file->original_filename }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="10">
                        <div class="empty-state">
                            <i class="bi bi-files"></i>
                            <h3>No files found</h3>
                            <p>Files will appear here once they are uploaded through any channel.</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($files->hasPages())
    <div style="padding:12px 16px;border-top:1px solid var(--border-color);">
        {{ $files->links() }}
    </div>
    @endif
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/crm-files.js') }}"></script>
@endpush
