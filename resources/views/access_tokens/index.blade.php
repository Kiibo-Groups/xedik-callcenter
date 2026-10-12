@extends('layouts.app')

@section('title', 'Access Tokens')
@section('page-title', 'Access Tokens')
@section('page-subtitle', 'Access tokens for external API integrations')

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-key-fill icon-primary"></i> Access Tokens</span>
        <button class="btn btn-primary" onclick="openModal('createModal')">
            <i class="bi bi-plus-circle-fill"></i> New Token
        </button>
    </div>

    <div class="table-wrapper">
        @if($tokens->count())
        <table>
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Token</th>
                    @if($tenants->count())<th>Tenant</th>@endif
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($tokens as $tok)
                <tr>
                    <td><div class="cell-strong">{{ $tok->label }}</div></td>
                    @if($tenants->count())
                    <td class="cell-muted">{{ $tok->tenant?->name ?? '—' }}</td>
                    @endif
                    <td>
                        <span style="font-family:monospace;font-size:12px;color:var(--text-muted);">
                            {{ $tok->maskedToken() }}
                        </span>
                        <button class="btn btn-outline btn-xs" style="margin-left:6px;"
                                onclick="copyToken('{{ $tok->token }}')" title="Copy full token">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </td>
                    <td>
                        @if($tok->is_active)
                            <span class="badge badge-success"><i class="bi bi-check-circle-fill badge-icon"></i> Active</span>
                        @else
                            <span class="badge badge-secondary"><i class="bi bi-slash-circle badge-icon"></i> Revoked</span>
                        @endif
                    </td>
                    <td class="cell-muted">{{ $tok->created_at->format('m/d/Y') }}</td>
                    <td>
                        <div class="table-actions">
                            @if($tok->is_active)
                            <form method="POST" action="{{ route('access-tokens.revoke', $tok) }}">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-outline btn-xs" title="Revoke"
                                        onclick="return confirm('Revoke token \'{{ addslashes($tok->label) }}\'?')">
                                    <i class="bi bi-slash-circle"></i> Revoke
                                </button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('access-tokens.destroy', $tok) }}"
                                  class="delete-form" data-name="{{ $tok->label }}">
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
            <i class="bi bi-key"></i>
            <h3>No tokens yet</h3>
            <p>Generate a token to allow external dialers to open the screen pop.</p>
        </div>
        @endif
    </div>
</div>

{{-- Create modal --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-key-fill modal-icon"></i> New Access Token</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('access-tokens.store') }}">
            @csrf
            <div class="modal-body">
                @if($tenants->count())
                <div class="form-group">
                    <label class="form-label">Tenant <span class="required-star">*</span></label>
                    <select name="tenant_id" class="form-control" required>
                        <option value="">— Select tenant —</option>
                        @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}" {{ old('tenant_id') == $tenant->id ? 'selected' : '' }}>
                            {{ $tenant->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="form-group">
                    <label class="form-label">Label <span class="required-star">*</span></label>
                    <input type="text" name="label" class="form-control"
                           placeholder="e.g. Power Dialer Production" required autofocus
                           value="{{ old('label') }}">
                    <div class="form-hint">Descriptive name so you can identify this token later.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-key"></i> Generate Token</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
window.AccessTokensConfig = {
    hasErrors: @json($errors->any()),
};
</script>
<script src="{{ asset('js/access-tokens.js') }}"></script>
@endpush
