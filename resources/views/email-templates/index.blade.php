@extends('layouts.app')

@section('title', 'Email Templates')
@section('page-title', 'Email Templates')
@section('page-subtitle', 'Manage reusable email templates with auto-fill variables')

@section('header-actions')
    <a href="{{ route('email-templates.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle-fill"></i> Add Template
    </a>
@endsection

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-file-earmark-text-fill"></i></div>
        <div><div class="stat-label">Total Templates</div><div class="stat-value">{{ $templates->count() }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-file-earmark-text icon-primary"></i> Email Templates</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    @if($isSuperadmin)<th>Tenant</th>@endif
                    <th>Name</th>
                    <th>Subject</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $tpl)
                <tr>
                    @if($isSuperadmin)
                    <td><span class="cell-code" style="font-size:11px;">{{ $tpl->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td><div class="cell-strong">{{ $tpl->name }}</div></td>
                    <td><div class="cell-muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $tpl->subject }}</div></td>
                    <td><span class="cell-muted">{{ $tpl->created_at->format('M j, Y') }}</span></td>
                    <td>
                        <div class="table-actions">
                            <a href="{{ route('email-templates.edit', $tpl) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('email-templates.destroy', $tpl) }}"
                                  class="delete-form" data-name="{{ $tpl->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="{{ $isSuperadmin ? 5 : 4 }}" style="text-align:center;padding:32px;color:var(--text-muted);">No templates yet. <a href="{{ route('email-templates.create') }}">Create the first one.</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="tip-box">
    <i class="bi bi-braces tip-icon"></i>
    <div>
        <strong>Available variables:</strong>
        Use the <em>Insert Variable</em> chips in the editor to insert placeholders.
        Variables are replaced with real data when an agent uses the template in the compose modal.
        If a field has no value, <strong>—</strong> is inserted automatically.
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
