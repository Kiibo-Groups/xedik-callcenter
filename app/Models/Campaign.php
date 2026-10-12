<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'program_id',
        'name',
        'slug',
        'dialer_pattern',
        'color',
        'bg_color',
        'description',
        'is_active',
        'is_default',
        'outbound_caller_id',
        'voice_gateway_id',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
    ];

    // ── Auto-generate slug on create ──
    protected static function booted(): void
    {
        static::creating(function ($campaign) {
            $campaign->slug = Str::slug($campaign->name, '_');
        });
    }

    // ── Relationships ──
    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function voiceGateway()
    {
        return $this->belongsTo(VoiceGateway::class);
    }

    public function scripts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Script::class);
    }

    public function omniQueues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OmniQueue::class);
    }

    // ── Match campaign from dialer campaign name ──
    // Logic: if contains "TLMK" → TLMK, if contains "gBPO" → Sales, etc.
    // Each campaign has its own unique pattern — no ambiguity needed.
    public static function matchFromDialer(string $dialerCampaign): ?self
    {
        return self::where('is_active', true)
            ->get()
            ->first(fn($c) => $c->dialer_pattern &&
                str_contains(strtolower($dialerCampaign), strtolower($c->dialer_pattern)));
    }

    // ── Fallback: get the default campaign ──
    public static function getDefault(): ?self
    {
        return self::where('is_default', true)->where('is_active', true)->first();
    }
}
