@extends('layouts.app')

@section('title', 'Dispositions')
@section('page-title', 'Dispositions')
@section('page-subtitle', 'Manage dispositions — set colors and control which ones create leads from the dialer')

@section('content')

<div class="tip-box" style="margin-bottom:20px">
    <i class="bi bi-info-circle-fill tip-icon"></i>
    <div style="font-size:13px;color:var(--text-mid);line-height:1.7">
        <strong style="color:var(--text-strong)">How it works:</strong>
        Each disposition maps to an agent action in the console. Set a <strong>color</strong> for each one — that color shows in the call history badge.
        Toggle <strong>Creates Lead</strong> ON for dispositions that should automatically create a new lead in the CRM when the contact doesn't exist yet.
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:24px">
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-person-plus-fill"></i></div>
        <div><div class="stat-label">Creates Lead</div><div class="stat-value">{{ $dispositions->where('is_active',true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-list-check"></i></div>
        <div><div class="stat-label">Total Dispositions</div><div class="stat-value">{{ $dispositions->count() }}</div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-funnel-fill" style="color:var(--primary)"></i> Dispositions</span>
        <button class="btn btn-primary btn-sm" onclick="openModal('addModal')">
            <i class="bi bi-plus-circle-fill"></i> Add Disposition
        </button>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Badge Preview</th>
                    <th style="text-align:center">Creates Lead</th>
                    <th style="text-align:center">Requires Scheduled Time</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($dispositions as $disp)
                <tr>
                    <td class="cell-strong">{{ $disp->name }}</td>
                    <td class="cell-muted" style="font-size:13px;">{{ $disp->description ?: '—' }}</td>
                    @if($isSuperAdmin)
                    <td>
                        @if($disp->tenant)
                            <span class="badge" style="background:var(--primary-light,#e0e7ff);color:var(--primary);">
                                {{ $disp->tenant->name }}
                            </span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    @endif
                    <td>
                        <span class="badge" style="color:{{ $disp->color }};background:{{ $disp->bg_color }}">
                            {{ $disp->name }}
                        </span>
                    </td>
                    <td style="text-align:center">
                        <label style="position:relative;display:inline-block;width:44px;height:24px;cursor:pointer">
                            <input type="checkbox" class="toggle-disp" data-id="{{ $disp->id }}"
                                   {{ $disp->is_active ? 'checked' : '' }}
                                   style="opacity:0;width:0;height:0">
                            <span id="track-{{ $disp->id }}" style="
                                position:absolute;inset:0;border-radius:99px;
                                background:{{ $disp->is_active ? '#22c55e' : '#cbd5e1' }};
                                transition:background .2s"></span>
                            <span id="thumb-{{ $disp->id }}" style="
                                position:absolute;top:3px;left:{{ $disp->is_active ? '23px' : '3px' }};
                                width:18px;height:18px;border-radius:50%;background:white;
                                transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)"></span>
                        </label>
                    </td>
                    <td style="text-align:center">
                        <span class="badge" style="background:{{ $disp->requires_callback_at ? '#dbeafe' : '#f3f4f6' }};color:{{ $disp->requires_callback_at ? '#1e40af' : '#64748b' }};">
                            {{ $disp->requires_callback_at ? 'Yes' : 'No' }}
                        </span>
                    </td>
                    <td class="table-actions">
                        <button class="btn btn-outline btn-xs edit-btn"
                            data-id="{{ $disp->id }}"
                            data-name="{{ $disp->name }}"
                            data-description="{{ $disp->description ?? '' }}"
                            data-requires-callback-at="{{ $disp->requires_callback_at ? '1' : '0' }}"
                            data-color="{{ $disp->color }}"
                            data-bg-color="{{ $disp->bg_color }}">
                            <i class="bi bi-palette"></i> Edit
                        </button>
                        <form method="POST" action="{{ route('dialer.destroy', $disp) }}"
                              class="delete-form" data-name="{{ $disp->name }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-xs">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- EDIT COLORS MODAL --}}
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil-fill" style="color:var(--primary);margin-right:6px"></i> Edit Disposition</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">

                <div class="form-group">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" id="editName" class="form-control" required maxlength="100">
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" id="editDescription" class="form-control" maxlength="255" placeholder="Optional description">
                </div>

                <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                    <input type="checkbox" name="requires_callback_at" id="editRequiresCallbackAt" value="1">
                    <span>Requires scheduled date/time</span>
                </label>

                <div style="text-align:center;margin-bottom:20px">
                    <span class="badge" id="badgePreview" style="font-size:14px;padding:6px 16px">Preview</span>
                </div>

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Text Color</label>
                        <div class="color-row">
                            <input type="color" id="colorPicker" value="#ffffff" class="color-picker">
                            <input type="text" name="color" id="colorHex" class="form-control color-hex" value="#ffffff" maxlength="7" placeholder="#ffffff">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Background Color</label>
                        <div class="color-row">
                            <input type="color" id="bgColorPicker" value="#64748b" class="color-picker">
                            <input type="text" name="bg_color" id="bgColorHex" class="form-control color-hex" value="#64748b" maxlength="7" placeholder="#64748b">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="form-label">Quick Presets</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        @foreach([
                            ['label'=>'Green',  'color'=>'#ffffff', 'bg'=>'#16a34a'],
                            ['label'=>'Red',    'color'=>'#ffffff', 'bg'=>'#dc2626'],
                            ['label'=>'Yellow', 'color'=>'#92400e', 'bg'=>'#fef9c3'],
                            ['label'=>'Blue',   'color'=>'#1e40af', 'bg'=>'#dbeafe'],
                            ['label'=>'Purple', 'color'=>'#4c1d95', 'bg'=>'#ede9fe'],
                            ['label'=>'Gray',   'color'=>'#374151', 'bg'=>'#f3f4f6'],
                            ['label'=>'Orange', 'color'=>'#ffffff', 'bg'=>'#ea580c'],
                        ] as $preset)
                        <button type="button" class="preset-btn"
                            data-color="{{ $preset['color'] }}" data-bg="{{ $preset['bg'] }}"
                            style="padding:4px 12px;border-radius:99px;border:none;cursor:pointer;font-size:12px;font-weight:600;color:{{ $preset['color'] }};background:{{ $preset['bg'] }}">
                            {{ $preset['label'] }}
                        </button>
                        @endforeach
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

{{-- ADD DISPOSITION MODAL --}}
<div class="modal-overlay" id="addModal">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill" style="color:var(--primary);margin-right:6px"></i> Add Disposition</div>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('dialer.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Disposition Name <span class="required-star">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Customer, Callback, No Answer">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" maxlength="255" placeholder="Optional description">
                </div>
                <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-top:16px;">
                    <input type="checkbox" name="requires_callback_at" value="1">
                    <span>Requires scheduled date/time</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Add</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.DispositionsConfig = {
    updateBase: '{{ url("dialer/dispositions") }}',
};
</script>
<script src="{{ asset('js/dispositions.js') }}"></script>
@endpush
