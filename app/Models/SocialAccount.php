<?php
declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use BelongsToTenant;

    public const PLATFORMS = ['facebook', 'instagram']; // linkedin después

    protected $fillable = [
        'tenant_id', 'omni_queue_id', 'platform',
        'zernio_profile_id', 'zernio_account_id', 'page_id',
        'username', 'display_name', 'picture_url', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo      { return $this->belongsTo(Tenant::class); }
    public function omniQueue(): BelongsTo   { return $this->belongsTo(OmniQueue::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
}
