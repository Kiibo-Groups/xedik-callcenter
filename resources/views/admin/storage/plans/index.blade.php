@extends('layouts.app')

@section('title', 'Storage Plans')
@section('page-title', 'Storage Plans')
@section('page-subtitle', 'Storage quota plans available for tenants')

@section('content')
@include('admin.storage._nav')

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-box-seam-fill"></i></div>
        <div>
            <div class="stat-label">Total Plans</div>
            <div class="stat-value">{{ $plans->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-gift-fill"></i></div>
        <div>
            <div class="stat-label">Free Plans</div>
            <div class="stat-value">{{ $plans->where('price_monthly', 0)->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-currency-dollar"></i></div>
        <div>
            <div class="stat-label">Paid Plans</div>
            <div class="stat-value">{{ $plans->where('price_monthly', '>', 0)->count() }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="bi bi-hdd-stack-fill"></i></div>
        <div>
            <div class="stat-label">Max Storage</div>
            <div class="stat-value">{{ number_format($plans->max('included_bytes') / (1024**3), 0) }} GB</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-box-seam icon-primary"></i> Storage Plans</span>
        <button class="btn btn-primary" onclick="openModal('createPlanModal')">
            <i class="bi bi-plus-circle-fill"></i> Add Plan
        </button>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Storage</th>
                    <th>Price / mo</th>
                    <th>Currency</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($plans as $plan)
                <tr>
                    <td>
                        <div class="cell-strong">{{ $plan->name }}</div>
                        <div class="cell-muted">{{ $plan->code }}</div>
                    </td>
                    <td>
                        <span class="cell-strong">{{ number_format($plan->included_gb, 0) }} GB</span>
                        <span class="cell-muted"> ({{ number_format($plan->included_bytes) }} bytes)</span>
                    </td>
                    <td>
                        @if($plan->price_monthly == 0)
                            <span class="badge badge-success">Free</span>
                        @else
                            <span class="cell-strong">${{ number_format($plan->price_monthly, 2) }}</span>
                        @endif
                    </td>
                    <td><span class="cell-muted">{{ $plan->currency }}</span></td>
                    <td>
                        <span class="badge {{ $plan->is_active ? 'badge-success' : 'badge-danger' }}">
                            <i class="bi {{ $plan->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $plan->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs edit-plan-btn"
                                data-id="{{ $plan->id }}"
                                data-code="{{ $plan->code }}"
                                data-name="{{ $plan->name }}"
                                data-bytes="{{ $plan->included_bytes }}"
                                data-price="{{ $plan->price_monthly }}"
                                data-currency="{{ $plan->currency }}"
                                data-is-active="{{ $plan->is_active ? '1' : '0' }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-box-seam"></i>
                            <h3>No storage plans yet</h3>
                            <p>Run <code>php artisan db:seed --class=StoragePlanSeeder</code> to load the default plans.</p>
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
        <strong>Quota model:</strong> Every tenant includes a <strong>1 GB base quota</strong> at no cost.
        Paid plans are <em>additive</em> on top of the base.
        Usage warnings fire at 80% · Critical at 95% · Uploads blocked at 100%
        (existing files remain accessible even when over the limit).
    </div>
</div>

{{-- ── CREATE MODAL ── --}}
<div class="modal-overlay" id="createPlanModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-plus-circle-fill modal-icon"></i> Add Storage Plan</div>
            <button class="modal-close" onclick="closeModal('createPlanModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.storage.plans.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Code <span class="required-star">*</span></label>
                        <input type="text" name="code" class="form-control" required maxlength="50" placeholder="extra_20gb">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Currency <span class="required-star">*</span></label>
                        <input type="text" name="currency" class="form-control" value="USD" maxlength="3" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="150" placeholder="Extra 20 GB">
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Storage (bytes) <span class="required-star">*</span></label>
                        <input type="number" name="included_bytes" id="cBytes" class="form-control" required min="1" placeholder="21474836480">
                        <div class="cell-muted" id="cBytesHint" style="margin-top:4px;font-size:12px;"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Price / mo <span class="required-star">*</span></label>
                        <input type="number" name="price_monthly" class="form-control" required min="0" step="0.01" value="0.00">
                    </div>
                </div>
                <div class="check-row">
                    <input type="checkbox" name="is_active" id="cPlanActive" value="1" checked>
                    <label for="cPlanActive">Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createPlanModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Plan</button>
            </div>
        </form>
    </div>
</div>

{{-- ── EDIT MODAL ── --}}
<div class="modal-overlay" id="editPlanModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Storage Plan</div>
            <button class="modal-close" onclick="closeModal('editPlanModal')">&times;</button>
        </div>
        <form method="POST" id="editPlanForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Code</label>
                        <input type="text" id="ePlanCode" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Currency <span class="required-star">*</span></label>
                        <input type="text" name="currency" id="ePlanCurrency" class="form-control" maxlength="3" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Name <span class="required-star">*</span></label>
                    <input type="text" name="name" id="ePlanName" class="form-control" required maxlength="150">
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Storage (bytes) <span class="required-star">*</span></label>
                        <input type="number" name="included_bytes" id="ePlanBytes" class="form-control" required min="1">
                        <div class="cell-muted" id="ePlanBytesHint" style="margin-top:4px;font-size:12px;"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Price / mo <span class="required-star">*</span></label>
                        <input type="number" name="price_monthly" id="ePlanPrice" class="form-control" required min="0" step="0.01">
                    </div>
                </div>
                <div class="check-row">
                    <input type="checkbox" name="is_active" id="ePlanActive" value="1">
                    <label for="ePlanActive">Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editPlanModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
@php $plansUpdateBase = url('admin/storage/plans'); @endphp
<script>
window.StoragePlansConfig = { updateBase: '{{ $plansUpdateBase }}' };
</script>
<script src="{{ asset('js/storage-plans.js') }}"></script>
@endpush
