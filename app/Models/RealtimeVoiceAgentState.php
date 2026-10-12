<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealtimeVoiceAgentState extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'pbx_id',
        'user_id',
        'omni_queue_id',
        'external_queue_name',
        'member_name',
        'extension',
        'interface',
        'state_interface',
        'status_code',
        'in_call',
        'paused',
        'pause_reason',
        'connection_state',
        'voice_state',
        'device_state',
        'state_since',
        'connected_since',
        'calls_taken',
        'last_call_at',
        'last_pause_at',
        'login_time_at',
        'last_seen_at',
        'payload',
    ];

    protected $casts = [
        'pbx_id' => 'integer',
        'status_code' => 'integer',
        'in_call' => 'boolean',
        'paused' => 'boolean',
        'calls_taken' => 'integer',
        'state_since' => 'datetime',
        'connected_since' => 'datetime',
        'last_call_at' => 'datetime',
        'last_pause_at' => 'datetime',
        'login_time_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function omniQueue(): BelongsTo
    {
        return $this->belongsTo(OmniQueue::class);
    }
}
