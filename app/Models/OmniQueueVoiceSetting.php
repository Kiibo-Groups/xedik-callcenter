<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmniQueueVoiceSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'omni_queue_id',
        'is_enabled',
        'asterisk_queue_name',
        'strategy',
        'timeout',
        'retry',
        'wrapup_time',
        'maxlen',
        'music_on_hold',
        'service_level',
        'weight',
        'ring_in_use',
        'autopause',
        'sync_status',
        'sync_error',
        'last_synced_at',
    ];

    protected $casts = [
        'is_enabled'    => 'boolean',
        'timeout'       => 'integer',
        'retry'         => 'integer',
        'wrapup_time'   => 'integer',
        'maxlen'        => 'integer',
        'service_level' => 'integer',
        'weight'        => 'integer',
        'ring_in_use'   => 'boolean',
        'autopause'     => 'boolean',
        'last_synced_at'=> 'datetime',
    ];

    public function omniQueue(): BelongsTo
    {
        return $this->belongsTo(OmniQueue::class);
    }
}
