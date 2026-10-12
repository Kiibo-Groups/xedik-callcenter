<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversation_report_id', 'direction', 'from_email',
        'to_email', 'from_name', 'subject', 'body_html',
        'body_text', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function conversationReport(): BelongsTo
    {
        return $this->belongsTo(ConversationReport::class);
    }
}
