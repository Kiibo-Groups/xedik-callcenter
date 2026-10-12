@extends('layouts.app')

@section('title', 'Omni Queues')
@section('page-title', 'Omni Queues')
@section('page-subtitle', 'Manage omnichannel queues and agent assignments')

@section('header-actions')
<a href="{{ route('omni-queues.create') }}" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> New Queue
</a>
@endsection

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-diagram-3-fill icon-primary"></i> Omni Queues</span>
    </div>
    <div class="table-wrapper">
        @if($queues->count())
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Assignment</th>
                    <th>Agents</th>
                    <th>Accounts</th>
                    <th>Max Wait</th>
                    <th>Status</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($queues as $queue)
                @php
                $typeColors = [
                'email' => 'background:#dbeafe;color:#1d4ed8;',
                'chat' => 'background:#dcfce7;color:#166534;',
                'omni' => 'background:#ede9fe;color:#6d28d9;',
                ];
                @endphp
                <tr>
                    <td class="cell-strong">{{ $queue->name }}</td>
                    <td>
                        <span class="badge" style="{{ $typeColors[$queue->type] ?? 'background:#f1f5f9;color:#64748b;' }}">
                            {{ ucfirst($queue->type) }}
                        </span>
                    </td>
                    <td class="cell-muted">
                        {{ ucfirst(str_replace('_', ' ', $queue->assignment_mode)) }}
                    </td>
                    <td>{{ $queue->agents_count }}</td>
                    <td>{{ $queue->email_accounts_count }}</td>
                    <td class="cell-muted">{{ $queue->max_wait_minutes }} min</td>
                    <td>
                        @if($queue->active)
                        <span class="badge badge-success">Active</span>
                        @else
                        <span class="badge badge-secondary">Inactive</span>
                        @endif
                    </td>
                    @if($isSuperAdmin)
                    <td><span class="cell-strong">{{ $queue->tenant?->name ?? '—' }}</span></td>
                    @endif
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <a href="{{ route('omni-queues.edit', $queue) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('omni-queues.destroy', $queue) }}"
                                style="display:inline"
                                class="delete-form" data-entity="queue" data-name="{{ $queue->name }}">
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
            <i class="bi bi-diagram-3"></i>
            <h3>No queues yet</h3>
            <p>Create the first queue to start routing conversations.</p>
        </div>
        @endif
    </div>

    @if($queues->hasPages())
    <div style="padding:16px 20px;border-top:1px solid var(--border);">
        {{ $queues->links('vendor.pagination.custom') }}
    </div>
    @endif
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush