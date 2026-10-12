<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\PhoneHelper;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCall extends Model
{
    use BelongsToTenant;

    /** States that indicate a call is still in progress. */
    public const ACTIVE_STATES = ['ringing', 'connecting', 'active', 'held'];

    /** Terminal states — no transition back to active is permitted. */
    public const TERMINAL_STATES = ['ended', 'rejected', 'failed', 'missed', 'busy', 'no_answer', 'congestion', 'unavailable', 'cancelled'];

    public const ALL_STATES = ['ringing', 'connecting', 'active', 'held', 'ended', 'rejected', 'failed', 'missed', 'busy', 'no_answer', 'congestion', 'unavailable', 'cancelled'];

    /** Legal transitions (from → [allowed to]). */
    public const TRANSITIONS = [
        'ringing'    => ['connecting', 'active', 'rejected', 'missed', 'failed', 'busy', 'no_answer', 'congestion', 'unavailable', 'cancelled'],
        'connecting' => ['ringing', 'active', 'rejected', 'failed', 'ended', 'busy', 'no_answer', 'congestion', 'unavailable', 'cancelled'],
        'active'     => ['held', 'ended', 'failed'],
        'held'       => ['active', 'ended', 'failed'],
        // Terminal states have no valid transitions
        'ended'      => [],
        'rejected'   => [],
        'failed'     => [],
        'missed'     => [],
        'busy'       => [],
        'no_answer'  => [],
        'congestion' => [],
        'unavailable'=> [],
        'cancelled'  => [],
    ];

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'voice_session_id',
        'agent_user_id',
        'call_uuid',
        'asterisk_linkedid',
        'asterisk_queue_name',
        'sip_call_id',
        'direction',
        'from_number',
        'to_number',
        'remote_number',
        'remote_display_name',
        'call_state',
        'ringing_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'talk_seconds',
        'hangup_cause',
        'preview_attempt_counted_at',
        'voice_caller_id_id',
        'wrapup_started_at',
        'wrapup_ended_at',
        'wrapup_seconds',
    ];

    protected $casts = [
        'ringing_at'       => 'datetime',
        'answered_at'      => 'datetime',
        'ended_at'         => 'datetime',
        'preview_attempt_counted_at' => 'datetime',
        'wrapup_started_at' => 'datetime',
        'wrapup_ended_at'  => 'datetime',
        'wrapup_seconds'   => 'integer',
        'duration_seconds' => 'integer',
        'talk_seconds'     => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function voiceSession(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function voiceCallerId(): BelongsTo
    {
        return $this->belongsTo(VoiceCallerId::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->call_state, self::TERMINAL_STATES, true);
    }

    public function isActive(): bool
    {
        return in_array($this->call_state, self::ACTIVE_STATES, true);
    }

    public function getAniNormalizedAttribute(): ?string
    {
        return PhoneHelper::normalizeNanpForReporting($this->from_number);
    }

    public function getDnisNormalizedAttribute(): ?string
    {
        return PhoneHelper::normalizeNanpForReporting($this->to_number);
    }

    public function getAniDisplayAttribute(): string
    {
        return PhoneHelper::formatNanpDisplay($this->from_number);
    }

    public function getDnisDisplayAttribute(): string
    {
        return PhoneHelper::formatNanpDisplay($this->to_number);
    }

    /** Check whether a transition to $newState is permitted from the current state. */
    public function canTransitionTo(string $newState): bool
    {
        if ($this->call_state === $newState) {
            return true; // idempotent
        }
        return in_array($newState, self::TRANSITIONS[$this->call_state] ?? [], true);
    }
}
