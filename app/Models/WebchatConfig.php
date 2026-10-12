<?php
declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebchatConfig extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'omni_queue_id', 'public_token', 'allowed_origin',
        'button_label', 'window_title', 'window_subtitle', 'form_subtitle',
        'primary_color', 'icon', 'position', 'label_position', 'label_visibility', 'size',
        'welcome_message', 'offline_message',
        'interest_enabled', 'interest_type', 'interest_options',
        'business_hours', 'timezone', 'is_active',
        'privacy_enabled', 'privacy_type', 'privacy_text', 'privacy_url',
    ];

    protected $casts = [
        'business_hours'   => 'array',
        'interest_options' => 'array',
        'is_active'        => 'boolean',
        'interest_enabled' => 'boolean',
        'privacy_enabled'  => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->public_token)) {
                $model->public_token = (string) Str::uuid();
            }
        });
    }

    public function omniQueue()
    {
        return $this->belongsTo(OmniQueue::class);
    }

    public function sessions()
    {
        return $this->hasMany(WebchatSession::class);
    }
}
