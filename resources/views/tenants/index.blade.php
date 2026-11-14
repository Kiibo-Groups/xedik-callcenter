@extends('layouts.app')

@section('title', 'Tenants')
@section('page-title', 'Tenant Management')
@section('page-subtitle', $tenants->count() . ' tenants registered')

@section('header-actions')
    <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <i class="bi bi-plus-lg"></i> Add Tenant
    </button>
@endsection

@section('content')

{{-- STATS --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="bi bi-building"></i></div>
        <div><div class="stat-label">Total Tenants</div><div class="stat-value">{{ $tenants->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="bi bi-building-check"></i></div>
        <div><div class="stat-label">Active</div><div class="stat-value">{{ $tenants->where('is_active', true)->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="bi bi-shop"></i></div>
        <div><div class="stat-label">Restaurantes / Pedidos</div><div class="stat-value">{{ $tenants->filter(fn($t) => $t->isRestaurant())->count() }}</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="bi bi-people-fill"></i></div>
        <div><div class="stat-label">Total Users</div><div class="stat-value">{{ $tenants->sum('users_count') }}</div></div>
    </div>
</div>

{{-- TABLE --}}
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-building icon-primary"></i> Tenants</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Slug</th>
                    <th>Tipo de Giro</th>
                    <th>Status</th>
                    <th>Users</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($tenants as $tenant)
                <tr>
                    <td><span class="cell-strong">{{ $tenant->name }}</span></td>
                    <td><code class="cell-code">{{ $tenant->slug }}</code></td>
                    <td>
                        @if($tenant->isRestaurant())
                            <span class="badge" style="background:#ffedd5;color:#c2410c;display:inline-flex;align-items:center;gap:5px;">
                                <i class="bi bi-shop"></i> Restaurante / Pedidos
                            </span>
                            @if($tenant->kitchenWhatsappPhone())
                                <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">
                                    <i class="bi bi-whatsapp" style="color:#16a34a;"></i> Cocina: <code>{{ $tenant->kitchenWhatsappPhone() }}</code>
                                </div>
                            @endif
                        @else
                            <span class="badge" style="background:#e0e7ff;color:#4338ca;display:inline-flex;align-items:center;gap:5px;">
                                <i class="bi bi-headset"></i> Call Center / B2B
                            </span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $tenant->is_active ? 'badge-success' : 'badge-danger' }}">
                            <i class="bi {{ $tenant->is_active ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }} badge-icon"></i>
                            {{ $tenant->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td><span class="cell-strong">{{ $tenant->users_count }}</span></td>
                    <td class="cell-muted">{{ $tenant->created_at->format('M d, Y') }}</td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-outline btn-xs edit-tenant-btn"
                                data-id="{{ $tenant->id }}"
                                data-name="{{ $tenant->name }}"
                                data-slug="{{ $tenant->slug }}"
                                data-business-type="{{ $tenant->businessType() }}"
                                data-kitchen-phone="{{ $tenant->kitchenWhatsappPhone() ?? '' }}"
                                data-delivery-fee="{{ $tenant->deliveryFee() ?? '' }}"
                                data-estimated-time="{{ $tenant->estimatedDeliveryTime() ?? '' }}"
                                data-is-active="{{ $tenant->is_active ? '1' : '0' }}"
                                data-users-count="{{ $tenant->users_count }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <a href="{{ route('tenants.permissions', $tenant) }}" class="btn btn-outline btn-xs">
                                <i class="bi bi-shield-lock"></i> Permissions
                            </a>
                            @if($tenant->users_count === 0)
                            <form method="POST" action="{{ route('tenants.destroy', $tenant) }}"
                                  class="delete-form" data-name="{{ $tenant->name }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-xs">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @else
                            <button class="btn btn-xs btn-disabled" disabled title="{{ $tenant->users_count }} users assigned">
                                <i class="bi bi-trash"></i>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">
                        <i class="bi bi-building" style="font-size:24px;display:block;margin-bottom:8px"></i>
                        No tenants yet. Create your first tenant.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- CREATE MODAL --}}
<div class="modal-overlay" id="createModal">
    <div class="modal" style="max-width:620px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-building modal-icon"></i> Add New Tenant</div>
            <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
        </div>
        <form method="POST" action="{{ route('tenants.store') }}" id="createTenantForm">
            @csrf
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Tenant Name <span class="required-star">*</span></label>
                        <input type="text" name="name" id="createName" class="form-control" required
                               value="{{ old('name') }}" placeholder="Ej. Tizón 317 Pizzería">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Slug <span class="required-star">*</span></label>
                        <input type="text" name="slug" id="createSlug" class="form-control" required
                               value="{{ old('slug') }}" placeholder="tizon317"
                               pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Tipo de Giro / Negocio <span class="required-star">*</span></label>
                    <select name="business_type" id="createBusinessType" class="form-control" onchange="syncTenantBusinessType('create', this.value)">
                        <option value="call_center" {{ old('business_type') === 'call_center' ? 'selected' : '' }}>📞 Call Center / Ventas B2B (Leads, Pipeline, Marcador Predictivo)</option>
                        <option value="restaurant" {{ old('business_type') === 'restaurant' ? 'selected' : '' }}>🍕 Restaurante / Toma de Pedidos (WhatsApp + Voz IA + Comandas)</option>
                    </select>
                </div>

                <div id="createRestaurantSettings" style="display:none;padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;margin-bottom:14px;">
                    <div style="font-weight:700;font-size:13px;color:#c2410c;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                        <i class="bi bi-shop"></i> Configuración Operativa del Restaurante
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp Privado de Cocina / Sucursal</label>
                        <input type="text" name="kitchen_whatsapp_phone" id="createKitchenPhone" class="form-control" placeholder="Ej. 5218112345678" value="{{ old('kitchen_whatsapp_phone') }}">
                        <div class="form-hint">A este número llegarán automáticamente las comandas/pedidos tomados por Voz o WhatsApp.</div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="form-group mb-0">
                            <label class="form-label">Costo de Envío ($)</label>
                            <input type="text" name="delivery_fee" id="createDeliveryFee" class="form-control" placeholder="Ej. $40.00 MXN" value="{{ old('delivery_fee', '$40.00') }}">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">Tiempo Estimado de Entrega</label>
                            <input type="text" name="estimated_time" id="createEstimatedTime" class="form-control" placeholder="Ej. 35 a 45 min" value="{{ old('estimated_time', '35 a 45 min') }}">
                        </div>
                    </div>
                </div>

                <div class="form-group mb-0">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <label class="form-label mb-0">Permissions granted to this tenant</label>
                        <div style="display:flex;gap:6px;">
                            <button type="button" class="btn btn-outline btn-xs" onclick="applyTenantPermPreset('restaurant')" style="color:#c2410c;border-color:#fdba74;">🍕 Preset Restaurante</button>
                            <button type="button" class="btn btn-outline btn-xs" onclick="applyTenantPermPreset('call_center')" style="color:#4338ca;border-color:#a5b4fc;">📞 Preset Call Center</button>
                        </div>
                    </div>
                    <div class="form-hint" style="margin-bottom:8px;">Selecciona los módulos habilitados para este Tenant (cambia automáticamente según el giro elegido arriba).</div>
                    @foreach($permissions as $module => $perms)
                    <div style="margin-bottom:10px;">
                        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
                            {{ ucfirst($module) }}
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            @foreach($perms as $perm)
                            <label style="display:flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:12px;">
                                <input type="checkbox" name="permission_ids[]" value="{{ $perm->id }}" data-perm-name="{{ $perm->name }}" class="js-create-perm">
                                <i class="bi {{ $perm->icon }}"></i> {{ $perm->label }}
                            </label>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Create Tenant</button>
            </div>
        </form>
    </div>
</div>

{{-- EDIT MODAL --}}
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:540px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil modal-icon"></i> Edit Tenant</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            @csrf @method('PUT')
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Tenant Name <span class="required-star">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Slug <span class="required-star">*</span></label>
                        <input type="text" name="slug" id="editSlug" class="form-control" required
                               pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Tipo de Giro / Negocio <span class="required-star">*</span></label>
                    <select name="business_type" id="editBusinessType" class="form-control" onchange="syncTenantBusinessType('edit', this.value)">
                        <option value="call_center">📞 Call Center / Ventas B2B</option>
                        <option value="restaurant">🍕 Restaurante / Toma de Pedidos (WhatsApp + Voz IA)</option>
                    </select>
                </div>

                <div id="editRestaurantSettings" style="display:none;padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;margin-bottom:14px;">
                    <div style="font-weight:700;font-size:13px;color:#c2410c;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                        <i class="bi bi-shop"></i> Configuración Operativa del Restaurante
                    </div>
                    <div class="form-group">
                        <label class="form-label">WhatsApp Privado de Cocina / Sucursal</label>
                        <input type="text" name="kitchen_whatsapp_phone" id="editKitchenPhone" class="form-control" placeholder="Ej. 5218112345678">
                        <div class="form-hint">A este número llegarán automáticamente las comandas/pedidos confirmados.</div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="form-group mb-0">
                            <label class="form-label">Costo de Envío ($)</label>
                            <input type="text" name="delivery_fee" id="editDeliveryFee" class="form-control" placeholder="Ej. $40.00 MXN">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label">Tiempo Estimado de Entrega</label>
                            <input type="text" name="estimated_time" id="editEstimatedTime" class="form-control" placeholder="Ej. 35 a 45 min">
                        </div>
                    </div>
                </div>

                <div class="form-group mb-0">
                    <div class="check-row">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="editIsActive" value="1" class="form-check">
                        <label for="editIsActive" class="form-label mb-0">Active</label>
                    </div>
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
window.TenantsConfig = {
    hasErrors:    @json($errors->any()),
    isEditMethod: @json(old('_method') === 'PUT'),
    old: {
        name:     @json(old('name', '')),
        slug:     @json(old('slug', '')),
        isActive: @json(old('is_active', '1')),
    }
};

const RESTAURANT_PERMS = [
    'view_console',
    'view_all_leads',
    'view_my_leads',
    'manage_whatsapp_accounts',
    'manage_whatsapp_templates',
    'manage_social_accounts',
    'manage_sms_channels',
    'manage_sms_templates',
    'manage_conversation_automation',
    'manage_marketing_campaigns',
    'manage_lead_lists',
    'manage_scripts',
    'manage_omni_queues',
    'manage_users',
    'view_calls_report',
    'view_conversations'
];

function applyTenantPermPreset(type) {
    document.querySelectorAll('.js-create-perm').forEach(cb => {
        if (type === 'call_center') {
            cb.checked = true;
        } else {
            cb.checked = RESTAURANT_PERMS.includes(cb.dataset.permName);
        }
    });
}

function syncTenantBusinessType(prefix, value) {
    const box = document.getElementById(prefix + 'RestaurantSettings');
    if (box) {
        box.style.display = (value === 'restaurant') ? '' : 'none';
    }
    if (prefix === 'create') {
        applyTenantPermPreset(value);
    }
}
</script>
<script src="{{ asset('js/tenants.js') }}"></script>
@endpush
