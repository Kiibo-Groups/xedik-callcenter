<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Script extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'name',
        'type',
        'body',
        'url',
        'url_params',
        'url_target',
        'is_active',
        'direction',
        'is_default',
        'sort_order',
        'voicebot_agent_id',
        'voicebot_voice_id',
    ];

    protected $casts = [
        'url_params'  => 'array',
        'is_active'   => 'boolean',
        'is_default'  => 'boolean',
        'sort_order'  => 'integer',
    ];

    public const DIRECTIONS = ['inbound', 'outbound', 'both'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function queues()
    {
        return $this->hasMany(OmniQueue::class, 'script_id');
    }
}
