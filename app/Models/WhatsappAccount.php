<?php
declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'omni_queue_id', 'display_name', 'phone_number',
        'phone_number_id', 'waba_id', 'access_token',
        'verify_token', 'app_secret', 'is_active',
    ];

    protected $hidden = ['access_token', 'verify_token', 'app_secret'];

    protected $casts = [
        'access_token' => 'encrypted',
        'app_secret'   => 'encrypted',
        'is_active'    => 'boolean',
    ];

    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function omniQueue(): BelongsTo   { return $this->belongsTo(OmniQueue::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
}
