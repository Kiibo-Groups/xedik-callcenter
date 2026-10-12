<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceTenantSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'voice_enabled',
        'preview_dialer_enabled',
        'dialplan_context',
        'outbound_context',
        'default_caller_id',
        'voice_gateway_id',
        'default_voice_queue_id',
        'session_ttl_seconds',
        'max_sessions_per_agent',
    ];

    protected $casts = [
        'voice_enabled'          => 'boolean',
        'preview_dialer_enabled' => 'boolean',
        'session_ttl_seconds'    => 'integer',
        'max_sessions_per_agent' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function voiceGateway(): BelongsTo
    {
        return $this->belongsTo(VoiceGateway::class);
    }

    public function defaultVoiceQueue(): BelongsTo
    {
        return $this->belongsTo(OmniQueue::class, 'default_voice_queue_id');
    }

    // Deterministic context names — never freely editable
    public function agentsContextName(): string
    {
        return "xedik_t{$this->tenant_id}_agents";
    }

    public function outboundContextName(): string
    {
        return "xedik_t{$this->tenant_id}_out";
    }

    public function voiceStatus(): string
    {
        if (!$this->voice_enabled) {
            return 'disabled';
        }

        $hasRanges = VoiceExtensionRange::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->where('is_active', true)
            ->exists();

        if (!$hasRanges) {
            return 'incomplete';
        }

        return 'ready';
    }
}
