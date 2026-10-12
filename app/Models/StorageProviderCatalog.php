<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageProviderCatalog extends Model
{
    protected $table = 'storage_provider_catalog';

    protected $fillable = [
        'code', 'name', 'driver',
        'requires_endpoint', 'requires_region',
        'supports_temporary_urls', 'supports_private_buckets',
        'is_active',
    ];

    protected $casts = [
        'requires_endpoint'        => 'boolean',
        'requires_region'          => 'boolean',
        'supports_temporary_urls'  => 'boolean',
        'supports_private_buckets' => 'boolean',
        'is_active'                => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
