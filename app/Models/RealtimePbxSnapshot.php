<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealtimePbxSnapshot extends Model
{
    protected $fillable = [
        'pbx_id',
        'sequence',
        'generated_at',
        'received_at',
        'queues_count',
        'members_count',
        'waiting_calls_count',
        'status',
        'processing_ms',
        'payload_hash',
        'error_message',
    ];

    protected $casts = [
        'pbx_id' => 'integer',
        'sequence' => 'integer',
        'generated_at' => 'datetime',
        'received_at' => 'datetime',
        'queues_count' => 'integer',
        'members_count' => 'integer',
        'waiting_calls_count' => 'integer',
        'processing_ms' => 'integer',
    ];
}
