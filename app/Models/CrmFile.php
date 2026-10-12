<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmFile extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'conversation_id', 'message_id',
        'related_type', 'related_id',
        'channel', 'direction', 'source', 'category',
        'storage_provider', 'storage_ownership_type', 'managed_storage_profile_id',
        'bucket', 'object_key',
        'original_filename', 'stored_filename', 'extension', 'mime_type',
        'size_bytes', 'checksum_sha256',
        'visibility', 'status',
        'retention_days', 'expires_at',
        'uploaded_by',
        'migrated_from_path', 'migrated_at',
    ];

    protected $casts = [
        'size_bytes'   => 'integer',
        'expires_at'   => 'datetime',
        'migrated_at'  => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function managedProfile()
    {
        return $this->belongsTo(ManagedStorageProfile::class, 'managed_storage_profile_id');
    }

    public function accessLogs()
    {
        return $this->hasMany(FileAccessLog::class);
    }

    public function related()
    {
        return $this->morphTo();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getSizeHumanAttribute(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 ** 2) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 ** 3) {
            return round($bytes / (1024 ** 2), 1) . ' MB';
        }
        return round($bytes / (1024 ** 3), 2) . ' GB';
    }
}
