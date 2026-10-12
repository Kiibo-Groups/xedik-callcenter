<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CannedResponse extends Model
{
    protected $fillable = [
        'queue_id',
        'name',
        'shortcut',
        'channel',
        'subject',
        'body',
        'is_template',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_template' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function queue()
    {
        return $this->belongsTo(OmniQueue::class);
    }
}
