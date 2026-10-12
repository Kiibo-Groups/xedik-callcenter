<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AgentSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'status', 'pause_reason_id',
        'pending_status_after_wrap_up', 'pending_pause_reason_id',
        'status_since', 'active_emails', 'active_chats', 'last_ping_at'
    ];

    protected $casts = [
        'status_since' => 'datetime',
        'last_ping_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pauseReason()
    {
        return $this->belongsTo(PauseReason::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isOverTime(): bool
    {
        if ($this->status !== 'paused' || !$this->pauseReason?->max_minutes) {
            return false;
        }
        return now()->diffInMinutes($this->status_since) > $this->pauseReason->max_minutes;
    }

    public function getTimerSecondsAttribute(): int
    {
        return (int) now()->diffInSeconds($this->status_since ?? now());
    }
}
