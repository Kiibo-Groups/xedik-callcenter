<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conexión técnica con un proveedor SMS (DIDWW, Twilio, Telnyx, ...).
 * Sin BelongsToTenant: catálogo global gestionado por superadmin — tenant_id es
 * solo atribución opcional, una cuenta puede alimentar sms_channels de varios tenants.
 */
class SmsProviderAccount extends Model
{
    protected $fillable = [
        'tenant_id', 'provider', 'display_name', 'region',
        'credentials', 'settings', 'is_active',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings'    => 'array',
        'is_active'   => 'boolean',
    ];

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function channels(): HasMany    { return $this->hasMany(SmsChannel::class); }
}
