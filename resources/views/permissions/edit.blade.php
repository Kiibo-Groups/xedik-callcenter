@extends('layouts.app')
@section('title', 'Edit Permission')
@section('page-title', 'Edit Permission')
@section('page-subtitle', 'Update permission details')

@section('content')
<div class="card" style="max-width:600px;">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-key" style="color:var(--primary);margin-right:6px;"></i> Edit Permission</div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('permissions.update', $permission) }}">
            @csrf @method('PUT')
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Module <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="module" class="form-control" value="{{ old('module', $permission->module) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Name (slug)</label>
                    <input type="text" class="form-control" value="{{ $permission->name }}" disabled>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Cannot be changed</div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Label <span style="color:#dc2626;">*</span></label>
                <input type="text" name="label" class="form-control" value="{{ old('label', $permission->label) }}">
            </div>
            <div class="form-group">
                <label class="form-label">Route <span style="color:#dc2626;">*</span></label>
                <input type="text" name="route" class="form-control" value="{{ old('route', $permission->route) }}">
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Icon</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" name="icon" id="iconInput" class="form-control" 
                            value="{{ old('icon', $permission->icon ?? 'bi-circle') }}" readonly
                            style="cursor:pointer;" onclick="openIconPicker()">
                        <div id="iconPreview" style="width:38px;height:38px;border:1px solid var(--border);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi {{ old('icon', $permission->icon ?? 'bi-circle') }}" style="font-size:18px;color:var(--primary);"></i>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $permission->sort_order) }}">
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check"></i> Save Changes</button>
                <a href="{{ route('permissions.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
{{-- Icon Picker Modal --}}
<div class="modal-overlay" id="iconPickerModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-grid" style="color:var(--primary);margin-right:6px;"></i> Select Icon</div>
            <button class="modal-close" type="button" onclick="closeIconPicker()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="text" id="iconSearch" placeholder="Search icons..." 
                class="form-control" style="margin-bottom:12px;"
                oninput="filterIcons()">
            <div id="iconGrid" style="display:grid;grid-template-columns:repeat(8,1fr);gap:8px;max-height:400px;overflow-y:auto;">
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/permissions-form.js') }}"></script>
@endpush