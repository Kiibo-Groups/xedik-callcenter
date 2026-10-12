<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversation_id', 'direction', 'from_email', 'from_name', 'to_email',
        'cc_emails','body_html', 'body_text', 'media_type', 'referral_data', 'message_id', 'in_reply_to',
        'sent_by', 'sent_at',
        'provider_message_id', 'provider_status', 'provider_error_code', 'segments',
        'provider_cost', 'provider_started_at', 'provider_completed_at', 'provider_payload',
    ];

    protected $casts = [
        'sent_at'                => 'datetime',
        'referral_data'          => 'array',
        'provider_payload'       => 'array',
        'provider_started_at'    => 'datetime',
        'provider_completed_at'  => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function crmFiles(): HasMany
    {
        return $this->hasMany(CrmFile::class);
    }

    public function isInbound(): bool  { return $this->direction === 'inbound'; }
    public function isOutbound(): bool { return $this->direction === 'outbound'; }
}