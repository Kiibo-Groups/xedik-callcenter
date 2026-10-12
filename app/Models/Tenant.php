<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'is_active', 'settings', 'zernio_profile_id'];
    protected $casts    = ['is_active' => 'boolean', 'settings' => 'array'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
    public function leads()
    {
        return $this->hasMany(Lead::class);
    }
    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
    public function emailAccounts()
    {
        return $this->hasMany(EmailAccount::class);
    }
    public function whatsappAccounts()
    {
        return $this->hasMany(WhatsappAccount::class);
    }
    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }
    public function omniQueues()
    {
        return $this->hasMany(OmniQueue::class);
    }
    public function storageSetting()
    {
        return $this->hasOne(TenantStorageSetting::class);
    }
    public function storageSubscriptions()
    {
        return $this->hasMany(TenantStorageSubscription::class);
    }
    public function crmFiles()
    {
        return $this->hasMany(CrmFile::class);
    }
    public function voiceTenantSetting(): HasOne
    {
        return $this->hasOne(VoiceTenantSetting::class);
    }
    public function voiceExtensions(): HasMany
    {
        return $this->hasMany(VoiceExtension::class);
    }
    public function voiceSessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }
    public function voiceExtensionRanges(): HasMany
    {
        return $this->hasMany(VoiceExtensionRange::class);
    }
    public function voiceGateways(): HasMany
    {
        return $this->hasMany(VoiceGateway::class);
    }

    /**
     * Permissions this tenant is entitled to use. Superadmin-controlled —
     * a tenant admin can only assign these to its roles, never the full
     * global Permission catalog.
     */
    public function enabledPermissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_tenant')->withPivot('enabled_at');
    }

    public function hasPermissionEnabled(string $permissionName): bool
    {
        return $this->enabledPermissions()->where('name', $permissionName)->exists();
    }
}
