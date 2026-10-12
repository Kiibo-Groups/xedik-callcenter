<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DialerCampaignCallerId extends Model
{
    use BelongsToTenant;

    protected $table = 'dialer_campaign_caller_ids';

    protected $fillable = [
        'tenant_id',
        'dialer_campaign_id',
        'voice_caller_id_id',
        'is_active',
        'last_used_at',
        'sort_order',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function dialerCampaign(): BelongsTo
    {
        return $this->belongsTo(DialerCampaign::class);
    }

    public function voiceCallerId(): BelongsTo
    {
        return $this->belongsTo(VoiceCallerId::class);
    }
}
