@extends('layouts.app')

@section('title', 'Do Not Call')
@section('page-title', 'Do Not Call')
@section('page-subtitle', $dncs->total() . ' numbers excluded from automated dialing')

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-telephone-x icon-primary"></i> Do Not Call List</span>
        <button class="btn btn-primary" onclick="openModal('createModal')">
            <i class="bi bi-plus-circle-fill"></i> Add Number
        </button>
    </div>
    <div class="table-wrapper">
        @if($dncs->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>Phone</th>
                    <th>Reason</th>
                    <th>Added By</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($dncs as $dnc)
                <tr>
                    <td><code class="cell-code">{{ $dnc->phone }}</code></td>
                    <td>{{ $dnc->reason ?? '—' }}</td>
                    <td class="cell-muted">{{ $dnc->addedBy?->name ?? '—' }}</td>
                    <td class="cell-muted">{{ $dnc->created_at->format('m/d/Y H:i') }}</td>
                    <td>
                        <div class="table-actions">
                            <form method="POST" action="{{ route('dialer-dnc.destroy', $dnc) }}" class="delete-form" data-name="{{ $dnc->phone }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($dncs->hasPages())
        <div style="padding:16px 20px;border-top:1px solid var(--border);">
            {{ $dncs->links('vendor.pagination.custom') }}
        </div>
        @endif
        @else
        <div class="empty-state">
            <i class="bi bi-telephone-x"></i>
            <h3>DNC list is empty</h3>
            <p>Numbers added here will be skipped in all future dialing runs and CSV imports.</p>
        </div>
        @endif
    </div>
</div>

{{-- Add Number Modal --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-telephone-x modal-icon"></i> Add to Do Not Call</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('dialer-dnc.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Phone *</label>
                    <input type="text" name="phone" class="form-control" placeholder="10 digits" required autofocus value="{{ old('phone') }}">
                    @error('phone')
                        <div class="form-hint" style="color:var(--danger);">{{ $message }}</div>
                    @enderror
                    <div class="form-hint">Only digits are stored. This number will be skipped in all future dialing runs and imports.</div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Reason</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g. Requested no contact" value="{{ old('reason') }}">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Add to DNC</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
<script>
function openModal(id)  { document.getElementById(id)?.classList.add('active'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('active'); }
@if($errors->any()) openModal('createModal'); @endif
</script>
@endpush
