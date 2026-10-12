<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebchatSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'webchat_config_id', 'session_token',
        'lead_id', 'conversation_id',
        'name', 'phone', 'email', 'interest',
        'page_url', 'ip', 'user_agent', 'outcome',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->session_token)) {
                $model->session_token = (string) Str::uuid();
            }
        });
    }

    public function config()
    {
        return $this->belongsTo(WebchatConfig::class, 'webchat_config_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class)->withoutGlobalScope(TenantScope::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class)->withoutGlobalScope(TenantScope::class);
    }
}
