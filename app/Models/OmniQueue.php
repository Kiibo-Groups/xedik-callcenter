<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OmniQueue extends Model
{
    use BelongsToTenant;

    public const ROUTING_PRIORITY_LABELS = [
        0 => 'Highest priority',
        1 => 'High priority',
        2 => 'Medium priority',
        3 => 'Low priority',
        4 => 'Lowest priority',
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'assignment_mode',
        'default_agent_id',
        'max_wait_minutes',
        'active',
        'campaign_id',
        'script_id',
    ];

    protected $casts = [
        'active'           => 'boolean',
        'max_wait_minutes' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function defaultAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_agent_id');
    }

    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    public function voiceSettings(): HasOne
    {
        return $this->hasOne(OmniQueueVoiceSetting::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'omni_queue_user')
                    ->withPivot(['max_emails', 'max_chats', 'priority', 'active'])
                    ->withTimestamps()
                    ->orderByPivot('priority');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Queues that can originate voice calls: active, voice-capable, with an enabled
     * Voice setting that has a non-empty asterisk_queue_name. Single source of the
     * "voice eligible" criteria shared by outbound queue selection (Manual Dial) and
     * Preview Dialer configuration. Tenant/campaign scoping is applied by the caller.
     */
    public function scopeVoiceEligible($query)
    {
        return $query->where('active', true)
            ->whereIn('type', ['voice', 'omni'])
            ->whereHas('voiceSettings', function ($q): void {
                $q->where('is_enabled', true)
                    ->whereNotNull('asterisk_queue_name')
                    ->where('asterisk_queue_name', '<>', '');
            });
    }

    public function supportsVoice(): bool
    {
        return in_array($this->type, ['voice', 'omni'], true);
    }

    public function hasVoiceRoutingEnabled(): bool
    {
        if (!$this->supportsVoice()) {
            return false;
        }

        if (!$this->voiceSettings) {
            return false;
        }

        return (bool) $this->voiceSettings->is_enabled;
    }

    public static function routingPriorityLabels(): array
    {
        return self::ROUTING_PRIORITY_LABELS;
    }

    public static function routingPriorityValues(): array
    {
        return array_keys(self::ROUTING_PRIORITY_LABELS);
    }

    public static function routingPriorityLabel(int|string|null $value): string
    {
        if ($value === null || !self::isRoutingPriorityValue($value)) {
            return self::ROUTING_PRIORITY_LABELS[4];
        }

        return self::ROUTING_PRIORITY_LABELS[(int) $value];
    }

    public static function isRoutingPriorityValue(mixed $value): bool
    {
        return in_array((string) $value, array_map('strval', self::routingPriorityValues()), true);
    }
}
