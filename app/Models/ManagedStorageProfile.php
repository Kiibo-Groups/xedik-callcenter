<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ManagedStorageProfile extends Model
{
    protected $fillable = [
        'code', 'name', 'provider_code', 'environment',
        'bucket', 'region', 'endpoint', 'base_path',
        'access_key_encrypted', 'secret_key_encrypted',
        'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    protected $hidden = ['access_key_encrypted', 'secret_key_encrypted'];

    public function setAccessKeyAttribute(string $value): void
    {
        $this->attributes['access_key_encrypted'] = Crypt::encryptString($value);
    }

    public function setSecretKeyAttribute(string $value): void
    {
        $this->attributes['secret_key_encrypted'] = Crypt::encryptString($value);
    }

    public function getAccessKeyDecrypted(): string
    {
        return Crypt::decryptString($this->access_key_encrypted);
    }

    public function getSecretKeyDecrypted(): string
    {
        return Crypt::decryptString($this->secret_key_encrypted);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true)->where('is_active', true);
    }

    public function tenantStorageSettings()
    {
        return $this->hasMany(TenantStorageSetting::class);
    }
}
