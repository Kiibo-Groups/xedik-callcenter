<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['module', 'name', 'label', 'route', 'icon', 'sort_order'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'permission_tenant')->withPivot('enabled_at');
    }

    /**
     * Permissions that make sense to grant to a tenant. Excludes
     * manage_permissions, which is superadmin-only and has no effect
     * even if granted to a tenant.
     */
    public function scopeGrantable(Builder $query): Builder
    {
        return $query->where('name', '!=', 'manage_permissions');
    }
}