@extends('layouts.app')

@section('title', 'Social Network')
@section('page-title', 'Social Network')
@section('page-subtitle', 'Connect Facebook and Instagram accounts to the agent console')

@section('header-actions')
<button class="btn btn-primary btn-sm" onclick="openModal('connectModal')">
    <i class="bi bi-plus-lg"></i> Connect Account
</button>
@endsection

@push('styles')
<style>
.sn-platform        { display: inline-flex; align-items: center; gap: 8px; }
.sn-platform-icon   { width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; color: #fff; flex-shrink: 0; }
.sn-platform-icon.facebook  { background: #1877F2; }
.sn-platform-icon.instagram { background: linear-gradient(45deg, #F58529, #DD2A7B, #8134AF); }
.sn-platform-icon.linkedin  { background: #0A66C2; }
.sn-account-pic     { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
.sn-choice          { display: flex; align-items: center; gap: 12px; padding: 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; transition: border-color .15s, background .15s; }
.sn-choice:hover    { border-color: var(--primary); background: var(--bg-th); }
.sn-choice input    { accent-color: var(--primary); }
.sn-choice-name     { font-weight: 600; color: var(--text-strong); font-size: 13px; }
.sn-choice-desc     { font-size: 12px; color: var(--text-muted); }
.sn-choices         { display: flex; flex-direction: column; gap: 10px; }
</style>
@endpush

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-share-fill icon-primary"></i> Connected Accounts</span>
    </div>
    <div class="table-wrapper">
        @if($accounts->count())
        <table>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Platform</th>
                    <th>Username</th>
                    <th>Queue</th>
                    @if($isSuperAdmin)<th>Tenant</th>@endif
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts as $account)
                <tr>
                    <td>
                        <div class="sn-platform">
                            @if($account->picture_url)
                                <img src="{{ $account->picture_url }}" alt="" class="sn-account-pic">
                            @else
                                <span class="sn-platform-icon {{ $account->platform }}">
                                    <i class="bi bi-{{ $account->platform === 'facebook' ? 'facebook' : ($account->platform === 'instagram' ? 'instagram' : 'linkedin') }}"></i>
                                </span>
                            @endif
                            <span class="cell-strong">{{ $account->display_name }}</span>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-secondary">
                            <i class="bi bi-{{ $account->platform === 'facebook' ? 'facebook' : ($account->platform === 'instagram' ? 'instagram' : 'linkedin') }} badge-icon"></i>
                            {{ ucfirst($account->platform) }}
                        </span>
                    </td>
                    <td class="cell-code">{{ $account->username ?? '—' }}</td>
                    <td>
                        @if($account->omniQueue)
                            <span class="badge" style="background:#e0e7ff;color:#3730a3;">{{ $account->omniQueue->name }}</span>
                        @else
                            <span class="cell-muted" style="font-style:italic;">—</span>
                        @endif
                    </td>
                    @if($isSuperAdmin)
                    <td class="cell-muted">{{ $account->tenant?->name ?? '—' }}</td>
                    @endif
                    <td>
                        <span class="badge {{ $account->is_active ? 'badge-success' : 'badge-secondary' }} toggle-active"
                              data-account-id="{{ $account->id }}" style="cursor:pointer" title="Click to toggle">
                            {{ $account->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions" style="justify-content:flex-end">
                            <button class="btn btn-outline btn-xs edit-account-btn"
                                data-id="{{ $account->id }}"
                                data-name="{{ $account->display_name }}"
                                data-queue="{{ $account->omni_queue_id }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('social-accounts.destroy', $account) }}"
                                  class="delete-form" data-name="{{ $account->display_name }}" data-entity="social account"
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
            <i class="bi bi-share-fill"></i>
            <h3>No social accounts connected yet</h3>
            <p>Connect a Facebook page or Instagram account to start receiving messages in the console.</p>
        </div>
        @endif
    </div>
</div>

{{-- ── CONNECT ACCOUNT MODAL ── --}}
<div class="modal-overlay" id="connectModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-share-fill modal-icon"></i> Connect Social Account</div>
            <button class="modal-close" onclick="closeModal('connectModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('social-accounts.connect') }}">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Channel <span class="required-star">*</span></label>
                    <div class="sn-choices">
                        <label class="sn-choice">
                            <input type="radio" name="platform" value="facebook" checked>
                            <span class="sn-platform-icon facebook"><i class="bi bi-facebook"></i></span>
                            <span>
                                <span class="sn-choice-name">Facebook Messenger</span><br>
                                <span class="sn-choice-desc">Connect a Facebook page to receive Messenger conversations</span>
                            </span>
                        </label>
                        <label class="sn-choice">
                            <input type="radio" name="platform" value="instagram">
                            <span class="sn-platform-icon instagram"><i class="bi bi-instagram"></i></span>
                            <span>
                                <span class="sn-choice-name">Instagram Direct</span><br>
                                <span class="sn-choice-desc">Connect an Instagram account to receive DMs (must be a Business or Creator account)</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Queue</label>
                    <select name="omni_queue_id" class="form-control">
                        <option value="">— No queue (assign later) —</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->id }}">{{ $queue->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-hint">Conversations from this account will be routed to this queue.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('connectModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-up-right"></i> Continue</button>
            </div>
        </form>
    </div>
</div>

{{-- ── EDIT ACCOUNT MODAL ── --}}
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Account</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Display Name <span class="required-star">*</span></label>
                    <input type="text" name="display_name" id="editDisplayName" class="form-control" required>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Queue</label>
                    <select name="omni_queue_id" id="editQueueId" class="form-control">
                        <option value="">— No queue —</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->id }}">{{ $queue->name }}</option>
                        @endforeach
                    </select>
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
window.SocialAccountsConfig = {
    baseUrl: '{{ url('/social-accounts') }}'
};
</script>
<script src="{{ asset('js/social-accounts.js') }}"></script>
<script src="{{ asset('js/catalog-delete.js') }}"></script>
@endpush
