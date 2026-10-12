<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationAutomationChannel extends Model
{
    use BelongsToTenant;

    /** Channels the settings screen renders a row for, in display order. */
    public const SUPPORTED_CHANNELS = ['whatsapp', 'facebook', 'instagram', 'chat', 'sms'];

    protected $fillable = [
        'tenant_id', 'channel', 'enabled', 'webhook_url',
        'events', 'auth_type', 'api_key', 'timeout_ms', 'settings',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'enabled'    => 'boolean',
        'events'     => 'array',
        'settings'   => 'array',
        'api_key'    => 'encrypted',
        'timeout_ms' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
