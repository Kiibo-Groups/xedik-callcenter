<?php
declare(strict_types=1);

namespace App\Traits;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                if (request()->filled('tenant_id')) {
                    $model->tenant_id = (int) request('tenant_id');
                } elseif (!empty($model->campaign_id)) {
                    $camp = \App\Models\Campaign::withoutGlobalScopes()->find($model->campaign_id);
                    if ($camp?->tenant_id) {
                        $model->tenant_id = (int) $camp->tenant_id;
                    }
                } elseif (auth()->check() && !auth()->user()->is_superadmin && auth()->user()->tenant_id) {
                    $model->tenant_id = (int) auth()->user()->tenant_id;
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
