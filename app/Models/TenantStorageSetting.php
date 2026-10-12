<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TenantStorageSetting extends Model
{
    protected $fillable = [
        'tenant_id', 'provider_code', 'ownership_type',
        'managed_storage_profile_id',
        'bucket', 'region', 'endpoint', 'base_path',
        'access_key_encrypted', 'secret_key_encrypted',
        'is_active',
        'last_connection_test_at', 'last_connection_status', 'last_connection_error',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'last_connection_test_at' => 'datetime',
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

    public function getAccessKeyDecrypted(): ?string
    {
        if (empty($this->access_key_encrypted)) {
            return null;
        }
        return Crypt::decryptString($this->access_key_encrypted);
    }

    public function getSecretKeyDecrypted(): ?string
    {
        if (empty($this->secret_key_encrypted)) {
            return null;
        }
        return Crypt::decryptString($this->secret_key_encrypted);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function managedProfile()
    {
        return $this->belongsTo(ManagedStorageProfile::class, 'managed_storage_profile_id');
    }

    public function isCrmManaged(): bool
    {
        return $this->ownership_type === 'crm_managed';
    }

    public function isTenantOwned(): bool
    {
        return $this->ownership_type === 'tenant_owned';
    }
}
