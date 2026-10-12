<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password',
        'role_id', 'is_active', 'phone_user_id', 'signature',
        'failed_login_attempts', 'locked_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'      => 'datetime',
        'locked_at'              => 'datetime',
        'password'               => 'hashed',
        'is_active'              => 'boolean',
        'is_superadmin'          => 'boolean',
        'failed_login_attempts'  => 'integer',
    ];

    public function isLocked(): bool
    {
        return !is_null($this->locked_at);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
    public function leads()
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }
    public function calls()
    {
        return $this->hasMany(Call::class, 'agent_id');
    }
    public function campaigns()
    {
        return $this->belongsToMany(Campaign::class)->withTimestamps();
    }
    public function agentSession()
    {
        return $this->hasOne(AgentSession::class);
    }
    public function omniQueues(): BelongsToMany
    {
        return $this->belongsToMany(OmniQueue::class, 'omni_queue_user')
            ->withPivot(['max_emails', 'max_chats', 'priority', 'active'])
            ->withTimestamps()
            ->orderByPivot('priority');
    }
    public function voiceExtension(): HasOne
    {
        return $this->hasOne(VoiceExtension::class);
    }

    public function voiceSessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }

    public function hasActiveVoice(): bool
    {
        return VoiceExtension::withoutGlobalScopes()
            ->where('user_id', $this->id)
            ->where('is_active', true)
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_superadmin;
    }
    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }
    public function isSupervisor(): bool
    {
        return $this->role?->name === 'supervisor';
    }
    public function isAgent(): bool
    {
        return $this->role?->name === 'agent';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) return true;
        if ($this->isAdmin()) return true;
        return $this->role?->permissions->pluck('name')->contains($permission) ?? false;
    }
}
