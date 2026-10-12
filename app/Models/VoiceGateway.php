<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceGateway extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'asterisk_endpoint_id',
        'hidden_prefix',
        'country_code',
        'national_number_length',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'national_number_length' => 'integer',
        'is_default'             => 'boolean',
        'is_active'              => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function tenantSettings(): HasMany
    {
        return $this->hasMany(VoiceTenantSetting::class);
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }

    public function isAccessibleByTenant(int $tenantId): bool
    {
        return $this->isGlobal() || $this->tenant_id === $tenantId;
    }

    public function scopeAccessibleBy(\Illuminate\Database\Eloquent\Builder $query, int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        });
    }
}
