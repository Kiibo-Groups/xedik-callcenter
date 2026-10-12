<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsChannel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sms_provider_account_id', 'omni_queue_id',
        'display_name', 'address', 'normalized_address', 'address_type',
        'country', 'capabilities', 'is_active',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'is_active'    => 'boolean',
    ];

    public function tenant(): BelongsTo             { return $this->belongsTo(Tenant::class); }
    public function providerAccount(): BelongsTo    { return $this->belongsTo(SmsProviderAccount::class, 'sms_provider_account_id'); }
    public function omniQueue(): BelongsTo          { return $this->belongsTo(OmniQueue::class); }
    public function conversations(): HasMany        { return $this->hasMany(Conversation::class); }

    public function supportsP2p(): bool
    {
        return (bool) ($this->capabilities['p2p'] ?? true);
    }

    public function supportsA2p(): bool
    {
        return (bool) ($this->capabilities['a2p'] ?? false);
    }
}
