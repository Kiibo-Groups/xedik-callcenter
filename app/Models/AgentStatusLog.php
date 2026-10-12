<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AgentStatusLog extends Model
{
    use BelongsToTenant;

    /** Selected/requested by the agent — mutually exclusive with EFFECTIVE_STATUSES. */
    public const REQUESTED_STATUSES = ['ready', 'paused', 'offline'];

    /** Voice occupancy states derived from actual work, layered on top of the requested status. */
    public const EFFECTIVE_STATUSES = ['ringing', 'in_call', 'wrap_up'];

    public const ALL_STATUSES = [...self::REQUESTED_STATUSES, ...self::EFFECTIVE_STATUSES];

    protected $fillable = [
        'tenant_id', 'user_id', 'status', 'pause_reason_id',
        'voice_call_id', 'source',
        'started_at', 'ended_at', 'duration_seconds',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pauseReason()
    {
        return $this->belongsTo(PauseReason::class);
    }

    public function voiceCall()
    {
        return $this->belongsTo(VoiceCall::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('ended_at');
    }

    public function getDurationFormattedAttribute(): string
    {
        $seconds = $this->duration_seconds ?? 0;
        return sprintf('%02d:%02d:%02d',
            floor($seconds / 3600),
            floor(($seconds % 3600) / 60),
            $seconds % 60
        );
    }

    /**
     * Canonical display label for a status value — the single place reports/UI
     * should map status -> label, so `in_call`/`wrap_up` never render as the
     * raw `ucfirst()` (`In_call`/`Wrap_up`) and never get silently merged into
     * an unrelated bucket (e.g. counted as "Paused").
     */
    public static function displayLabel(string $status): string
    {
        return match ($status) {
            'in_call' => 'In Call',
            'wrap_up' => 'Wrap-Up',
            default   => ucfirst($status),
        };
    }
}