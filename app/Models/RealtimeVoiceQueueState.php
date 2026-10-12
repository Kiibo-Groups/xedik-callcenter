<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealtimeVoiceQueueState extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'pbx_id',
        'omni_queue_id',
        'external_queue_name',
        'calls_waiting',
        'hold_time',
        'talk_time',
        'completed',
        'abandoned',
        'service_level',
        'service_level_perf',
        'weight',
        'available_agents',
        'busy_agents',
        'paused_agents',
        'unavailable_agents',
        'longest_wait_seconds',
        'last_seen_at',
        'payload',
    ];

    protected $casts = [
        'pbx_id' => 'integer',
        'calls_waiting' => 'integer',
        'hold_time' => 'integer',
        'talk_time' => 'integer',
        'completed' => 'integer',
        'abandoned' => 'integer',
        'service_level' => 'integer',
        'service_level_perf' => 'decimal:2',
        'weight' => 'integer',
        'available_agents' => 'integer',
        'busy_agents' => 'integer',
        'paused_agents' => 'integer',
        'unavailable_agents' => 'integer',
        'longest_wait_seconds' => 'integer',
        'last_seen_at' => 'datetime',
        'payload' => 'array',
    ];

    public function omniQueue(): BelongsTo
    {
        return $this->belongsTo(OmniQueue::class);
    }
}
