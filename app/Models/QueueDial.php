<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueDial extends Model
{
    use BelongsToTenant;

    protected $table = 'queue_dial';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'dialer_campaign_id', 'lead_list_table',
        'dialer_lead_id', 'phone', 'status',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
    ];

    public function dialerCampaign(): BelongsTo
    {
        return $this->belongsTo(DialerCampaign::class);
    }
}
