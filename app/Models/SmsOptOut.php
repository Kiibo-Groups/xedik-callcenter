<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsOptOut extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'phone', 'sms_channel_id', 'campaign_id', 'scope',
        'reason', 'provider', 'source_message_id', 'opted_out_at',
    ];

    protected $casts = [
        'opted_out_at' => 'datetime',
    ];

    public function smsChannel(): BelongsTo { return $this->belongsTo(SmsChannel::class); }
    public function campaign(): BelongsTo   { return $this->belongsTo(Campaign::class); }

    /**
     * Chequeo de opt-out para el MVP: scope tenant-wide (ignora canal/campaña).
     * Cuando se agregue scope por canal/campaña, extender el where aquí —
     * único punto de verdad para "¿puedo enviarle SMS a este número?".
     */
    public static function isOptedOut(int $tenantId, string $normalizedPhone): bool
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone', $normalizedPhone)
            ->exists();
    }
}
