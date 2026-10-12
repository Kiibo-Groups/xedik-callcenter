<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoragePlan extends Model
{
    protected $fillable = [
        'code', 'name', 'included_bytes',
        'price_monthly', 'currency', 'is_active',
    ];

    protected $casts = [
        'included_bytes' => 'integer',
        'price_monthly'  => 'decimal:2',
        'is_active'      => 'boolean',
    ];

    public function getIncludedGbAttribute(): float
    {
        return round($this->included_bytes / (1024 ** 3), 2);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function subscriptions()
    {
        return $this->hasMany(TenantStorageSubscription::class);
    }
}
