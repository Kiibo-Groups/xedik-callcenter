<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelephonyCall extends Model
{
    use BelongsToTenant;

    public const TERMINAL_EVENTS = [
        'COMPLETEAGENT',
        'COMPLETECALLER',
        'ABANDON',
        'EXITWITHTIMEOUT',
        'EXITEMPTY',
        'EXITWITHKEY',
        'TRANSFER',
    ];

    protected $fillable = [
        'pbx_id',
        'tenant_id',
        'user_id',
        'linkedid',
        'conversation_id',
        'call_uuid',
        'direction',
        'call_mode',
        'did',
        'queue_name',
        'caller_number',
        'dial_prefix',
        'agent',
        'started_at',
        'entered_queue_at',
        'answered_at',
        'ended_at',
        'exit_reason',
        'hangup_cause',
        'disposition',
        'wrapup_started_at',
        'wrapup_ended_at',
        'wrapup_seconds',
        'wait_seconds',
        'ring_seconds',
        'talk_seconds',
        'call_duration_seconds',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'entered_queue_at' => 'datetime',
        'user_id' => 'integer',
        'conversation_id' => 'integer',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'wrapup_started_at' => 'datetime',
        'wrapup_ended_at' => 'datetime',
        'wrapup_seconds' => 'integer',
        'wait_seconds' => 'integer',
        'ring_seconds' => 'integer',
        'talk_seconds' => 'integer',
        'call_duration_seconds' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
