@extends('layouts.app')

@section('title', 'Tags')
@section('page-title', 'Tags Catalog')
@section('page-subtitle', 'Manage reusable tags for leads')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-circle-fill"></i> Add Tag
    </button>
@endsection

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-tag-fill"></i></div>
        <div><div class="stat-label">Total Tags</div><div class="stat-value">{{ $tags->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Leads Tagged</div><div class="stat-value">{{ $tags->sum('leads_count') }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">
            <i class="bi bi-tag icon-primary"></i> Tags
        </span>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tag</th>
                    <th>Slug</th>
                    <th>Preview</th>
                    <th>Leads</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($tags as $tag)
                <tr data-id="{{ $tag->id }}">
                    <td>
                        <div class="cell-strong">{{ $tag->name }}</div>
                    </td>
                    <td>
                        <code class="cell-code">{{ $tag->slug }}</code>
                    </td>
                    <td>
                        <span class="badge" style="background:{{ $tag->bg_color }};color:{{ $tag->color }}">
                            {{ $tag->name }}
                        </span>
                    </td>
                    <td>
                        <span class="cell-strong">{{ $tag->leads_count }}</span>
                        @if($tag->leads_count > 0)
                            <span class="cell-muted"> lead{{ $tag->leads_count != 1 ? 's' : '' }}</span>
                        @endif
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs edit-tag-btn"
                                data-id="{{ $tag->id }}"
                                data-name="{{ $tag->name }}"
                                data-color="{{ $tag->color }}"
                                data-bg="{{ $tag->bg_color }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            @if($tag->leads_count === 0)
                            <form method="POST" action="{{ route('tags.destroy', $tag) }}"
                                  class="delete-form" data-name="{{ $tag->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @else
                            <button class="btn btn-xs btn-disabled" title="Cannot delete — has leads">
                                <i class="bi bi-trash"></i>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- COLOR PREVIEW TIP --}}
<div class="tip-box">
    <i class="bi bi-lightbulb tip-icon"></i>
    <div>
        <strong>Tips:</strong>
        The <strong>Slug</strong> is auto-generated from the name and is used internally — you can't change it after creation.
        You <strong>cannot delete</strong> a tag that has leads assigned — remove it from those leads first.
    </div>
</div>

{{-- ── CREATE MODAL ── --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add New Tag</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('tags.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tag Name *</label>
                    <input type="text" name="name" class="form-control" required
                           placeholder="e.g. VIP, Follow Up, Urgent"
                           id="createName">
                    <div class="form-hint">
                        Slug will be auto-generated: <code id="slugPreview" style="background:var(--bg-th);padding:1px 5px;border-radius:3px">—</code>
                    </div>
                </div>

                <div class="form-row cols-2 mb-16">
                    <div class="form-group mb-0">
                        <label class="form-label">Text Color *</label>
                        <div class="color-row">
                            <input type="color" name="color" id="createColor" value="#4f46e5" class="color-picker">
                            <input type="text" id="createColorHex" value="#4f46e5" class="form-control color-hex">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Background Color *</label>
                        <div class="color-row">
                            <input type="color" name="bg_color" id="createBg" value="#e0e7ff" class="color-picker">
                            <input type="text" id="createBgHex" value="#e0e7ff" class="form-control color-hex">
                        </div>
                    </div>
                </div>

                {{-- LIVE PREVIEW --}}
                <div class="preview-box">
                    <span class="cell-muted">Preview:</span>
                    <span class="badge" id="createPreview" style="background:#e0e7ff;color:#4f46e5">New Tag</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Tag</button>
            </div>
        </form>
    </div>
</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Tag</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tag Name *</label>
                    <input type="text" name="name" id="editName" class="form-control" required>
                </div>

                <div class="form-row cols-2 mb-16">
                    <div class="form-group mb-0">
                        <label class="form-label">Text Color *</label>
                        <div class="color-row">
                            <input type="color" name="color" id="editColor" class="color-picker">
                            <input type="text" id="editColorHex" class="form-control color-hex">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">Background Color *</label>
                        <div class="color-row">
                            <input type="color" name="bg_color" id="editBg" class="color-picker">
                            <input type="text" id="editBgHex" class="form-control color-hex">
                        </div>
                    </div>
                </div>

                <div class="preview-box">
                    <span class="cell-muted">Preview:</span>
                    <span class="badge" id="editPreview">Tag</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.TagsConfig = {
    hasErrors: @json($errors->any())
};
</script>
<script src="{{ asset('js/tags.js') }}"></script>
@endpush
