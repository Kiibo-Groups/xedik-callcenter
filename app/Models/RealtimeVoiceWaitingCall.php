<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealtimeVoiceWaitingCall extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'pbx_id',
        'interaction_key',
        'omni_queue_id',
        'external_queue_name',
        'uniqueid',
        'channel',
        'caller_number',
        'caller_name',
        'position',
        'priority',
        'waiting_since',
        'wait_seconds',
        'status',
        'last_seen_at',
        'payload',
    ];

    protected $casts = [
        'pbx_id' => 'integer',
        'position' => 'integer',
        'priority' => 'integer',
        'waiting_since' => 'datetime',
        'wait_seconds' => 'integer',
        'last_seen_at' => 'datetime',
        'payload' => 'array',
    ];

    public function omniQueue(): BelongsTo
    {
        return $this->belongsTo(OmniQueue::class);
    }
}
