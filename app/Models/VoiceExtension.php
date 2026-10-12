<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceExtension extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'extension',
        'display_name',
        'caller_id_number',
        'caller_id_name',
        'personal_caller_id_id',
        'caller_id_source',
        'dialplan_context',
        'outbound_context',
        'sip_username',
        'pjsip_endpoint',
        'pjsip_auth',
        'pjsip_aor',
        'allow_outbound',
        'allow_internal',
        'allow_transfer',
        'is_active',
        'pjsip_provisioned_at',
        'sip_password_rotated_at',
        'sip_invalidated_at',
        'sip_provisioning_status',
        'sip_provisioning_error',
        'released_at',
    ];

    protected $casts = [
        'allow_outbound' => 'boolean',
        'allow_internal' => 'boolean',
        'allow_transfer' => 'boolean',
        'is_active' => 'boolean',
        'pjsip_provisioned_at'    => 'datetime',
        'sip_password_rotated_at' => 'datetime',
        'sip_invalidated_at'      => 'datetime',
        'released_at'             => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }

    public function personalCallerId(): BelongsTo
    {
        return $this->belongsTo(VoiceCallerId::class, 'personal_caller_id_id');
    }

    /** True when the caller_id_number was set explicitly by the user (not inherited from tenant). */
    public function hasPersonalCallerId(): bool
    {
        return $this->caller_id_source === 'user' && $this->personal_caller_id_id !== null;
    }
}
