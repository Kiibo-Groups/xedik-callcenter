<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantStorageUsageSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'used_bytes', 'allowed_bytes',
        'usage_percent', 'active_files_count', 'snapshot_date',
    ];

    protected $casts = [
        'used_bytes'         => 'integer',
        'allowed_bytes'      => 'integer',
        'usage_percent'      => 'decimal:2',
        'active_files_count' => 'integer',
        'snapshot_date'      => 'date',
        'created_at'         => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
