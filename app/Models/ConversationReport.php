<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversation_id', 'agent_id', 'agent_name',
        'lead_id', 'lead_name', 'contact_email',
        'channel', 'direction', 'subject',
        'first_message_preview', 'disposition', 'resolved_source',
        'message_count', 'inbound_message_count', 'outbound_message_count',
        'duration_seconds',
        'wait_since', 'resolved_at',
        'notes',
    ];

    protected $casts = [
        'wait_since'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function messageReports(): HasMany
    {
        return $this->hasMany(MessageReport::class);
    }

    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration_seconds) return '—';
        $h = floor($this->duration_seconds / 3600);
        $m = floor(($this->duration_seconds % 3600) / 60);
        $s = $this->duration_seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
