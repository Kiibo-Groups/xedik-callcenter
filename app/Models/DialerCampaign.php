<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DialerCampaign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'campaign_id', 'omni_queue_id', 'lead_list_id', 'script_id',
        'lead_order_direction', 'filter_config', 'compliance_config',
        'dialer_type', 'caller_id', 'context',
        'max_retries', 'dial_block_size', 'offer_timeout_seconds',
        'status', 'started_at', 'completed_at',
        'scheduled_at', 'scheduled_end_at', 'daily_start_time', 'daily_end_time',
    ];

    protected $casts = [
        'offer_timeout_seconds' => 'integer',
        'filter_config'         => 'array',
        'compliance_config'     => 'array',
        'started_at'            => 'datetime',
        'completed_at'          => 'datetime',
        'scheduled_at'          => 'datetime',
        'scheduled_end_at'      => 'datetime',
    ];

    public function getLeadOrderDirectionAttribute($value): string
    {
        return in_array($value, ['asc', 'desc'], true) ? $value : 'asc';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Operational Voice Queue (Preview Dialer identity). Supplies asterisk_queue_name
     * for QueueLog / telephony_calls / reporting. Does NOT distribute calls.
     */
    public function omniQueue(): BelongsTo
    {
        return $this->belongsTo(OmniQueue::class);
    }

    public function leadList(): BelongsTo
    {
        return $this->belongsTo(LeadList::class, 'lead_list_id');
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class, 'script_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function callerIdAssignments(): HasMany
    {
        return $this->hasMany(DialerCampaignCallerId::class);
    }

    public function callerIds(): BelongsToMany
    {
        return $this->belongsToMany(VoiceCallerId::class, 'dialer_campaign_caller_ids')
            ->withPivot(['is_active', 'last_used_at', 'sort_order'])
            ->withTimestamps();
    }

    public function isRunning(): bool   { return $this->status === 'running'; }
    public function isIdle(): bool      { return $this->status === 'idle'; }
    public function isPaused(): bool    { return $this->status === 'paused'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isScheduled(): bool { return $this->status === 'scheduled'; }
}
