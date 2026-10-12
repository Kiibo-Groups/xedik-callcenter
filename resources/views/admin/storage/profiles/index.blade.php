@extends('layouts.app')

@section('title', 'Managed Storage Profiles')
@section('page-title', 'Managed Storage Profiles')
@section('page-subtitle', 'CRM-managed storage backends')

@section('content')
@include('admin.storage._nav')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-cloud-fill"></i></div>
        <div>
            <div class="stat-label">Total Profiles</div>
            <div class="stat-value">{{ $profiles->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
        <div>
            <div class="stat-label">Active</div>
            <div class="stat-value">{{ $profiles->where('is_active', true)->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-star-fill"></i></div>
        <div>
            <div class="stat-label">Production</div>
            <div class="stat-value">{{ $profiles->where('environment', 'production')->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="bi bi-code-slash"></i></div>
        <div>
            <div class="stat-label">Dev / Staging</div>
            <div class="stat-value">{{ $profiles->whereIn('environment', ['dev','staging'])->count() }}</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-cloud-arrow-up icon-primary"></i> Storage Profiles</span>
        <button class="btn btn-primary" onclick="openModal('createProfileModal')">
            <i class="bi bi-plus-circle-fill"></i> Add Profile
        </button>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Profile</th>
                    <th>Provider</th>
                    <th>Environment</th>
                    <th>Bucket</th>
                    <th>Endpoint</th>
                    <th>Base Path</th>
                    <th>Default</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($profiles as $profile)
                <tr>
                    <td>
                        <div class="cell-strong">{{ $profile->name }}</div>
                        <div class="cell-muted">{{ $profile->code }}</div>
                    </td>
                    <td><span class="badge badge-secondary">{{ $profile->provider_code }}</span></td>
                    <td>
                        <span class="badge {{ $profile->environment === 'production' ? 'badge-danger' : ($profile->environment === 'staging' ? 'badge-warning' : 'badge-secondary') }}">
                            {{ $profile->environment }}
                        </span>
                    </td>
                    <td><code class="cell-code">{{ $profile->bucket }}</code></td>
                    <td>
                        @if($profile->endpoint)
                            <span class="cell-muted" style="font-size:11px;">{{ Str::limit($profile->endpoint, 40) }}</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($profile->base_path)
                            <code class="cell-code">{{ $profile->base_path }}</code>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($profile->is_default)
                            <span class="badge badge-primary"><i class="bi bi-star-fill badge-icon"></i> Default</span>
                        @else
                            <span class="cell-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $profile->is_active ? 'badge-success' : 'badge-danger' }}">
                            <i class="bi {{ $profile->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $profile->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs test-conn-btn"
                                data-id="{{ $profile->id }}"
                                data-url="{{ route('admin.storage.profiles.test-connection', $profile) }}"
                                title="Test Connection">
                                <i class="bi bi-wifi"></i> Test
                            </button>
                            <button class="btn btn-outline btn-xs edit-profile-btn"
                                data-id="{{ $profile->id }}"
                                data-code="{{ $profile->code }}"
                                data-name="{{ $profile->name }}"
                                data-provider="{{ $profile->provider_code }}"
                                data-environment="{{ $profile->environment }}"
                                data-bucket="{{ $profile->bucket }}"
                                data-region="{{ $profile->region }}"
                                data-endpoint="{{ $profile->endpoint }}"
                                data-base-path="{{ $profile->base_path }}"
                                data-is-default="{{ $profile->is_default ? '1' : '0' }}"
                                data-is-active="{{ $profile->is_active ? '1' : '0' }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9">
                        <div class="empty-state">
                            <i class="bi bi-cloud-slash"></i>
                            <h3>No storage profiles yet</h3>
                            <p>Create your first profile or run <code>php artisan db:seed --class=ManagedStorageProfileSeeder</code></p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="tip-box">
    <i class="bi bi-lightbulb tip-icon"></i>
    <div>
        <strong>R2 credentials:</strong> Use the <strong>Access Key ID</strong> and <strong>Secret Access Key</strong> from R2 —
        <em>not</em> the Cloudflare API Token. The endpoint format is
        <code>https://&lt;account_id&gt;.r2.cloudflarestorage.com</code> and the region is always <code>auto</code>.
        Mark one profile as <strong>Default</strong> so it becomes the storage backend for tenants without a custom configuration.
        Credentials are stored encrypted — the secret key is never shown again after saving.
    </div>
</div>

{{-- ── CREATE MODAL ── --}}
<div class="modal-overlay" id="createProfileModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Storage Profile</div>
            <button class="modal-close" onclick="closeModal('createProfileModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.storage.profiles.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Code <span class="required-star">*</span></label>
                        <input type="text" name="code" class="form-control" required maxlength="50" placeholder="r2_prod">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Environment <span class="required-star">*</span></label>
                        <select name="environment" class="form-control" required>
                            <option value="dev">Dev</option>
                            <option value="staging">Staging</option>
                            <option value="production" selected>Production</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="150" placeholder="Cloudflare R2 — Production">
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Provider <span class="required-star">*</span></label>
                        <select name="provider_code" class="form-control" required>
                            @foreach($providers as $p)
                                <option value="{{ $p->code }}" {{ $p->code === 'cloudflare_r2' ? 'selected' : '' }}>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Region</label>
                        <input type="text" name="region" class="form-control" value="auto" maxlength="50">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Bucket <span class="required-star">*</span></label>
                    <input type="text" name="bucket" class="form-control" required maxlength="255" placeholder="my-crm-bucket">
                </div>
                <div class="form-group">
                    <label class="form-label">Endpoint</label>
                    <input type="text" name="endpoint" class="form-control" maxlength="500"
                        placeholder="https://<account_id>.r2.cloudflarestorage.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Base Path <span class="cell-muted">(optional — prefix inside the bucket)</span></label>
                    <input type="text" name="base_path" class="form-control" maxlength="255" placeholder="crm">
                </div>
                <div class="form-group">
                    <label class="form-label">Access Key ID <span class="required-star">*</span></label>
                    <input type="text" name="access_key" class="form-control" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label">Secret Access Key <span class="required-star">*</span></label>
                    <input type="password" name="secret_key" class="form-control" required autocomplete="off">
                </div>
                <div class="form-row cols-2">
                    <div class="check-row">
                        <input type="checkbox" name="is_default" id="cIsDefault" value="1">
                        <label for="cIsDefault">Mark as Default</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="is_active" id="cIsActive" value="1" checked>
                        <label for="cIsActive">Active</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createProfileModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Profile</button>
            </div>
        </form>
    </div>
</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal-overlay" id="editProfileModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Storage Profile</div>
            <button class="modal-close" onclick="closeModal('editProfileModal')">&times;</button>
        </div>
        <form method="POST" id="editProfileForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Code</label>
                        <input type="text" id="eCode" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Environment <span class="required-star">*</span></label>
                        <select name="environment" id="eEnv" class="form-control" required>
                            <option value="dev">Dev</option>
                            <option value="staging">Staging</option>
                            <option value="production">Production</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" id="eName" class="form-control" required maxlength="150">
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Provider <span class="required-star">*</span></label>
                        <select name="provider_code" id="eProvider" class="form-control" required>
                            @foreach($providers as $p)
                                <option value="{{ $p->code }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Region</label>
                        <input type="text" name="region" id="eRegion" class="form-control" maxlength="50">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Bucket <span class="required-star">*</span></label>
                    <input type="text" name="bucket" id="eBucket" class="form-control" required maxlength="255">
                </div>
                <div class="form-group">
                    <label class="form-label">Endpoint</label>
                    <input type="text" name="endpoint" id="eEndpoint" class="form-control" maxlength="500">
                </div>
                <div class="form-group">
                    <label class="form-label">Base Path</label>
                    <input type="text" name="base_path" id="eBasePath" class="form-control" maxlength="255">
                </div>
                <div class="form-group">
                    <label class="form-label">Access Key ID <span class="cell-muted">(leave blank to keep current)</span></label>
                    <input type="text" name="access_key" id="eAccessKey" class="form-control" autocomplete="off" placeholder="••••••••••••••••">
                </div>
                <div class="form-group">
                    <label class="form-label">Secret Access Key <span class="cell-muted">(leave blank to keep current)</span></label>
                    <input type="password" name="secret_key" id="eSecretKey" class="form-control" autocomplete="off" placeholder="••••••••••••••••">
                </div>
                <div class="form-row cols-2">
                    <div class="check-row">
                        <input type="checkbox" name="is_default" id="eIsDefault" value="1">
                        <label for="eIsDefault">Mark as Default</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="is_active" id="eIsActive" value="1">
                        <label for="eIsActive">Active</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editProfileModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
@php $updateBase = url('admin/storage/profiles'); @endphp
<script>
window.StorageProfilesConfig = {
    csrf:       '{{ csrf_token() }}',
    updateBase: '{{ $updateBase }}'
};
</script>
<script src="{{ asset('js/storage-profiles.js') }}"></script>
@endpush
