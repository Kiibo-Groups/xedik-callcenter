<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceCallerId extends Model
{
    use BelongsToTenant;

    protected $table = 'voice_caller_ids';

    protected $fillable = [
        'tenant_id',
        'voice_gateway_id',
        'name',
        'number',
        'is_active',
        'is_verified',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'is_verified' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function voiceGateway(): BelongsTo
    {
        return $this->belongsTo(VoiceGateway::class);
    }

    public function voiceExtensions(): HasMany
    {
        return $this->hasMany(VoiceExtension::class, 'personal_caller_id_id');
    }

    /** Label for dropdowns: "name (number)" or just "number". */
    public function getDisplayLabelAttribute(): string
    {
        return $this->name
            ? "{$this->name} ({$this->number})"
            : $this->number;
    }

    /** Query scope: only caller IDs usable in a user dropdown. */
    public function scopeSelectable($query)
    {
        return $query->where('is_active', true)->where('is_verified', true);
    }
}
