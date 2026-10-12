<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'omni_queue_id', 'name', 'body', 'is_locked', 'is_active',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function omniQueue(): BelongsTo { return $this->belongsTo(OmniQueue::class); }
}
