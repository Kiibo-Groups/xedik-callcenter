<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'appointment_wa_confirmation',
        'appointment_wa_reminder',
        'confirmation_wa_template_id',
        'reminder_wa_template_id',
    ];

    protected $casts = [
        'url_params'                  => 'array',
        'is_active'                   => 'boolean',
        'is_default'                  => 'boolean',
        'sort_order'                  => 'integer',
        'appointment_wa_confirmation' => 'boolean',
        'appointment_wa_reminder'     => 'boolean',
    ];

    public const DIRECTIONS = ['inbound', 'outbound', 'both'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function confirmationWaTemplate(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'confirmation_wa_template_id')->withoutGlobalScopes();
    }

    public function reminderWaTemplate(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'reminder_wa_template_id')->withoutGlobalScopes();
    }

    public function queues()
    {
        return $this->hasMany(OmniQueue::class, 'script_id');
    }
}
