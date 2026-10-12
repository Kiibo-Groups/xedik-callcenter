<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreviewDialerOffer extends Model
{
    use BelongsToTenant;

    /**
     * Transient outbound-call preparation result attached by
     * PreviewDialerOfferService::accept() so the accept response can expose the
     * backend-built SIP headers and the voice_call_id to the frontend. Declared as a
     * real class property (not an Eloquent attribute) so it is never persisted.
     *
     * @var array{voice_call_id: int, caller_id_number: ?string, extra_headers: array<int, string>}|null
     */
    public ?array $callPreparation = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'dialer_campaign_id',
        'lead_list_id',
        'lead_list_row_id',
        'phone',
        'reservation_token',
        'status',
        'active_slot',
        'offered_at',
        'expires_at',
        'accepted_at',
        'declined_at',
        'expired_at',
        'finalized_at',
        'call_uuid',
        'conversation_id',
        'voice_caller_id_id',
        'dial_prefix',
    ];

    protected $casts = [
        'lead_list_row_id' => 'integer',
        'active_slot'      => 'integer',
        'offered_at'       => 'datetime',
        'expires_at'       => 'datetime',
        'accepted_at'      => 'datetime',
        'declined_at'      => 'datetime',
        'expired_at'       => 'datetime',
        'finalized_at'     => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dialerCampaign(): BelongsTo
    {
        return $this->belongsTo(DialerCampaign::class);
    }

    public function leadList(): BelongsTo
    {
        return $this->belongsTo(LeadList::class);
    }

    public function voiceCallerId(): BelongsTo
    {
        return $this->belongsTo(VoiceCallerId::class);
    }

    public function isActive(): bool
    {
        return (int) $this->active_slot === 1
            && in_array($this->status, ['pending', 'accepted'], true);
    }

    public function isTerminal(): bool
    {
        return $this->active_slot === null
            && in_array($this->status, ['declined', 'expired', 'completed', 'failed'], true);
    }
}
