<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'message_id', 'filename', 'mime_type', 'size', 'path',
        'content_id', 'imap_part_number', 'crm_file_id',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function crmFile(): BelongsTo
    {
        return $this->belongsTo(CrmFile::class);
    }

    public function isOnR2(): bool
    {
        return !is_null($this->crm_file_id);
    }

    public function sizeForHumans(): string
    {
        if ($this->size < 1024)    return $this->size . ' B';
        if ($this->size < 1048576) return round($this->size / 1024, 1) . ' KB';
        return round($this->size / 1048576, 1) . ' MB';
    }
}