<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceSession extends Model
{
    use BelongsToTenant;

    // ── Call state constants ──────────────────────────────────────────────────

    /** Explicitly no call in progress. Set by the browser when a call ends. */
    public const CALL_STATE_NO_CALL    = 'no_call';

    /** Remote end is ringing (outbound) or we are ringing (inbound). */
    public const CALL_STATE_RINGING    = 'ringing';

    /** SIP INVITE sent, waiting for 200 OK. */
    public const CALL_STATE_CONNECTING = 'connecting';

    /** Call established (ACK sent/received). */
    public const CALL_STATE_ACTIVE     = 'active';

    /** Call is on hold (re-INVITE with sendonly/recvonly). */
    public const CALL_STATE_HELD       = 'held';

    /** BYE sent or transfer in progress — call is being terminated. */
    public const CALL_STATE_ENDING     = 'ending';

    /**
     * Historical sessions pre-dating the call_state column, or sessions
     * where the browser never reported state.  Always treated conservatively
     * (ownership policy = confirm, never silent or blocked).
     */
    public const CALL_STATE_UNKNOWN    = 'unknown';

    /** All valid values the browser may submit. */
    public const ALLOWED_CALL_STATES = [
        self::CALL_STATE_NO_CALL,
        self::CALL_STATE_RINGING,
        self::CALL_STATE_CONNECTING,
        self::CALL_STATE_ACTIVE,
        self::CALL_STATE_HELD,
        self::CALL_STATE_ENDING,
    ];

    /** States that indicate a call is in progress (or about to end). */
    public const ACTIVE_CALL_STATES = [
        self::CALL_STATE_RINGING,
        self::CALL_STATE_CONNECTING,
        self::CALL_STATE_ACTIVE,
        self::CALL_STATE_HELD,
        self::CALL_STATE_ENDING,
    ];

    // ── Eloquent ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'user_id',
        'voice_extension_id',
        'browser_tab_id',
        'session_uuid',
        'status',
        'registered_at',
        'last_seen_at',
        'expires_at',
        'revoked_at',
        'replaced_at',
        'failed_at',
        'failure_reason',
        'ip',
        'user_agent',
        'call_state',
        'call_started_at',
        'call_state_updated_at',
    ];

    protected $casts = [
        'registered_at'          => 'datetime',
        'last_seen_at'           => 'datetime',
        'expires_at'             => 'datetime',
        'revoked_at'             => 'datetime',
        'replaced_at'            => 'datetime',
        'failed_at'              => 'datetime',
        'call_started_at'        => 'datetime',
        'call_state_updated_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voiceExtension(): BelongsTo
    {
        return $this->belongsTo(VoiceExtension::class);
    }

    // ── Call state helpers ────────────────────────────────────────────────────

    /**
     * Effective call state for policy evaluation.
     * NULL (pre-migration rows) is treated as UNKNOWN, never as no_call.
     */
    public function effectiveCallState(): string
    {
        return $this->call_state ?? self::CALL_STATE_UNKNOWN;
    }

    public function hasActiveCall(): bool
    {
        return in_array($this->call_state, self::ACTIVE_CALL_STATES, true);
    }
}
